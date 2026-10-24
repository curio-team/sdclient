<?php

namespace Curio\SdClient\Tests\Feature;

use App\Models\User;
use Curio\SdClient\SdClientController;
use Curio\SdClient\Tests\Concerns\InteractsWithSdLogin;
use Curio\SdClient\Tests\TestCase;
use GuzzleHttp\Psr7\Response;

/**
 * Regression tests for security issues in the OAuth flow.
 */
class SdClientSecurityTest extends TestCase
{
    use InteractsWithSdLogin;

    public function test_redirect_includes_state_and_pkce_challenge()
    {
        $query = $this->startLoginQuery();

        $this->assertGreaterThanOrEqual(40, strlen($query['state']));
        $this->assertEquals('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
    }

    public function test_redirect_uses_a_new_state_every_time()
    {
        $this->assertNotEquals($this->startLogin(), $this->startLogin());
    }

    public function test_redirect_uri_is_url_encoded()
    {
        // An unencoded '&' in the callback URL would otherwise inject extra parameters into the authorize request
        url()->forceRootUrl('http://app.example.com/sub?x=1&client_id=evil');

        $location = app(SdClientController::class)->redirectUrl();
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertEquals('test-client-id', $query['client_id']);
        $this->assertStringStartsWith('http://app.example.com/sub?x=1&client_id=evil', $query['redirect_uri']);
    }

    public function test_callback_without_state_is_rejected()
    {
        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
        ]);

        $response = $this->get('/sdclient/callback?code=attacker-code');

        $response->assertRedirect('/sdclient/error');
        $response->assertSessionHas('sdclient.error', 'invalid_state');
        $this->assertCount(0, $this->guzzleHistory, 'The code must not be exchanged without a valid state');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_with_state_from_another_session_is_rejected()
    {
        // Login CSRF: the attacker started a login in their own browser and sends their callback URL to a victim
        $this->startLogin();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
        ]);

        $response = $this->get('/sdclient/callback?code=attacker-code&state=' . str_repeat('a', 40));

        $response->assertRedirect('/sdclient/error');
        $response->assertSessionHas('sdclient.error', 'invalid_state');
        $this->assertCount(0, $this->guzzleHistory);
        $this->assertGuest();
    }

    public function test_callback_with_array_state_is_rejected()
    {
        $this->startLogin();

        $response = $this->get('/sdclient/callback?code=attacker-code&state[]=x');

        $response->assertRedirect('/sdclient/error');
        $response->assertSessionHas('sdclient.error', 'invalid_state');
        $this->assertGuest();
    }

    public function test_state_can_only_be_used_once()
    {
        $state = $this->startLogin();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
        ]);

        $this->get('/sdclient/callback?code=test-auth-code&state=' . $state)
            ->assertRedirect('/sdclient/ready');

        $this->get('/sdclient/callback?code=test-auth-code&state=' . $state)
            ->assertRedirect('/sdclient/error');

        $this->assertCount(1, $this->guzzleHistory);
    }

    public function test_logins_started_in_multiple_tabs_all_work()
    {
        $firstState = $this->startLogin();
        $secondState = $this->startLogin();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
        ]);

        $this->get('/sdclient/callback?code=test-auth-code&state=' . $secondState)
            ->assertRedirect('/sdclient/ready');
        $this->get('/sdclient/callback?code=test-auth-code&state=' . $firstState)
            ->assertRedirect('/sdclient/ready');
    }

    public function test_pending_logins_are_capped()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->startLogin();
        }

        $this->assertCount(5, session(SdClientController::PENDING_SESSION_KEY));
    }

    public function test_crafted_error_callback_does_not_reach_the_error_page()
    {
        $response = $this->get('/sdclient/callback?error=access_denied&error_description=' . urlencode('<script>alert(1)</script>'));

        $response->assertRedirect('/sdclient/error');
        $response->assertSessionHas('sdclient.error', 'invalid_state');
        $this->assertStringNotContainsString('<script>', (string) session('sdclient.error_description'));
    }

    public function test_token_exchange_sends_matching_pkce_code_verifier()
    {
        $query = $this->startLoginQuery();

        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens())),
        ]);

        $this->get('/sdclient/callback?code=test-auth-code&state=' . $query['state']);

        parse_str((string) $this->guzzleHistory[0]['request']->getBody(), $params);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $params['code_verifier'], true)), '+/', '-_'), '=');

        $this->assertEquals($query['code_challenge'], $expectedChallenge);
    }

    public function test_id_token_for_another_client_is_rejected()
    {
        $this->mockHttpClientFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->buildSignedTokens('admin', 'other-client-id'))),
        ]);

        $response = $this->get('/sdclient/callback?code=test-auth-code&state=' . $this->startLogin());

        $response->assertStatus(400);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_logout_forgets_tokens()
    {
        $user = User::create([
            'id' => 'user-123',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'type' => 'teacher',
        ]);

        $this->actingAs($user)
            ->withSession([
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
            ]);

        $this->get('/sdclient/logout')->assertRedirect('/sdclient/ready');

        $this->assertGuest();
        $this->assertNull(session('access_token'));
        $this->assertNull(session('refresh_token'));
    }
}
