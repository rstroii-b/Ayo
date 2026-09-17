<?php

declare(strict_types=1);

namespace Saveurs\Support;

/**
 * Validation des entrées côté serveur.
 *
 * Règle du projet : le front n'est jamais une source de confiance. Tout ce qui arrive dans un
 * body JSON ou une query string passe par ici avant d'atteindre une requête SQL ou un calcul
 * de prix. Le point important n'est pas seulement de refuser ce qui est absurde (quantité
 * négative, prix négatif) mais de refuser ce qui n'est pas du bon *type* : un `{"quantity":
 * {"$gt": 0}}` ou un `{"name": ["x"]}` envoyé à PDO produit sinon une erreur 500 bavarde au
 * lieu d'un 422 propre.
 *
 * Chaque méthode lève ValidationException (→ 422) ou renvoie une valeur du type PHP attendu.
 * Classe volontairement sans dépendance (pas de PDO, pas de PSR-7) : elle est testable seule,
 * voir api/tests/ValidatorTest.php.
 */
final class Validator
{
    /** Bornes géographiques valides — au-delà, la valeur ne vient pas d'un GPS. */
    private const LAT_MIN = -90.0;
    private const LAT_MAX = 90.0;
    private const LNG_MIN = -180.0;
    private const LNG_MAX = 180.0;

    /**
     * Chaîne non vide, bornée en longueur (en caractères, pas en octets : un nom ivoirien
     * accentué ne doit pas être refusé pour cause de comptage UTF-8).
     */
    public static function str(array $body, string $field, int $max, int $min = 1): string
    {
        $value = $body[$field] ?? null;

        if (!is_string($value)) {
            throw new ValidationException($field, "Le champ « {$field} » est requis.");
        }

        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $min) {
            throw new ValidationException($field, "Le champ « {$field} » est requis.");
        }

        if ($length > $max) {
            throw new ValidationException($field, "Le champ « {$field} » dépasse {$max} caractères.");
        }

