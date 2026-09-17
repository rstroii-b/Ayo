<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\KycStorage;

/** Revue KYC des livreurs — réservé au rôle admin. */
final class AdminController
{
    private const STATUSES = ['pending', 'verified', 'rejected'];

    /** GET /admin/drivers?kyc_status=pending */
    public function listDriversForKyc(Request $request, Response $response): Response
    {
        $status = $request->getQueryParams()['kyc_status'] ?? 'pending';
        if (!in_array($status, self::STATUSES, true)) {
            return JsonResponse::error($response, 422, 'kyc_status invalide');
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.phone, u.email,
                    dp.rccm, dp.vehicule_type, dp.kyc_status, dp.kyc_rejection_reason,
                    (dp.kyc_document_path IS NOT NULL) AS has_kyc_document
             FROM driver_profiles dp
             JOIN users u ON u.id = dp.user_id
             WHERE dp.kyc_status = ?
             ORDER BY u.id'
        );
        $stmt->execute([$status]);
        $drivers = $stmt->fetchAll();

        foreach ($drivers as &$driver) {
            $driver['has_kyc_document'] = (bool) $driver['has_kyc_document'];
        }

        return JsonResponse::ok($response, ['drivers' => $drivers]);
    }

    /** GET /admin/drivers/{id}/kyc-document — stream binaire de la pièce d'identité. */
    public function kycDocument(Request $request, Response $response, array $routeArgs): Response
    {
        $stmt = Database::connection()->prepare('SELECT kyc_document_path FROM driver_profiles WHERE user_id = ?');
        $stmt->execute([$routeArgs['id']]);
        $path = $stmt->fetchColumn();

        if ($path === false || $path === null) {
            return JsonResponse::error($response, 404, 'Aucun document pour ce livreur');
        }

        $fullPath = KycStorage::path($path);
        if (!is_file($fullPath)) {
            return JsonResponse::error($response, 404, 'Document introuvable');
        }

        $response->getBody()->write((string) file_get_contents($fullPath));

        // Le document est servi en pièce jointe, jamais rendu dans l'onglet : une pièce
        // d'identité au format PDF peut embarquer du script, et le visualiseur intégré
        // l'exécuterait sur l'origine de l'API, session admin ouverte. `nosniff` empêche par
        // ailleurs le navigateur de « deviner » un autre type que celui annoncé.
        return $response
            ->withHeader('Content-Type', KycStorage::contentType($path))
            ->withHeader('Content-Disposition', 'attachment; filename="kyc-' . (int) $routeArgs['id'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** PATCH /admin/drivers/{id}/kyc — body {status: verified|rejected, reason?} */
    public function decideKyc(Request $request, Response $response, array $routeArgs): Response
    {
        $body = (array) $request->getParsedBody();
        $status = $body['status'] ?? null;

        if (!in_array($status, ['verified', 'rejected'], true)) {
            return JsonResponse::error($response, 422, 'status doit être "verified" ou "rejected"');
        }

        $db = Database::connection();
        $exists = $db->prepare('SELECT 1 FROM driver_profiles WHERE user_id = ?');
        $exists->execute([$routeArgs['id']]);
        if ($exists->fetch() === false) {
            return JsonResponse::error($response, 404, 'Livreur introuvable');
        }

        $reason = null;
        if ($status === 'rejected') {
            $reason = isset($body['reason']) && is_string($body['reason'])
                ? mb_substr(trim($body['reason']), 0, 255)
                : null;
        }

        // Un refus remet aussi le livreur hors ligne : sans cela, un compte déjà en ligne au
        // moment du refus restait dans la file de dispatch jusqu'à sa prochaine déconnexion.
        $db->prepare(
            'UPDATE driver_profiles SET kyc_status = ?, kyc_rejection_reason = ?,
                    is_online = IF(? = \'verified\', is_online, 0)
             WHERE user_id = ?'
        )->execute([$status, $reason, $status, (int) $routeArgs['id']]);

        return JsonResponse::ok($response, ['status' => $status]);
    }
}
