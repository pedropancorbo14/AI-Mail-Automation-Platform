<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Conexiones de correo con OAuth (Fase 3). Registro de la app en Azure:
    // redirect URI = APP_URL/api/v1/mail-connections/oauth/microsoft/callback.
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        // "common" = cuentas de cualquier organización y personales.
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI', rtrim((string) env('APP_URL'), '/') . '/api/v1/mail-connections/oauth/microsoft/callback'),
    ],

    // Registro de la app en Google Cloud (OAuth client "Web application"):
    // redirect URI = APP_URL/api/v1/mail-connections/oauth/google/callback.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/') . '/api/v1/mail-connections/oauth/google/callback'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
