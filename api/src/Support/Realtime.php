<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Pusher\Pusher;

final class Realtime
{
    /** Canal de supervision de toute la plateforme — réservé au rôle admin (voir RealtimeController). */
    public const ADMIN_CHANNEL = 'private-admin';

    private static ?Pusher $client = null;

    public static function client(): Pusher
    {
        if (self::$client === null) {
            self::$client = new Pusher(
                $_ENV['PUSHER_KEY'] ?? '',
                $_ENV['PUSHER_SECRET'] ?? '',
                $_ENV['PUSHER_APP_ID'] ?? '',
                ['cluster' => $_ENV['PUSHER_CLUSTER'] ?? 'eu', 'useTLS' => true]
            );
        }

        return self::$client;
    }

    /**
     * Ne bloque jamais une requête si Pusher est indisponible — le polling reste le filet de
     * sécurité côté client. La panne est en revanche tracée : sans ce log, le temps réel
     * pouvait être mort pendant des jours sans que personne ne s'en aperçoive, l'app continuant
     * de se rafraîchir toutes les 15-20 s.
     */
    public static function trigger(string $channel, string $event, array $payload): void
    {
        try {
            self::client()->trigger($channel, $event, $payload);
        } catch (\Throwable $e) {
            Log::app()->warning('realtime.trigger_failed', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Diffuse un événement de supervision (paiement, virement) au tableau de bord admin. */
    public static function notifyAdmins(string $event, array $payload): void
    {
        self::trigger(self::ADMIN_CHANNEL, $event, $payload);
    }
}
