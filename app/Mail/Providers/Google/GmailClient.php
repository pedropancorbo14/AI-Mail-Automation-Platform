<?php

namespace App\Mail\Providers\Google;

use App\Mail\OAuth\OAuthApiClient;
use Illuminate\Http\Client\Response;

/** Cliente HTTP de la Gmail API (v1) para el buzón de la cuenta conectada. */
class GmailClient extends OAuthApiClient
{
    public const string BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    protected function serviceName(): string
    {
        return 'Gmail';
    }

    protected function baseUrl(): string
    {
        return self::BASE_URL;
    }

    protected function errorDetail(Response $response): ?string
    {
        // Formato Google: {"error": {"code": 403, "status": "PERMISSION_DENIED", "message": "..."}}
        return $response->json('error.status');
    }
}
