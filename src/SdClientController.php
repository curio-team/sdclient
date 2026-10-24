<?php

namespace Curio\SdClient;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;

class SdClientController extends Controller
{
    /**
     * Session key holding the pending authorization requests (state => PKCE code verifier).
     */
    public const PENDING_SESSION_KEY = 'sdclient.pending_authorizations';

    /**
     * How many pending authorization requests to remember, so logging in from multiple tabs at once still works.
     */
    private const MAX_PENDING = 5;

    private HttpClientFactory $httpClientFactory;

    public function __construct(HttpClientFactory $httpClientFactory)
    {
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * @return non-empty-string
     */
    private function clientId(): string
    {
        /** @var string|null $client_id */
        $client_id = config('sdclient.client_id');

        if (! is_string($client_id) || $client_id === '') {
            abort(500, 'Please set SD_CLIENT_ID and SD_CLIENT_SECRET in .env file.');
        }

        return $client_id;
    }

    public function redirectUrl()
    {
        $client_id = $this->clientId();

        // The state binds the callback to this browser session, preventing login CSRF (an attacker
        // logging a victim in to the attacker's account). PKCE binds the authorization code to it,
        // so a leaked or injected code can't be redeemed.
        $state = Str::random(40);
        $codeVerifier = Str::random(128);

        $pending = session(self::PENDING_SESSION_KEY, []);
        $pending[$state] = $codeVerifier;
        session()->put(self::PENDING_SESSION_KEY, array_slice($pending, -self::MAX_PENDING, null, true));

        $root = config('sdclient.url');
        $query = http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => url('sdclient/callback'),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        return "$root/oauth/authorize?$query";
    }

    public function redirect()
    {
        $url = $this->redirectUrl();

        return redirect($url);
    }

    public function callback(Request $request)
    {
        $root = config('sdclient.url');
        $http = $this->httpClientFactory->make();

        $codeVerifier = $this->pullCodeVerifier($request);

        if ($codeVerifier === null) {
            return redirect('/sdclient/error')
                ->with('sdclient.error', 'invalid_state')
                ->with('sdclient.error_description', 'The login request is invalid or has expired, please try again.');
        }

        if (isset($request->error)) {
            return redirect('/sdclient/error')
                ->with('sdclient.error', $request->error)
                ->with('sdclient.error_description', $request->error_description);
        }

        try {
            // Exchange authcode for tokens
            $response = $http->post("$root/oauth/token", [
                'form_params' => [
                    'client_id' => config('sdclient.client_id'),
                    'client_secret' => config('sdclient.client_secret'),
                    'redirect_uri' => url('sdclient/callback'),
                    'code' => $request->code,
                    'code_verifier' => $codeVerifier,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $config = SdClientHelper::getTokenConfig();
            $tokens = json_decode((string) $response->getBody());

            try {
                $token = $config->parser()->parse($tokens->id_token);
            } catch (Exception $exception) {
                abort(400, $exception->getMessage());
            }

            try {
                // The id_token must have been issued to this app, not to another client
                $constraints = $config->validationConstraints();
                $constraints[] = new PermittedFor($this->clientId());
                $config->validator()->assert($token, ...$constraints);
            } catch (RequiredConstraintsViolated $exception) {
                abort(400, $exception->getMessage());
            }

            /** @var Plain $token */
            $claims = $token->claims();
            $token_user = $claims->get('user');
            $token_user = json_decode($token_user);

            // Check if user may login
            if (config('sdclient.app_for') == 'teachers' && ! in_array($token_user->type, ['teacher', 'admin'])) {
                abort(403, 'Oops: This app is only available to teacher-accounts!');
            }

            if (config('sdclient.app_for') == 'admins' && $token_user->type !== 'admin') {
                abort(403, 'Oops: This app is only available to admin-accounts!');
            }

            // Create new user if not exists
            $userModel = config('sdclient.user_model', User::class);
            $user = $userModel::find($token_user->id);
            if (! $user) {
                /** @var \Illuminate\Foundation\Auth\User $user */
                $user = new $userModel;
                $user->setAttribute('id', $token_user->id);
                $user->setAttribute('name', $token_user->name);
                $user->setAttribute('email', $token_user->email);
                $user->setAttribute('type', $token_user->type);
                $user->save();
            } else {
                // Update the user information if it exists
                $user->setAttribute('name', $token_user->name);
                $user->setAttribute('email', $token_user->email);
                $user->setAttribute('type', $token_user->type);
                $user->save();
            }

            Auth::login($user, true);

            // Store access- and refresh-token in session
            $request->session()->put('access_token', $tokens->access_token);
            $request->session()->put('refresh_token', $tokens->refresh_token);

            return redirect('/sdclient/ready');
        } catch (BadResponseException $e) {
            abort(500, 'Unable to retrieve access token: '.$e->getResponse()->getBody());
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();

        // Also drops the access- and refresh-token, so the next user of this browser can't use them
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/sdclient/ready');
    }

    /**
     * Returns the PKCE code verifier belonging to the callback's state, forgetting it so each state is only usable once.
     */
    private function pullCodeVerifier(Request $request): ?string
    {
        $state = $request->query('state');
        $pending = $request->session()->get(self::PENDING_SESSION_KEY, []);

        if (! is_string($state) || ! is_array($pending) || ! isset($pending[$state])) {
            return null;
        }

        $codeVerifier = $pending[$state];
        unset($pending[$state]);
        $request->session()->put(self::PENDING_SESSION_KEY, $pending);

        return $codeVerifier;
    }

    private static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
