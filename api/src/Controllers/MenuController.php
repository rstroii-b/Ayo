<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

/** Gestion du menu par le restaurateur — voir écran "Back-office · Menu". */
final class MenuController
{
    /** POST /restaurants/{id}/menu/categories */
    public function createCategory(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        if (empty($body['name'])) {
            return JsonResponse::error($response, 422, 'Champ manquant', 'name');
        }

        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$routeArgs['id'], $body['name'], $body['sort_order'] ?? 0]);

        return JsonResponse::ok($response, ['category_id' => (int) $db->lastInsertId()], 201);
    }

    /** POST /restaurants/{id}/menu/items */
    public function create(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        foreach (['category_id', 'name', 'price_cents'] as $field) {
            if (!isset($body[$field]) || $body[$field] === '') {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO menu_items (restaurant_id, category_id, name, description, price_cents, photo_url, allergenes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $routeArgs['id'],
            $body['category_id'],
            $body['name'],
            $body['description'] ?? null,
            $body['price_cents'],
            $body['photo_url'] ?? null,
            $body['allergenes'] ?? null,
        ]);

        return JsonResponse::ok($response, ['item_id' => (int) Database::connection()->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id}/menu/items/{itemId} — prix, dispo, description */
    public function update(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();
        $allowed = ['name', 'description', 'price_cents', 'is_available', 'photo_url', 'allergenes'];
        $fields = array_intersect_key($body, array_flip($allowed));

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        $set = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($fields)));
        $args = array_values($fields);
        $args[] = $routeArgs['itemId'];
        $args[] = $routeArgs['id'];

        $stmt = Database::connection()->prepare(
            "UPDATE menu_items SET {$set} WHERE id = ? AND restaurant_id = ?"
        );
        $stmt->execute($args);

        return JsonResponse::ok($response, ['updated' => $stmt->rowCount() > 0]);
    }

    private function ownsRestaurant(Request $request, int $restaurantId): bool
    {
        $stmt = Database::connection()->prepare('SELECT owner_id FROM restaurants WHERE id = ?');
        $stmt->execute([$restaurantId]);
        $owner = $stmt->fetchColumn();

        return $owner !== false && (int) $owner === (int) $request->getAttribute('user_id');
    }
}
