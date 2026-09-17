<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\CouponService;
use Saveurs\Services\PayoutService;
use Saveurs\Services\PricingService;
use Saveurs\Services\PushService;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Realtime;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

/**
 * Transitions de statut autorisées par rôle — voir §3/§6 du document
 * d'architecture (le dispatch reste une proposition, jamais une affectation
 * forcée : le livreur choisit "picked_up", il n'est jamais basculé de force).
 */
final class OrderController
{
    private const TRANSITIONS = [
        'restaurant' => [
            'pending' => ['accepted', 'cancelled'],
            'accepted' => ['preparing'],
            'preparing' => ['ready_for_pickup'],
        ],
        'driver' => [
            'ready_for_pickup' => ['picked_up'],
            'picked_up' => ['delivering'],
            'delivering' => ['delivered'],
        ],
        'client' => [
            'pending' => ['cancelled'],
        ],
        // Résolution de litige : un admin peut annuler une commande à n'importe quel stade non
        // terminal (jamais forcer "delivered", qui déclenche les virements — voir PayoutService).
        'admin' => [
            'pending' => ['cancelled'],
            'accepted' => ['cancelled'],
            'preparing' => ['cancelled'],
            'ready_for_pickup' => ['cancelled'],
            'picked_up' => ['cancelled'],
            'delivering' => ['cancelled'],
        ],
    ];

    /**
     * Colonnes renvoyées pour une commande.
     *
     * Liste explicite, et pas `o.*` : la table porte `cinetpay_notify_token`, le secret qui
     * sert à vérifier l'authenticité des webhooks CinetPay. Un `SELECT o.*` le renvoyait au
     * client, au restaurateur et au livreur — de quoi forger une confirmation de paiement pour
     * une commande jamais payée. Même raison pour `idempotency_key`, qui identifie la requête.
     */
    private const ORDER_COLUMNS = 'o.id, o.client_id, o.restaurant_id, o.driver_id, o.status,
        o.subtotal_cents, o.delivery_fee_cents, o.tva_cents, o.discount_cents, o.promo_code,
        o.delivery_mode, o.total_cents, o.payment_status, o.adresse_livraison, o.lat, o.lng,
        o.note_livreur, o.created_at, o.accepted_at, o.ready_at, o.picked_up_at, o.delivered_at';

    /**
     * POST /orders/quote — récapitulatif chiffré d'un panier, sans rien créer.
     *
     * C'est cet endpoint que l'écran panier interroge à chaque changement (quantité, mode de
     * livraison, code promo) : le total affiché est ainsi, par construction, celui qui sera
     * facturé. Auparavant le front additionnait ses propres frais et ses propres remises, et
     * découvrait l'écart au moment du débit.
     */
    public function quote(Request $request, Response $response): Response
    {
        $clientId = (int) $request->getAttribute('user_id');

        try {
            $basket = $this->priceBasket((array) $request->getParsedBody(), $clientId, requireLabel: false);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        } catch (\DomainException $e) {
            return JsonResponse::error($response, 422, $e->getMessage());
        }

        return JsonResponse::ok($response, [
            ...$basket['summary'],
            'delivery_mode' => $basket['delivery_mode'],
            'distance_km' => round($basket['distance_km'], 2),
            'eta_low_min' => $basket['eta']['low'],
            'eta_high_min' => $basket['eta']['high'],
            'promo' => [
                'code' => $basket['coupon']['coupon']['code'] ?? null,
                'status' => $basket['coupon']['status'],
                'message' => $basket['coupon']['message'],
            ],
            'lines' => array_map(
                fn (array $line) => [
                    'menu_item_id' => $line['menu_item_id'],
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $line['unit_price_cents'],
                    'line_total_cents' => $line['line_total_cents'],
                    'options' => $line['options'],
                ],
                $basket['lines']
            ),
        ]);
    }

    /** POST /orders — le client passe commande. Les prix sont toujours recalculés côté serveur. */
    public function create(Request $request, Response $response): Response
    {
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 80) {
            return JsonResponse::error($response, 422, "L'en-tête Idempotency-Key est requis");
        }

        $db = Database::connection();
        $clientId = (int) $request->getAttribute('user_id');

        // Rejeu de la même requête : on renvoie la commande déjà créée. Le filtre sur
        // client_id est indispensable — sans lui, n'importe qui pouvait présenter la clé
        // d'un autre et récupérer l'identifiant, le statut et le montant de sa commande.
        $existing = $db->prepare(
            'SELECT id AS order_id, status, subtotal_cents, delivery_fee_cents, tva_cents,
                    discount_cents, total_cents
             FROM orders WHERE idempotency_key = ? AND client_id = ?'
        );
        $existing->execute([$idempotencyKey, $clientId]);
        if (($order = $existing->fetch()) !== false) {
            return JsonResponse::ok($response, $order, 200);
        }

