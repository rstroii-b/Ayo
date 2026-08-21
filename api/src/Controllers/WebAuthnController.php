<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Jwt;

/**
 * Connexion biométrique (WebAuthn / passkeys — Face ID, empreinte, Windows Hello...).
 * L'API étant sans session serveur (JWT), le défi ("challenge") généré entre les deux temps
 * de chaque cérémonie (options → vérification) est stocké temporairement en base (voir
 * webauthn_challenges), avec une expiration courte.
 */
final class WebAuthnController
{
    private function webauthn(): WebAuthn
    {
        return new WebAuthn(
            $_ENV['WEBAUTHN_RP_NAME'] ?? 'Ayo',
            $_ENV['WEBAUTHN_RP_ID'] ?? 'ayo.jobivoire.com',
            null,
            true // useBase64UrlEncoding — les champs binaires (challenge, ids) voyagent en JSON base64url avec le front
        );
    }

    /** POST /webauthn/register/options — le compte est déjà connecté (JWT), on lui propose d'ajouter une clé. */
    public function registerOptions(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::connection();

        $user = $db->prepare('SELECT email, first_name, last_name FROM users WHERE id = ?');
        $user->execute([$userId]);
        $user = $user->fetch();

        $existing = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
        $existing->execute([$userId]);
        $excludeIds = array_map(
            fn ($row) => $this->b64urlDecode($row['credential_id']),
            $existing->fetchAll()
        );

        $webauthn = $this->webauthn();
        $args = $webauthn->getCreateArgs(
            (string) $userId,
            $user['email'],
            trim("{$user['first_name']} {$user['last_name']}"),
            60,
            true,  // requireResidentKey — passkey détectable, prépare une future connexion sans email
            true,  // requireUserVerification — impose la vérification biométrique/PIN, pas juste la présence
            null,
            $excludeIds
        );

        $this->storeChallenge($userId, $webauthn->getChallenge()->getBinaryString());

        return JsonResponse::ok($response, ['publicKey' => $args->publicKey]);
    }

    /** POST /webauthn/register/verify */
    public function registerVerify(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        foreach (['clientDataJSON', 'attestationObject'] as $field) {
            if (empty($body[$field])) {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        $challenge = $this->consumeChallenge($userId);
        if ($challenge === null) {
            return JsonResponse::error($response, 400, 'Défi expiré ou introuvable — relance l\'activation');
        }

        try {
            $data = $this->webauthn()->processCreate(
                $this->b64urlDecode($body['clientDataJSON']),
                $this->b64urlDecode($body['attestationObject']),
                ByteBuffer::fromBase64Url($challenge),
                true
            );
        } catch (WebAuthnException $e) {
            return JsonResponse::error($response, 400, 'Vérification de la clé échouée', $e->getMessage());
        }

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, label) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $this->b64urlEncode($data->credentialId),
            $data->credentialPublicKey,
            $data->signatureCounter ?? 0,
            $body['label'] ?? null,
        ]);

