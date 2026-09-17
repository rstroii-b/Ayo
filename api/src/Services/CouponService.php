<?php

declare(strict_types=1);

namespace Saveurs\Services;

use Saveurs\Support\Database;

/**
 * Validation des codes promo — côté serveur, et uniquement côté serveur.
 *
 * La table `coupons` existait déjà (migration 001) mais n'était lue par personne : le front
 * portait sa propre liste de codes en dur (`PROMO_CODES` dans panier.js), ce qui revenait à
 * laisser le navigateur décider d'une remise. Un simple `PROMO_CODES.MOI = 0.99` dans la
 * console suffisait à afficher -99 %… sans que la commande facturée en tienne compte, d'où un
 * écart entre l'écran et le débit réel.
 *
 * Ici le code promo n'est plus qu'une chaîne envoyée au serveur : lui seul décide s'il existe,
 * s'il est encore valable, et de combien.
 */
final class CouponService
{
    /** Refus explicites — le front affiche un message différent selon la raison. */
    public const REFUS_MESSAGES = [
        'unknown' => 'Ce code promo n\'existe pas.',
        'inactive' => 'Ce code promo n\'est plus actif.',
        'not_started' => 'Ce code promo n\'est pas encore valable.',
        'expired' => 'Ce code promo a expiré.',
        'exhausted' => 'Ce code promo a atteint sa limite d\'utilisation.',
        'already_used' => 'Tu as déjà utilisé ce code promo.',
        'min_order' => 'Ce code promo demande un panier minimum.',
    ];

    /**
     * Évalue un code pour un client et un sous-total donnés.
     *
     * @return array{status:string, message:string, discount_cents:int, coupon:?array<string,mixed>}
     */
    public function evaluate(?string $code, int $clientId, int $subtotalCents): array
    {
        if ($code === null) {
            return $this->result('none', '', 0, null);
        }

        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT id, code, discount_type, discount_value, max_discount_cents, min_order_cents,
                    usage_limit, usage_count, starts_at, expires_at, is_active
             FROM coupons WHERE code = ?'
        );
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();

        if ($coupon === false) {
            return $this->result('unknown', self::REFUS_MESSAGES['unknown'], 0, null);
        }

        if ((int) $coupon['is_active'] !== 1) {
            return $this->result('inactive', self::REFUS_MESSAGES['inactive'], 0, null);
        }

        $now = time();

        if ($coupon['starts_at'] !== null && strtotime((string) $coupon['starts_at']) > $now) {
            return $this->result('not_started', self::REFUS_MESSAGES['not_started'], 0, null);
        }

        if ($coupon['expires_at'] !== null && strtotime((string) $coupon['expires_at']) < $now) {
            return $this->result('expired', self::REFUS_MESSAGES['expired'], 0, null);
        }

        if ($coupon['usage_limit'] !== null && (int) $coupon['usage_count'] >= (int) $coupon['usage_limit']) {
            return $this->result('exhausted', self::REFUS_MESSAGES['exhausted'], 0, null);
        }

        // Un coupon = une fois par compte. Sans cette règle, un code en pourcentage se
        // réutilise à chaque commande : c'est une remise permanente déguisée, jamais ce
        // qu'un commerçant a en tête en créant une promo de lancement.
        $used = $db->prepare('SELECT 1 FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ? LIMIT 1');
        $used->execute([$coupon['id'], $clientId]);
        if ($used->fetch() !== false) {
            return $this->result('already_used', self::REFUS_MESSAGES['already_used'], 0, null);
        }

        $minOrder = (int) $coupon['min_order_cents'];
        if ($subtotalCents < $minOrder) {
            return $this->result(
                'min_order',
                'Ce code promo demande un panier d\'au moins ' . $this->formatCents($minOrder) . '.',
                0,
                null
            );
        }

        $discount = PricingService::discountCents($coupon, $subtotalCents);

        if ($discount <= 0) {
            return $this->result('no_effect', 'Ce code promo ne s\'applique pas à ce panier.', 0, null);
        }

        return $this->result('ok', 'Code promo appliqué.', $discount, $coupon);
    }

    /**
     * Enregistre l'utilisation du coupon pour une commande. Appelée dans la transaction de
     * création : l'incrément de `usage_count` et la ligne de `coupon_redemptions` doivent
     * vivre ou mourir avec la commande, sinon un coupon à usage unique se retrouve consommé
     * par une commande qui n'existe pas.
     *
     * La clause `usage_count < usage_limit` rend l'incrément atomique : deux commandes
     * simultanées sur le dernier exemplaire d'un coupon ne peuvent pas passer toutes les deux.
     */
    public function redeem(\PDO $db, int $couponId, int $clientId, int $orderId, int $discountCents): bool
    {
        $stmt = $db->prepare(
            'UPDATE coupons SET usage_count = usage_count + 1
             WHERE id = ? AND is_active = 1 AND (usage_limit IS NULL OR usage_count < usage_limit)'
        );
        $stmt->execute([$couponId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $db->prepare(
            'INSERT INTO coupon_redemptions (coupon_id, user_id, order_id, discount_cents) VALUES (?, ?, ?, ?)'
        )->execute([$couponId, $clientId, $orderId, $discountCents]);

        return true;
    }

    /** @return array{status:string, message:string, discount_cents:int, coupon:?array<string,mixed>} */
    private function result(string $status, string $message, int $discountCents, ?array $coupon): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'discount_cents' => $discountCents,
            'coupon' => $coupon,
        ];
    }

    /** Même convention d'affichage que le front (web/js/format.js) : XOF sans sous-unité. */
    private function formatCents(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ') . ' FCFA';
    }
}
