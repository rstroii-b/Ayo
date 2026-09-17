<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

/**
 * Paiements sortants (reversements) — restaurateurs et livreurs enregistrent leur compte
 * mobile money, utilisé par PayoutService pour les virements CinetPay.
 */
final class ConnectController
{
    /**
     * Opérateurs mobile money acceptés par CinetPay en Côte d'Ivoire.
     *
     * Le champ partait auparavant tel quel vers l'API de virement : une valeur fantaisiste ne
     * se découvrait qu'au moment du reversement, c'est-à-dire trop tard, avec un virement en
     * échec et un commerçant qui attend son argent.
     */
    private const OPERATORS = ['OM_CI', 'MTN_CI', 'MOOV_CI', 'WAVE_CI'];

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

        try {
            $operator = Validator::enum($body, 'operator', self::OPERATORS);
            $phoneNumber = Validator::optionalPhone($body, 'phone_number');
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        if ($phoneNumber === null) {
            return JsonResponse::error($response, 422, 'Numéro mobile money requis', 'phone_number');
        }

        $db = Database::connection();

        if ($role === 'restaurant_owner') {
            $restaurant = $this->restaurantForOwner($userId);
            if ($restaurant === null) {
                return JsonResponse::error($response, 404, "Vous n'avez pas encore de restaurant");
            }

            $db->prepare('UPDATE restaurants SET mobile_money_operator = ?, mobile_money_number = ? WHERE id = ?')
                ->execute([$operator, $phoneNumber, $restaurant['id']]);
        } else {
            $db->prepare('UPDATE driver_profiles SET mobile_money_operator = ?, mobile_money_number = ? WHERE user_id = ?')
                ->execute([$operator, $phoneNumber, $userId]);
        }

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
