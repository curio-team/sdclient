<?php

namespace Curio\SdClient\Tests\Concerns;

use Curio\SdClient\HttpClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

trait InteractsWithSdLogin
{
    private array $guzzleHistory = [];

    private function buildSignedTokens(string $userType = 'teacher', ?string $audience = null): object
    {
        $secret = config('sdclient.client_secret');

        $config = Configuration::forSymmetricSigner(
            new Sha256,
            InMemory::plainText($secret)
        );

        $now = new \DateTimeImmutable;

        $idToken = $config->builder()
            ->permittedFor($audience ?? config('sdclient.client_id'))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('user', json_encode([
                'id' => 'user-123',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'type' => $userType,
            ]))
            ->getToken($config->signer(), $config->signingKey());

        $accessToken = $config->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+1 hour'))
            ->getToken($config->signer(), $config->signingKey());

        return (object) [
            'id_token' => $idToken->toString(),
            'access_token' => $accessToken->toString(),
            'refresh_token' => 'fake-refresh-token',
        ];
    }

    private function mockHttpClientFactory(array $responses): void
    {
        $this->guzzleHistory = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->guzzleHistory));
        $mockClient = new Client(['handler' => $handlerStack]);

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('make')->willReturn($mockClient);

        $this->app->instance(HttpClientFactory::class, $factory);
    }

    /**
     * Starts a login like a browser would, returning the authorize URL's query parameters.
     */
    private function startLoginQuery(): array
    {
        $location = $this->get('/sdclient/redirect')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Starts a login like a browser would, returning the state the callback must include.
     */
    private function startLogin(): string
    {
        return $this->startLoginQuery()['state'];
    }
}
