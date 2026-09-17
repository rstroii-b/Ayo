<?php

declare(strict_types=1);

use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

/**
 * Ces cas décrivent ce qu'un attaquant envoie réellement : pas des valeurs absurdes mais des
 * valeurs du mauvais *type*. Un `(int)` PHP transforme silencieusement "3abc" en 3 et "abc"
 * en 0 ; un tableau passé là où on attend une chaîne fait exploser PDO en 500 bavarde. C'est
 * exactement ce que le code faisait avant d'avoir un validateur.
 */

describe('Validator — chaînes');

test('accepte une chaîne normale et la nettoie', function (): void {
    assertSame('Maquis du Plateau', Validator::str(['name' => '  Maquis du Plateau  '], 'name', 150));
});

test('refuse un tableau là où une chaîne est attendue', function (): void {
    // `{"name": ["x"]}` : PDO recevait le tableau et levait une erreur interne.
    assertThrows(ValidationException::class, fn () => Validator::str(['name' => ['x']], 'name', 150));
});

test('refuse une chaîne vide ou uniquement composée d\'espaces', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::str(['name' => '   '], 'name', 150));
});

test('refuse un dépassement de longueur au lieu de tronquer en base', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::str(['name' => str_repeat('a', 300)], 'name', 150));
});

test('compte les caractères, pas les octets', function (): void {
    // « é » fait deux octets : un contrôle sur strlen refuserait à tort un nom accentué court.
    assertSame('Crème', Validator::str(['name' => 'Crème'], 'name', 5));
});

describe('Validator — entiers');

test('accepte un entier et une chaîne numérique', function (): void {
    assertSame(3, Validator::int(['quantity' => 3], 'quantity', 1, 30));
    assertSame(3, Validator::int(['quantity' => '3'], 'quantity', 1, 30));
});

test('refuse une chaîne partiellement numérique', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::int(['quantity' => '3abc'], 'quantity', 1, 30));
});

test('refuse une valeur hors bornes', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::int(['quantity' => 0], 'quantity', 1, 30));
    assertThrows(ValidationException::class, fn () => Validator::int(['quantity' => 9999], 'quantity', 1, 30));
});

test('refuse une quantité négative au lieu de la ramener à 1', function (): void {
    // L'ancien code faisait `max(1, (int) $quantity)` : une quantité de -5 devenait 1 sans
    // que personne ne soit prévenu que la commande reçue n'était pas celle envoyée.
    assertThrows(ValidationException::class, fn () => Validator::int(['quantity' => -5], 'quantity', 1, 30));
});

test('refuse un booléen déguisé en entier', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::int(['quantity' => true], 'quantity', 1, 30));
});

describe('Validator — prix et taux');

test('refuse un prix négatif', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::int(['price_cents' => -1], 'price_cents', 0, 100000000));
});

test('accepte un prix nul (article offert)', function (): void {
    assertSame(0, Validator::int(['price_cents' => 0], 'price_cents', 0, 100000000));
});

test('refuse un taux de TVA fantaisiste', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::float(['vat_rate' => 900], 'vat_rate', 0.0, 30.0));
});

test('refuse NAN et INF', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::float(['vat_rate' => NAN], 'vat_rate', 0.0, 30.0));
    assertThrows(ValidationException::class, fn () => Validator::float(['vat_rate' => INF], 'vat_rate', 0.0, 30.0));
});

describe('Validator — coordonnées');

test('accepte une position à Abidjan', function (): void {
    assertSame(5.36, Validator::latitude(['lat' => 5.36]));
    assertSame(-4.0083, Validator::longitude(['lng' => -4.0083]));
});

test('refuse une latitude hors du globe', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::latitude(['lat' => 91]));
});

test('refuse une coordonnée absente plutôt que de la lire comme 0', function (): void {
    // `(float) null` valait 0.0 : la distance était alors calculée depuis le golfe de Guinée,
    // ce qui produisait des frais de livraison énormes sur une adresse mal renseignée.
    assertThrows(ValidationException::class, fn () => Validator::latitude([]));
});

describe('Validator — listes');

