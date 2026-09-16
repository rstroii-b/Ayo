<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Services\FraudDetector;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\RequestContext;

/**
 * Paiements sortants (reversements) — restaurateurs et livreurs enregistrent leur compte
 * mobile money, utilisé par PayoutService pour les virements CinetPay.
 */
final class ConnectController
{
    /** GET /connect/status — l'app peut afficher "paiements activés" une fois le mobile money renseigné */
    public function status(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('user_role');
        $userId = (int) $request->getAttribute('user_id');

        if (!in_array($role, ['restaurant_owner', 'driver'], true)) {
            return JsonResponse::error($response, 403, 'Réservé aux restaurateurs et livreurs');
        }

        $target = $role === 'restaurant_owner'
            ? $this->restaurantForOwner($userId)
            : $this->driverProfile($userId);

        if ($target === null) {
            return JsonResponse::ok($response, ['mobile_money_configured' => false]);
        }

        return JsonResponse::ok($response, [
            'mobile_money_configured' => $target['mobile_money_operator'] !== null && $target['mobile_money_number'] !== null,
            'mobile_money_operator' => $target['mobile_money_operator'],
            'mobile_money_number' => $target['mobile_money_number'],
        ]);
    }

    /** PATCH /connect/mobile-money — enregistre le compte mobile money du restaurateur ou livreur. */
    public function updateMobileMoney(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('user_role');
        $userId = (int) $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        if (!in_array($role, ['restaurant_owner', 'driver'], true)) {
            return JsonResponse::error($response, 403, 'Réservé aux restaurateurs et livreurs');
        }

        if (empty($body['operator']) || empty($body['phone_number'])) {
            return JsonResponse::error($response, 422, 'operator et phone_number requis');
        }

        $number = (string) $body['phone_number'];
        $db = Database::connection();

        if ($role === 'restaurant_owner') {
            $restaurant = $this->restaurantForOwner($userId);
            if ($restaurant === null) {
                return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
            }

            // Anti multi-comptes : un même portefeuille de reversement sur plusieurs bénéficiaires
            // est le montage type pour siphonner des virements. On refuse et on lève une alerte.
            if (FraudDetector::isMobileMoneyReused($number, 'restaurant', (int) $restaurant['id'])) {
                return JsonResponse::error($response, 409, 'Numéro mobile money déjà utilisé', 'Ce numéro est déjà rattaché à un autre compte.');
            }

            $db->prepare('UPDATE restaurants SET mobile_money_operator = ?, mobile_money_number = ? WHERE id = ?')
                ->execute([$body['operator'], $number, $restaurant['id']]);
        } else {
            if (FraudDetector::isMobileMoneyReused($number, 'driver', $userId)) {
                return JsonResponse::error($response, 409, 'Numéro mobile money déjà utilisé', 'Ce numéro est déjà rattaché à un autre compte.');
            }

            $db->prepare('UPDATE driver_profiles SET mobile_money_operator = ?, mobile_money_number = ? WHERE user_id = ?')
                ->execute([$body['operator'], $number, $userId]);
        }

        FraudDetector::record('mobile_money_update', $userId, RequestContext::ip($request), RequestContext::userAgent($request), ['role' => $role]);

        return JsonResponse::ok($response, ['updated' => true]);
    }

    private function restaurantForOwner(int $ownerId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, mobile_money_operator, mobile_money_number FROM restaurants WHERE owner_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function driverProfile(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id AS id, mobile_money_operator, mobile_money_number FROM driver_profiles WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
