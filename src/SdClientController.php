<?php

namespace Curio\SdClient;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;

class SdClientController extends Controller
{
    private HttpClientFactory $httpClientFactory;

    public function __construct(HttpClientFactory $httpClientFactory)
    {
        $this->httpClientFactory = $httpClientFactory;
    }

    public function redirectUrl()
    {
        $client_id = config('sdclient.client_id');

        if ($client_id == null) {
            abort(500, 'Please set SD_CLIENT_ID and SD_CLIENT_SECRET in .env file.');
        }

        $callback_url = url('sdclient/callback');
        $root = config('sdclient.url');

        return "$root/oauth/authorize?client_id=$client_id&redirect_uri=$callback_url&response_type=code";
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

        if (isset($request->error)) {
            return redirect('/sdclient/error')
                ->with('sdclient.error', $request->error)
                ->with('sdclient.error_description', $request->error_description);
        }

        try {
            //Exchange authcode for tokens
            $response = $http->post("$root/oauth/token", [
                'form_params' => [
                    'client_id' => config('sdclient.client_id'),
                    'client_secret' => config('sdclient.client_secret'),
                    'redirect_uri' => url('sdclient/callback'),
                    'code' => $request->code,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $config = SdClientHelper::getTokenConfig();
            $tokens = json_decode((string) $response->getBody());

            try {
                $token = $config->parser()->parse($tokens->id_token);
            } catch (\Lcobucci\JWT\Exception $exception) {
                abort(400, $exception->getMessage());
            }

            try {
                $constraints = $config->validationConstraints();
                $config->validator()->assert($token, ...$constraints);
            } catch (RequiredConstraintsViolated $exception) {
                abort(400, $exception->getMessage());
            }

            /** @var \Lcobucci\JWT\Token\Plain $token */
            $claims = $token->claims();
            $token_user = $claims->get('user');
            $token_user = json_decode($token_user);

            //Check if user may login
            if (config('sdclient.app_for') == 'teachers' && $token_user->type != 'teacher') {
                abort(403, 'Oops: This app is only available to teacher-accounts!');
            }

            //Create new user if not exists
            $userModel = config('sdclient.user_model', \App\Models\User::class);
            $user = $userModel::find($token_user->id);
            if (! $user) {
                /** @var \Illuminate\Foundation\Auth\User $user */
                $user = new $userModel();
                $user->id = $token_user->id;
                $user->name = $token_user->name;
                $user->email = $token_user->email;
                $user->type = $token_user->type;
                $user->save();
            } else {
                // Update the user name if exists
                $user->name = $token_user->name;
                $user->save();
            }

            Auth::login($user);

            //Store access- and refresh-token in session
            $request->session()->put('access_token', $tokens->access_token);
            $request->session()->put('refresh_token', $tokens->refresh_token);

            return redirect('/sdclient/ready');
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            abort(500, 'Unable to retrieve access token: ' . $e->getResponse()->getBody());
        }
    }

    public function logout()
    {
        Auth::logout();

        return redirect('/sdclient/ready');
    }
}
