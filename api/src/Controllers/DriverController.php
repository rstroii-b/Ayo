<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;

/** Statut en ligne et position du livreur — alimente le dispatch automatique (voir OrderController). */
final class DriverController
{
    /** PATCH /driver/status — bascule en ligne / hors ligne. */
    public function updateStatus(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (!isset($body['is_online'])) {
            return JsonResponse::error($response, 422, 'is_online requis');
        }

        Database::connection()->prepare('UPDATE driver_profiles SET is_online = ? WHERE user_id = ?')
            ->execute([$body['is_online'] ? 1 : 0, $request->getAttribute('user_id')]);

        return JsonResponse::ok($response, ['is_online' => (bool) $body['is_online']]);
    }

    /** POST /driver/location — position GPS, envoyée périodiquement pendant que le livreur est en ligne. */
    public function updateLocation(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (!isset($body['lat'], $body['lng'])) {
            return JsonResponse::error($response, 422, 'lat et lng requis');
        }

        $driverId = $request->getAttribute('user_id');

        Database::connection()->prepare(
            'INSERT INTO driver_locations (driver_id, lat, lng) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng)'
        )->execute([$driverId, $body['lat'], $body['lng']]);

        return JsonResponse::ok($response, ['updated' => true]);
    }
}
