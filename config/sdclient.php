<?php

use App\Models\User;

return [
    /**
     * The URL of the SD server, useful to override with a local server for development.
     */
    'url' => env('SD_DEV_URL', 'https://api.curio.codes'),

    /**
     * The client_id and client_secret of the application.
     * (As registered in curiologin)
     */
    'client_id' => env('SD_CLIENT_ID', null),
    'client_secret' => env('SD_CLIENT_SECRET', null),

    /**
     * Who this app is for. Options:
     *   'teachers' - allows teacher and admin accounts; blocks students
     *   'admins'   - allows admin accounts only; blocks teachers and students
     *   'all'      - allows everyone
     */
    'app_for' => env('SD_APP_FOR', 'teachers'),

    /**
     * The user model class used to find/create users on login.
     */
    'user_model' => env('SD_USER_MODEL', User::class),

    /**
     * Whether to use the migration file or not.
     */
    'use_migration' => env('SD_USE_MIGRATION', 'yes'),

    /**
     * Whether to log usage of token or not.
     */
    'api_log' => env('SD_API_LOG', 'no'),

    /**
     * Whether to verify the SSL certificate or not.
     */
    'ssl_verify_peer' => env('SD_SSL_VERIFYPEER', 'yes'),
];
