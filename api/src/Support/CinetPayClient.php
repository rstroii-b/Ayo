<?php

declare(strict_types=1);

namespace Saveurs\Support;

use CinetPay\CinetPay;
use CinetPay\Country;

final class CinetPayClient
{
    private static ?CinetPay $client = null;

    /** Renvoie null si les identifiants CinetPay ne sont pas configurés (zone XOF pas encore prête en prod). */
    public static function client(): ?CinetPay
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $apiKey = $_ENV['CINETPAY_API_KEY'] ?? '';
        $apiPassword = $_ENV['CINETPAY_API_PASSWORD'] ?? '';

        if ($apiKey === '' || $apiPassword === '') {
            return null;
        }

        $country = Country::from($_ENV['CINETPAY_COUNTRY'] ?? 'CI');
        $isProduction = ($_ENV['CINETPAY_ENV'] ?? 'sandbox') === 'production';

        self::$client = $isProduction
            ? CinetPay::production($apiKey, $apiPassword, $country)
            : CinetPay::sandbox($apiKey, $apiPassword, $country);

        return self::$client;
    }
}
