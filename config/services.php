<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'casdoor' => [
        'enabled' => filter_var(env('CASDOOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'issuer' => env('CASDOOR_ISSUER'),
        'client_id' => env('CASDOOR_CLIENT_ID'),
        'client_secret' => env('CASDOOR_CLIENT_SECRET'),
        'redirect' => env('CASDOOR_REDIRECT_URI', env('APP_URL').'/auth/casdoor/callback'),
        'scope' => env('CASDOOR_SCOPE', 'openid profile email'),
        'pending_ttl' => env('CASDOOR_PENDING_TTL', 10),
    ],

];
