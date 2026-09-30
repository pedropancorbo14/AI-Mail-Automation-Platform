<?php

namespace App\Mail\OAuth;

use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\Contracts\TokenSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Base de los clientes HTTP de APIs de correo con OAuth (Graph, Gmail):
 * añade el token, reintenta 429/503/504, renueva el token una vez ante un
 * 401 y traduce los errores HTTP a nuestras excepciones (sin tokens).
 */
abstract class OAuthApiClient
{
    public function __construct(
        protected readonly TokenSource $tokens,
        protected readonly int $timeout = 15,
    ) {
    }

    /** Nombre del servicio para los mensajes de error ("Microsoft", "Gmail"). */
    abstract protected function serviceName(): string;

    abstract protected function baseUrl(): string;

    /** Mensaje de error de la API (sin datos sensibles) para incluir en la excepción. */
    abstract protected function errorDetail(Response $response): ?string;

    /**
     * @param  array<string, mixed>  $options  query/json de la petición
     * @return array<string, mixed>
     *
     * @throws MailProviderException
     */
    public function request(string $method, string $pathOrUrl, array $options = [], array $headers = []): array
    {
        $response = $this->send($method, $pathOrUrl, $options, $headers, $this->tokens->accessToken());

        // Token rechazado aunque no hubiera caducado (revocado, rotado...): se renueva una vez.
        if ($response->status() === 401) {
            $response = $this->send($method, $pathOrUrl, $options, $headers, $this->tokens->refresh());
        }

        if ($response->failed()) {
            throw $this->translate($response);
        }

        return $response->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $pathOrUrl, array $query = [], array $headers = []): array
    {
        // Sin 'query' cuando no hay parámetros: Guzzle sustituiría la query string de
        // la URL (p. ej. el $skiptoken de un @odata.nextLink) por una vacía.
        return $this->request('GET', $pathOrUrl, $query === [] ? [] : ['query' => $query], $headers);
    }

    /** @return array<string, mixed> */
    public function post(string $pathOrUrl, array $json = []): array
    {
        return $this->request('POST', $pathOrUrl, ['json' => $json]);
    }

    /** @return array<string, mixed> */
    public function patch(string $pathOrUrl, array $json = []): array
    {
        return $this->request('PATCH', $pathOrUrl, ['json' => $json]);
    }

    private function send(string $method, string $pathOrUrl, array $options, array $headers, string $token): Response
    {
        $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : $this->baseUrl() . $pathOrUrl;

        try {
            return $this->http($token)->withHeaders($headers)->send($method, $url, $options);
        } catch (ConnectionException $e) {
            throw new MailConnectionException("No se pudo conectar con {$this->serviceName()}.", previous: $e);
        }
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout($this->timeout)
            // Límite de peticiones o caída momentánea: 3 intentos en total
            // (en Laravel, retry($times) cuenta el intento inicial).
            ->retry(
                3,
                fn (int $attempt) => $attempt * 500,
                fn ($exception) => $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 503, 504], true),
                throw: false,
            );
    }

    private function translate(Response $response): MailProviderException
    {
        $service = $this->serviceName();
        $detail = $this->errorDetail($response);
        $suffix = $detail ? " ({$detail})" : '';

        return match (true) {
            $response->status() === 401 => new MailAuthenticationException("{$service} rechazó el acceso a la cuenta: hay que volver a conectarla."),
            $response->status() === 403 => new MailAuthenticationException("{$service} deniega la operación: faltan permisos en la cuenta conectada{$suffix}."),
            $response->status() === 404 => new MailNotFoundException("{$service}: el elemento solicitado no existe{$suffix}."),
            $response->status() === 429 => new MailConnectionException("{$service} ha limitado las peticiones; inténtalo más tarde."),
            $response->serverError() => new MailConnectionException("{$service} no está disponible en este momento (HTTP {$response->status()})."),
            default => new MailProviderException("{$service} devolvió un error (HTTP {$response->status()}){$suffix}."),
        };
    }
}
