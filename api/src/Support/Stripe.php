<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Stripe\StripeClient;

final class Stripe
{
    private static ?StripeClient $client = null;

    public static function client(): StripeClient
    {
        if (self::$client === null) {
            self::$client = new StripeClient($_ENV['STRIPE_SECRET_KEY']);
        }

        return self::$client;
    }
}
