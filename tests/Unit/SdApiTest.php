<?php

namespace Curio\SdClient\Tests\Unit;

use Curio\SdClient\HttpClientFactory;
use Curio\SdClient\SdApi;
use Curio\SdClient\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class SdApiTest extends TestCase
{
    private function buildAccessToken(string $expiresModifier = '+1 hour'): string
    {
        $secret = config('sdclient.client_secret');

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $now = new \DateTimeImmutable();

        return $config->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify($expiresModifier))
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    private function mockHttpClientFactory(array $responses): HttpClientFactory
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('make')->willReturn($mockClient);

        return $factory;
    }

    public function test_get_aborts_without_access_token()
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $factory = $this->mockHttpClientFactory([]);
        $api = new SdApi($factory);
        $api->get('/user');
    }

    public function test_get_makes_api_call_with_valid_token()
    {
        $accessToken = $this->buildAccessToken();
        session()->put('access_token', $accessToken);

        $factory = $this->mockHttpClientFactory([
            new Response(200, [], json_encode(['name' => 'Test User'])),
        ]);

        $api = new SdApi($factory);
        $result = $api->get('/user');

        $this->assertEquals('Test User', $result['name']);
    }

    public function test_get_refreshes_expired_token()
    {
        $expiredToken = $this->buildAccessToken('-1 hour');
        $freshToken = $this->buildAccessToken('+1 hour');

        session()->put('access_token', $expiredToken);
        session()->put('refresh_token', 'fake-refresh-token');

        $factory = $this->mockHttpClientFactory([
            new Response(200, [], json_encode([
                'access_token' => $freshToken,
                'refresh_token' => 'new-refresh-token',
            ])),
            new Response(200, [], json_encode(['data' => 'success'])),
        ]);

        $api = new SdApi($factory);
        $result = $api->get('/some-endpoint');

        $this->assertEquals('success', $result['data']);
        $this->assertEquals('new-refresh-token', session('refresh_token'));
    }
}
