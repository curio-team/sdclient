<?php

namespace Curio\SdClient\Tests\Unit;

use Curio\SdClient\HttpClientFactory;
use Curio\SdClient\SdApi;
use Curio\SdClient\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SdApiTest extends TestCase
{
    private array $guzzleHistory = [];

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
        $this->guzzleHistory = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->guzzleHistory));
        $mockClient = new Client(['handler' => $handlerStack]);

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('make')->willReturn($mockClient);

        return $factory;
    }

    public function test_get_aborts_without_access_token()
    {
        $this->expectException(HttpException::class);

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

    public function test_get_sends_correct_api_request()
    {
        $accessToken = $this->buildAccessToken();
        session()->put('access_token', $accessToken);

        $factory = $this->mockHttpClientFactory([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $api = new SdApi($factory);
        $api->get('/user');

        $this->assertCount(1, $this->guzzleHistory);

        $request = $this->guzzleHistory[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://api.curio.codes/user', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
    }

    public function test_get_prepends_slash_to_endpoint()
    {
        $accessToken = $this->buildAccessToken();
        session()->put('access_token', $accessToken);

        $factory = $this->mockHttpClientFactory([
            new Response(200, [], json_encode([])),
        ]);

        $api = new SdApi($factory);
        $api->get('courses');

        $request = $this->guzzleHistory[0]['request'];
        $this->assertEquals('https://api.curio.codes/courses', (string) $request->getUri());
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

    public function test_refresh_sends_correct_request()
    {
        $expiredToken = $this->buildAccessToken('-1 hour');
        $freshToken = $this->buildAccessToken('+1 hour');

        session()->put('access_token', $expiredToken);
        session()->put('refresh_token', 'my-refresh-token');

        $factory = $this->mockHttpClientFactory([
            new Response(200, [], json_encode([
                'access_token' => $freshToken,
                'refresh_token' => 'new-refresh',
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $api = new SdApi($factory);
        $api->get('/any');

        // First request should be the refresh token exchange
        $refreshRequest = $this->guzzleHistory[0]['request'];
        $this->assertEquals('POST', $refreshRequest->getMethod());
        $this->assertEquals('https://login.example.com/oauth/token', (string) $refreshRequest->getUri());

        $body = (string) $refreshRequest->getBody();
        parse_str($body, $params);
        $this->assertEquals('refresh_token', $params['grant_type']);
        $this->assertEquals('my-refresh-token', $params['refresh_token']);
        $this->assertEquals(config('sdclient.client_id'), $params['client_id']);
        $this->assertEquals(config('sdclient.client_secret'), $params['client_secret']);
    }

    public function test_refresh_failure_aborts_with_redirect()
    {
        $expiredToken = $this->buildAccessToken('-1 hour');

        session()->put('access_token', $expiredToken);
        session()->put('refresh_token', 'bad-refresh-token');

        $factory = $this->mockHttpClientFactory([
            new Response(401, [], json_encode(['error' => 'invalid_grant'])),
        ]);

        $this->app->instance(HttpClientFactory::class, $factory);

        try {
            $api = new SdApi($factory);
            $api->get('/some-endpoint');
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $e) {
            $this->assertEquals(302, $e->getStatusCode());
            $headers = $e->getHeaders();
            $this->assertArrayHasKey('Location', $headers);
            $this->assertStringContainsString('login.example.com/oauth/authorize', $headers['Location']);
        }
    }
}
