<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;

final class Jwt
{
    public static function issue(int $userId, string $role): string
    {
        $now = time();

        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + ((int) $_ENV['JWT_TTL_MINUTES'] * 60),
        ];

        return FirebaseJwt::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }

    /**
     * @return array{sub:int, role:string}|null
     */
    public static function verify(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

            return ['sub' => (int) $decoded->sub, 'role' => $decoded->role];
        } catch (\Throwable) {
            return null;
        }
    }
}
