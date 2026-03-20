<?php

namespace Curio\SdClient;

use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;

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

        $client_secret = config('sdclient.client_secret');

        if ($client_secret == null) {
            abort(500, 'Please set SD_CLIENT_ID and SD_CLIENT_SECRET in .env file.');
        }

        $signingKey = InMemory::plainText($client_secret);

        self::$cachedConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            $signingKey
        )->withValidationConstraints(
            new StrictValidAt(
                new class(new DateTimeZone(\date_default_timezone_get())) implements ClockInterface {
                    public function __construct(private DateTimeZone $timezone) {}

                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable('now', $this->timezone);
                    }
                },
                // Fixes occasional "The token was issued in the future" when we're slow (e.g: when debugging with dd)
                // Gives us a 1 minute leeway
                new \DateInterval('PT1M')
            ),
            new SignedWith(new Sha256(), $signingKey)
        );

        return self::$cachedConfig;
    }
}
