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

        $categoryCheck = Database::connection()->prepare(
            'SELECT id FROM menu_categories WHERE id = ? AND restaurant_id = ?'
        );
        $categoryCheck->execute([$body['category_id'], $routeArgs['id']]);
        if ($categoryCheck->fetch() === false) {
            return JsonResponse::error($response, 422, "Cette catégorie n'appartient pas à ce restaurant");
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO menu_items (restaurant_id, category_id, name, description, ingredients, price_cents, vat_rate, photo_url, allergenes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $routeArgs['id'],
            $body['category_id'],
            $body['name'],
            $body['description'] ?? null,
            $body['ingredients'] ?? null,
            $body['price_cents'],
            $body['vat_rate'] ?? 18.00,
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
        $allowed = ['name', 'description', 'ingredients', 'price_cents', 'vat_rate', 'is_available', 'photo_url', 'allergenes'];
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

    /**
     * POST /restaurants/{id}/menu/items/{itemId}/options — variante d'un article (taille,
     * couleur, niveau de piment...). option_group regroupe plusieurs choix côté client
     * (ex: toutes les "Taille" ensemble), stock_quantity NULL = non suivi.
     */
    public function createOption(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        if (!$this->ownsItem((int) $routeArgs['itemId'], (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 404, 'Article introuvable');
        }

        $body = (array) $request->getParsedBody();
        if (empty($body['name'])) {
            return JsonResponse::error($response, 422, 'Champ manquant', 'name');
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO item_options (menu_item_id, name, option_group, price_delta_cents, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $routeArgs['itemId'],
            $body['name'],
            $body['option_group'] ?? null,
            $body['price_delta_cents'] ?? 0,
            $body['stock_quantity'] ?? null,
        ]);

        return JsonResponse::ok($response, ['option_id' => (int) $db->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id}/menu/items/{itemId}/options/{optionId} */
    public function updateOption(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();
        $allowed = ['name', 'option_group', 'price_delta_cents', 'stock_quantity'];
        $fields = array_intersect_key($body, array_flip($allowed));

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        $set = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($fields)));
        $args = array_values($fields);
        $args[] = $routeArgs['optionId'];
        $args[] = $routeArgs['itemId'];

        $stmt = Database::connection()->prepare(
            "UPDATE item_options io JOIN menu_items mi ON mi.id = io.menu_item_id
             SET {$set} WHERE io.id = ? AND io.menu_item_id = ? AND mi.restaurant_id = " . (int) $routeArgs['id']
        );
        $stmt->execute($args);

        return JsonResponse::ok($response, ['updated' => $stmt->rowCount() > 0]);
    }

    /** DELETE /restaurants/{id}/menu/items/{itemId}/options/{optionId} */
    public function deleteOption(Request $request, Response $response, array $routeArgs): Response
    {
        if (!$this->ownsRestaurant($request, (int) $routeArgs['id'])) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $stmt = Database::connection()->prepare(
            'DELETE io FROM item_options io JOIN menu_items mi ON mi.id = io.menu_item_id
             WHERE io.id = ? AND io.menu_item_id = ? AND mi.restaurant_id = ?'
        );
        $stmt->execute([$routeArgs['optionId'], $routeArgs['itemId'], $routeArgs['id']]);

        return JsonResponse::ok($response, ['deleted' => $stmt->rowCount() > 0]);
    }

    private function ownsItem(int $itemId, int $restaurantId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM menu_items WHERE id = ? AND restaurant_id = ?');
        $stmt->execute([$itemId, $restaurantId]);

        return $stmt->fetch() !== false;
    }

    private function ownsRestaurant(Request $request, int $restaurantId): bool
    {
        $stmt = Database::connection()->prepare('SELECT owner_id FROM restaurants WHERE id = ?');
        $stmt->execute([$restaurantId]);
        $owner = $stmt->fetchColumn();

        return $owner !== false && (int) $owner === (int) $request->getAttribute('user_id');
    }
}
