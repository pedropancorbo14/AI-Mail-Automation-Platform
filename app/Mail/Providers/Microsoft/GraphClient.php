<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\OAuth\OAuthApiClient;
use Illuminate\Http\Client\Response;

/** Cliente HTTP de Microsoft Graph (v1.0) para el buzón de la cuenta conectada. */
class GraphClient extends OAuthApiClient
{
    public const string BASE_URL = 'https://graph.microsoft.com/v1.0';

    protected function serviceName(): string
    {
        return 'Microsoft';
    }

    protected function baseUrl(): string
    {
        return self::BASE_URL;
    }

    protected function errorDetail(Response $response): ?string
    {
        // Formato Graph: {"error": {"code": "ErrorItemNotFound", "message": "..."}}
        return $response->json('error.code');
    }

    /**
     * Sigue @odata.nextLink hasta reunir $limit elementos (o no haber más).
     *
     * @return list<array<string, mixed>>
     */
    public function collect(string $path, array $query, int $limit): array
    {
        $items = [];
        $page = $this->get($path, $query);

        while (true) {
            $items = [...$items, ...($page['value'] ?? [])];

            if (count($items) >= $limit || empty($page['@odata.nextLink'])) {
                return array_slice($items, 0, $limit);
            }

            $page = $this->get($page['@odata.nextLink']);
        }
    }

    /**
     * Varias peticiones GET en una sola llamada ($batch, máx. 20).
     *
     * @param  array<string, string>  $requests  id => ruta relativa (p. ej. "/me/mailFolders/inbox")
     * @return array<string, array{status: int, body: array<string, mixed>}> id => respuesta
     */
    public function batch(array $requests): array
    {
        $payload = [];

        foreach ($requests as $id => $url) {
            $payload[] = ['id' => (string) $id, 'method' => 'GET', 'url' => $url];
        }

        $responses = [];

        foreach ($this->post('/$batch', ['requests' => $payload])['responses'] ?? [] as $response) {
            $responses[$response['id']] = ['status' => (int) $response['status'], 'body' => $response['body'] ?? []];
        }

        return $responses;
    }
}
