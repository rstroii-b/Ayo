<?php

declare(strict_types=1);

use Saveurs\Services\PricingService;

/**
 * Le calcul d'une commande est l'endroit où une erreur coûte de l'argent réel — au client si
 * on facture trop, au commerçant si on facture trop peu. Ces cas figent le comportement
 * attendu, y compris les situations que l'ancienne version traitait mal : remise supérieure au
 * panier, total négatif, frais express, distance nulle.
 */

describe('PricingService — frais de livraison');

test('applique le plancher de la zone sur une très courte distance', function (): void {
    // 0 km : base 500 + 0 × 150 = 500, sous le minimum de 1 000 → c'est le minimum qui vaut.
    $fee = PricingService::deliveryFeeCents(0.0, PricingService::FALLBACK_ZONE);

    assertSame(100000, $fee, 'frais plancher');
});

test('facture la distance au-delà du plancher', function (): void {
    // 10 km : 500 + 10 × 150 = 2 000, au-dessus du minimum → c'est le calcul qui vaut.
    $fee = PricingService::deliveryFeeCents(10.0, PricingService::FALLBACK_ZONE);

    assertSame(200000, $fee, 'frais à 10 km');
});

test('applique le multiplicateur de pointe de la zone', function (): void {
    $zone = [...PricingService::FALLBACK_ZONE, 'surge_multiplier' => 1.5];

    assertSame(300000, PricingService::deliveryFeeCents(10.0, $zone));
});

test('majore l\'express de 60 % par rapport au standard', function (): void {
    $standard = PricingService::deliveryFeeCents(10.0, PricingService::FALLBACK_ZONE, 'standard');
    $express = PricingService::deliveryFeeCents(10.0, PricingService::FALLBACK_ZONE, 'express');

    assertSame(320000, $express);
    assertTrue($express > $standard, 'l\'express doit coûter plus cher que le standard');
});

test('retombe sur les valeurs par défaut quand le commerce n\'a pas de zone', function (): void {
    // LEFT JOIN sans zone : toutes les colonnes arrivent à null.
    $zoneVide = [
        'base_fee_cents' => null,
        'price_per_km_cents' => null,
        'min_fee_cents' => null,
        'surge_multiplier' => null,
    ];

    assertSame(
        PricingService::deliveryFeeCents(10.0, PricingService::FALLBACK_ZONE),
        PricingService::deliveryFeeCents(10.0, $zoneVide),
        'un commerce sans zone doit être facturé comme le repli documenté'
    );
});

test('ne facture jamais une distance négative', function (): void {
    assertSame(
        PricingService::deliveryFeeCents(0.0, PricingService::FALLBACK_ZONE),
        PricingService::deliveryFeeCents(-5.0, PricingService::FALLBACK_ZONE)
    );
});

describe('PricingService — distance');

test('mesure une distance connue dans Abidjan', function (): void {
    // Plateau → Cocody, environ 4 km à vol d'oiseau.
    $km = PricingService::haversineKm(5.3200, -4.0200, 5.3500, -3.9900);

    assertTrue($km > 3.5 && $km < 5.5, "distance attendue entre 3,5 et 5,5 km, obtenu {$km}");
});

test('renvoie zéro pour deux points identiques', function (): void {
    assertSame(0.0, round(PricingService::haversineKm(5.36, -4.0083, 5.36, -4.0083), 6));
});

describe('PricingService — TVA par ligne');

test('calcule la TVA au taux de l\'article, pas à un taux global', function (): void {
    $plat = PricingService::lineTotals(100000, 2, 18.0);   // 2 × 1 000 FCFA à 18 %
    $courses = PricingService::lineTotals(100000, 2, 9.0); // même montant à 9 %

    assertSame(200000, $plat['line_total_cents']);
    assertSame(36000, $plat['tva_cents']);
    assertSame(18000, $courses['tva_cents'], 'un taux réduit doit produire une TVA réduite');
});

describe('PricingService — remises');

test('applique une remise en pourcentage', function (): void {
    $coupon = ['discount_type' => 'percentage', 'discount_value' => 10, 'max_discount_cents' => null];

    assertSame(50000, PricingService::discountCents($coupon, 500000));
});

test('applique une remise fixe', function (): void {
    $coupon = ['discount_type' => 'fixed', 'discount_value' => 30000, 'max_discount_cents' => null];

    assertSame(30000, PricingService::discountCents($coupon, 500000));
});

test('respecte le plafond de remise du coupon', function (): void {
    $coupon = ['discount_type' => 'percentage', 'discount_value' => 50, 'max_discount_cents' => 30000];

    // 50 % de 500 000 = 250 000, mais le coupon plafonne à 30 000.
    assertSame(30000, PricingService::discountCents($coupon, 500000));
});

test('ne rembourse jamais au-delà du sous-total', function (): void {
    // Une remise fixe supérieure au panier ne doit pas créer un avoir.
    $coupon = ['discount_type' => 'fixed', 'discount_value' => 900000, 'max_discount_cents' => null];

    assertSame(50000, PricingService::discountCents($coupon, 50000));
});

describe('PricingService — total');

test('additionne articles, livraison et TVA, puis retire la remise', function (): void {
    assertSame(310000, PricingService::totalCents(200000, 100000, 36000, 26000));
});

test('ne descend jamais sous zéro', function (): void {
    // orders.total_cents est un INT UNSIGNED : un total négatif ferait échouer l'insertion.
    assertSame(0, PricingService::totalCents(10000, 0, 0, 999999));
});

test('le récapitulatif expose exactement les champs attendus par le panier', function (): void {
    $summary = PricingService::summary(200000, 100000, 36000, 26000);

    assertSame(
        ['subtotal_cents', 'delivery_fee_cents', 'tva_cents', 'discount_cents', 'total_cents'],
        array_keys($summary)
    );
    assertSame(310000, $summary['total_cents']);
});

describe('PricingService — délais');

test('annonce une fourchette croissante avec la distance', function (): void {
    $proche = PricingService::etaMinutes(1.0);
    $loin = PricingService::etaMinutes(15.0);

    assertTrue($proche['low'] < $loin['low'], 'plus loin doit être annoncé plus long');
    assertSame($proche['low'] + 10, $proche['high'], 'la fourchette fait toujours 10 minutes');
});

test('l\'express est annoncé plus rapide que le standard', function (): void {
    $standard = PricingService::etaMinutes(8.0, 'standard');
    $express = PricingService::etaMinutes(8.0, 'express');

    assertTrue($express['low'] <= $standard['low'], 'l\'express ne peut pas être annoncé plus lent');
});
