<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;

final class Jwt
{
    /**
     * Durée de vie absolue d'une session, indépendante des rafraîchissements.
     *
     * Sans elle, `/auth/refresh` réémettait un jeton à l'infini tant que le précédent était
     * encore valide : un jeton volé (extension malveillante, poste partagé, XSS) restait
     * exploitable pour toujours, il suffisait de le rafraîchir toutes les 25 minutes. Le claim
     * `sid_iat` fige l'instant d'ouverture de session et n'est jamais repoussé lors d'un
     * rafraîchissement — au bout de JWT_SESSION_MAX_DAYS, il faut se reconnecter.
     */
    private const DEFAULT_SESSION_MAX_DAYS = 30;
    private const DEFAULT_TTL_MINUTES = 30;

    /**
     * @param int|null $sessionStartedAt Instant d'ouverture de session à conserver lors d'un
     *                                   rafraîchissement ; null pour une nouvelle session.
     */
    public static function issue(int $userId, string $role, ?int $sessionStartedAt = null): string
    {
        $now = time();
        $ttlMinutes = (int) ($_ENV['JWT_TTL_MINUTES'] ?? self::DEFAULT_TTL_MINUTES);

        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'sid_iat' => $sessionStartedAt ?? $now,
            'exp' => $now + max(1, $ttlMinutes) * 60,
        ];

        return FirebaseJwt::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }

    /**
     * @return array{sub:int, role:string, sid_iat:int}|null
     */
    public static function verify(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        // Jetons émis avant l'introduction du claim : on les traite comme des sessions
        // ouvertes à leur émission plutôt que de déconnecter tout le monde au déploiement.
        $sessionStartedAt = isset($decoded->sid_iat) ? (int) $decoded->sid_iat : (int) ($decoded->iat ?? time());

        if (self::sessionExpired($sessionStartedAt)) {
            return null;
        }

        return [
            'sub' => (int) $decoded->sub,
            'role' => (string) $decoded->role,
            'sid_iat' => $sessionStartedAt,
        ];
    }

    public static function sessionExpired(int $sessionStartedAt): bool
    {
        $maxDays = (int) ($_ENV['JWT_SESSION_MAX_DAYS'] ?? self::DEFAULT_SESSION_MAX_DAYS);

        return $sessionStartedAt + max(1, $maxDays) * 86400 < time();
    }
}
