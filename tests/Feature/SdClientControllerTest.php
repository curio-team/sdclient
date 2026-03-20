<?php

namespace Curio\SdClient\Tests\Feature;

use App\Models\User;
use Curio\SdClient\HttpClientFactory;
use Curio\SdClient\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class SdClientControllerTest extends TestCase
{
    private array $guzzleHistory = [];

    private function buildSignedTokens(string $userType = 'teacher'): object
    {
        $secret = config('sdclient.client_secret');

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $now = new \DateTimeImmutable();

        $idToken = $config->builder()
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

    public function test_redirect_url_contains_correct_oauth_params()
    {
        $response = $this->get('/sdclient/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('login.example.com/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('redirect_uri=', $location);
        $this->assertStringContainsString('response_type=code', $location);
    }

    public function test_callback_with_error_redirects_to_error_page()
    {
        $response = $this->get('/sdclient/callback?error=access_denied&error_description=User+denied');

        $response->assertRedirect('/sdclient/error');
    }

    public function test_callback_sends_correct_token_exchange_request()
    {
        $tokens = $this->buildSignedTokens();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $this->get('/sdclient/callback?code=test-auth-code');

        $this->assertCount(1, $this->guzzleHistory);

        $request = $this->guzzleHistory[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://login.example.com/oauth/token', (string) $request->getUri());

        $body = (string) $request->getBody();
        parse_str($body, $params);
        $this->assertEquals('test-client-id', $params['client_id']);
        $this->assertEquals('test-client-secret', $params['client_secret']);
        $this->assertEquals('test-auth-code', $params['code']);
        $this->assertEquals('authorization_code', $params['grant_type']);
    }

    public function test_callback_exchanges_code_and_creates_user()
    {
        $tokens = $this->buildSignedTokens();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $this->assertDatabaseCount('users', 0);

        $response = $this->get('/sdclient/callback?code=test-auth-code');

        $response->assertRedirect('/sdclient/ready');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => 'user-123',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'type' => 'teacher',
        ]);
        $this->assertAuthenticatedAs(User::find('user-123'));
    }

    public function test_callback_updates_existing_user()
    {
        User::create([
            'id' => 'user-123',
            'name' => 'Old Name',
            'email' => 'test@example.com',
            'type' => 'teacher',
        ]);

        $tokens = $this->buildSignedTokens();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $response = $this->get('/sdclient/callback?code=test-auth-code');

        $response->assertRedirect('/sdclient/ready');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => 'user-123',
            'name' => 'Test User',
        ]);
    }

    public function test_callback_stores_tokens_in_session()
    {
        $tokens = $this->buildSignedTokens();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $this->get('/sdclient/callback?code=test-auth-code');

        $this->assertEquals($tokens->access_token, session('access_token'));
        $this->assertEquals($tokens->refresh_token, session('refresh_token'));
    }

    public function test_callback_with_student_account_for_teacher_app_is_forbidden()
    {
        $tokens = $this->buildSignedTokens('student');

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $response = $this->get('/sdclient/callback?code=test-auth-code');

        $response->assertStatus(403);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_allows_student_when_app_not_for_teachers()
    {
        config()->set('sdclient.app_for', 'everyone');
        $tokens = $this->buildSignedTokens('student');

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]);

        $response = $this->get('/sdclient/callback?code=test-auth-code');

        $response->assertRedirect('/sdclient/ready');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_with_bad_token_exchange_aborts()
    {
        $this->mockHttpClientFactory([
            new Response(400, [], json_encode(['error' => 'invalid_grant'])),
        ]);

        $response = $this->get('/sdclient/callback?code=bad-code');

        $response->assertStatus(500);
    }

    public function test_logout_redirects_and_logs_out()
    {
        $user = User::create([
            'id' => 'user-123',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'type' => 'teacher',
        ]);

        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        $response = $this->get('/sdclient/logout');

        $response->assertRedirect('/sdclient/ready');
        $this->assertGuest();
    }
}
