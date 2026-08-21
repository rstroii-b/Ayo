<?php

declare(strict_types=1);

namespace Saveurs\Services;

use CinetPay\Currency as CinetPayCurrency;
use CinetPay\Request\CreateTransferRequest as CinetPayCreateTransferRequest;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\Stripe;
use Stripe\Exception\ApiErrorException;

/**
 * Déclenche les deux virements à la livraison — voir §5 :
 *   transfer_restaurant = sous_total_plats − commission_plateforme
 *   transfer_livreur    = frais_livraison − commission_dispatch
 * Jamais avant "delivered", pour pouvoir annuler/rembourser proprement en cas de litige.
 * Zone EUR : Stripe Connect. Zone XOF (Abidjan) : virement mobile money CinetPay.
 */
final class PayoutService
{
    public function releaseForOrder(int $orderId): void
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT o.subtotal_cents, o.delivery_fee_cents, o.driver_id, o.stripe_charge_id,
                    COALESCE(z.currency, "EUR") AS currency,
                    r.id AS restaurant_id, r.stripe_account_id AS restaurant_stripe_account,
                    r.mobile_money_operator AS restaurant_mm_operator,
                    r.mobile_money_number AS restaurant_mm_number,
                    r.commission_pct,
                    dp.stripe_account_id AS driver_stripe_account,
                    dp.mobile_money_operator AS driver_mm_operator,
                    dp.mobile_money_number AS driver_mm_number
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             LEFT JOIN delivery_zones z ON z.id = r.zone_id
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

        if ($order['currency'] === 'XOF') {
            $this->transferCinetPay(
                $orderId, 'restaurant', $order['restaurant_id'], null,
                $order['restaurant_mm_operator'], $order['restaurant_mm_number'], $restaurantAmount
            );
            $this->transferCinetPay(
                $orderId, 'driver', null, $order['driver_id'],
                $order['driver_mm_operator'], $order['driver_mm_number'], $driverAmount
            );

            return;
        }

        $this->transferStripe($orderId, 'restaurant', $order['restaurant_id'], null, $order['restaurant_stripe_account'], $restaurantAmount, $order['stripe_charge_id']);
        $this->transferStripe($orderId, 'driver', null, $order['driver_id'], $order['driver_stripe_account'], $driverAmount, $order['stripe_charge_id']);
    }

    private function transferStripe(
        int $orderId,
        string $recipientType,
        ?int $restaurantId,
        ?int $driverId,
        ?string $stripeAccountId,
        int $amountCents,
        ?string $chargeId
    ): void {
        if ($stripeAccountId === null) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', failureReason: 'Compte Stripe non configuré');

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

            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'sent', stripeTransferId: $transfer->id);
        } catch (ApiErrorException $e) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', failureReason: $e->getMessage());
        }
    }

    private function transferCinetPay(
        int $orderId,
        string $recipientType,
        ?int $restaurantId,
        ?int $driverId,
        ?string $operator,
        ?string $phoneNumber,
        int $amountCents
    ): void {
        if ($operator === null || $phoneNumber === null) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', failureReason: 'Mobile money non configuré');

            return;
        }

        $client = CinetPayClient::client();
        if ($client === null) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', failureReason: 'CinetPay non configuré');

            return;
        }

        $apiUrl = rtrim($_ENV['API_URL'] ?? 'https://api-ayo.jobivoire.com', '/');
        $merchantTransactionId = 'ayo-po-' . $orderId . '-' . $recipientType[0] . '-' . bin2hex(random_bytes(4));

        try {
            $transfer = $client->transfers()->create(new CinetPayCreateTransferRequest(
                currency: CinetPayCurrency::XOF,
                merchantTransactionId: $merchantTransactionId,
                phoneNumber: $phoneNumber,
                // amount_cents garde la convention interne "×100" même pour le XOF (pas de décimales réelles).
                amount: intdiv($amountCents, 100),
                paymentMethod: $operator,
                reason: "Commande Ayo #{$orderId}",
                notifyUrl: "{$apiUrl}/webhooks/cinetpay",
            ));
        } catch (\Throwable $e) {
            $this->recordPayout($orderId, $recipientType, $restaurantId, $driverId, $amountCents, 'failed', failureReason: $e->getMessage());

            return;
        }

        // isFinal()+échec = rejeté tout de suite par l'opérateur ; sinon on reste "pending"
        // jusqu'à la confirmation par webhook (voir PaymentController::cinetpayWebhook).
        $statut = $transfer->isSuccessful() ? 'sent' : ($transfer->isFinal() ? 'failed' : 'pending');
        $failureReason = $statut === 'failed' ? "CinetPay: {$transfer->status}" : null;

        $this->recordPayout(
            $orderId, $recipientType, $restaurantId, $driverId, $amountCents, $statut,
            cinetpayTransferId: $merchantTransactionId,
            cinetpayNotifyToken: $transfer->notifyToken,
            failureReason: $failureReason
        );
    }

    private function recordPayout(
        int $orderId,
        string $recipientType,
        ?int $restaurantId,
        ?int $driverId,
        int $amountCents,
        string $statut,
        ?string $stripeTransferId = null,
        ?string $cinetpayTransferId = null,
        ?string $cinetpayNotifyToken = null,
        ?string $failureReason = null
    ): void {
        Database::connection()->prepare(
            'INSERT INTO payouts (order_id, recipient_type, restaurant_id, driver_id, amount_cents, statut, stripe_transfer_id, cinetpay_transfer_id, cinetpay_notify_token, failure_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId, $recipientType, $restaurantId, $driverId, $amountCents, $statut,
            $stripeTransferId, $cinetpayTransferId, $cinetpayNotifyToken, $failureReason,
        ]);
    }
}
