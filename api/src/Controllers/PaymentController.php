<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\PayoutService;
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
            'SELECT o.id, o.client_id, o.status, o.total_cents, o.payment_intent_id, z.currency
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
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

        // Le paiement par carte (Stripe) n'est branché que pour la zone EUR pour l'instant —
        // les zones en Franc CFA (ex: Abidjan) attendent une intégration mobile money dédiée
        // (Wave/Orange Money via CinetPay), pas encore réalisée. Mieux vaut bloquer proprement
        // que de facturer le mauvais montant dans la mauvaise devise.
        $currency = $order['currency'] ?? 'EUR';
        if ($currency !== 'EUR') {
            return JsonResponse::error(
                $response,
                501,
                'Paiement par carte indisponible pour cette zone',
                "Le paiement mobile money pour la devise {$currency} n'est pas encore disponible."
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
}