        return $value;
    }

    /** Idem, mais renvoie null si le champ est absent ou vide plutôt que de lever. */
    public static function optionalStr(array $body, string $field, int $max): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return self::str($body, $field, $max);
    }

    /**
     * Entier borné. Refuse les chaînes non numériques et les flottants : un `(int)` PHP
     * transforme silencieusement "12abc" en 12 et "abc" en 0, ce qui a déjà suffi à faire
     * passer une quantité fantaisiste pour une quantité valide.
     */
    public static function int(array $body, string $field, int $min, int $max): int
    {
        $value = $body[$field] ?? null;

        if (is_bool($value) || !is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value)) {
            throw new ValidationException($field, "Le champ « {$field} » doit être un nombre entier.");
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            throw new ValidationException($field, "Le champ « {$field} » doit être compris entre {$min} et {$max}.");
        }

        return $value;
    }

    /** Entier borné, avec repli si le champ est absent. */
    public static function optionalInt(array $body, string $field, int $min, int $max, int $default): int
    {
        if (!isset($body[$field]) || $body[$field] === '') {
            return $default;
        }

        return self::int($body, $field, $min, $max);
    }

    /** Décimal borné (taux de TVA, coordonnées...). */
    public static function float(array $body, string $field, float $min, float $max): float
    {
        $value = $body[$field] ?? null;

        if (is_bool($value) || !is_scalar($value) || !is_numeric($value)) {
            throw new ValidationException($field, "Le champ « {$field} » doit être un nombre.");
        }

        $value = (float) $value;

        if (!is_finite($value) || $value < $min || $value > $max) {
            throw new ValidationException($field, "Le champ « {$field} » doit être compris entre {$min} et {$max}.");
        }

        return $value;
    }

    public static function latitude(array $body, string $field = 'lat'): float
    {
        return self::float($body, $field, self::LAT_MIN, self::LAT_MAX);
    }

    public static function longitude(array $body, string $field = 'lng'): float
    {
        return self::float($body, $field, self::LNG_MIN, self::LNG_MAX);
    }

    /** Valeur parmi une liste fermée (statut, rôle, opérateur mobile money...). */
    public static function enum(array $body, string $field, array $allowed): string
    {
        $value = $body[$field] ?? null;

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationException(
                $field,
                "Le champ « {$field} » doit valoir : " . implode(', ', $allowed) . '.'
            );
        }

        return $value;
    }

    public static function optionalEnum(array $body, string $field, array $allowed, string $default): string
    {
        if (!isset($body[$field]) || $body[$field] === '' || $body[$field] === null) {
            return $default;
        }

        return self::enum($body, $field, $allowed);
    }

    /**
     * Booléen tolérant aux formes envoyées par un formulaire HTML ("1", "true", "on") mais
     * jamais silencieux : une valeur non reconnue est une erreur, pas un `false` implicite.
     */
    public static function bool(array $body, string $field): bool
    {
        $value = $body[$field] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if (in_array($normalised, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($normalised, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        throw new ValidationException($field, "Le champ « {$field} » doit être vrai ou faux.");
    }

    /**
     * Liste non vide et bornée de sous-tableaux (lignes de panier, par exemple). Le plafond
     * n'est pas cosmétique : sans lui, un `items` de 100 000 entrées fait exploser la requête
     * `IN (...)` construite plus loin et transforme un POST en déni de service.
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $body, string $field, int $maxItems): array
    {
        $value = $body[$field] ?? null;

        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new ValidationException($field, "Le champ « {$field} » doit être une liste non vide.");
        }

        if (count($value) > $maxItems) {
            throw new ValidationException($field, "Le champ « {$field} » est limité à {$maxItems} entrées.");
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new ValidationException($field, "Le champ « {$field} » contient une entrée invalide.");
            }
        }

        return array_values($value);
    }

    /**
     * Liste d'identifiants entiers positifs (option_ids...). Déduplique : sélectionner deux
     * fois la même variante ne doit pas la facturer deux fois.
     *
     * @return list<int>
     */
    public static function idList(mixed $value, string $field, int $maxItems): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new ValidationException($field, "Le champ « {$field} » doit être une liste d'identifiants.");
        }

        if (count($value) > $maxItems) {
            throw new ValidationException($field, "Le champ « {$field} » est limité à {$maxItems} entrées.");
        }

        $ids = [];
        foreach ($value as $raw) {
            if (is_bool($raw) || !is_scalar($raw) || !preg_match('/^\d+$/', (string) $raw)) {
                throw new ValidationException($field, "Le champ « {$field} » contient un identifiant invalide.");
            }

            $id = (int) $raw;
            if ($id < 1) {
                throw new ValidationException($field, "Le champ « {$field} » contient un identifiant invalide.");
            }

            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /** Identifiant entier positif issu d'un segment d'URL ou d'un body. */
    public static function id(mixed $value, string $field): int
    {
        if (is_bool($value) || !is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            throw new ValidationException($field, "L'identifiant « {$field} » est invalide.");
        }

        $id = (int) $value;
        if ($id < 1) {
            throw new ValidationException($field, "L'identifiant « {$field} » est invalide.");
        }

        return $id;
    }

    /**
     * URL d'image acceptée en base. Seul https:// est autorisé (les photos publiées par les
     * commerçants finissent dans un `background-image` côté front) : `javascript:`, `data:`
     * et consorts n'ont rien à faire là, et le front applique de son côté le même filtre
     * (voir web/js/format.js::safeImageUrl) — deux barrières valent mieux qu'une.
     */
    public static function optionalHttpsUrl(array $body, string $field, int $max = 255): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new ValidationException($field, "Le champ « {$field} » est invalide.");
        }

        $value = trim($value);

        if (!str_starts_with(strtolower($value), 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException($field, "Le champ « {$field} » doit être une URL https.");
        }

        return $value;
    }

    /**
     * Code promo : alphanumérique, tiret et souligné, normalisé en majuscules pour que
     * "ayo10", "Ayo10" et "AYO10" désignent le même coupon en base.
     */
    public static function optionalCouponCode(array $body, string $field = 'promo_code'): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException($field, 'Code promo invalide.');
        }

        $value = strtoupper(trim($value));

        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $value)) {
            throw new ValidationException($field, 'Code promo invalide.');
        }

        return $value;
    }

    /** Numéro de téléphone mobile money / contact — chiffres, espaces, +, tirets. */
    public static function optionalPhone(array $body, string $field = 'phone'): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException($field, 'Numéro de téléphone invalide.');
        }

        $value = trim($value);

        if (!preg_match('/^\+?[0-9 ().-]{6,25}$/', $value)) {
            throw new ValidationException($field, 'Numéro de téléphone invalide.');
        }

        return $value;
    }
}
