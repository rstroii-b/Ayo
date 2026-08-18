<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

final class RestaurantController
{
    /** POST /restaurants — le restaurateur crée sa fiche (préalable à l'onboarding Stripe). */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        foreach (['name', 'siret', 'adresse', 'lat', 'lng'] as $field) {
            if (empty($body[$field]) && $body[$field] !== '0') {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($body['name'])), '-');

        $stmt = Database::connection()->prepare(
            'INSERT INTO restaurants (owner_id, zone_id, name, slug, siret, adresse, lat, lng, cuisine_origine)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $request->getAttribute('user_id'),
            $body['zone_id'] ?? null,
            $body['name'],
            $slug,
            $body['siret'],
            $body['adresse'],
            $body['lat'],
            $body['lng'],
            $body['cuisine_origine'] ?? null,
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
        $allowed = ['name', 'adresse', 'lat', 'lng', 'cuisine_origine', 'zone_id'];
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
            'SELECT id, name, slug, adresse, cuisine_origine, stripe_account_id, commission_pct
             FROM restaurants WHERE owner_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$request->getAttribute('user_id')]);
        $restaurant = $stmt->fetch();

        if ($restaurant === false) {
            return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
        }

        $restaurant['stripe_connected'] = $restaurant['stripe_account_id'] !== null;
        unset($restaurant['stripe_account_id']);

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
            'SELECT id, category_id, name, description, price_cents, photo_url, is_available, allergenes
             FROM menu_items WHERE restaurant_id = ?'
        );
        $items->execute([$restaurantId]);
        $items = $items->fetchAll();

        foreach ($categories as &$category) {
            $category['items'] = array_values(array_filter(
                $items,
                fn ($item) => (int) $item['category_id'] === (int) $category['id']
            ));
        }

        return JsonResponse::ok($response, ['categories' => $categories]);
    }

    /** GET /restaurants?lat=&lng=&region=&q= */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::connection();

        $where = ['is_active = 1'];
        $args = [];

        if (!empty($params['region'])) {
            $where[] = 'cuisine_origine = ?';
            $args[] = $params['region'];
        }

        if (!empty($params['q'])) {
            $where[] = 'name LIKE ?';
            $args[] = '%' . $params['q'] . '%';
        }

        $orderBy = 'name ASC';

        // Tri par distance (formule de Haversine) si la position du client est fournie.
        if (!empty($params['lat']) && !empty($params['lng'])) {
            $lat = (float) $params['lat'];
            $lng = (float) $params['lng'];

            $select = "id, name, slug, cuisine_origine, lat, lng,
                (6371 * acos(cos(radians(?)) * cos(radians(lat)) *
                cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distance_km";
            $args = array_merge([$lat, $lng, $lat], $args);
            $orderBy = 'distance_km ASC';
        } else {
            $select = 'id, name, slug, cuisine_origine, lat, lng';
        }

        $sql = "SELECT {$select} FROM restaurants WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($args);

        return JsonResponse::ok($response, ['restaurants' => $stmt->fetchAll()]);
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
            'SELECT id, category_id, name, description, price_cents, photo_url, is_available, allergenes
             FROM menu_items WHERE restaurant_id = ? AND is_available = 1'
        );
        $items->execute([$restaurant['id']]);
        $items = $items->fetchAll();

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
            'SELECT id, owner_id, name, slug, adresse, lat, lng, cuisine_origine, commission_pct
             FROM restaurants WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$id]);
        $restaurant = $stmt->fetch();

        return $restaurant === false ? null : $restaurant;
    }
}
