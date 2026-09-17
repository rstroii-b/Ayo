<?php

declare(strict_types=1);

namespace Saveurs\Services;

/**
 * Source de vérité unique du calcul d'une commande.
 *
 * Avant cette classe, trois endroits calculaient un prix : OrderController (à la création),
 * RestaurantController (l'estimation affichée dans la liste) et surtout web/js/pages/panier.js,
 * qui appliquait ses *propres* frais de livraison (1 500) et ses *propres* codes promo écrits en
 * dur dans le JavaScript. Le client voyait donc un total que le serveur n'a jamais calculé, et
 * les « réductions » n'étaient appliquées nulle part en base : l'écran promettait -10 %, la
 * facture CinetPay était au prix plein.
 *
 * Désormais : un seul calcul, ici, appelé aussi bien par l'aperçu (POST /orders/quote) que par
 * la création réelle (POST /orders). Le front n'additionne plus rien — il affiche ce que le
 * serveur renvoie.
 *
 * Convention monétaire du projet : tout est en « centièmes » (voir database/schema.sql), y
 * compris en XOF qui n'a pas de sous-unité réelle ; seul l'affichage divise par 100.
 *
 * Les méthodes de calcul sont pures (aucun accès base) pour être testables directement —
 * voir api/tests/PricingServiceTest.php.
 */
final class PricingService
{
    public const DELIVERY_MODES = ['standard', 'express'];

    /**
     * Repli si le restaurant n'a pas de zone assignée — mêmes valeurs que le seed Abidjan
     * (database/schema.sql), pour rester à l'échelle XOF plutôt qu'un repli EUR-cents.
     */
    public const FALLBACK_ZONE = [
        'base_fee_cents' => 50000,
        'price_per_km_cents' => 15000,
        'min_fee_cents' => 100000,
        'surge_multiplier' => 1.0,
    ];

    /**
     * L'express est prioritaire sur la file de dispatch : il coûte 60 % de plus que le
     * standard. Le supplément porte sur les frais de livraison seuls, jamais sur les plats —
     * le restaurateur est payé pareil quel que soit le mode choisi par le client.
     */
    private const EXPRESS_SURCHARGE = 0.60;

    /** Garde-fous : au-delà, ce n'est plus une commande de quartier mais une erreur de saisie. */
    public const MAX_ITEMS_PER_ORDER = 40;
    public const MAX_QUANTITY_PER_LINE = 30;
    public const MAX_OPTIONS_PER_LINE = 15;
    public const MAX_DELIVERY_DISTANCE_KM = 40.0;

    /**
     * Distance à vol d'oiseau entre deux points (formule de Haversine).
     * Utilisée pour les frais et l'estimation de délai — jamais présentée comme un trajet réel.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Frais de livraison pour une distance et une zone données.
     *
     * @param array<string, mixed> $zone Ligne delivery_zones (valeurs nulles tolérées → repli)
     */
    public static function deliveryFeeCents(float $distanceKm, array $zone, string $deliveryMode = 'standard'): int
    {
        $baseFee = (int) ($zone['base_fee_cents'] ?? self::FALLBACK_ZONE['base_fee_cents']);
        $perKm = (int) ($zone['price_per_km_cents'] ?? self::FALLBACK_ZONE['price_per_km_cents']);
        $minFee = (int) ($zone['min_fee_cents'] ?? self::FALLBACK_ZONE['min_fee_cents']);
        $surge = (float) ($zone['surge_multiplier'] ?? self::FALLBACK_ZONE['surge_multiplier']);

        $distanceKm = max(0.0, $distanceKm);
        $fee = max($minFee, $baseFee + $distanceKm * $perKm) * $surge;

        if ($deliveryMode === 'express') {
            $fee *= 1 + self::EXPRESS_SURCHARGE;
        }

        return (int) round($fee);
    }

    /**
     * Fourchette de délai affichée. Volontairement large et arrondie à 5 min : c'est une
     * estimation (préparation forfaitaire + trajet à vitesse urbaine moyenne), jamais un
     * engagement contractuel — voir CGV.
     *
     * @return array{low:int, high:int}
     */
    public static function etaMinutes(float $distanceKm, string $deliveryMode = 'standard'): array
    {
        $prepMin = $deliveryMode === 'express' ? 10 : 15;
        $speedKmh = $deliveryMode === 'express' ? 22 : 18;
        $travelMin = (max(0.0, $distanceKm) / $speedKmh) * 60;

        $low = max(10, (int) (round(($prepMin + $travelMin - 5) / 5) * 5));

        return ['low' => $low, 'high' => $low + 10];
    }

    /**
     * Total d'une ligne de commande et sa TVA.
     *
     * La TVA est calculée ligne à ligne, chaque article portant son propre taux : un panier de
     * supermarché peut mélanger des taux différents, un taux « restauration » unique fausserait
     * la facture.
     *
     * @return array{line_total_cents:int, tva_cents:int}
     */
    public static function lineTotals(int $unitPriceCents, int $quantity, float $vatRate): array
    {
        $lineTotal = $unitPriceCents * $quantity;

        return [
            'line_total_cents' => $lineTotal,
            'tva_cents' => (int) round($lineTotal * ($vatRate / 100)),
        ];
    }

    /**
     * Réduction accordée par un coupon, bornée par son plafond et par le sous-total.
     * Une remise ne rend jamais d'argent : elle ne peut pas dépasser le montant des articles.
     *
     * @param array<string, mixed> $coupon Ligne de la table coupons
     */
    public static function discountCents(array $coupon, int $subtotalCents): int
    {
        $value = (int) $coupon['discount_value'];

        $discount = ($coupon['discount_type'] ?? 'percentage') === 'fixed'
            ? $value
            : (int) round($subtotalCents * ($value / 100));

        $maxDiscount = $coupon['max_discount_cents'] ?? null;
        if ($maxDiscount !== null) {
            $discount = min($discount, (int) $maxDiscount);
        }

        return max(0, min($discount, $subtotalCents));
    }

    /**
     * Total dû par le client. La remise s'applique après la TVA et ne peut pas rendre le
     * total négatif : `orders.total_cents` est un INT UNSIGNED, un total négatif ferait
     * échouer l'insertion — et surtout n'aurait aucun sens comptable.
     */
    public static function totalCents(int $subtotalCents, int $deliveryFeeCents, int $tvaCents, int $discountCents): int
    {
        return max(0, $subtotalCents + $deliveryFeeCents + $tvaCents - $discountCents);
    }

    /**
     * Assemble le récapitulatif complet renvoyé au front (aperçu comme commande réelle).
     * C'est exactement cette structure que le panier affiche — il n'en recalcule aucun champ.
     *
     * @return array{subtotal_cents:int, delivery_fee_cents:int, tva_cents:int, discount_cents:int, total_cents:int}
     */
    public static function summary(
        int $subtotalCents,
        int $deliveryFeeCents,
        int $tvaCents,
        int $discountCents
    ): array {
        return [
            'subtotal_cents' => $subtotalCents,
            'delivery_fee_cents' => $deliveryFeeCents,
            'tva_cents' => $tvaCents,
            'discount_cents' => $discountCents,
            'total_cents' => self::totalCents($subtotalCents, $deliveryFeeCents, $tvaCents, $discountCents),
        ];
    }
}