test('accepte une liste de lignes de panier', function (): void {
    $items = Validator::objectList(['items' => [['menu_item_id' => 1], ['menu_item_id' => 2]]], 'items', 40);

    assertSame(2, count($items));
});

test('refuse une liste vide', function (): void {
    // `array_fill(0, 0, '?')` produisait `IN ()`, une erreur de syntaxe SQL, donc une 500.
    assertThrows(ValidationException::class, fn () => Validator::objectList(['items' => []], 'items', 40));
});

test('refuse un objet là où une liste est attendue', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::objectList(['items' => ['a' => 1]], 'items', 40));
});

test('refuse une liste trop longue', function (): void {
    $enorme = array_fill(0, 500, ['menu_item_id' => 1]);

    assertThrows(ValidationException::class, fn () => Validator::objectList(['items' => $enorme], 'items', 40));
});

test('déduplique les identifiants de variantes', function (): void {
    // Sélectionner deux fois la même option ne doit pas la facturer deux fois.
    assertSame([7, 9], Validator::idList([7, 9, 7], 'option_ids', 15));
});

test('refuse un identifiant non numérique dans une liste', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::idList([1, 'x'], 'option_ids', 15));
});

test('refuse l\'identifiant zéro', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::id(0, 'restaurant_id'));
});

describe('Validator — URL de photo');

test('accepte une URL https', function (): void {
    assertSame('https://cdn.example.com/p.jpg', Validator::optionalHttpsUrl(['photo_url' => 'https://cdn.example.com/p.jpg'], 'photo_url'));
});

test('refuse javascript: et data:', function (): void {
    // Cette valeur finit dans un `background-image` côté front.
    assertThrows(ValidationException::class, fn () => Validator::optionalHttpsUrl(['photo_url' => 'javascript:alert(1)'], 'photo_url'));
    assertThrows(ValidationException::class, fn () => Validator::optionalHttpsUrl(['photo_url' => 'data:text/html,<script>'], 'photo_url'));
});

test('refuse http en clair', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::optionalHttpsUrl(['photo_url' => 'http://example.com/p.jpg'], 'photo_url'));
});

test('accepte l\'absence de photo', function (): void {
    assertSame(null, Validator::optionalHttpsUrl([], 'photo_url'));
});

describe('Validator — code promo');

test('normalise la casse', function (): void {
    assertSame('AYO10', Validator::optionalCouponCode(['promo_code' => ' ayo10 ']));
});

test('refuse un code contenant des caractères inattendus', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::optionalCouponCode(['promo_code' => "AYO' OR 1=1"]));
});

test('traite un code vide comme une absence de code', function (): void {
    assertSame(null, Validator::optionalCouponCode(['promo_code' => '']));
});

describe('Validator — booléens et énumérations');

test('accepte les formes envoyées par un formulaire HTML', function (): void {
    assertSame(true, Validator::bool(['is_online' => 'true'], 'is_online'));
    assertSame(true, Validator::bool(['is_online' => 1], 'is_online'));
    assertSame(false, Validator::bool(['is_online' => '0'], 'is_online'));
});

test('refuse une valeur non reconnue au lieu de la traiter comme false', function (): void {
    // `$body['is_online'] ? 1 : 0` transformait "non" — chaîne non vide — en `true`.
    assertThrows(ValidationException::class, fn () => Validator::bool(['is_online' => 'peut-être'], 'is_online'));
});

test('refuse une valeur hors énumération', function (): void {
    assertThrows(ValidationException::class, fn () => Validator::enum(['role' => 'admin'], 'role', ['client', 'driver']));
});

test('applique la valeur par défaut d\'une énumération optionnelle', function (): void {
    assertSame('standard', Validator::optionalEnum([], 'delivery_mode', ['standard', 'express'], 'standard'));
});

test('porte le nom du champ fautif dans l\'exception', function (): void {
    try {
        Validator::str([], 'adresse', 255);
    } catch (ValidationException $e) {
        assertSame('adresse', $e->field);

        return;
    }

    throw new RuntimeException('aucune exception levée');
});
