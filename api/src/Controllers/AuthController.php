<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Jwt;

final class AuthController
{
    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        foreach (['email', 'password', 'first_name', 'last_name', 'role'] as $field) {
            if (empty($body[$field]) || !is_string($body[$field])) {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        if (strlen($body['password']) < 8) {
            return JsonResponse::error($response, 422, 'Le mot de passe doit contenir au moins 8 caractères');
        }

        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            return JsonResponse::error($response, 422, 'Adresse email invalide');
        }

        if (!in_array($body['role'], ['client', 'restaurant_owner', 'driver'], true)) {
            return JsonResponse::error($response, 422, 'Rôle invalide');
        }

        $db = Database::connection();

        $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$body['email']]);
        if ($exists->fetch() !== false) {
            return JsonResponse::error($response, 409, 'Un compte existe déjà avec cet email');
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO users (email, phone, password_hash, first_name, last_name, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $body['email'],
                $body['phone'] ?? null,
                password_hash($body['password'], PASSWORD_BCRYPT),
                $body['first_name'],
                $body['last_name'],
                $body['role'],
            ]);

            $userId = (int) $db->lastInsertId();

            if ($body['role'] === 'driver') {
                // RCCM facultatif (voir CGU §2) — beaucoup de livreurs indépendants n'en ont pas encore.
                $db->prepare(
                    'INSERT INTO driver_profiles (user_id, rccm, vehicule_type) VALUES (?, ?, ?)'
                )->execute([$userId, empty($body['rccm']) ? null : $body['rccm'], $body['vehicule_type'] ?? 'velo']);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return JsonResponse::ok($response, [
            'user_id' => $userId,
            'token' => Jwt::issue($userId, $body['role']),
        ], 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (empty($body['email']) || empty($body['password']) || !is_string($body['email']) || !is_string($body['password'])) {
            return JsonResponse::error($response, 422, 'Email et mot de passe requis');
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $stmt->execute([$body['email']]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($body['password'], $user['password_hash'])) {
            return JsonResponse::error($response, 401, 'Identifiants invalides');
        }

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
     * Réémet un jeton tant que l'actuel est encore valide.
     * Squelette volontairement simple — une vraie rotation de refresh
     * token (table dédiée, révocation) est à ajouter avant la prod.
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

        return JsonResponse::ok($response, [
            'token' => Jwt::issue($claims['sub'], $claims['role']),
        ]);
    }
}
