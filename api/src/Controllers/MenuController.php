<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

/**
 * Gestion du menu par le restaurateur — voir écran "Back-office · Menu".
 *
 * Les bornes ci-dessous ne sont pas décoratives. Un `price_cents` passait tel quel du body
 * JSON à la requête SQL : une valeur négative partait en base (ou faisait échouer l'insertion
 * avec une 500 bavarde selon la colonne), un `vat_rate` à 900 gonflait la TVA de toutes les
 * commandes contenant l'article, et un `price_delta_cents` très négatif sur une variante
 * revenait à s'offrir une remise permanente. Le commerçant est authentifié, pas
 * automatiquement digne de confiance : il a un intérêt financier direct à ces champs.
 */
final class MenuController
{
    /** ~1 000 000 FCFA : au-delà, c'est une faute de frappe, pas un prix. */
    private const MAX_PRICE_CENTS = 100000000;
    private const MAX_PRICE_DELTA_CENTS = 10000000;
    private const MAX_STOCK = 1000000;
    private const MAX_VAT_RATE = 30.0;

    /** POST /restaurants/{id}/menu/categories */
    public function createCategory(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurantId = (int) $routeArgs['id'];

        if (!$this->ownsRestaurant($request, $restaurantId)) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        try {
            $name = Validator::str($body, 'name', 100);
            $sortOrder = Validator::optionalInt($body, 'sort_order', 0, 1000, 0);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$restaurantId, $name, $sortOrder]);

