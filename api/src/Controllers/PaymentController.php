<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use CinetPay\Currency as CinetPayCurrency;
use CinetPay\Language as CinetPayLanguage;
use CinetPay\Request\CreatePaymentRequest as CinetPayCreatePaymentRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PayoutService;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Stripe;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

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
                    o.cinetpay_notify_token, o.cinetpay_payment_url, z.currency,
                    u.first_name, u.last_name, u.email, u.phone
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.client_id
             LEFT JOIN delivery_zones z ON z.id = r.zone_id
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

        $currency = $order['currency'] ?? 'EUR';

        if ($currency === 'XOF') {
            return $this->createCinetPayPayment($response, $db, $order);
        }

        if ($currency !== 'EUR') {
            return JsonResponse::error(
                $response,
                501,
                'Paiement par carte indisponible pour cette zone',
                "Le paiement pour la devise {$currency} n'est pas encore disponible."
            );
        }

        try {
            if ($order['payment_intent_id'] !== null) {
                // Déjà créé (ex: le client a rechargé la page) — on renvoie le même secret.
                $intent = Stripe::client()->paymentIntents->retrieve($order['payment_intent_id']);
            } else {
                $intent = Stripe::client()->paymentIntents->create([
                    'amount' => $order['total_cents'],
                    'currency' => 'eur',
                    // allow_redirects=never exclut Klarna/Bancontact/etc. (qui demandent une page
                    // de retour côté front) — carte + Apple/Google Pay suffisent pour le MVP.
                    'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                    'transfer_group' => "order_{$order['id']}",
                    'metadata' => ['order_id' => $order['id']],
                ]);

                $db->prepare('UPDATE orders SET payment_intent_id = ? WHERE id = ?')
                    ->execute([$intent->id, $order['id']]);
            }
        } catch (ApiErrorException $e) {
            return JsonResponse::error($response, 502, 'Erreur Stripe', $e->getMessage());
        }

        return JsonResponse::ok($response, [
            'client_secret' => $intent->client_secret,
            'publishable_key' => $_ENV['STRIPE_PUBLISHABLE_KEY'],
        ]);
    }

    /** Zone XOF (Abidjan) — CinetPay au lieu de Stripe. */
    private function createCinetPayPayment(Response $response, \PDO $db, array $order): Response
    {
        $client = CinetPayClient::client();
        if ($client === null) {
            return JsonResponse::error(
                $response,
                503,
                'Paiement mobile money indisponible',
                "CinetPay n'est pas encore configuré pour cette zone."
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
                notifyUrl: "{$apiUrl}/webhooks/cinetpay",
                language: CinetPayLanguage::French,
                designation: "Commande Ayo #{$order['id']}",
                clientFirstName: $order['first_name'],
                clientLastName: $order['last_name'],
                clientEmail: $order['email'],
            ));
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 502, 'Erreur CinetPay', $e->getMessage());
        }

        $db->prepare(
            'UPDATE orders SET payment_intent_id = ?, cinetpay_notify_token = ?, cinetpay_payment_url = ? WHERE id = ?'
        )->execute([$init->merchantTransactionId, $init->notifyToken, $init->paymentUrl, $order['id']]);

        return JsonResponse::ok($response, ['payment_url' => $init->paymentUrl]);
    }

    /** POST /webhooks/stripe — non authentifié (JWT), vérifié par signature Stripe. */
    public function webhook(Request $request, Response $response): Response
    {
        $payload = (string) $request->getBody();
        $signature = $request->getHeaderLine('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $signature, $_ENV['STRIPE_WEBHOOK_SECRET']);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return JsonResponse::error($response, 400, 'Signature Stripe invalide');
        }

        $db = Database::connection();

        // Un PaymentIntent qui ne vient pas de POST /payments/intent (fixture Stripe, test manuel
        // depuis le dashboard) n'a pas de metadata.order_id — l'événement ne nous concerne pas.
        $orderId = isset($event->data->object->metadata->order_id)
            ? (int) $event->data->object->metadata->order_id
            : null;

        switch ($event->type) {
            case 'payment_intent.succeeded':
                if ($orderId !== null) {
                    // Sert de source_transaction pour les Transfer — voir PayoutService.
                    $db->prepare('UPDATE orders SET stripe_charge_id = ? WHERE id = ?')
                        ->execute([$event->data->object->latest_charge, $orderId]);
                    $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_succeeded", "system")')
                        ->execute([$orderId]);
                }
                break;

            case 'payment_intent.payment_failed':
                if ($orderId !== null) {
                    $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
                        ->execute([$orderId]);
                    $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_failed", "system")')
                        ->execute([$orderId]);
                }
                break;

            case 'account.updated':
                // Le KYC Connect d'un restaurant/livreur a changé de statut — rien à synchroniser
                // ici pour le squelette, GET /connect/status interroge Stripe à la demande.
                break;
        }

        return JsonResponse::ok($response, ['received' => true]);
    }

    /** POST /webhooks/cinetpay — non authentifié (JWT), vérifié par notify_token. Paiements ET virements. */
    public function cinetpayWebhook(Request $request, Response $response): Response
    {
        $client = CinetPayClient::client();
        if ($client === null) {
            return JsonResponse::error($response, 503, 'CinetPay non configuré');
        }

        $raw = (string) $request->getBody();

        try {
            $notification = $client->webhooks()->parse($raw);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 400, 'Webhook CinetPay invalide');
        }

        // Préfixe posé à la création (voir PaymentController::createCinetPayPayment et
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
            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        try {
            $confirmed = $client->webhooks()->handlePayment($raw, $order['cinetpay_notify_token']);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 400, 'Vérification du webhook CinetPay échouée');
        }

        $orderId = (int) $order['id'];

        if ($confirmed->isSuccessful()) {
            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_succeeded", "system")')
                ->execute([$orderId]);
        } elseif ($confirmed->isFinal()) {
            $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
                ->execute([$orderId]);
            $db->prepare('INSERT INTO order_events (order_id, status, actor_type) VALUES (?, "payment_failed", "system")')
                ->execute([$orderId]);
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
            return JsonResponse::error($response, 404, 'Virement introuvable');
        }

        try {
            $confirmed = $client->webhooks()->handleTransfer($raw, $payout['cinetpay_notify_token']);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 400, 'Vérification du webhook CinetPay échouée');
        }

        if ($confirmed->isFinal()) {
            $db->prepare('UPDATE payouts SET statut = ? WHERE id = ?')
                ->execute([$confirmed->isSuccessful() ? 'sent' : 'failed', $payout['id']]);
        }

        return JsonResponse::ok($response, ['received' => true]);
    }
}
