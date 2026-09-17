<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Jwt;
use Saveurs\Support\RateLimiter;
use Saveurs\Support\ValidationException;
use Saveurs\Support\Validator;

final class AuthController
{
    private const ROLES = ['client', 'restaurant_owner', 'driver'];
    private const VEHICLE_TYPES = ['velo', 'scooter', 'voiture', 'moto'];

    /**
     * Longueur maximale du mot de passe. bcrypt ignore au-delà de 72 octets : accepter plus
     * long donnerait l'illusion d'un secret plus fort qu'il ne l'est réellement.
     */
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 72;

    /**
     * Haché factice comparé quand l'email n'existe pas, au même coût bcrypt que les vrais
     * (coût 12, valeur par défaut de PASSWORD_BCRYPT ici) : le temps de réponse est alors le
     * même qu'un compte existe ou non. Sans lui, un « identifiants invalides » instantané
     * signalait « cet email n'est pas chez nous » et le login devenait un annuaire.
     */
    private const TIMING_DUMMY_HASH = '$2y$12$0n971CETy4DrkZ1qwGwuueZGfqay4kV10zyWrcfAMBt3OlyAJKy4O';

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $ip = RateLimiter::clientIp($request);

        // Même plafond que la connexion : sans lui, la création de comptes en masse est
        // gratuite (et sert ensuite à sonder les endpoints authentifiés).
        if (($retryAfter = RateLimiter::retryAfter('register', $ip, $ip)) !== null) {
            return $this->tooManyAttempts($response, $retryAfter);
        }

        try {
            $email = Validator::str($body, 'email', 190);
            $firstName = Validator::str($body, 'first_name', 100);
            $lastName = Validator::str($body, 'last_name', 100);
            $role = Validator::enum($body, 'role', self::ROLES);
            $phone = Validator::optionalPhone($body);
            $rccm = Validator::optionalStr($body, 'rccm', 50);
            $vehicleType = Validator::optionalEnum($body, 'vehicule_type', self::VEHICLE_TYPES, 'velo');
        } catch (ValidationException $e) {
            return JsonResponse::error($response, 422, $e->getMessage(), $e->field);
        }

        $password = $body['password'] ?? null;

        if (!is_string($password) || strlen($password) < self::PASSWORD_MIN) {
            return JsonResponse::error(
                $response,
                422,
                'Le mot de passe doit contenir au moins ' . self::PASSWORD_MIN . ' caractères'
            );
        }

        if (strlen($password) > self::PASSWORD_MAX) {
            return JsonResponse::error(
                $response,
                422,
                'Le mot de passe ne peut pas dépasser ' . self::PASSWORD_MAX . ' caractères'
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return JsonResponse::error($response, 422, 'Adresse email invalide');
        }

        $db = Database::connection();

        $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch() !== false) {
            RateLimiter::record('register', $ip, $ip, false);

            return JsonResponse::error($response, 409, 'Un compte existe déjà avec cet email');
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO users (email, phone, password_hash, first_name, last_name, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $email,
                $phone,
                password_hash($password, PASSWORD_BCRYPT),
                $firstName,
                $lastName,
                $role,
            ]);

            $userId = (int) $db->lastInsertId();

            if ($role === 'driver') {
                // RCCM facultatif (voir CGU §2) — beaucoup de livreurs indépendants n'en ont pas encore.
                $db->prepare(
                    'INSERT INTO driver_profiles (user_id, rccm, vehicule_type) VALUES (?, ?, ?)'
                )->execute([$userId, $rccm, $vehicleType]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        RateLimiter::record('register', $ip, $ip, true);

        return JsonResponse::ok($response, [
            'user_id' => $userId,
            'token' => Jwt::issue($userId, $role),
        ], 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (empty($body['email']) || empty($body['password']) || !is_string($body['email']) || !is_string($body['password'])) {
            return JsonResponse::error($response, 422, 'Email et mot de passe requis');
        }

        $email = trim($body['email']);
        $identifier = mb_strtolower($email);
        $ip = RateLimiter::clientIp($request);

        if (($retryAfter = RateLimiter::retryAfter('login', $identifier, $ip)) !== null) {
            return $this->tooManyAttempts($response, $retryAfter);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Le hachage est exécuté même sans compte correspondant : sinon, le temps de réponse
        // dit à l'attaquant si l'email existe, ce qui transforme le login en annuaire.
        $hash = $user === false ? self::TIMING_DUMMY_HASH : $user['password_hash'];
        $passwordOk = password_verify($body['password'], $hash);

        if ($user === false || !$passwordOk) {
            RateLimiter::record('login', $identifier, $ip, false);

            return JsonResponse::error($response, 401, 'Identifiants invalides');
        }

        RateLimiter::record('login', $identifier, $ip, true);

        return JsonResponse::ok($response, [
            'user_id' => (int) $user['id'],
            'token' => Jwt::issue((int) $user['id'], $user['role']),
        ]);
    }

    /** GET /auth/me — profil du compte connecté (le nom n'est jamais dans le JWT). */
    public function me(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, phone, first_name, last_name, role FROM users WHERE id = ?'
        );
        $stmt->execute([$request->getAttribute('user_id')]);
        $user = $stmt->fetch();

        if ($user === false) {
            return JsonResponse::error($response, 404, 'Compte introuvable');
        }

        return JsonResponse::ok($response, $user);
    }

    /**
     * Réémet un jeton tant que l'actuel est valide ET que la session n'a pas dépassé sa durée
     * absolue (voir Jwt::issue). Le rôle est relu en base plutôt que recopié du jeton : un
     * compte rétrogradé ne doit pas conserver ses anciens droits jusqu'à l'expiration.
     *
     * Une vraie rotation de refresh token (table dédiée, révocation ciblée d'un appareil)
     * reste à ajouter — voir SECURITY-REVIEW.md, point « sessions ».
     */
    public function refresh(Request $request, Response $response): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return JsonResponse::error($response, 401, 'Jeton manquant');
        }

        $claims = Jwt::verify(substr($header, 7));

        if ($claims === null) {
            return JsonResponse::error($response, 401, 'Jeton invalide ou expiré');
        }

        $stmt = Database::connection()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$claims['sub']]);
        $role = $stmt->fetchColumn();

        if ($role === false) {
            return JsonResponse::error($response, 401, 'Compte introuvable');
        }

        return JsonResponse::ok($response, [
            'token' => Jwt::issue($claims['sub'], (string) $role, $claims['sid_iat']),
        ]);
    }

    private function tooManyAttempts(Response $response, int $retryAfter): Response
    {
        return JsonResponse::error(
            $response,
            429,
            'Trop de tentatives',
            'Réessaie dans ' . max(1, (int) ceil($retryAfter / 60)) . ' minute(s).'
        )->withHeader('Retry-After', (string) $retryAfter);
    }
}
