<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Journalisation structurée — une ligne JSON par entrée, dans api/storage/logs/ : hors du
 * webroot (comme les pièces KYC, voir KycStorage) et déjà ignoré par git.
 *
 * Deux canaux volontairement séparés :
 *   app()          — exploitation : erreurs applicatives, webhooks refusés, panne du temps réel…
 *                    rotation courte (LOG_MAX_FILES jours).
 *   transactions() — piste d'audit financière : un événement par mouvement d'argent
 *                    (paiement confirmé/refusé, virement envoyé/échoué). Rotation très longue,
 *                    c'est la trace qu'on relit en cas de litige ou de contrôle comptable.
 *
 * Écrire un log ne doit jamais faire tomber une requête : si le dossier n'est pas inscriptible,
 * on bascule sur stderr (récupéré par les logs du serveur web) au lieu de laisser Monolog jeter.
 */
final class Log
{
    private static ?LoggerInterface $app = null;
    private static ?LoggerInterface $transactions = null;
    private static ?string $requestId = null;

    public static function app(): LoggerInterface
    {
        return self::$app ??= self::build(
            'app',
            'app.log',
            self::levelFromEnv(),
            (int) ($_ENV['LOG_MAX_FILES'] ?? 14)
        );
    }

    public static function transactions(): LoggerInterface
    {
        return self::$transactions ??= self::build(
            'transactions',
            'transactions.log',
            Level::Info,
            (int) ($_ENV['LOG_TRANSACTIONS_MAX_FILES'] ?? 400)
        );
    }

    /**
     * Identifiant corrélant toutes les lignes d'une même requête (ou d'un même passage du
     * script de réconciliation) — indispensable pour relier une erreur CinetPay à l'appel
     * client qui l'a déclenchée. Réutilise X-Request-Id si un reverse-proxy en pose un.
     */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            $fromProxy = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
            self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $fromProxy) === 1
                ? $fromProxy
                : bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    public static function logDir(): string
    {
        $dir = __DIR__ . '/../../storage/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private static function build(string $channel, string $filename, Level $level, int $maxFiles): LoggerInterface
    {
        $logger = new Logger($channel);
        $dir = self::logDir();

        try {
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new \RuntimeException("Dossier de logs non inscriptible : {$dir}");
            }

            $handler = new RotatingFileHandler($dir . '/' . $filename, $maxFiles, $level);
        } catch (\Throwable) {
            $handler = new StreamHandler('php://stderr', $level);
        }

        $handler->setFormatter(new JsonFormatter());
        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['request_id'] = self::requestId();

            return $record;
        });

        return $logger;
    }

    private static function levelFromEnv(): Level
    {
        try {
            return Level::fromName((string) ($_ENV['LOG_LEVEL'] ?? 'info'));
        } catch (\Throwable) {
            return Level::Info;
        }
    }
}
