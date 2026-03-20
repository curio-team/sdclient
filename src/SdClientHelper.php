<?php

namespace Curio\SdClient;

use DateTimeZone;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\ValidAt;

class SdClientHelper
{
    private static $cachedConfig = null;

    public static function resetCache(): void
    {
        self::$cachedConfig = null;
    }

    public static function getTokenConfig()
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $client_id = config('sdclient.client_secret');

        if ($client_id == null) {
            abort(500, 'Please set SD_CLIENT_ID and SD_CLIENT_SECRET in .env file.');
        }

        self::$cachedConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText('')
        );

        self::$cachedConfig->setValidationConstraints(
            new ValidAt(
                new SystemClock(
                    new DateTimeZone(\date_default_timezone_get()),
                ),
                // Fixes occasional "The token was issued in the future" when we're slow (e.g: when debugging with dd)
                // Gives us a 1 minute leeway
                new \DateInterval('PT1M')
            ),
            new SignedWith(new Sha256(), InMemory::plainText($client_id))
        );

        return self::$cachedConfig;
    }
}
