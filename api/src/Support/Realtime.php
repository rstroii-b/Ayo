<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Pusher\Pusher;

final class Realtime
{
    private static ?Pusher $client = null;

    public static function client(): Pusher
    {
        if (self::$client === null) {
            self::$client = new Pusher(
                $_ENV['PUSHER_KEY'],
                $_ENV['PUSHER_SECRET'],
                $_ENV['PUSHER_APP_ID'],
                ['cluster' => $_ENV['PUSHER_CLUSTER'], 'useTLS' => true]
            );
        }

        return self::$client;
    }

    /** Ne bloque jamais une requête si Pusher est indisponible — le polling reste le filet de sécurité. */
    public static function trigger(string $channel, string $event, array $payload): void
    {
        try {
            self::client()->trigger($channel, $event, $payload);
        } catch (\Throwable) {
        }
    }
}
