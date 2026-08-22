<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

final class RestaurantController
{
    private const BUSINESS_TYPES = ['food', 'fashion', 'furniture', 'grocery'];

    // Les meubles se livrent sur créneau programmé plutôt qu'en dispatch instantané (voir §5/6
    // du document d'architecture) — les autres catégories tiennent dans un sac à dos de livreur.
    private const SCHEDULED_TYPES = ['furniture'];

    /** POST /restaurants — le restaurateur crée sa fiche. */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        foreach (['name', 'adresse', 'lat', 'lng'] as $field) {
            if (empty($body[$field]) && $body[$field] !== '0') {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        $businessType = $body['business_type'] ?? 'food';
        if (!in_array($businessType, self::BUSINESS_TYPES, true)) {
            return JsonResponse::error($response, 422, 'Type de commerce invalide');
        }

        $deliveryMode = in_array($businessType, self::SCHEDULED_TYPES, true) ? 'scheduled' : 'instant';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($body['name'])), '-');

        $stmt = Database::connection()->prepare(
            'INSERT INTO restaurants (owner_id, zone_id, name, slug, rccm, adresse, lat, lng, cuisine_origine, business_type, delivery_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $request->getAttribute('user_id'),
            $body['zone_id'] ?? null,
            $body['name'],
            $slug,
            $body['rccm'] ?? null,
            $body['adresse'],
            $body['lat'],
            $body['lng'],
            $body['cuisine_origine'] ?? null,
            $businessType,
            $deliveryMode,
        ]);

        return JsonResponse::ok($response, ['restaurant_id' => (int) Database::connection()->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id} — le restaurateur corrige sa fiche (nom, adresse, position...). */
    public function update(Request $request, Response $response, array $routeArgs): Response
    {
        $stmt = Database::connection()->prepare('SELECT owner_id FROM restaurants WHERE id = ?');
        $stmt->execute([$routeArgs['id']]);
        $ownerId = $stmt->fetchColumn();

        if ($ownerId === false || (int) $ownerId !== (int) $request->getAttribute('user_id')) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();
        $allowed = ['name', 'adresse', 'lat', 'lng', 'cuisine_origine', 'zone_id', 'photo_url'];
        $fields = array_intersect_key($body, array_flip($allowed));

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        $set = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($fields)));
        $args = array_values($fields);
        $args[] = $routeArgs['id'];

        Database::connection()->prepare("UPDATE restaurants SET {$set} WHERE id = ?")->execute($args);

        return JsonResponse::ok($response, ['updated' => true]);
    }

    /** GET /restaurant/mine — le restaurateur retrouve sa propre fiche (back-office). */
    public function mine(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.name, r.slug, r.adresse, r.lat, r.lng, r.cuisine_origine, r.photo_url, r.business_type, r.delivery_mode,
                    r.commission_pct, COALESCE(z.currency, "XOF") AS currency
             FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
             WHERE r.owner_id = ? ORDER BY r.id LIMIT 1'
        );
        $stmt->execute([$request->getAttribute('user_id')]);
        $restaurant = $stmt->fetch();

        if ($restaurant === false) {
            return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
        }

        return JsonResponse::ok($response, $restaurant);
    }

    /**
     * GET /restaurant/mine/menu — vue de gestion pour le restaurateur : contrairement à
     * GET /restaurants/{id}/menu (public), inclut aussi les plats marqués indisponibles,
     * sinon un plat désactivé deviendrait invisible et impossible à réactiver.
     */
    public function mineMenu(Request $request, Response $response): Response
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM restaurants WHERE owner_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$request->getAttribute('user_id')]);
        $restaurantId = $stmt->fetchColumn();

        if ($restaurantId === false) {
            return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
        }

        $categories = $db->prepare(
            'SELECT id, name, sort_order FROM menu_categories WHERE restaurant_id = ? ORDER BY sort_order'
        );
        $categories->execute([$restaurantId]);
        $categories = $categories->fetchAll();

        $items = $db->prepare(
            'SELECT id, category_id, name, description, ingredients, price_cents, vat_rate, photo_url, is_available, allergenes
             FROM menu_items WHERE restaurant_id = ?'
        );
        $items->execute([$restaurantId]);
        $items = $this->attachOptions($db, $items->fetchAll());

        foreach ($categories as &$category) {
            $category['items'] = array_values(array_filter(
                $items,
                fn ($item) => (int) $item['category_id'] === (int) $category['id']
            ));
        }

        return JsonResponse::ok($response, ['categories' => $categories]);
    }

    /** Ajoute les variantes (item_options) à chaque article, groupées par option_group. */
    private function attachOptions(\PDO $db, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $db->prepare(
            "SELECT id, menu_item_id, name, option_group, price_delta_cents, stock_quantity
             FROM item_options WHERE menu_item_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $byItem = [];
        foreach ($stmt->fetchAll() as $option) {
            $byItem[$option['menu_item_id']][] = $option;
        }

        foreach ($items as &$item) {
            $item['options'] = $byItem[$item['id']] ?? [];
        }

        return $items;
    }

    /** GET /restaurants?lat=&lng=&region=&business_type=&q= */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::connection();

        $where = ['is_active = 1'];
        $args = [];

        if (!empty($params['business_type']) && in_array($params['business_type'], self::BUSINESS_TYPES, true)) {
            $where[] = 'business_type = ?';
            $args[] = $params['business_type'];
        }

        if (!empty($params['region'])) {
            $where[] = 'cuisine_origine = ?';
            $args[] = $params['region'];
        }

        if (!empty($params['q'])) {
            $where[] = 'name LIKE ?';
            $args[] = '%' . $params['q'] . '%';
        }

        $orderBy = 'name ASC';
        $withDistance = !empty($params['lat']) && !empty($params['lng']);

        // Tri par distance (formule de Haversine) si la position du client est fournie —
        // sert aussi à estimer frais et délai de livraison (voir estimateDelivery ci-dessous).
        if ($withDistance) {
            $lat = (float) $params['lat'];
            $lng = (float) $params['lng'];

            $select = "r.id, r.name, r.slug, r.cuisine_origine, r.photo_url, r.business_type, r.delivery_mode, r.lat, r.lng,
                COALESCE(z.currency, 'XOF') AS currency, z.base_fee_cents, z.price_per_km_cents, z.min_fee_cents, z.surge_multiplier,
                (6371 * acos(cos(radians(?)) * cos(radians(r.lat)) *
                cos(radians(r.lng) - radians(?)) + sin(radians(?)) * sin(radians(r.lat)))) AS distance_km";
            $args = array_merge([$lat, $lng, $lat], $args);
            $orderBy = 'distance_km ASC';
        } else {
            $select = "r.id, r.name, r.slug, r.cuisine_origine, r.photo_url, r.business_type, r.delivery_mode, r.lat, r.lng, COALESCE(z.currency, 'XOF') AS currency";
        }

        $sql = "SELECT {$select} FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
                WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $restaurants = $stmt->fetchAll();

        if ($withDistance) {
            $restaurants = array_map([$this, 'withDeliveryEstimate'], $restaurants);
        }

        return JsonResponse::ok($response, ['restaurants' => $restaurants]);
    }

    /**
     * Estimation frais + délai de livraison pour la liste — même formule de frais que la
     * commande réelle (voir OrderController::create). Le délai est une estimation grossière
     * (préparation fixe + trajet à vitesse moyenne), affichée comme fourchette, jamais promise.
     */
    private function withDeliveryEstimate(array $restaurant): array
    {
        $distanceKm = (float) $restaurant['distance_km'];
        // Repli si le restaurant n'a pas de zone_id assigné — mêmes valeurs que le seed Abidjan
        // (voir database/schema.sql), pour rester à l'échelle XOF plutôt qu'un repli EUR-cents.
        $baseFee = (int) ($restaurant['base_fee_cents'] ?? 50000);
        $perKm = (int) ($restaurant['price_per_km_cents'] ?? 15000);
        $minFee = (int) ($restaurant['min_fee_cents'] ?? 100000);
        $surge = (float) ($restaurant['surge_multiplier'] ?? 1.0);

        $restaurant['delivery_fee_cents'] = (int) round(max($minFee, $baseFee + $distanceKm * $perKm) * $surge);

        $prepMin = 15;
        $travelMin = ($distanceKm / 18) * 60; // vitesse moyenne estimée 18 km/h (vélo/scooter urbain)
        $low = max(15, (int) (round(($prepMin + $travelMin - 5) / 5) * 5));
        $restaurant['eta_low_min'] = $low;
        $restaurant['eta_high_min'] = $low + 10;

        unset($restaurant['base_fee_cents'], $restaurant['price_per_km_cents'], $restaurant['min_fee_cents'], $restaurant['surge_multiplier']);

        return $restaurant;
    }

    /** GET /restaurants/{id} */
    public function show(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurant = $this->findRestaurant($routeArgs['id']);

        if ($restaurant === null) {
            return JsonResponse::error($response, 404, 'Restaurant introuvable');
        }

        return JsonResponse::ok($response, $restaurant);
    }

    /** GET /restaurants/{id}/menu */
    public function menu(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurant = $this->findRestaurant($routeArgs['id']);

        if ($restaurant === null) {
            return JsonResponse::error($response, 404, 'Restaurant introuvable');
        }

        $db = Database::connection();

        $categories = $db->prepare(
            'SELECT id, name, sort_order FROM menu_categories WHERE restaurant_id = ? ORDER BY sort_order'
        );
        $categories->execute([$restaurant['id']]);
        $categories = $categories->fetchAll();

        $items = $db->prepare(
            'SELECT id, category_id, name, description, ingredients, price_cents, vat_rate, photo_url, is_available, allergenes
             FROM menu_items WHERE restaurant_id = ? AND is_available = 1'
        );
        $items->execute([$restaurant['id']]);
        $items = $this->attachOptions($db, $items->fetchAll());

        foreach ($categories as &$category) {
            $category['items'] = array_values(array_filter(
                $items,
                fn ($item) => (int) $item['category_id'] === (int) $category['id']
            ));
        }

        return JsonResponse::ok($response, ['categories' => $categories]);
    }

    private function findRestaurant(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.owner_id, r.name, r.slug, r.adresse, r.lat, r.lng, r.cuisine_origine, r.photo_url, r.business_type,
                    r.delivery_mode, r.commission_pct, COALESCE(z.currency, "XOF") AS currency
             FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
             WHERE r.id = ? AND r.is_active = 1'
        );
        $stmt->execute([$id]);
        $restaurant = $stmt->fetch();

        return $restaurant === false ? null : $restaurant;
    }
}
