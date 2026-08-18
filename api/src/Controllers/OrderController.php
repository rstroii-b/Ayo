<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PayoutService;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

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
            "SELECT id, price_cents FROM menu_items WHERE id IN ({$placeholders}) AND restaurant_id = ? AND is_available = 1"
        );
        $priceStmt->execute([...$itemIds, $restaurantId]);
        $prices = array_column($priceStmt->fetchAll(), 'price_cents', 'id');

        $subtotalCents = 0;
        $orderItems = [];

        foreach ($body['items'] as $line) {
            $menuItemId = (int) $line['menu_item_id'];
            if (!isset($prices[$menuItemId])) {
                return JsonResponse::error($response, 422, 'Plat indisponible', (string) $menuItemId);
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $lineTotal = $prices[$menuItemId] * $quantity;
            $subtotalCents += $lineTotal;
            $orderItems[] = [$menuItemId, $quantity, $prices[$menuItemId], json_encode($line['option_ids'] ?? [])];
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
        $tvaCents = (int) round($subtotalCents * 0.10); // TVA restauration 10% — voir §7, à affiner selon mandataire/commissionnaire
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

            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "pending", "client")')
                ->execute([$orderId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return JsonResponse::ok($response, [
            'order_id' => $orderId,
            'status' => 'pending',
            'subtotal_cents' => $subtotalCents,
            'delivery_fee_cents' => $deliveryFeeCents,
            'tva_cents' => $tvaCents,
            'total_cents' => $totalCents,
        ], 201);
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
                'SELECT u.first_name, dp.vehicule_type FROM users u
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

        // Ici, le PHP publierait l'événement sur le service temps réel (Soketi) — voir §4.

        if ($nextStatus === 'delivered') {
            (new PayoutService())->releaseForOrder($order['id']);
        }

        return JsonResponse::ok($response, ['order_id' => $order['id'], 'status' => $nextStatus]);
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
            'SELECT o.*, r.owner_id AS restaurant_owner_id
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
