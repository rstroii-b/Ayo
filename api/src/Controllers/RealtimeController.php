<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Log;
use Saveurs\Support\Realtime;

/**
 * Autorisation des canaux privés Pusher — un client ne peut s'abonner qu'aux canaux qui le
 * concernent (même logique d'accès que les endpoints REST correspondants).
 */
final class RealtimeController
{
    public function auth(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $channel = $body['channel_name'] ?? '';
        $socketId = $body['socket_id'] ?? '';
        $userId = (int) $request->getAttribute('user_id');
        $role = (string) $request->getAttribute('user_role');

        if ($channel === '' || $socketId === '') {
            return JsonResponse::error($response, 422, 'channel_name et socket_id requis');
        }

        if (!$this->userCanAccess($channel, $userId, $role)) {
            Log::app()->warning('realtime.channel_denied', [
                'channel' => $channel,
                'user_id' => $userId,
                'role' => $role,
            ]);

            return JsonResponse::error($response, 403, 'Accès refusé à ce canal');
        }

        $auth = Realtime::client()->authorizeChannel($channel, $socketId);
        $response->getBody()->write($auth);

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function userCanAccess(string $channel, int $userId, string $role): bool
    {
        // Canal de supervision : toutes les transactions de la plateforme y passent, donc
        // strictement réservé aux admins.
        if ($channel === Realtime::ADMIN_CHANNEL) {
            return $role === 'admin';
        }

        $db = Database::connection();

        if (preg_match('/^private-order\.(\d+)$/', $channel, $m)) {
            $stmt = $db->prepare(
                'SELECT o.client_id, o.driver_id, r.owner_id AS restaurant_owner_id
                 FROM orders o JOIN restaurants r ON r.id = o.restaurant_id WHERE o.id = ?'
            );
            $stmt->execute([$m[1]]);
            $order = $stmt->fetch();

            if ($order === false) {
                return false;
            }

            return in_array($userId, [
                (int) $order['client_id'],
                (int) ($order['driver_id'] ?? 0),
                (int) $order['restaurant_owner_id'],
            ], true);
        }

        if (preg_match('/^private-restaurant\.(\d+)$/', $channel, $m)) {
            $stmt = $db->prepare('SELECT owner_id FROM restaurants WHERE id = ?');
            $stmt->execute([$m[1]]);

            return (int) $stmt->fetchColumn() === $userId;
        }

        if (preg_match('/^private-driver\.(\d+)$/', $channel, $m)) {
            return (int) $m[1] === $userId;
        }

        return false;
    }
}