        return JsonResponse::ok($response, ['registered' => true], 201);
    }

    /** GET /webauthn/credentials — clés déjà activées sur ce compte (page Compte). */
    public function list(Request $request, Response $response): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, label, created_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([(int) $request->getAttribute('user_id')]);

        return JsonResponse::ok($response, ['credentials' => $stmt->fetchAll()]);
    }

    /** DELETE /webauthn/credentials/{id} */
    public function delete(Request $request, Response $response, array $routeArgs): Response
    {
        $stmt = Database::connection()->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?');
        $stmt->execute([$routeArgs['id'], (int) $request->getAttribute('user_id')]);

        return JsonResponse::ok($response, ['deleted' => $stmt->rowCount() > 0]);
    }

    /** POST /webauthn/login/options — non authentifié, identifie le compte par email. */
    public function loginOptions(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (empty($body['email']) || !is_string($body['email'])) {
            return JsonResponse::error($response, 422, 'Email requis');
        }

        $db = Database::connection();
        $user = $db->prepare('SELECT id FROM users WHERE email = ?');
        $user->execute([$body['email']]);
        $userId = $user->fetchColumn();

        $credentials = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
        $credentials->execute([$userId ?: 0]);
        $credentialIds = array_map(
            fn ($row) => $this->b64urlDecode($row['credential_id']),
            $credentials->fetchAll()
        );

        if ($userId === false || $credentialIds === []) {
            // Même erreur générique qu'un login classique raté — n'indique jamais si l'email existe.
            return JsonResponse::error($response, 401, 'Aucune clé biométrique disponible pour ce compte');
        }

        $webauthn = $this->webauthn();
        $args = $webauthn->getGetArgs($credentialIds, 60, true, true, true, true, true, true);
        $this->storeChallenge((int) $userId, $webauthn->getChallenge()->getBinaryString());

        return JsonResponse::ok($response, ['publicKey' => $args->publicKey]);
    }

    /** POST /webauthn/login/verify */
    public function loginVerify(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        foreach (['email', 'id', 'clientDataJSON', 'authenticatorData', 'signature'] as $field) {
            if (empty($body[$field]) || !is_string($body[$field])) {
                return JsonResponse::error($response, 422, 'Champ manquant', $field);
            }
        }

        $db = Database::connection();
        $user = $db->prepare('SELECT id, role FROM users WHERE email = ?');
        $user->execute([$body['email']]);
        $user = $user->fetch();

        if ($user === false) {
            return JsonResponse::error($response, 401, 'Identifiants invalides');
        }

        $credential = $db->prepare(
            'SELECT id, public_key, sign_count FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?'
        );
        $credential->execute([$user['id'], $body['id']]);
        $credential = $credential->fetch();

        $challenge = $this->consumeChallenge((int) $user['id']);

        if ($credential === false || $challenge === null) {
            return JsonResponse::error($response, 401, 'Identifiants invalides');
        }

        try {
            $authenticatorDataRaw = $this->b64urlDecode($body['authenticatorData']);

            $this->webauthn()->processGet(
                $this->b64urlDecode($body['clientDataJSON']),
                $authenticatorDataRaw,
                $this->b64urlDecode($body['signature']),
                $credential['public_key'],
                ByteBuffer::fromBase64Url($challenge),
                (int) $credential['sign_count'],
                true
            );
        } catch (WebAuthnException $e) {
            return JsonResponse::error($response, 401, 'Vérification échouée', $e->getMessage());
        }

        $newSignCount = (new \lbuchs\WebAuthn\Attestation\AuthenticatorData($authenticatorDataRaw))->getSignCount();
        $db->prepare('UPDATE webauthn_credentials SET sign_count = ? WHERE id = ?')
            ->execute([$newSignCount, $credential['id']]);

        return JsonResponse::ok($response, [
            'user_id' => (int) $user['id'],
            'token' => Jwt::issue((int) $user['id'], $user['role']),
        ]);
    }

    private function storeChallenge(int $userId, string $challengeBinary): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM webauthn_challenges WHERE expires_at < NOW()')->execute();
        $db->prepare('INSERT INTO webauthn_challenges (user_id, challenge, expires_at) VALUES (?, ?, NOW() + INTERVAL 5 MINUTE)')
            ->execute([$userId, $this->b64urlEncode($challengeBinary)]);
    }

    /** Renvoie le défi (base64url) et le supprime — usage unique. */
    private function consumeChallenge(int $userId): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, challenge FROM webauthn_challenges WHERE user_id = ? AND expires_at >= NOW() ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $db->prepare('DELETE FROM webauthn_challenges WHERE id = ?')->execute([$row['id']]);

        return $row['challenge'];
    }

    private function b64urlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $b64url): string
    {
        return (string) base64_decode(strtr($b64url, '-_', '+/') . str_repeat('=', (4 - strlen($b64url) % 4) % 4));
    }
}
