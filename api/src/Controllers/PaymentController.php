<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use CinetPay\Currency as CinetPayCurrency;
use CinetPay\Language as CinetPayLanguage;
use CinetPay\Request\CreatePaymentRequest as CinetPayCreatePaymentRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PaymentLedger;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Log;

final class PaymentController
{
    /** POST /payments/intent — le client encaisse pour une commande déjà créée (statut "pending"). */
    public function createIntent(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $userId = (int) $request->getAttribute('user_id');

        if (empty($body['order_id'])) {
            return JsonResponse::error($response, 422, 'order_id requis');
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT o.id, o.client_id, o.status, o.total_cents, o.payment_intent_id,
                    o.cinetpay_notify_token, o.cinetpay_payment_url,
                    u.first_name, u.last_name, u.email, u.phone
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.client_id
             WHERE o.id = ?'
        );
        $stmt->execute([$body['order_id']]);
        $order = $stmt->fetch();

        if ($order === false || (int) $order['client_id'] !== $userId) {
            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        if ($order['status'] !== 'pending') {
            return JsonResponse::error($response, 409, 'Cette commande ne peut plus être payée');
        }

        $client = CinetPayClient::client();
        if ($client === null) {
            Log::app()->error('cinetpay.not_configured', ['order_id' => (int) $order['id'], 'action' => 'payment_intent']);

            return JsonResponse::error(
                $response,
                503,
                'Paiement mobile money indisponible',
                "CinetPay n'est pas encore configuré."
            );
        }

        // Rechargement de page : on renvoie le même lien plutôt que d'en recréer un
        // (un merchant_transaction_id réutilisé est rejeté par CinetPay avec TRANSACTION_EXIST).
        if ($order['payment_intent_id'] !== null && $order['cinetpay_payment_url'] !== null) {
            return JsonResponse::ok($response, ['payment_url' => $order['cinetpay_payment_url']]);
        }

        $frontUrl = rtrim($_ENV['FRONT_URL'] ?? 'https://ayo.jobivoire.com', '/');
        $apiUrl = rtrim($_ENV['API_URL'] ?? 'https://api-ayo.jobivoire.com', '/');
        $merchantTransactionId = 'ayo-' . $order['id'] . '-' . bin2hex(random_bytes(4));

        try {
            $init = $client->payments()->create(new CinetPayCreatePaymentRequest(
                currency: CinetPayCurrency::XOF,
                merchantTransactionId: $merchantTransactionId,
                // total_cents garde la convention interne "×100" même pour le XOF (pas de décimales réelles).
                amount: intdiv((int) $order['total_cents'], 100),
                successUrl: "{$frontUrl}/suivi.html?order={$order['id']}",
                failedUrl: "{$frontUrl}/suivi.html?order={$order['id']}&payment=failed",
                notifyUrl: "{$apiUrl}/api/v1/webhooks/cinetpay",
                language: CinetPayLanguage::French,
                designation: "Commande Ayo #{$order['id']}",
                clientFirstName: $order['first_name'],
                clientLastName: $order['last_name'],
                clientEmail: $order['email'],
            ));
        } catch (\Throwable $e) {
            Log::app()->error('cinetpay.payment_intent_failed', [
                'order_id' => (int) $order['id'],
                'merchant_transaction_id' => $merchantTransactionId,
                'amount_cents' => (int) $order['total_cents'],
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error($response, 502, 'Erreur CinetPay', $e->getMessage());
        }

        $db->prepare(
            'UPDATE orders SET payment_intent_id = ?, cinetpay_notify_token = ?, cinetpay_payment_url = ? WHERE id = ?'
        )->execute([$init->merchantTransactionId, $init->notifyToken, $init->paymentUrl, $order['id']]);

        Log::transactions()->info('payment.intent_created', [
            'order_id' => (int) $order['id'],
            'client_id' => $userId,
            'amount_cents' => (int) $order['total_cents'],
            'payment_intent_id' => $init->merchantTransactionId,
        ]);

        return JsonResponse::ok($response, ['payment_url' => $init->paymentUrl]);
    }

    /** POST /webhooks/cinetpay — non authentifié (JWT), vérifié par notify_token. Paiements ET virements. */
    public function cinetpayWebhook(Request $request, Response $response): Response
    {
        $client = CinetPayClient::client();
        if ($client === null) {
            Log::app()->error('cinetpay.not_configured', ['action' => 'webhook']);

            return JsonResponse::error($response, 503, 'CinetPay non configuré');
        }

        $raw = (string) $request->getBody();

        try {
            $notification = $client->webhooks()->parse($raw);
        } catch (\Throwable $e) {
            // Corps illisible : soit CinetPay a changé de format, soit quelqu'un sonde
            // l'endpoint. Dans les deux cas on veut le savoir.
            Log::app()->warning('webhook.unparseable', [
                'error' => $e->getMessage(),
                'body_size' => strlen($raw),
            ]);

            return JsonResponse::error($response, 400, 'Webhook CinetPay invalide');
        }

        // Préfixe posé à la création (voir PaymentController::createIntent et
        // PayoutService::transferCinetPay) — évite d'interroger les deux tables à l'aveugle.
        if (str_starts_with($notification->merchantTransactionId, 'ayo-po-')) {
            return $this->cinetpayTransferWebhook($response, $client, $raw, $notification->merchantTransactionId);
        }

        return $this->cinetpayPaymentWebhook($response, $client, $raw, $notification->merchantTransactionId);
    }

    private function cinetpayPaymentWebhook(Response $response, \CinetPay\CinetPay $client, string $raw, string $merchantTransactionId): Response
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, cinetpay_notify_token FROM orders WHERE payment_intent_id = ?');
        $stmt->execute([$merchantTransactionId]);
        $order = $stmt->fetch();

        if ($order === false || $order['cinetpay_notify_token'] === null) {
            Log::app()->warning('webhook.unknown_payment', ['merchant_transaction_id' => $merchantTransactionId]);

            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        try {
            $confirmed = $client->webhooks()->handlePayment($raw, $order['cinetpay_notify_token']);
        } catch (\Throwable $e) {
            // Signature invalide : c'est le scénario "quelqu'un essaie de faire passer une
            // commande pour payée". Niveau error, jamais silencieux.
            Log::app()->error('webhook.verification_failed', [
                'kind' => 'payment',
                'order_id' => (int) $order['id'],
                'merchant_transaction_id' => $merchantTransactionId,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error($response, 400, 'Vérification du webhook CinetPay échouée');
        }

        $orderId = (int) $order['id'];

        // PaymentLedger porte l'idempotence : CinetPay rejoue ses notifications, et le script
        // de réconciliation peut arriver en même temps sur la même transaction.
        if ($confirmed->isSuccessful()) {
            // Montant réellement débité (réponse canonique CinetPay, XOF entier → cents internes ×100).
            $paidCents = isset($confirmed->payment->raw['amount']) ? (int) round((float) $confirmed->payment->raw['amount'] * 100) : null;
            PaymentLedger::markPaid($orderId, 'webhook', $paidCents);
        } elseif ($confirmed->isFinal()) {
            PaymentLedger::markPaymentFailed($orderId, 'webhook', $confirmed->payment->status);
        }

        return JsonResponse::ok($response, ['received' => true]);
    }

    private function cinetpayTransferWebhook(Response $response, \CinetPay\CinetPay $client, string $raw, string $merchantTransactionId): Response
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, cinetpay_notify_token FROM payouts WHERE cinetpay_transfer_id = ?');
        $stmt->execute([$merchantTransactionId]);
        $payout = $stmt->fetch();

        if ($payout === false || $payout['cinetpay_notify_token'] === null) {
            Log::app()->warning('webhook.unknown_transfer', ['merchant_transaction_id' => $merchantTransactionId]);

            return JsonResponse::error($response, 404, 'Virement introuvable');
        }

        try {
            $confirmed = $client->webhooks()->handleTransfer($raw, $payout['cinetpay_notify_token']);
        } catch (\Throwable $e) {
            Log::app()->error('webhook.verification_failed', [
                'kind' => 'transfer',
                'payout_id' => (int) $payout['id'],
                'merchant_transaction_id' => $merchantTransactionId,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error($response, 400, 'Vérification du webhook CinetPay échouée');
        }

        if ($confirmed->isFinal()) {
            PaymentLedger::settlePayout(
                (int) $payout['id'],
                $confirmed->isSuccessful(),
                $confirmed->isSuccessful() ? null : "CinetPay: {$confirmed->transfer->status}",
                'webhook'
            );
        }

        return JsonResponse::ok($response, ['received' => true]);
    }
}
