<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\OAuthApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
     * Sube un fichero a una "upload session" de Graph por trozos (adjuntos de
     * más de 3 MB). La URL de subida ya va autenticada: NO se envía el token
     * (Graph lo rechaza) y solo se aceptan URLs https de Microsoft.
     *
     * @param  int  $chunkBytes  múltiplo de 320 KiB, como pide Graph
     *
     * @throws MailProviderException
     */
    public function upload(string $uploadUrl, string $content, int $chunkBytes = 10 * 320 * 1024): void
    {
        $host = (string) parse_url($uploadUrl, PHP_URL_HOST);

        if (! str_starts_with($uploadUrl, 'https://') || ! preg_match('/(^|\.)(office\.com|office365\.com|outlook\.com|microsoft\.com)$/i', $host)) {
            throw new MailProviderException('Microsoft devolvió una dirección de subida de adjuntos no válida.');
        }

        $total = strlen($content);

        for ($start = 0; $start < $total; $start += $chunkBytes) {
            $chunk = substr($content, $start, $chunkBytes);
            $end = $start + strlen($chunk) - 1;

            try {
                $response = Http::withBody($chunk, 'application/octet-stream')
                    ->withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$total}"])
                    ->timeout($this->timeout * 2)
                    ->put($uploadUrl);
            } catch (ConnectionException $e) {
                throw new MailConnectionException('Se perdió la conexión con Microsoft al subir un adjunto.', previous: $e);
            }

            if ($response->serverError() || $response->status() === 429) {
                throw new MailConnectionException("Microsoft no está disponible al subir un adjunto (HTTP {$response->status()}).");
            }

            if ($response->failed()) {
                $detail = $this->errorDetail($response);
                throw new MailProviderException('Microsoft rechazó la subida de un adjunto (HTTP ' . $response->status() . ($detail ? ", {$detail}" : '') . ').');
            }
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
