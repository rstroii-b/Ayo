<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\KycStorage;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

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
        $driver['is_online'] = (bool) $driver['is_online'];
        $driver['can_work'] = $driver['kyc_status'] === 'verified';

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

        // Un nouveau document remet la vérification à zéro — et repasse le livreur hors ligne :
        // tant que la pièce n'est pas validée, il ne doit pas rester dans la file de dispatch.
        $db->prepare(
            "UPDATE driver_profiles
             SET kyc_document_path = ?, kyc_status = 'pending', kyc_rejection_reason = NULL, is_online = 0
             WHERE user_id = ?"
        )->execute([$filename, $driverId]);

        // Resoumission : l'ancien fichier n'est plus référencé par personne, autant ne pas l'accumuler.
        if ($previousPath !== false && $previousPath !== null) {
            @unlink(KycStorage::path($previousPath));
        }

        return JsonResponse::ok($response, ['uploaded' => true]);
    }

    /**
     * PATCH /driver/status — bascule en ligne / hors ligne.
     *
     * Passer en ligne exige un KYC validé. Le rôle « driver » s'obtient en remplissant un
     * formulaire d'inscription : sans ce contrôle, un compte créé en trente secondes entrait
     * dans la file de dispatch, recevait les notifications de courses et pouvait en prendre
     * une — l'écran affichait bien « identité non vérifiée », mais l'API ne l'imposait pas.
     */
    public function updateStatus(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $driverId = (int) $request->getAttribute('user_id');

        try {
            $isOnline = Validator::bool($body, 'is_online');
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $db = Database::connection();

        if ($isOnline) {
            $stmt = $db->prepare('SELECT kyc_status FROM driver_profiles WHERE user_id = ?');
            $stmt->execute([$driverId]);
            $kycStatus = $stmt->fetchColumn();

            if ($kycStatus === false) {
                return JsonResponse::error($response, 404, 'Profil livreur introuvable');
            }

            if ($kycStatus !== 'verified') {
                return JsonResponse::error(
                    $response,
                    403,
                    'Vérification d\'identité requise',
                    'Ton identité doit être vérifiée avant de passer en ligne.'
                );
            }
        }

        $db->prepare('UPDATE driver_profiles SET is_online = ? WHERE user_id = ?')
            ->execute([$isOnline ? 1 : 0, $driverId]);

        return JsonResponse::ok($response, ['is_online' => $isOnline]);
    }

    /** POST /driver/location — position GPS, envoyée périodiquement pendant que le livreur est en ligne. */
    public function updateLocation(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        try {
            $lat = Validator::latitude($body);
            $lng = Validator::longitude($body);
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        Database::connection()->prepare(
            'INSERT INTO driver_locations (driver_id, lat, lng) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng)'
        )->execute([$request->getAttribute('user_id'), $lat, $lng]);

        return JsonResponse::ok($response, ['updated' => true]);
    }
}
