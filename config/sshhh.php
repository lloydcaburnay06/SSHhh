<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    'tagline' => env('APP_TAGLINE', 'Deployments so quiet, you can hear a server drop.'),

    /*
    |--------------------------------------------------------------------------
    | Credential vault
    |--------------------------------------------------------------------------
    |
    | SSH passwords, private keys, tokens and project variables are encrypted
    | with AES-256-GCM using a key derived from this master secret. When
    | DEPLOYER_SECRET_KEY is blank the key is read from (or generated into)
    | storage/secret.key. Changing it makes stored credentials unreadable.
    |
    */

    'secret_key' => env('DEPLOYER_SECRET_KEY'),

    'key_file' => storage_path('secret.key'),

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    */

    'auth' => [
        'device_cookie' => 'sshhh_device_token',
        'device_days' => 30,
        'max_failed_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log checker
    |--------------------------------------------------------------------------
    */

    'log_watch' => [
        'lock_file' => storage_path('log_watch.lock'),
        'run_log' => storage_path('log_watch.log'),
    ],

];
