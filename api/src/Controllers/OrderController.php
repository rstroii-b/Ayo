<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PayoutService;
use Saveurs\Services\PushService;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Realtime;

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
    ];

    /** POST /orders — le client passe commande. Les prix sont toujours recalculés côté serveur. */
    public function create(Request $request, Response $response): Response
    {
        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        if ($idempotencyKey === '') {
            return JsonResponse::error($response, 422, "L'en-tête Idempotency-Key est requis");
        }

        $db = Database::connection();

        $existing = $db->prepare('SELECT id, status, total_cents FROM orders WHERE idempotency_key = ?');
        $existing->execute([$idempotencyKey]);
        if (($order = $existing->fetch()) !== false) {
            return JsonResponse::ok($response, $order, 200);
        }

        $body = (array) $request->getParsedBody();
        $clientId = (int) $request->getAttribute('user_id');

        if (empty($body['restaurant_id']) || empty($body['items']) || empty($body['delivery_address'])) {
            return JsonResponse::error($response, 422, 'restaurant_id, items et delivery_address sont requis');
        }

        $restaurantId = (int) $body['restaurant_id'];
        $address = $body['delivery_address'];

        $restaurantStmt = $db->prepare(
            'SELECT r.lat, r.lng, z.base_fee_cents, z.price_per_km_cents, z.min_fee_cents, z.surge_multiplier
             FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
             WHERE r.id = ? AND r.is_active = 1'
        );
        $restaurantStmt->execute([$restaurantId]);
        $restaurant = $restaurantStmt->fetch();

        if ($restaurant === false) {
            return JsonResponse::error($response, 404, 'Restaurant introuvable');
        }

        // Prix figés en base — jamais ceux envoyés par le client.
        $itemIds = array_column($body['items'], 'menu_item_id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $priceStmt = $db->prepare(
            "SELECT id, price_cents, vat_rate FROM menu_items WHERE id IN ({$placeholders}) AND restaurant_id = ? AND is_available = 1"
        );
        $priceStmt->execute([...$itemIds, $restaurantId]);
        $menuItems = [];
        foreach ($priceStmt->fetchAll() as $row) {
            $menuItems[(int) $row['id']] = $row;
        }

        // Variantes (item_options) — taille/couleur pour la mode, niveau de piment pour un plat...
        $optionsStmt = $db->prepare(
            "SELECT id, menu_item_id, name, price_delta_cents, stock_quantity FROM item_options WHERE menu_item_id IN ({$placeholders})"
        );
        $optionsStmt->execute($itemIds);
        $optionsById = [];
        foreach ($optionsStmt->fetchAll() as $option) {
            $optionsById[(int) $option['id']] = $option;
        }

        $subtotalCents = 0;
        $tvaCents = 0;
        $orderItems = [];
        $stockDecrements = []; // option_id => quantité totale à décrémenter

        foreach ($body['items'] as $line) {
            $menuItemId = (int) $line['menu_item_id'];
            if (!isset($menuItems[$menuItemId])) {
                return JsonResponse::error($response, 422, 'Article indisponible', (string) $menuItemId);
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            $unitPriceCents = (int) $menuItems[$menuItemId]['price_cents'];
            $selectedOptions = [];
            foreach ((array) ($line['option_ids'] ?? []) as $optionId) {
                $optionId = (int) $optionId;
                $option = $optionsById[$optionId] ?? null;

                // Sécurité : une option doit appartenir à l'article commandé, jamais un autre.
                if ($option === null || (int) $option['menu_item_id'] !== $menuItemId) {
                    return JsonResponse::error($response, 422, 'Variante invalide', (string) $optionId);
                }

                $unitPriceCents += (int) $option['price_delta_cents'];
                $selectedOptions[] = ['id' => $optionId, 'name' => $option['name'], 'price_delta_cents' => (int) $option['price_delta_cents']];

                if ($option['stock_quantity'] !== null) {
                    $stockDecrements[$optionId] = ($stockDecrements[$optionId] ?? 0) + $quantity;
                    if ($stockDecrements[$optionId] > (int) $option['stock_quantity']) {
                        return JsonResponse::error($response, 422, 'Stock insuffisant', $option['name']);
                    }
                }
            }

            $lineTotal = $unitPriceCents * $quantity;
            $subtotalCents += $lineTotal;
            $tvaCents += (int) round($lineTotal * ((float) $menuItems[$menuItemId]['vat_rate'] / 100));
            $orderItems[] = [$menuItemId, $quantity, $unitPriceCents, json_encode($selectedOptions)];
        }

        $distanceKm = $this->haversineKm(
            (float) $restaurant['lat'],
            (float) $restaurant['lng'],
            (float) $address['lat'],
            (float) $address['lng']
        );

        $baseFee = (int) ($restaurant['base_fee_cents'] ?? 150);
        $perKm = (int) ($restaurant['price_per_km_cents'] ?? 40);
        $minFee = (int) ($restaurant['min_fee_cents'] ?? 190);
        $surge = (float) ($restaurant['surge_multiplier'] ?? 1.0);

        $deliveryFeeCents = (int) round(max($minFee, $baseFee + $distanceKm * $perKm) * $surge);
        // TVA calculée par ligne (chaque article porte son propre taux — voir §7) plutôt qu'un taux
        // fixe restauration : un panier peut mélanger plusieurs taux (ex: supermarché).
        $totalCents = $subtotalCents + $deliveryFeeCents + $tvaCents;

        $db->beginTransaction();

        try {
            $orderStmt = $db->prepare(
                'INSERT INTO orders (client_id, restaurant_id, status, subtotal_cents, delivery_fee_cents,
                    tva_cents, total_cents, idempotency_key, adresse_livraison, lat, lng, note_livreur)
                 VALUES (?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->execute([
                $clientId, $restaurantId, $subtotalCents, $deliveryFeeCents, $tvaCents, $totalCents,
                $idempotencyKey, $address['label'] ?? '', $address['lat'], $address['lng'], $body['note'] ?? null,
            ]);
            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, quantity, price_cents, options_json) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($orderItems as [$menuItemId, $quantity, $priceCents, $optionsJson]) {
                $itemStmt->execute([$orderId, $menuItemId, $quantity, $priceCents, $optionsJson]);
            }

            // Décrément atomique — la clause stock_quantity >= ? empêche une vente en double si
            // deux commandes touchent le même stock limité en même temps (ex: dernière taille M).
            if ($stockDecrements !== []) {
                $stockStmt = $db->prepare(
                    'UPDATE item_options SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?'
                );
                foreach ($stockDecrements as $optionId => $qty) {
                    $stockStmt->execute([$qty, $optionId, $qty]);
                    if ($stockStmt->rowCount() === 0) {
                        $db->rollBack();

                        return JsonResponse::error($response, 409, 'Stock insuffisant pour une variante choisie');
                    }
                }
            }

            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "pending", "client")')
                ->execute([$orderId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Realtime::trigger("private-restaurant.{$restaurantId}", 'new-order', ['order_id' => $orderId]);

        return JsonResponse::ok($response, [
            'order_id' => $orderId,
            'status' => 'pending',
            'subtotal_cents' => $subtotalCents,
            'delivery_fee_cents' => $deliveryFeeCents,
            'tva_cents' => $tvaCents,
            'total_cents' => $totalCents,
        ], 201);
    }

    /** GET /orders/mine — historique des commandes du client connecté. */
    public function mine(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.id, o.status, o.total_cents, o.created_at, r.name AS restaurant_name
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.client_id = ?
             ORDER BY o.created_at DESC'
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

        if ($order['driver_id'] !== null) {
            $driver = Database::connection()->prepare(
                'SELECT u.first_name, u.phone, dp.vehicule_type FROM users u
                 JOIN driver_profiles dp ON dp.user_id = u.id WHERE u.id = ?'
            );
            $driver->execute([$order['driver_id']]);
            $order['driver'] = $driver->fetch() ?: null;
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
        $nextStatus = $body['status'] ?? '';
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

        $db = Database::connection();
        $timestampColumn = [
            'accepted' => 'accepted_at',
            'ready_for_pickup' => 'ready_at',
            'picked_up' => 'picked_up_at',
            'delivered' => 'delivered_at',
        ][$nextStatus] ?? null;

        $sql = 'UPDATE orders SET status = ?' . ($timestampColumn ? ", {$timestampColumn} = NOW()" : '') . ' WHERE id = ?';
        $db->prepare($sql)->execute([$nextStatus, $order['id']]);

        $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, ?, ?)')
            ->execute([$order['id'], $nextStatus, $role]);

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
             WHERE dp.is_online = 1 AND dl.updated_at >= (NOW() - INTERVAL 10 MINUTE)
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

        $stmt = $db->prepare(
            "UPDATE orders SET driver_id = ? WHERE id = ? AND status = 'ready_for_pickup' AND driver_id IS NULL"
        );
        $stmt->execute([$driverId, $routeArgs['id']]);

        if ($stmt->rowCount() === 0) {
            return JsonResponse::error($response, 409, 'Commande déjà prise ou plus disponible');
        }

        $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "driver_assigned", "driver")')
            ->execute([$routeArgs['id']]);

        $restaurantId = $db->prepare('SELECT restaurant_id FROM orders WHERE id = ?');
        $restaurantId->execute([$routeArgs['id']]);
        $restaurantId = $restaurantId->fetchColumn();

        Realtime::trigger("private-order.{$routeArgs['id']}", 'driver-assigned', ['driver_id' => $driverId]);
        Realtime::trigger("private-restaurant.{$restaurantId}", 'order-updated', [
            'order_id' => (int) $routeArgs['id'],
            'status' => 'ready_for_pickup',
        ]);

        return JsonResponse::ok($response, ['order_id' => (int) $routeArgs['id'], 'driver_id' => $driverId]);
    }

    /** GET /restaurant/orders/live — file d'attente temps réel du back-office */
    public function liveForRestaurant(Request $request, Response $response): Response
    {
        $ownerId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT o.id, o.status, o.total_cents, o.note_livreur, o.created_at, u.first_name AS client_first_name
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

    /** GET /driver/orders/available — commandes prêtes, pas encore prises par un livreur. */
    public function availableForDriver(Request $request, Response $response): Response
    {
        $db = Database::connection();

        $stmt = $db->query(
            "SELECT o.id, o.total_cents, o.delivery_fee_cents, o.adresse_livraison, o.ready_at,
                    r.name AS restaurant_name, r.adresse AS restaurant_adresse
             FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.status = 'ready_for_pickup' AND o.driver_id IS NULL
             ORDER BY o.ready_at ASC"
        );

        return JsonResponse::ok($response, ['orders' => $this->withItemSummaries($db, $stmt->fetchAll())]);
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
            'SELECT o.*, r.owner_id AS restaurant_owner_id, r.name AS restaurant_name
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
        $userId = (int) $request->getAttribute('user_id');

        return match ($userId) {
            (int) $order['restaurant_owner_id'] => 'restaurant',
            (int) ($order['driver_id'] ?? 0) => 'driver',
            default => 'client',
        };
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
