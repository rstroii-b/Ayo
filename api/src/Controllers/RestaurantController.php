<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PricingService;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

final class RestaurantController
{
    private const BUSINESS_TYPES = ['food', 'fashion', 'furniture', 'grocery'];

    // Les meubles se livrent sur créneau programmé plutôt qu'en dispatch instantané (voir §5/6
    // du document d'architecture) — les autres catégories tiennent dans un sac à dos de livreur.
    private const SCHEDULED_TYPES = ['furniture'];

    /**
     * Champs publics d'une fiche commerce.
     *
     * `commission_pct` et `owner_id` n'en font pas partie : le taux de commission négocié avec
     * un commerçant est une donnée commerciale interne, elle était renvoyée à tout visiteur de
     * GET /restaurants/{id}. Le propriétaire la retrouve sur sa propre fiche (GET /restaurant/mine).
     */
    private const PUBLIC_COLUMNS = 'r.id, r.name, r.slug, r.adresse, r.lat, r.lng, r.cuisine_origine,
        r.photo_url, r.business_type, r.delivery_mode, r.rating_avg, r.review_count,
        COALESCE(z.currency, "XOF") AS currency';

    /** POST /restaurants — le restaurateur crée sa fiche. */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        try {
            $name = Validator::str($body, 'name', 150);
            $adresse = Validator::str($body, 'adresse', 255);
            $lat = Validator::latitude($body);
            $lng = Validator::longitude($body);
            $businessType = Validator::optionalEnum($body, 'business_type', self::BUSINESS_TYPES, 'food');
            $cuisine = Validator::optionalStr($body, 'cuisine_origine', 100);
            $rccm = Validator::optionalStr($body, 'rccm', 50);
            $zoneId = isset($body['zone_id']) && $body['zone_id'] !== '' && $body['zone_id'] !== null
                ? Validator::id($body['zone_id'], 'zone_id')
                : null;
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $db = Database::connection();

        // La zone porte les frais de livraison et le multiplicateur de pointe : on vérifie
        // qu'elle existe plutôt que de laisser passer une clé étrangère invalide (500) ou un
        // rattachement à une zone choisie au hasard.
        if ($zoneId !== null && !$this->zoneExists($db, $zoneId)) {
            return JsonResponse::error($response, 422, 'Zone de livraison inconnue', 'zone_id');
        }

        $deliveryMode = in_array($businessType, self::SCHEDULED_TYPES, true) ? 'scheduled' : 'instant';

        $stmt = $db->prepare(
            'INSERT INTO restaurants (owner_id, zone_id, name, slug, rccm, adresse, lat, lng, cuisine_origine, business_type, delivery_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $request->getAttribute('user_id'),
            $zoneId,
            $name,
            $this->uniqueSlug($db, $name),
            $rccm,
            $adresse,
            $lat,
            $lng,
            $cuisine,
            $businessType,
            $deliveryMode,
        ]);

