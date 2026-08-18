<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

final class PushController
{
    /** GET /push/vapid-key — clé publique nécessaire côté navigateur pour s'abonner. */
    public function vapidKey(Request $request, Response $response): Response
    {
        return JsonResponse::ok($response, ['publicKey' => $_ENV['VAPID_PUBLIC_KEY']]);
    }

    /** POST /push/subscribe — enregistre l'abonnement du navigateur pour le compte connecté. */
    public function subscribe(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $keys = $body['keys'] ?? [];

        if (empty($body['endpoint']) || empty($keys['p256dh']) || empty($keys['auth'])) {
            return JsonResponse::error($response, 422, "Abonnement push invalide");
        }

        Database::connection()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
        )->execute([
            $request->getAttribute('user_id'),
            $body['endpoint'],
            $keys['p256dh'],
            $keys['auth'],
        ]);

        return JsonResponse::ok($response, ['subscribed' => true], 201);
    }

    /** POST /push/unsubscribe */
    public function unsubscribe(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (empty($body['endpoint'])) {
            return JsonResponse::error($response, 422, 'endpoint requis');
        }

        Database::connection()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?')
            ->execute([$body['endpoint'], $request->getAttribute('user_id')]);

        return JsonResponse::ok($response, ['unsubscribed' => true]);
    }
}
