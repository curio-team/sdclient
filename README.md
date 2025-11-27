# 🔑 SD Client

A Laravel package for use with the [🔐 sdlogin OpenID connect server](https://github.com/curio-team/sdlogin).

## 🚀 Using this package

> [!WARNING]
> Please make sure your app is using _https_, to prevent unwanted exposure of token, secrets, etc.

To use `sdclient` in your project:

1. In your laravel project run: `composer require curio/sdclient`

2. Set these keys in your .env file:

    * `SD_CLIENT_ID`
    * `SD_CLIENT_SECRET`
    * `SD_API_LOG` _(optional)_
        * _Default:_ `no`
        * Set to `yes` to make SdClient log all usage of access_tokens and refresh_tokens to the default log-channel.
    * `SD_APP_FOR` _(optional)_
        * _Default:_ `teachers`
        * This key determines if students can login to your application.
        * May be one of:
    * `all`: everyone can login, you may restrict access using guards or middleware.
    * `teachers`: a student will be completely blocked and no user will be created when they try to login.
    * `SD_USE_MIGRATION` _(optional)_
        * _Default:_ `yes`
        * Set to no if you want to use your own migration instead of the users migration this package provides
    * `SD_SSL_VERIFYPEER` _(optional)_
        * _Default:_ `yes`
        * Set to `no` if you want to disable SSL verification. This is only recommended for during development and only on trusted networks.

3. Alter your User model and add the lines: `public $incrementing = false;` and `protected $keyType = 'string';`

4. _(Recommended)_ Remove any default users-migration from your app, because SdClient will conflict with it. Do _not_ remove the user-model. If you want to keep using your own migration, in your .env file set: `SD_USE_MIGRATION=no`.

    _Note that (unlike default user migrations) SD users have a string as their primary key. Any foreign keys pointing to the users table should also be of type string._

5. Lastly, run `php artisan migrate`.

Read the required implementations below to see how to redirect your users to the login-server and how to catch the after-login redirect.

## 🔨 Required implementations

> [!NOTE]
> SdClient is not compatible in combination with Laravel's `make:auth` command.

### 1️⃣ Letting your users login

Redirect your users to `http://yoursite/sdclient/redirect`. From here `sdclient` will send your user to _sdlogin_ for authentication.

> **Example:**
>
> Implement a named route that will serve your users with a button or direct redirect to `/sdclient/redirect.`:
>
> ```php
> Route::get('/login', function() {
>   return redirect('/sdclient/redirect');
> })->name('login');
> ```

### 2️⃣ Catch the after-login redirect

The _sdlogin_ server will ask the user if they want to allow your application to access their data. After the user has made their choice, they will be redirected to the `/sdclient/ready` or `/sdclient/error` route in your application.

#### Handling success (`/sdclient/ready`)

After confirming a successful login with _sdlogin_, the `sdclient` package will redirect you to `/sdclient/ready`.

> **Example:**
>
> Define a route in your applications `routes/web.php` file to handle this:
>
> ```php
> Route::get('/sdclient/ready', function() {
>   return redirect('/educations');
> });
> ```

#### Handling errors (`/sdclient/error`)

If the user denies access to your application, or if something else goes wrong, the user will be redirected to `/sdclient/error`. The error and error_description will be stored in the session (as `sdclient.error` and `sdclient.error_description` respectively).

> **Example:**
>
> Define a route in your applications `routes/web.php` file to handle this:
>
> ```php
> Route::get('/sdclient/error', function() {
>   $error = session('sdclient.error');
>   $error_description = session('sdclient.error_description');
>
>   return view('errors.sdclient', compact('error', 'error_description'));
>   // or simply:
>   // return 'There was an error signing in: ' . $error_description . ' (' . $error . ')<br><a href="/login">Try again</a>';
> });
> ```

### 3️⃣ Logging out

Send your user to `/sdclient/logout`.

> [!NOTE]
> A real logout cannot be accomplished at this time. If you log-out of your app, but are still logged-in to the _sdlogin_-server, this will have no effect.
> This is because the _sdlogin_-server is a single-sign-on server, and is designed to keep you logged in to all applications that use it.

## 📈 SdApi

Apart from being the central login-server, _login.amo.rocks_ also exposes an api. Please note this api is currently undocumented, although there are options to explore the api:

* Refer to _sdlogin_'s [routes/api.php](https://github.com/curio-team/sdlogin/blob/main/routes/api.php) file.
* Play around at [apitest.curio.codes](https://apitest.curio.codes/).

### SdClient API Interface

An example of calling the api through SdClient:

```php
namespace App\Http\Controllers;

use \Curio\SdClient\Facades\SdApi;

class MyController extends Controller
{
  // This method should be protected by the auth-middleware
  public function index()
  {
    $users = SdApi::get('users');
    return view('users.index')->with(compact('users'));
  }
}
```

**Known 'bug':** Currently the SdApi class doesn't check if the token expired but just refreshes it anytime you use it.

### `SdApi::get($endpoint)`

* Performs an HTTP-request like `GET https://api.curio.codes/$endpoint`.
* This method relies on a user being authenticated through the `sdclient` first. Only call this method from routes and/or controllers protected by the _auth_ middleware.
* Returns a Laravel-collection

## 👷‍♀️ Contributing

We welcome contributions from the community. Please see the [contributing guide](CONTRIBUTING.md) for more information.
