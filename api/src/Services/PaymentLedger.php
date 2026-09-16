<?php

declare(strict_types=1);

namespace Saveurs\Services;

use Saveurs\Support\Database;
use Saveurs\Support\Log;
use Saveurs\Support\Realtime;

/**
 * Point de passage unique pour tout changement d'état d'argent (paiement client, virement
 * sortant). Webhook CinetPay et script de réconciliation (bin/reconcile.php) appellent les
 * mêmes méthodes : l'idempotence, la piste d'audit et la diffusion temps réel vivent donc à
 * un seul endroit plutôt qu'en double.
 *
 * Idempotence : chaque transition est un UPDATE conditionné à l'état de départ. CinetPay
 * rejoue ses webhooks (et le cron peut tomber sur la même transaction en même temps) — sans
 * ça, une commande accumulait des lignes "payment_succeeded" en double dans order_events.
 * rowCount() === 0 signifie "quelqu'un est déjà passé avant nous", et on ne rejoue rien.
 */
final class PaymentLedger
{
    /**
     * @param string $source d'où vient la confirmation ("webhook" ou "reconcile") — tracé tel
     *                       quel dans les logs pour savoir si le webhook fait son travail.
     *
     * @return bool true si c'est bien cette confirmation qui a fait basculer la commande
     */
    public static function markPaid(int $orderId, string $source): bool
    {
        $db = Database::connection();

        $update = $db->prepare(
            "UPDATE orders SET payment_status = 'paid', paid_at = NOW()
             WHERE id = ? AND payment_status <> 'paid'"
        );
        $update->execute([$orderId]);

        if ($update->rowCount() === 0) {
            Log::app()->info('payment.already_settled', ['order_id' => $orderId, 'source' => $source]);

            return false;
        }

        $order = self::orderSummary($orderId);

        $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_succeeded", "system")')
            ->execute([$orderId]);

        Log::transactions()->info('payment.succeeded', [
            'order_id' => $orderId,
            'amount_cents' => $order['total_cents'] ?? null,
            'client_id' => $order['client_id'] ?? null,
            'restaurant_id' => $order['restaurant_id'] ?? null,
            'payment_intent_id' => $order['payment_intent_id'] ?? null,
            'source' => $source,
        ]);

        self::broadcast($orderId, $order, 'paid', $source);

        return true;
    }

    /** @return bool true si c'est bien cet appel qui a marqué le paiement en échec */
    public static function markPaymentFailed(int $orderId, string $source, ?string $reason = null): bool
    {
        $db = Database::connection();

        $update = $db->prepare(
            "UPDATE orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'unpaid'"
        );
        $update->execute([$orderId]);

        if ($update->rowCount() === 0) {
            Log::app()->info('payment.already_settled', ['order_id' => $orderId, 'source' => $source]);

            return false;
        }

        // Une commande jamais payée n'a pas à rester visible côté restaurant : on l'annule,
        // mais uniquement si elle n'a pas déjà été acceptée entre-temps.
        $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
            ->execute([$orderId]);

        $order = self::orderSummary($orderId);

        $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_failed", "system")')
            ->execute([$orderId]);

        Log::transactions()->warning('payment.failed', [
            'order_id' => $orderId,
            'amount_cents' => $order['total_cents'] ?? null,
            'client_id' => $order['client_id'] ?? null,
            'restaurant_id' => $order['restaurant_id'] ?? null,
            'payment_intent_id' => $order['payment_intent_id'] ?? null,
            'reason' => $reason,
            'source' => $source,
        ]);

        self::broadcast($orderId, $order, 'failed', $source);

        return true;
    }

    /**
     * Clôture un virement resté "pending" (confirmation asynchrone de l'opérateur mobile money).
     *
     * @return bool true si c'est bien cet appel qui a clôturé le virement
     */
    public static function settlePayout(int $payoutId, bool $successful, ?string $failureReason, string $source): bool
    {
        $db = Database::connection();
        $statut = $successful ? 'sent' : 'failed';

        $update = $db->prepare("UPDATE payouts SET statut = ?, failure_reason = ? WHERE id = ? AND statut = 'pending'");
        $update->execute([$statut, $successful ? null : $failureReason, $payoutId]);

        if ($update->rowCount() === 0) {
            Log::app()->info('payout.already_settled', ['payout_id' => $payoutId, 'source' => $source]);

            return false;
        }

        $stmt = $db->prepare('SELECT order_id, recipient_type, amount_cents, driver_id, restaurant_id FROM payouts WHERE id = ?');
        $stmt->execute([$payoutId]);
        $payout = $stmt->fetch() ?: [];

        self::recordPayoutEvent($payoutId, $statut, $payout, $failureReason, $source);

        return true;
    }

    /**
     * Trace + diffusion d'un virement, appelée aussi bien à sa création (PayoutService) qu'à sa
     * clôture par webhook/réconciliation.
     *
     * @param array<string, mixed> $payout
     */
    public static function recordPayoutEvent(
        int $payoutId,
        string $statut,
        array $payout,
        ?string $failureReason,
        string $source
    ): void {
        $context = [
            'payout_id' => $payoutId,
            'order_id' => $payout['order_id'] ?? null,
            'recipient_type' => $payout['recipient_type'] ?? null,
            'amount_cents' => $payout['amount_cents'] ?? null,
            'statut' => $statut,
            'failure_reason' => $failureReason,
            'source' => $source,
        ];

        if ($statut === 'failed') {
            Log::transactions()->error('payout.failed', $context);
        } else {
            Log::transactions()->info('payout.' . $statut, $context);
        }

        Realtime::notifyAdmins('payout', $context);
    }

    /** @return array<string, mixed> */
    private static function orderSummary(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, client_id, restaurant_id, total_cents, payment_intent_id, status
             FROM orders WHERE id = ?'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetch() ?: [];
    }

    /** @param array<string, mixed> $order */
    private static function broadcast(int $orderId, array $order, string $paymentStatus, string $source): void
    {
        Realtime::trigger("private-order.{$orderId}", 'payment-updated', ['payment_status' => $paymentStatus]);

        if (isset($order['restaurant_id'])) {
            Realtime::trigger("private-restaurant.{$order['restaurant_id']}", 'order-updated', [
                'order_id' => $orderId,
                'payment_status' => $paymentStatus,
            ]);
        }

        Realtime::notifyAdmins('payment', [
            'order_id' => $orderId,
            'payment_status' => $paymentStatus,
            'amount_cents' => $order['total_cents'] ?? null,
            'restaurant_id' => $order['restaurant_id'] ?? null,
            'source' => $source,
        ]);
    }
}