        $body = (array) $request->getParsedBody();

        try {
            $basket = $this->priceBasket($body, $clientId, requireLabel: true);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        } catch (\DomainException $e) {
            return JsonResponse::error($response, 422, $e->getMessage());
        }

        // Un code promo refusé n'est jamais ignoré en silence : le client a vu une remise à
        // l'écran, il doit savoir pourquoi elle ne s'applique plus (expiré entre-temps,
        // panier repassé sous le minimum…) avant d'être débité du prix plein.
        if ($basket['coupon']['status'] !== 'none' && $basket['coupon']['status'] !== 'ok') {
            return JsonResponse::error($response, 422, 'Code promo refusé', $basket['coupon']['message']);
        }

        $restaurantId = (int) $basket['restaurant']['id'];
        $summary = $basket['summary'];
        $note = Validator::optionalStr($body, 'note', 255);

        $db->beginTransaction();

        try {
            $orderStmt = $db->prepare(
                'INSERT INTO orders (client_id, restaurant_id, status, subtotal_cents, delivery_fee_cents,
                    tva_cents, total_cents, promo_code, discount_cents, delivery_mode, idempotency_key,
                    adresse_livraison, lat, lng, note_livreur)
                 VALUES (?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->execute([
                $clientId,
                $restaurantId,
                $summary['subtotal_cents'],
                $summary['delivery_fee_cents'],
                $summary['tva_cents'],
                $summary['total_cents'],
                $basket['coupon']['coupon']['code'] ?? null,
                $summary['discount_cents'],
                $basket['delivery_mode'],
                $idempotencyKey,
                $basket['address']['label'],
                $basket['address']['lat'],
                $basket['address']['lng'],
                $note,
            ]);
            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, quantity, price_cents, options_json) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($basket['lines'] as $line) {
                $itemStmt->execute([
                    $orderId,
                    $line['menu_item_id'],
                    $line['quantity'],
                    $line['unit_price_cents'],
                    json_encode($line['options'], JSON_UNESCAPED_UNICODE),
                ]);
            }

            // Décrément atomique — la clause stock_quantity >= ? empêche une vente en double si
            // deux commandes touchent le même stock limité en même temps (ex: dernière taille M).
            if ($basket['stock_decrements'] !== []) {
                $stockStmt = $db->prepare(
                    'UPDATE item_options SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?'
                );
                foreach ($basket['stock_decrements'] as $optionId => $qty) {
                    $stockStmt->execute([$qty, $optionId, $qty]);
                    if ($stockStmt->rowCount() === 0) {
                        $db->rollBack();

                        return JsonResponse::error($response, 409, 'Stock insuffisant pour une variante choisie');
                    }
                }
            }

            // Consommation du coupon dans la même transaction que la commande : un coupon à
            // usage unique ne doit pas être brûlé par une commande qui échoue ensuite.
            if ($basket['coupon']['status'] === 'ok') {
                $redeemed = (new CouponService())->redeem(
                    $db,
                    (int) $basket['coupon']['coupon']['id'],
                    $clientId,
                    $orderId,
                    $summary['discount_cents']
                );

                if (!$redeemed) {
                    $db->rollBack();

                    return JsonResponse::error($response, 409, 'Ce code promo vient d\'atteindre sa limite d\'utilisation');
                }
            }

            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "pending", "client")')
                ->execute([$orderId]);

            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();

            // Clé d'idempotence déjà utilisée par un autre compte (la contrainte d'unicité est
            // globale) : c'est un doublon, pas une panne — 409 plutôt qu'une 500 opaque.
            if ($e->getCode() === '23000') {
                return JsonResponse::error($response, 409, 'Cette commande a déjà été enregistrée');
            }

            throw $e;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Realtime::trigger("private-restaurant.{$restaurantId}", 'new-order', ['order_id' => $orderId]);

