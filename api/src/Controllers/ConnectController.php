<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Stripe;
use Stripe\Exception\ApiErrorException;

/**
 * Onboarding Stripe Connect Express — restaurateurs et livreurs (auto-entrepreneurs).
 * Voir §5 du document d'architecture : Stripe gère le KYC (pièce d'identité, IBAN, SIRET),
 * l'app ne stocke jamais de données bancaires elle-même.
 */
final class ConnectController
{
    /** POST /connect/onboard — crée le compte Connect si besoin et renvoie le lien d'onboarding hébergé */
    public function onboard(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('user_role');
        $userId = (int) $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        if (!in_array($role, ['restaurant_owner', 'driver'], true)) {
            return JsonResponse::error($response, 403, 'Onboarding réservé aux restaurateurs et livreurs');
        }

        $target = $role === 'restaurant_owner'
            ? $this->restaurantForOwner($userId)
            : $this->driverProfile($userId);

        if ($target === null) {
            $message = $role === 'restaurant_owner'
                ? "Créez d'abord votre restaurant (POST /restaurants)"
                : 'Profil livreur introuvable';

            return JsonResponse::error($response, 404, $message);
        }

        $db = Database::connection();
        $stripe = Stripe::client();

        try {
            $accountId = $target['stripe_account_id'];

            if ($accountId === null) {
                $email = $this->emailForUser($userId);

                $account = $stripe->accounts->create([
                    'type' => 'express',
                    'country' => 'FR',
                    'email' => $email,
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                        'card_payments' => ['requested' => true],
                    ],
                    'business_type' => 'individual',
                ]);

                $accountId = $account->id;

                if ($role === 'restaurant_owner') {
                    $db->prepare('UPDATE restaurants SET stripe_account_id = ? WHERE id = ?')
                        ->execute([$accountId, $target['id']]);
                } else {
                    $db->prepare('UPDATE driver_profiles SET stripe_account_id = ? WHERE user_id = ?')
                        ->execute([$accountId, $userId]);
                }
            }

            $link = $stripe->accountLinks->create([
                'account' => $accountId,
                'refresh_url' => $body['refresh_url'] ?? 'http://localhost:5500/onboarding.html?status=refresh',
                'return_url' => $body['return_url'] ?? 'http://localhost:5500/onboarding.html?status=done',
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException $e) {
            return JsonResponse::error($response, 502, 'Erreur Stripe', $e->getMessage());
        }

        return JsonResponse::ok($response, ['onboarding_url' => $link->url]);
    }

    /** GET /connect/status — l'app peut afficher "paiements activés" une fois le KYC Stripe terminé */
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

        if ($target === null || $target['stripe_account_id'] === null) {
            return JsonResponse::ok($response, ['onboarded' => false, 'payouts_enabled' => false]);
        }

        try {
            $account = Stripe::client()->accounts->retrieve($target['stripe_account_id']);
        } catch (ApiErrorException $e) {
            return JsonResponse::error($response, 502, 'Erreur Stripe', $e->getMessage());
        }

        return JsonResponse::ok($response, [
            'onboarded' => true,
            'payouts_enabled' => $account->payouts_enabled,
            'charges_enabled' => $account->charges_enabled,
        ]);
    }

    private function restaurantForOwner(int $ownerId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, stripe_account_id FROM restaurants WHERE owner_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function driverProfile(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id AS id, stripe_account_id FROM driver_profiles WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function emailForUser(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }
}