        return JsonResponse::ok($response, ['restaurant_id' => (int) $db->lastInsertId()], 201);
    }

    /** PATCH /restaurants/{id} — le restaurateur corrige sa fiche (nom, adresse, position...). */
    public function update(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurantId = (int) $routeArgs['id'];
        $db = Database::connection();

        $stmt = $db->prepare('SELECT owner_id FROM restaurants WHERE id = ?');
        $stmt->execute([$restaurantId]);
        $ownerId = $stmt->fetchColumn();

        if ($ownerId === false || (int) $ownerId !== (int) $request->getAttribute('user_id')) {
            return JsonResponse::error($response, 403, "Ce restaurant ne vous appartient pas");
        }

        $body = (array) $request->getParsedBody();

        try {
            $validators = [
                'name' => fn () => Validator::str($body, 'name', 150),
                'adresse' => fn () => Validator::str($body, 'adresse', 255),
                'lat' => fn () => Validator::latitude($body),
                'lng' => fn () => Validator::longitude($body),
                'cuisine_origine' => fn () => Validator::optionalStr($body, 'cuisine_origine', 100),
                'zone_id' => fn () => Validator::id($body['zone_id'], 'zone_id'),
                // Cette URL finit dans un `background-image` côté front : seul https est accepté.
                'photo_url' => fn () => Validator::optionalHttpsUrl($body, 'photo_url'),
            ];

            $fields = [];
            foreach ($validators as $field => $validate) {
                if (array_key_exists($field, $body)) {
                    $fields[$field] = $validate();
                }
            }
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        if ($fields === []) {
            return JsonResponse::error($response, 422, 'Aucun champ modifiable fourni');
        }

        if (isset($fields['zone_id']) && !$this->zoneExists($db, (int) $fields['zone_id'])) {
            return JsonResponse::error($response, 422, 'Zone de livraison inconnue', 'zone_id');
        }

        $set = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($fields)));
        $args = [...array_values($fields), $restaurantId];

        $db->prepare("UPDATE restaurants SET {$set} WHERE id = ?")->execute($args);

        return JsonResponse::ok($response, ['updated' => true]);
    }

    /** GET /restaurant/mine — le restaurateur retrouve sa propre fiche (back-office). */
    public function mine(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.name, r.slug, r.adresse, r.lat, r.lng, r.cuisine_origine, r.photo_url, r.business_type, r.delivery_mode,
                    r.rating_avg, r.review_count, r.commission_pct, COALESCE(z.currency, "XOF") AS currency
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

        $where = ['r.is_active = 1'];
        $args = [];

        if (!empty($params['business_type']) && in_array($params['business_type'], self::BUSINESS_TYPES, true)) {
            $where[] = 'r.business_type = ?';
            $args[] = $params['business_type'];
        }

        if (!empty($params['region']) && is_string($params['region'])) {
            $where[] = 'r.cuisine_origine = ?';
            $args[] = mb_substr($params['region'], 0, 100);
        }

        if (!empty($params['q']) && is_string($params['q'])) {
            $where[] = 'r.name LIKE ?';
            $args[] = '%' . $this->escapeLike(mb_substr($params['q'], 0, 80)) . '%';
        }

        $orderBy = 'r.name ASC';
        $withDistance = $this->hasCoordinates($params);
        $select = self::PUBLIC_COLUMNS;

        // Tri par distance (formule de Haversine) si la position du client est fournie —
        // sert aussi à estimer frais et délai de livraison (voir withDeliveryEstimate).
        if ($withDistance) {
            $lat = (float) $params['lat'];
            $lng = (float) $params['lng'];

            $select .= ", z.base_fee_cents, z.price_per_km_cents, z.min_fee_cents, z.surge_multiplier,
                (6371 * acos(cos(radians(?)) * cos(radians(r.lat)) *
                cos(radians(r.lng) - radians(?)) + sin(radians(?)) * sin(radians(r.lat)))) AS distance_km";
            $args = array_merge([$lat, $lng, $lat], $args);
            $orderBy = 'distance_km ASC';
        }

        $sql = "SELECT {$select} FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
                WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $restaurants = $stmt->fetchAll();

        if ($withDistance) {
            $restaurants = array_map([$this, 'withDeliveryEstimate'], $restaurants);
        }

        return JsonResponse::ok($response, ['restaurants' => array_map([$this, 'withRating'], $restaurants)]);
    }

    /**
     * Estimation frais + délai de livraison pour la liste.
     *
     * Le calcul n'est plus refait ici : il passe par PricingService, exactement comme la
     * commande réelle. C'est le seul moyen d'éviter la situation précédente, où la liste
     * annonçait un montant que la commande ne confirmait pas.
     */
    private function withDeliveryEstimate(array $restaurant): array
    {
        $distanceKm = (float) $restaurant['distance_km'];

        $restaurant['delivery_fee_cents'] = PricingService::deliveryFeeCents($distanceKm, $restaurant);
        $eta = PricingService::etaMinutes($distanceKm);
        $restaurant['eta_low_min'] = $eta['low'];
        $restaurant['eta_high_min'] = $eta['high'];

        unset(
            $restaurant['base_fee_cents'],
            $restaurant['price_per_km_cents'],
            $restaurant['min_fee_cents'],
            $restaurant['surge_multiplier']
        );

        return $restaurant;
    }

    /**
     * Normalise la note affichée. `review_count = 0` est renvoyé tel quel, sans note inventée :
     * la fiche restaurant affichait « 4,8 ★ · 1 200 avis » pour tous les commerces, y compris
     * ceux n'ayant jamais reçu un seul avis.
     */
    private function withRating(array $restaurant): array
    {
        $reviewCount = (int) ($restaurant['review_count'] ?? 0);

        $restaurant['review_count'] = $reviewCount;
        $restaurant['rating_avg'] = $reviewCount > 0 ? round((float) $restaurant['rating_avg'], 1) : null;

        return $restaurant;
    }

    /** GET /restaurants/{id} */
    public function show(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurant = $this->findRestaurant((int) $routeArgs['id']);

        if ($restaurant === null) {
            return JsonResponse::error($response, 404, 'Restaurant introuvable');
        }

        $params = $request->getQueryParams();

        // Frais et délai réels si le client a partagé sa position : la fiche affiche alors la
        // même estimation que la liste et que le panier, au lieu d'une valeur de repli.
        if ($this->hasCoordinates($params)) {
            $distanceKm = PricingService::haversineKm(
                (float) $params['lat'],
                (float) $params['lng'],
                (float) $restaurant['lat'],
                (float) $restaurant['lng']
            );

            $restaurant['distance_km'] = round($distanceKm, 2);
            $restaurant['delivery_fee_cents'] = PricingService::deliveryFeeCents($distanceKm, $restaurant);
            $eta = PricingService::etaMinutes($distanceKm);
            $restaurant['eta_low_min'] = $eta['low'];
            $restaurant['eta_high_min'] = $eta['high'];
        }

        unset(
            $restaurant['base_fee_cents'],
            $restaurant['price_per_km_cents'],
            $restaurant['min_fee_cents'],
            $restaurant['surge_multiplier']
        );

        return JsonResponse::ok($response, $this->withRating($restaurant));
    }

    /** GET /restaurants/{id}/menu */
    public function menu(Request $request, Response $response, array $routeArgs): Response
    {
        $restaurant = $this->findRestaurant((int) $routeArgs['id']);

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

        return JsonResponse::ok($response, [
            'business_type' => $restaurant['business_type'],
            'categories' => $categories,
        ]);
    }

    private function findRestaurant(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', z.base_fee_cents, z.price_per_km_cents,
                    z.min_fee_cents, z.surge_multiplier
             FROM restaurants r LEFT JOIN delivery_zones z ON z.id = r.zone_id
             WHERE r.id = ? AND r.is_active = 1'
        );
        $stmt->execute([$id]);
        $restaurant = $stmt->fetch();

        return $restaurant === false ? null : $restaurant;
    }

    private function hasCoordinates(array $params): bool
    {
        return isset($params['lat'], $params['lng'])
            && is_numeric($params['lat'])
            && is_numeric($params['lng'])
            && abs((float) $params['lat']) <= 90
            && abs((float) $params['lng']) <= 180;
    }

    private function zoneExists(\PDO $db, int $zoneId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM delivery_zones WHERE id = ?');
        $stmt->execute([$zoneId]);

        return $stmt->fetch() !== false;
    }

    /**
     * `%` et `_` sont des jokers SQL : sans échappement, une recherche « %%% » parcourt
     * l'ensemble de la table et une recherche littérale sur un underscore ne trouve pas ce
     * qu'on cherche. Ce n'est pas une injection (la valeur reste paramétrée), mais un
     * comportement faux et un coût inutile.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Le slug alimente une URL publique : il doit rester unique. Deux commerces homonymes
     * produisaient le même slug et se disputaient l'adresse.
     */
    private function uniqueSlug(\PDO $db, string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-');
        if ($base === '') {
            $base = 'commerce';
        }
        $base = mb_substr($base, 0, 120);

        $stmt = $db->prepare('SELECT 1 FROM restaurants WHERE slug = ?');

        $slug = $base;
        for ($suffix = 2; $suffix < 100; $suffix++) {
            $stmt->execute([$slug]);
            if ($stmt->fetch() === false) {
                return $slug;
            }
            $slug = "{$base}-{$suffix}";
        }

        return $base . '-' . bin2hex(random_bytes(3));
    }
}