        return JsonResponse::ok($response, [
            'order_id' => $orderId,
            'status' => 'pending',
            ...$summary,
        ], 201);
    }

    /**
     * Chiffre un panier : validation des entrées, prix repris en base, frais de livraison,
     * TVA et remise. Aucune écriture — partagé tel quel par l'aperçu (quote) et la création
     * (create), pour qu'aucun écart de calcul ne puisse exister entre les deux.
     *
     * @throws ValidationException entrée mal formée (→ 422 avec le champ fautif)
     * @throws \DomainException    entrée bien formée mais refusée métier (article indisponible…)
     *
     * @return array{restaurant:array, address:array, lines:list<array>, stock_decrements:array<int,int>,
     *               summary:array, coupon:array, distance_km:float, delivery_mode:string, eta:array}
     */
    private function priceBasket(array $body, int $clientId, bool $requireLabel): array
    {
        $restaurantId = Validator::id($body['restaurant_id'] ?? null, 'restaurant_id');
        $rawLines = Validator::objectList($body, 'items', PricingService::MAX_ITEMS_PER_ORDER);
        $deliveryMode = Validator::optionalEnum($body, 'delivery_mode', PricingService::DELIVERY_MODES, 'standard');
        $promoCode = Validator::optionalCouponCode($body);

        $rawAddress = $body['delivery_address'] ?? null;
        if (!is_array($rawAddress)) {
            throw new ValidationException('delivery_address', 'L\'adresse de livraison est requise.');
        }

        $address = [
            'lat' => Validator::latitude($rawAddress),
            'lng' => Validator::longitude($rawAddress),
            'label' => $requireLabel
                ? Validator::str($rawAddress, 'label', 255)
                : (Validator::optionalStr($rawAddress, 'label', 255) ?? ''),
        ];

        $db = Database::connection();

        $restaurantStmt = $db->prepare(
            'SELECT r.id, r.name, r.lat, r.lng, z.base_fee_cents, z.price_per_km_cents,
                    z.min_fee_cents, z.surge_multiplier
             FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
             WHERE r.id = ? AND r.is_active = 1'
        );
        $restaurantStmt->execute([$restaurantId]);
        $restaurant = $restaurantStmt->fetch();

        if ($restaurant === false) {
            throw new \DomainException('Ce commerce n\'est pas disponible.');
        }

        $distanceKm = PricingService::haversineKm(
            (float) $restaurant['lat'],
            (float) $restaurant['lng'],
            $address['lat'],
            $address['lng']
        );

        // Hors rayon : mieux vaut un refus clair à la commande qu'une course impossible
        // proposée aux livreurs, puis annulée à la main.
        if ($distanceKm > PricingService::MAX_DELIVERY_DISTANCE_KM) {
            throw new \DomainException(sprintf(
                'Cette adresse est trop éloignée du commerce (%.1f km, maximum %.0f km).',
                $distanceKm,
                PricingService::MAX_DELIVERY_DISTANCE_KM
            ));
        }

        // Normalisation des lignes AVANT toute requête : on ne construit une clause IN (...)
        // qu'avec des entiers déjà validés.
        $lines = [];
        foreach ($rawLines as $index => $rawLine) {
            $lines[] = [
                'menu_item_id' => Validator::id($rawLine['menu_item_id'] ?? null, "items[{$index}].menu_item_id"),
                'quantity' => Validator::optionalInt(
                    $rawLine,
                    'quantity',
                    1,
                    PricingService::MAX_QUANTITY_PER_LINE,
                    1
                ),
                'option_ids' => Validator::idList(
                    $rawLine['option_ids'] ?? [],
                    "items[{$index}].option_ids",
                    PricingService::MAX_OPTIONS_PER_LINE
                ),
            ];
        }

        $itemIds = array_values(array_unique(array_column($lines, 'menu_item_id')));
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        // Prix figés en base — jamais ceux envoyés par le client.
        $priceStmt = $db->prepare(
            "SELECT id, name, price_cents, vat_rate FROM menu_items
             WHERE id IN ({$placeholders}) AND restaurant_id = ? AND is_available = 1"
        );
        $priceStmt->execute([...$itemIds, $restaurantId]);
        $menuItems = [];
        foreach ($priceStmt->fetchAll() as $row) {
            $menuItems[(int) $row['id']] = $row;
        }

        // Variantes (item_options) — taille/couleur pour la mode, niveau de piment pour un plat...
        $optionsStmt = $db->prepare(
            "SELECT id, menu_item_id, name, price_delta_cents, stock_quantity
             FROM item_options WHERE menu_item_id IN ({$placeholders})"
        );
        $optionsStmt->execute($itemIds);
        $optionsById = [];
        foreach ($optionsStmt->fetchAll() as $option) {
            $optionsById[(int) $option['id']] = $option;
        }

        $subtotalCents = 0;
        $tvaCents = 0;
        $pricedLines = [];
        $stockDecrements = []; // option_id => quantité totale à décrémenter

        foreach ($lines as $line) {
            $menuItemId = $line['menu_item_id'];

            if (!isset($menuItems[$menuItemId])) {
                throw new \DomainException('Un article de ton panier n\'est plus disponible.');
            }

            $menuItem = $menuItems[$menuItemId];
            $quantity = $line['quantity'];
            $unitPriceCents = (int) $menuItem['price_cents'];
            $selectedOptions = [];

            foreach ($line['option_ids'] as $optionId) {
                $option = $optionsById[$optionId] ?? null;

                // Sécurité : une option doit appartenir à l'article commandé, jamais un autre.
                // Sans cette vérification, il suffisait de rattacher l'option « -5 000 » d'un
                // autre article pour se faire sa propre remise.
                if ($option === null || (int) $option['menu_item_id'] !== $menuItemId) {
                    throw new \DomainException('Une variante choisie n\'existe pas pour cet article.');
                }

                $unitPriceCents += (int) $option['price_delta_cents'];
                $selectedOptions[] = [
                    'id' => $optionId,
                    'name' => $option['name'],
                    'price_delta_cents' => (int) $option['price_delta_cents'],
                ];

                if ($option['stock_quantity'] !== null) {
                    $stockDecrements[$optionId] = ($stockDecrements[$optionId] ?? 0) + $quantity;
                    if ($stockDecrements[$optionId] > (int) $option['stock_quantity']) {
                        throw new \DomainException("Stock insuffisant pour « {$option['name']} ».");
                    }
                }
            }

            // Un cumul de variantes à delta négatif ne doit jamais rendre une ligne gratuite
            // ou créditrice : le prix unitaire est plancher à 0.
            $unitPriceCents = max(0, $unitPriceCents);

            $totals = PricingService::lineTotals($unitPriceCents, $quantity, (float) $menuItem['vat_rate']);
            $subtotalCents += $totals['line_total_cents'];
            $tvaCents += $totals['tva_cents'];

            $pricedLines[] = [
                'menu_item_id' => $menuItemId,
                'name' => $menuItem['name'],
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $totals['line_total_cents'],
                'options' => $selectedOptions,
            ];
        }

        $deliveryFeeCents = PricingService::deliveryFeeCents($distanceKm, $restaurant, $deliveryMode);
        $coupon = (new CouponService())->evaluate($promoCode, $clientId, $subtotalCents);

        return [
            'restaurant' => $restaurant,
            'address' => $address,
            'lines' => $pricedLines,
            'stock_decrements' => $stockDecrements,
            'summary' => PricingService::summary(
                $subtotalCents,
                $deliveryFeeCents,
                $tvaCents,
                $coupon['discount_cents']
            ),
            'coupon' => $coupon,
            'distance_km' => $distanceKm,
            'delivery_mode' => $deliveryMode,
            'eta' => PricingService::etaMinutes($distanceKm, $deliveryMode),
        ];
    }

    /** GET /orders/mine — historique des commandes du client connecté. */
    public function mine(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.id, o.status, o.total_cents, o.payment_status, o.created_at, r.name AS restaurant_name
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.client_id = ?
             ORDER BY o.created_at DESC
             LIMIT 100'
        );
        $stmt->execute([$request->getAttribute('user_id')]);

        return JsonResponse::ok($response, ['orders' => $stmt->fetchAll()]);
    }

    /**
     * GET /recommendations/mine — "Pour toi ce soir" : le plat le plus souvent recommandé au
     * client d'après ses commandes livrées. Aucune recommandation renvoyée sans historique réel
     * (jamais de suggestion inventée), ni si le plat ou le restaurant n'est plus actif.
     */
    public function recommendationForClient(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            "SELECT mi.id AS menu_item_id, mi.name, mi.price_cents, r.id AS restaurant_id, r.name AS restaurant_name,
                    SUM(oi.quantity) AS total_quantity
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN restaurants r ON r.id = mi.restaurant_id
             WHERE o.client_id = ? AND o.status = 'delivered' AND mi.is_available = 1 AND r.is_active = 1
             GROUP BY mi.id, mi.name, mi.price_cents, r.id, r.name
             ORDER BY total_quantity DESC
             LIMIT 1"
        );
        $stmt->execute([$request->getAttribute('user_id')]);
        $reco = $stmt->fetch();

        return JsonResponse::ok($response, ['recommendation' => $reco === false ? null : $reco]);
    }

    /** GET /orders/{id} */
    public function show(Request $request, Response $response, array $routeArgs): Response
    {
        $order = $this->findAccessibleOrder($request, (int) $routeArgs['id']);

        if ($order === null) {
            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        $items = Database::connection()->prepare(
            'SELECT oi.menu_item_id, mi.name, oi.quantity, oi.price_cents, oi.options_json
             FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = ?'
        );
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll();

        // Le téléphone du livreur n'est communiqué qu'aux personnes qui doivent le joindre
        // pendant la course — pas au restaurateur, et plus du tout une fois la commande
        // terminée : un numéro personnel n'a pas à rester consultable indéfiniment.
        if ($order['driver_id'] !== null) {
            $driver = Database::connection()->prepare(
                'SELECT u.first_name, u.phone, dp.vehicule_type FROM users u
                 JOIN driver_profiles dp ON dp.user_id = u.id WHERE u.id = ?'
            );
            $driver->execute([$order['driver_id']]);
            $driverRow = $driver->fetch() ?: null;

            if ($driverRow !== null) {
                $userId = (int) $request->getAttribute('user_id');
                $isLive = in_array($order['status'], ['picked_up', 'delivering'], true);
                $canCall = $isLive && ($userId === (int) $order['client_id'] || $userId === (int) $order['driver_id']);

                if (!$canCall) {
                    unset($driverRow['phone']);
                }
            }

            $order['driver'] = $driverRow;
        }

        return JsonResponse::ok($response, $order);
    }

    /** PATCH /orders/{id}/status */
    public function updateStatus(Request $request, Response $response, array $routeArgs): Response
    {
        $order = $this->findAccessibleOrder($request, (int) $routeArgs['id']);
        if ($order === null) {
            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        $body = (array) $request->getParsedBody();
        $nextStatus = is_string($body['status'] ?? null) ? $body['status'] : '';
        $role = $this->roleForOrder($request, $order);

        $allowed = self::TRANSITIONS[$role][$order['status']] ?? [];
        if (!in_array($nextStatus, $allowed, true)) {
            return JsonResponse::error(
                $response,
                409,
                'Transition de statut invalide',
                "{$order['status']} → {$nextStatus} n'est pas autorisé pour {$role}"
            );
        }

        // Une commande n'entre en cuisine qu'une fois encaissée. Le contrôle ne s'applique que
        // si l'encaissement en ligne est réellement branché : sur une instance sans CinetPay
        // configuré (démo, recette), il n'y a pas de paiement à attendre et bloquer ici
        // figerait toutes les commandes en "pending".
        if ($nextStatus === 'accepted' && $this->paymentRequired() && $order['payment_status'] !== 'paid') {
            return JsonResponse::error(
                $response,
                409,
                'Commande non payée',
                "Le paiement de cette commande n'a pas encore été confirmé."
            );
        }

        $db = Database::connection();
        $timestampColumn = [
            'accepted' => 'accepted_at',
            'ready_for_pickup' => 'ready_at',
            'picked_up' => 'picked_up_at',
            'delivered' => 'delivered_at',
        ][$nextStatus] ?? null;

        $sql = 'UPDATE orders SET status = ?' . ($timestampColumn ? ", {$timestampColumn} = NOW()" : '')
            . ' WHERE id = ? AND status = ?';

        // Transaction : le changement de statut et sa trace dans order_events doivent réussir
        // ensemble, sinon la commande se retrouve dans un état muet (statut changé, aucun
        // historique de qui/quand) sans même que l'appelant reçoive une réponse cohérente.
        //
        // La clause `AND status = ?` rend la transition atomique : deux requêtes simultanées
        // (le restaurateur sur deux onglets, un double-tap) ne peuvent pas franchir deux fois
        // la même étape — et donc pas déclencher deux fois les virements sur "delivered".
        $db->beginTransaction();

        try {
            $update = $db->prepare($sql);
            $update->execute([$nextStatus, $order['id'], $order['status']]);

            if ($update->rowCount() === 0) {
                $db->rollBack();

                return JsonResponse::error($response, 409, 'Cette commande vient de changer de statut');
            }

            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, ?, ?)')
                ->execute([$order['id'], $nextStatus, $role]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Realtime::trigger("private-order.{$order['id']}", 'status-updated', ['status' => $nextStatus]);
        Realtime::trigger("private-restaurant.{$order['restaurant_id']}", 'order-updated', [
            'order_id' => $order['id'],
            'status' => $nextStatus,
        ]);
        $this->notifyClient((int) $order['client_id'], (int) $order['id'], $nextStatus);

        if ($nextStatus === 'ready_for_pickup') {
            $this->notifyNearbyDrivers((int) $order['id'], (int) $order['restaurant_id']);
        }

        if ($nextStatus === 'delivered') {
            (new PayoutService())->releaseForOrder($order['id']);
        }

        return JsonResponse::ok($response, ['order_id' => $order['id'], 'status' => $nextStatus]);
    }

    /**
     * L'encaissement en ligne est-il effectivement branché sur cette instance ?
     * Sans identifiants CinetPay, la plateforme tourne en mode démonstration/paiement à la
     * livraison : aucune confirmation de paiement ne viendra jamais.
     */
    private function paymentRequired(): bool
    {
        return CinetPayClient::client() !== null;
    }

    private const STATUS_NOTIF = [
        'accepted' => 'Ta commande a été acceptée par le restaurant.',
        'preparing' => 'Ta commande est en préparation.',
        'ready_for_pickup' => 'Ta commande est prête, en attente d\'un livreur.',
        'picked_up' => 'Le livreur a récupéré ta commande.',
        'delivering' => 'Le livreur est en route vers toi.',
        'delivered' => 'Ta commande a été livrée. Bon appétit !',
        'cancelled' => 'Ta commande a été annulée.',
    ];

    private function notifyClient(int $clientId, int $orderId, string $status): void
    {
        $body = self::STATUS_NOTIF[$status] ?? null;
        if ($body === null) {
            return;
        }

        (new PushService())->sendToUser($clientId, [
            'title' => 'Ayo',
            'body' => $body,
            'url' => "/suivi.html?order={$orderId}",
        ]);
    }

    /**
     * Dispatch automatique — voir §6 du document d'architecture : une simple proposition
     * poussée aux livreurs les plus proches, jamais une affectation forcée. Premier arrivé,
     * premier servi (le UPDATE ... WHERE driver_id IS NULL dans claim() gère la course).
     */
    private function notifyNearbyDrivers(int $orderId, int $restaurantId): void
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT dp.user_id,
                    (6371 * acos(cos(radians(r.lat)) * cos(radians(dl.lat)) *
                    cos(radians(dl.lng) - radians(r.lng)) + sin(radians(r.lat)) * sin(radians(dl.lat)))) AS distance_km
             FROM driver_profiles dp
             JOIN driver_locations dl ON dl.driver_id = dp.user_id
             JOIN restaurants r ON r.id = ?
             WHERE dp.is_online = 1 AND dp.kyc_status = 'verified'
               AND dl.updated_at >= (NOW() - INTERVAL 10 MINUTE)
             HAVING distance_km <= 5
             ORDER BY distance_km ASC
             LIMIT 5"
        );
        $stmt->execute([$restaurantId]);

        $pushService = new PushService();
        foreach ($stmt->fetchAll() as $driver) {
            $pushService->sendToUser((int) $driver['user_id'], [
                'title' => 'Ayo',
                'body' => 'Une course est disponible près de toi.',
                'url' => '/driver.html',
            ]);
            Realtime::trigger("private-driver.{$driver['user_id']}", 'order-available', ['order_id' => $orderId]);
        }
    }

    /** PATCH /orders/{id}/claim — un livreur prend une commande prête (jamais une affectation forcée). */
    public function claim(Request $request, Response $response, array $routeArgs): Response
    {
        $db = Database::connection();
        $driverId = (int) $request->getAttribute('user_id');
        $orderId = (int) $routeArgs['id'];

        // Une course n'est confiée qu'à un livreur dont la pièce d'identité a été validée.
        // Le rôle "driver" est obtenu dès l'inscription : sans ce contrôle, un compte créé en
        // trente secondes pouvait récupérer une commande, et donc l'adresse d'un client.
        if (!$this->driverIsVerified($driverId)) {
            return JsonResponse::error(
                $response,
                403,
                'Vérification d\'identité requise',
                'Ton identité doit être vérifiée avant de pouvoir prendre une course.'
            );
        }

        $stmt = $db->prepare(
            "UPDATE orders SET driver_id = ? WHERE id = ? AND status = 'ready_for_pickup' AND driver_id IS NULL"
        );
        $stmt->execute([$driverId, $orderId]);

        if ($stmt->rowCount() === 0) {
            return JsonResponse::error($response, 409, 'Commande déjà prise ou plus disponible');
        }

        $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "driver_assigned", "driver")')
            ->execute([$orderId]);

        $restaurantStmt = $db->prepare('SELECT restaurant_id FROM orders WHERE id = ?');
        $restaurantStmt->execute([$orderId]);
        $restaurantId = (int) $restaurantStmt->fetchColumn();

        Realtime::trigger("private-order.{$orderId}", 'driver-assigned', ['driver_id' => $driverId]);
        Realtime::trigger("private-restaurant.{$restaurantId}", 'order-updated', [
            'order_id' => $orderId,
            'status' => 'ready_for_pickup',
        ]);

        return JsonResponse::ok($response, ['order_id' => $orderId, 'driver_id' => $driverId]);
    }

    private function driverIsVerified(int $driverId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM driver_profiles WHERE user_id = ? AND kyc_status = 'verified'"
        );
        $stmt->execute([$driverId]);

        return $stmt->fetch() !== false;
    }

    /** GET /restaurant/orders/live — file d'attente temps réel du back-office */
    public function liveForRestaurant(Request $request, Response $response): Response
    {
        $ownerId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT o.id, o.status, o.total_cents, o.payment_status, o.delivery_mode, o.note_livreur,
                    o.created_at, u.first_name AS client_first_name
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.client_id
             WHERE r.owner_id = ? AND o.status IN ('pending','accepted','preparing','ready_for_pickup')
             ORDER BY o.created_at ASC"
        );
        $stmt->execute([$ownerId]);
        $orders = $stmt->fetchAll();

        return JsonResponse::ok($response, ['orders' => $this->withItemSummaries($db, $orders)]);
    }

    /** GET /restaurant/orders/history — commandes terminées ou annulées (le kanban ne montre que les commandes actives). */
    public function historyForRestaurant(Request $request, Response $response): Response
    {
        $ownerId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT o.id, o.status, o.subtotal_cents, o.total_cents, o.created_at, o.delivered_at,
                    u.first_name AS client_first_name
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.client_id
             WHERE r.owner_id = ? AND o.status IN ('delivered','cancelled')
             ORDER BY o.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$ownerId]);
        $orders = $stmt->fetchAll();

        return JsonResponse::ok($response, ['orders' => $this->withItemSummaries($db, $orders)]);
    }

    /** GET /restaurant/stats — tableau de bord : chiffre d'affaires, volume, plats les plus vendus. */
    public function statsForRestaurant(Request $request, Response $response): Response
    {
        $ownerId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $restaurantStmt = $db->prepare('SELECT id FROM restaurants WHERE owner_id = ? ORDER BY id LIMIT 1');
        $restaurantStmt->execute([$ownerId]);
        $restaurantId = $restaurantStmt->fetchColumn();

        if ($restaurantId === false) {
            return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
        }

        $today = $db->prepare(
            "SELECT COUNT(*) AS orders_count, COALESCE(SUM(subtotal_cents), 0) AS revenue_cents
             FROM orders WHERE restaurant_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURDATE()"
        );
        $today->execute([$restaurantId]);
        $today = $today->fetch();

        $week = $db->prepare(
            "SELECT COUNT(*) AS orders_count, COALESCE(SUM(subtotal_cents), 0) AS revenue_cents
             FROM orders WHERE restaurant_id = ? AND status = 'delivered' AND delivered_at >= (NOW() - INTERVAL 7 DAY)"
        );
        $week->execute([$restaurantId]);
        $week = $week->fetch();

        $pending = $db->prepare(
            "SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status IN ('pending','accepted','preparing','ready_for_pickup')"
        );
        $pending->execute([$restaurantId]);
        $pendingCount = (int) $pending->fetchColumn();

        $topItems = $db->prepare(
            "SELECT mi.name, SUM(oi.quantity) AS total_quantity
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE o.restaurant_id = ? AND o.status = 'delivered' AND o.delivered_at >= (NOW() - INTERVAL 30 DAY)
             GROUP BY mi.id, mi.name
             ORDER BY total_quantity DESC
             LIMIT 5"
        );
        $topItems->execute([$restaurantId]);

        // Chiffre d'affaires par jour sur les 7 derniers jours (pour le mini-graphique du dashboard) —
        // les jours sans commande livrée sont complétés à 0 ci-dessous, MySQL ne renvoie que les jours ayant des lignes.
        $dailyRows = $db->prepare(
            "SELECT DATE(delivered_at) AS day, SUM(subtotal_cents) AS revenue_cents
             FROM orders WHERE restaurant_id = ? AND status = 'delivered' AND delivered_at >= (CURDATE() - INTERVAL 6 DAY)
             GROUP BY DATE(delivered_at)"
        );
        $dailyRows->execute([$restaurantId]);
        $dailyByDate = array_column($dailyRows->fetchAll(), 'revenue_cents', 'day');

        $dailyRevenue = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dailyRevenue[] = ['date' => $date, 'revenue_cents' => (int) ($dailyByDate[$date] ?? 0)];
        }

        return JsonResponse::ok($response, [
            'orders_today' => (int) $today['orders_count'],
            'revenue_today_cents' => (int) $today['revenue_cents'],
            'orders_week' => (int) $week['orders_count'],
            'revenue_week_cents' => (int) $week['revenue_cents'],
            'daily_revenue' => $dailyRevenue,
            'pending_orders' => $pendingCount,
            'top_items' => $topItems->fetchAll(),
        ]);
    }

    /**
     * GET /driver/orders/available — commandes prêtes, pas encore prises par un livreur.
     *
     * L'adresse exacte du client n'apparaît qu'une fois la course acceptée (voir
     * activeForDriver) : la liste publique des courses se contente du quartier. Sans cela,
     * n'importe quel compte livreur consultait en continu les adresses de tous les clients
     * de la ville sans jamais livrer.
     */
    public function availableForDriver(Request $request, Response $response): Response
    {
        $db = Database::connection();
        $driverId = (int) $request->getAttribute('user_id');

        if (!$this->driverIsVerified($driverId)) {
            return JsonResponse::ok($response, ['orders' => [], 'kyc_required' => true]);
        }

        $stmt = $db->query(
            "SELECT o.id, o.total_cents, o.delivery_fee_cents, o.delivery_mode, o.ready_at, o.adresse_livraison,
                    r.name AS restaurant_name, r.adresse AS restaurant_adresse
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.status = 'ready_for_pickup' AND o.driver_id IS NULL
             ORDER BY o.ready_at ASC
             LIMIT 50"
        );

        $orders = array_map(
            function (array $order): array {
                $order['delivery_area'] = self::coarseArea((string) $order['adresse_livraison']);
                unset($order['adresse_livraison']);

                return $order;
            },
            $stmt->fetchAll()
        );

        return JsonResponse::ok($response, ['orders' => $this->withItemSummaries($db, $orders)]);
    }

    /**
     * Réduit une adresse à sa zone (« Rue des Jardins, Cocody, Abidjan » → « Cocody, Abidjan »).
     * Assez précis pour qu'un livreur décide s'il prend la course, trop vague pour se présenter
     * chez quelqu'un qui n'a rien commandé.
     */
    private static function coarseArea(string $address): string
    {
        $segments = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($s) => $s !== ''));

        if ($segments === []) {
            return 'Zone non précisée';
        }

        return implode(', ', array_slice($segments, -2));
    }

    /** GET /driver/orders/active — la ou les livraisons en cours de ce livreur. */
    public function activeForDriver(Request $request, Response $response): Response
    {
        $driverId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT o.id, o.status, o.total_cents, o.delivery_fee_cents, o.adresse_livraison, o.note_livreur,
                    r.name AS restaurant_name, r.adresse AS restaurant_adresse
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.driver_id = ? AND o.status IN ('ready_for_pickup','picked_up','delivering')
             ORDER BY o.created_at ASC"
        );
        $stmt->execute([$driverId]);

        return JsonResponse::ok($response, ['orders' => $this->withItemSummaries($db, $stmt->fetchAll())]);
    }

    /** Ajoute un résumé texte des plats ("2x Thiéboudienne, 1x Bissap") à chaque commande, en une seule requête. */
    private function withItemSummaries(\PDO $db, array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $ids = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $db->prepare(
            "SELECT oi.order_id, oi.quantity, mi.name
             FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $byOrder = [];
        foreach ($stmt->fetchAll() as $row) {
            $byOrder[$row['order_id']][] = "{$row['quantity']}x {$row['name']}";
        }

        foreach ($orders as &$order) {
            $order['items_summary'] = implode(', ', $byOrder[$order['id']] ?? []);
        }

        return $orders;
    }

    private function findAccessibleOrder(Request $request, int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ', r.owner_id AS restaurant_owner_id, r.name AS restaurant_name
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id WHERE o.id = ?'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order === false) {
            return null;
        }

        $userId = (int) $request->getAttribute('user_id');
        $role = $request->getAttribute('user_role');

        $isParty = $userId === (int) $order['client_id']
            || $userId === (int) $order['restaurant_owner_id']
            || $userId === (int) ($order['driver_id'] ?? 0)
            || $role === 'admin';

        return $isParty ? $order : null;
    }

    private function roleForOrder(Request $request, array $order): string
    {
        // Vérifié avant le match par user_id : un admin n'est jamais partie à la commande par
        // identité (c'est findAccessibleOrder qui lui donne accès via son rôle, pas son id).
        if ($request->getAttribute('user_role') === 'admin') {
            return 'admin';
        }

        $userId = (int) $request->getAttribute('user_id');

        return match ($userId) {
            (int) $order['restaurant_owner_id'] => 'restaurant',
            (int) ($order['driver_id'] ?? 0) => 'driver',
            default => 'client',
        };
    }
}