        return JsonResponse::ok($response, ['category_id' => (int) $db->lastInsertId()], 201);
    }

    /** POST /restaurants/{id}/menu/items */
    public function create(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurantId = (int) $routeArgs['id'];

        if (!$this->ownsRestaurant($request, $restaurantId)) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        try {
            $categoryId = Validator::id($body['category_id'] ?? null, 'category_id');
            $fields = [
                'name' => Validator::str($body, 'name', 150),
                'description' => Validator::optionalStr($body, 'description', 500),
                'ingredients' => Validator::optionalStr($body, 'ingredients', 2000),
                'price_cents' => Validator::int($body, 'price_cents', 0, self::MAX_PRICE_CENTS),
                'vat_rate' => Validator::float($body, 'vat_rate', 0.0, self::MAX_VAT_RATE),
                'photo_url' => Validator::optionalHttpsUrl($body, 'photo_url'),
                'allergenes' => Validator::optionalStr($body, 'allergenes', 255),
            ];
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $categoryCheck = Database::connection()->prepare(
            'SELECT id FROM menu_categories WHERE id = ? AND restaurant_id = ?'
        );
        $categoryCheck->execute([$categoryId, $restaurantId]);
        if ($categoryCheck->fetch() === false) {
            return JsonResponse::error($response, 422, "Cette catégorie n'appartient pas à ce restaurant");
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO menu_items (restaurant_id, category_id, name, description, ingredients, price_cents, vat_rate, photo_url, allergenes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $restaurantId,
            $categoryId,
            $fields['name'],
            $fields['description'],
            $fields['ingredients'],
            $fields['price_cents'],
            $fields['vat_rate'],
            $fields['photo_url'],
            $fields['allergenes'],
        ]);

        return JsonResponse::ok($response, ['item_id' => (int) Database::connection()->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id}/menu/items/{itemId} — prix, dispo, description */
    public function update(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurantId = (int) $routeArgs['id'];

        if (!$this->ownsRestaurant($request, $restaurantId)) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        try {
            $fields = $this->validatedSubset($body, [
                'name' => fn () => Validator::str($body, 'name', 150),
                'description' => fn () => Validator::optionalStr($body, 'description', 500),
                'ingredients' => fn () => Validator::optionalStr($body, 'ingredients', 2000),
                'price_cents' => fn () => Validator::int($body, 'price_cents', 0, self::MAX_PRICE_CENTS),
                'vat_rate' => fn () => Validator::float($body, 'vat_rate', 0.0, self::MAX_VAT_RATE),
                'is_available' => fn () => Validator::bool($body, 'is_available') ? 1 : 0,
                'photo_url' => fn () => Validator::optionalHttpsUrl($body, 'photo_url'),
                'allergenes' => fn () => Validator::optionalStr($body, 'allergenes', 255),
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        // Les noms de colonnes viennent de la liste blanche ci-dessus, jamais des clés du body :
        // rien d'arbitraire n'entre dans le SQL, seules les valeurs sont paramétrées.
        $set = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($fields)));
        $args = [...array_values($fields), (int) $routeArgs['itemId'], $restaurantId];

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
        $restaurantId = (int) $routeArgs['id'];
        $itemId = (int) $routeArgs['itemId'];

        if (!$this->ownsRestaurant($request, $restaurantId)) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        if (!$this->ownsItem($itemId, $restaurantId)) {
            return JsonResponse::error($response, 404, 'Article introuvable');
        }

        $body = (array) $request->getParsedBody();

        try {
            $name = Validator::str($body, 'name', 100);
            $group = Validator::optionalStr($body, 'option_group', 50);
            $priceDelta = Validator::optionalInt(
                $body,
                'price_delta_cents',
                -self::MAX_PRICE_DELTA_CENTS,
                self::MAX_PRICE_DELTA_CENTS,
                0
            );
            $stock = isset($body['stock_quantity']) && $body['stock_quantity'] !== null && $body['stock_quantity'] !== ''
                ? Validator::int($body, 'stock_quantity', 0, self::MAX_STOCK)
                : null;
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO item_options (menu_item_id, name, option_group, price_delta_cents, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$itemId, $name, $group, $priceDelta, $stock]);

        return JsonResponse::ok($response, ['option_id' => (int) $db->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id}/menu/items/{itemId}/options/{optionId} */
    public function updateOption(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurantId = (int) $routeArgs['id'];

        if (!$this->ownsRestaurant($request, $restaurantId)) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        try {
            $fields = $this->validatedSubset($body, [
                'name' => fn () => Validator::str($body, 'name', 100),
                'option_group' => fn () => Validator::optionalStr($body, 'option_group', 50),
                'price_delta_cents' => fn () => Validator::int(
                    $body,
                    'price_delta_cents',
                    -self::MAX_PRICE_DELTA_CENTS,
                    self::MAX_PRICE_DELTA_CENTS
                ),
                'stock_quantity' => fn () => $body['stock_quantity'] === null
                    ? null
                    : Validator::int($body, 'stock_quantity', 0, self::MAX_STOCK),
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        $set = implode(', ', array_map(fn ($f) => "io.{$f} = ?", array_keys($fields)));
        $args = [...array_values($fields), (int) $routeArgs['optionId'], (int) $routeArgs['itemId'], $restaurantId];

        // L'identifiant du restaurant est un paramètre lié, plus une concaténation de chaîne :
        // la version précédente reposait uniquement sur un transtypage `(int)` pour rester sûre.
        $stmt = Database::connection()->prepare(
            "UPDATE item_options io JOIN menu_items mi ON mi.id = io.menu_item_id
             SET {$set} WHERE io.id = ? AND io.menu_item_id = ? AND mi.restaurant_id = ?"
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
        $stmt->execute([
            (int) $routeArgs['optionId'],
            (int) $routeArgs['itemId'],
            (int) $routeArgs['id'],
        ]);

        return JsonResponse::ok($response, ['deleted' => $stmt->rowCount() > 0]);
    }

    /**
     * Ne garde que les champs réellement envoyés, chacun passé par son propre validateur.
     * Un PATCH partiel reste possible sans que l'absence d'un champ vaille « mets-le à zéro ».
     *
     * @param array<string, callable():mixed> $validators
     *
     * @return array<string, mixed>
     */
    private function validatedSubset(array $body, array $validators): array
    {
        $fields = [];

        foreach ($validators as $field => $validate) {
            if (array_key_exists($field, $body)) {
                $fields[$field] = $validate();
            }
        }

        return $fields;
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
