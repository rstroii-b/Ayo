<?php

declare(strict_types=1);

namespace Saveurs\Support;

/**
 * Plafonnement des tentatives sensibles (connexion, inscription).
 *
 * `/auth/login` n'avait aucune limite : rien n'empêchait un script d'essayer des milliers de
 * mots de passe à la suite. bcrypt ralentit l'attaquant, il ne l'arrête pas — et sur un parc
 * de comptes réels, quelques mots de passe faibles suffisent.
 *
 * Deux compteurs indépendants, tous deux nécessaires :
 *   - par identifiant (email) : protège un compte précis qu'on cible depuis plusieurs machines ;
 *   - par adresse IP : protège l'ensemble des comptes d'un balayage depuis une seule machine.
 *
 * Implémentation volontairement simple (une table, pas de Redis) : la plateforme tient sur un
 * seul serveur, et une limite imparfaite vaut infiniment mieux que pas de limite. À remplacer
 * par un compteur en mémoire partagée le jour où l'API tourne sur plusieurs instances.
 */
final class RateLimiter
{
    /** Tentatives échouées tolérées avant blocage, et durée de la fenêtre glissante. */
    private const MAX_ATTEMPTS_PER_IDENTIFIER = 8;
    private const MAX_ATTEMPTS_PER_IP = 30;
    private const WINDOW_MINUTES = 15;

    /**
     * La tentative est-elle autorisée ? Renvoie le nombre de secondes à attendre si elle est
     * bloquée, null sinon.
     */
    public static function retryAfter(string $scope, string $identifier, string $ip): ?int
    {
        $db = Database::connection();

        $blocked = self::countRecentFailures($db, $scope, $identifier) >= self::MAX_ATTEMPTS_PER_IDENTIFIER
            || self::countRecentFailures($db, $scope . ':ip', $ip) >= self::MAX_ATTEMPTS_PER_IP;

        if (!$blocked) {
            return null;
        }

        // Temps restant avant que la plus ancienne tentative de la fenêtre n'en sorte.
        $stmt = $db->prepare(
            'SELECT MIN(created_at) FROM auth_attempts
             WHERE scope IN (?, ?) AND identifier IN (?, ?) AND succeeded = 0
               AND created_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$scope, $scope . ':ip', $identifier, $ip]);
        $oldest = $stmt->fetchColumn();

        if ($oldest === false || $oldest === null) {
            return 60;
        }

        $elapsed = time() - strtotime((string) $oldest);

        return max(1, self::WINDOW_MINUTES * 60 - $elapsed);
    }

    /** Enregistre l'issue d'une tentative. Un succès purge les échecs accumulés pour cet identifiant. */
    public static function record(string $scope, string $identifier, string $ip, bool $succeeded): void
    {
        $db = Database::connection();

        if ($succeeded) {
            $clear = $db->prepare('DELETE FROM auth_attempts WHERE scope = ? AND identifier = ?');
            $clear->execute([$scope, $identifier]);
        }

        $stmt = $db->prepare('INSERT INTO auth_attempts (scope, identifier, succeeded) VALUES (?, ?, ?)');
        $stmt->execute([$scope, mb_substr($identifier, 0, 190), $succeeded ? 1 : 0]);
        $stmt->execute([$scope . ':ip', mb_substr($ip, 0, 190), $succeeded ? 1 : 0]);

        // Purge opportuniste — évite une tâche planifiée pour une table de log éphémère.
        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM auth_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    private static function countRecentFailures(\PDO $db, string $scope, string $identifier): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM auth_attempts
             WHERE scope = ? AND identifier = ? AND succeeded = 0
               AND created_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$scope, mb_substr($identifier, 0, 190)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Adresse IP de l'appelant. `X-Forwarded-For` n'est lu que si l'application est
     * explicitement déclarée derrière un proxy de confiance (TRUSTED_PROXY=true) : sinon
     * n'importe qui contourne le plafond en forgeant l'en-tête.
     */
    public static function clientIp(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        if (($_ENV['TRUSTED_PROXY'] ?? 'false') === 'true') {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return (string) ($server['REMOTE_ADDR'] ?? 'unknown');
    }
}
