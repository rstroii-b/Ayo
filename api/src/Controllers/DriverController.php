<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\KycStorage;

/** Statut en ligne et position du livreur — alimente le dispatch automatique (voir OrderController). */
final class DriverController
{
    /** GET /driver/me — profil livreur du compte connecté (statut KYC, véhicule, rccm...). */
    public function me(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT rccm, vehicule_type, is_online, kyc_status, kyc_rejection_reason,
                    (kyc_document_path IS NOT NULL) AS has_kyc_document
             FROM driver_profiles WHERE user_id = ?'
        );
        $stmt->execute([$request->getAttribute('user_id')]);
        $driver = $stmt->fetch();

        if ($driver === false) {
            return JsonResponse::error($response, 404, 'Profil livreur introuvable');
        }

        $driver['has_kyc_document'] = (bool) $driver['has_kyc_document'];

        return JsonResponse::ok($response, $driver);
    }

    /** POST /driver/kyc-document — téléverse/remplace la pièce d'identité, repasse le KYC en attente. */
    public function uploadKycDocument(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();

        if (!isset($files['document'])) {
            return JsonResponse::error($response, 422, 'Fichier "document" requis');
        }

        $driverId = (int) $request->getAttribute('user_id');

        try {
            $filename = KycStorage::save($driverId, $files['document']);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, 422, $e->getMessage());
        }

        $db = Database::connection();
        $previous = $db->prepare('SELECT kyc_document_path FROM driver_profiles WHERE user_id = ?');
        $previous->execute([$driverId]);
        $previousPath = $previous->fetchColumn();

        $db->prepare(
            "UPDATE driver_profiles SET kyc_document_path = ?, kyc_status = 'pending', kyc_rejection_reason = NULL
             WHERE user_id = ?"
        )->execute([$filename, $driverId]);

        // Resoumission : l'ancien fichier n'est plus référencé par personne, autant ne pas l'accumuler.
        if ($previousPath !== false && $previousPath !== null) {
            @unlink(KycStorage::path($previousPath));
        }

        return JsonResponse::ok($response, ['uploaded' => true]);
    }

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
