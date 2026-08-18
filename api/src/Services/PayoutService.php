<?php

declare(strict_types=1);

namespace Saveurs\Services;

use Saveurs\Support\Database;
use Saveurs\Support\Stripe;
use Stripe\Exception\ApiErrorException;

/**
 * Déclenche les deux virements Stripe Connect à la livraison — voir §5 :
 *   transfer_restaurant = sous_total_plats − commission_plateforme
 *   transfer_livreur    = frais_livraison − commission_dispatch
 * Jamais avant "delivered", pour pouvoir annuler/rembourser proprement en cas de litige.
 */
final class PayoutService
{
    public function releaseForOrder(int $orderId): void
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT o.subtotal_cents, o.delivery_fee_cents, o.driver_id, o.stripe_charge_id,
                    r.id AS restaurant_id, r.stripe_account_id AS restaurant_account, r.commission_pct,
                    dp.stripe_account_id AS driver_account
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             LEFT JOIN driver_profiles dp ON dp.user_id = o.driver_id
             WHERE o.id = ?'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order === false) {
            return;
        }

        $dispatchCommissionPct = (float) ($_ENV['DISPATCH_COMMISSION_PCT'] ?? 15);

        $restaurantAmount = (int) round(
            $order['subtotal_cents'] * (1 - ((float) $order['commission_pct'] / 100))
        );
        $driverAmount = (int) round(
            $order['delivery_fee_cents'] * (1 - ($dispatchCommissionPct / 100))
        );

        $this->transfer($orderId, 'restaurant', $order['restaurant_id'], null, $order['restaurant_account'], $restaurantAmount, $order['stripe_charge_id']);
        $this->transfer($orderId, 'driver', null, $order['driver_id'], $order['driver_account'], $driverAmount, $order['stripe_charge_id']);
    }

    private function transfer(
        int $orderId,
        string $recipientType,
        ?int $restaurantId,
        ?int $driverId,
        ?string $stripeAccountId,
        int $amountCents,
        ?string $chargeId
    ): void {
        $db = Database::connection();

        if ($stripeAccountId === null) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', null, 'Compte Stripe non configuré');

            return;
        }

        try {
            $params = [
                'amount' => $amountCents,
                'currency' => 'eur',
                'destination' => $stripeAccountId,
                'transfer_group' => "order_{$orderId}",
            ];

            // Rattache le virement à la charge d'origine : évite de dépendre du solde
            // disponible de la plateforme (qui peut ne pas être encore réglé — voir §5).
            if ($chargeId !== null) {
                $params['source_transaction'] = $chargeId;
            }

            $transfer = Stripe::client()->transfers->create($params);

            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'sent', $transfer->id, null);
        } catch (ApiErrorException $e) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', null, $e->getMessage());
        }
    }

    private function recordPayout(
        int $orderId,
        string $recipientType,
        ?int $restaurantId,
        ?int $driverId,
        int $amountCents,
        string $statut,
        ?string $transferId,
        ?string $failureReason
    ): void {
        Database::connection()->prepare(
            'INSERT INTO payouts (order_id, recipient_type, restaurant_id, driver_id, amount_cents, statut, stripe_transfer_id, failure_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $recipientType, $restaurantId, $driverId, $amountCents, $statut, $transferId, $failureReason]);
    }
}
