<?php

namespace App\ERP\StandardApi;

use App\Mail\Security\MailHostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Probar conexión" de la API estándar de una empresa: pide la lista de
 * pedidos (y de facturas, si la implementa) de un cliente que no existe y
 * comprueba que responde 200 con el formato del contrato.
 *
 * La URL la escribe la empresa (anti-SSRF): HTTPS, host comprobado con
 * MailHostGuard y conexión fijada a esas IPs (CURLOPT_RESOLVE: sin DNS
 * rebinding), sin redirecciones, 5 s y 1 MB como máximo. La clave nunca
 * aparece en mensajes ni logs.
 */
class StandardApiTester
{
    /** Cliente de prueba: dominio reservado, nunca es de nadie. */
    public const string PROBE_EMAIL = 'prueba-de-conexion@example.invalid';

    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly MailHostGuard $guard,
        private readonly StandardApiContract $contract,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    /** Motivo por el que la URL no se puede usar, o null si vale. */
    public function rejectUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'La dirección no es válida.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'La dirección no puede llevar usuario, contraseña, parámetros ni "#": la clave va aparte.';
        }
        $scheme = strtolower($parts['scheme']);
        // HTTP solo en local (un ERP de pruebas en la red de Docker).
        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowPrivateHosts)) {
            return 'La dirección tiene que empezar por https://.';
        }

        return $this->guard->reject($parts['host']);
    }

    public function test(string $baseUrl, string $token, bool $orders, bool $invoices): StandardApiTestResult
    {
        if ($reason = $this->rejectUrl($baseUrl)) {
            return StandardApiTestResult::failed($reason);
        }

        $checks = [];
        foreach (['orders' => [$orders, 'pedidos', 'order-list'], 'invoices' => [$invoices, 'facturación', 'invoice-list']] as $resource => [$enabled, $system, $schema]) {
            if ($enabled) {
                $checks[$resource] = $this->check($baseUrl, $token, $resource, $system, $schema);
            }
        }

        if ($checks === []) {
            return StandardApiTestResult::failed('Indica qué partes de la API tiene tu sistema (pedidos o facturas).');
        }

        $errors = array_values(array_filter($checks));

        return $errors === []
            ? StandardApiTestResult::passed(array_keys($checks))
            : StandardApiTestResult::failed(implode(' ', $errors), $checks);
    }

    /** null = correcto; si no, el motivo. */
    private function check(string $baseUrl, string $token, string $resource, string $system, string $schema): ?string
    {
        $url = rtrim($baseUrl, '/') . "/v1/{$resource}";
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443);
        $ips = $this->guard->allowedIps($host);
        $curl = $ips === [] ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]];

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, 'curl' => $curl])
                ->withToken($token)
                ->accept('application/json')
                ->withUserAgent('ai-mail-automation/1')
                ->get($url, ['customer_email' => self::PROBE_EMAIL, 'limit' => 1]);
        } catch (ConnectionException $e) {
            Log::info('API estándar: sin conexión', ['host' => $host, 'error' => class_basename($e)]);

            return "El sistema de {$system} no responde (comprueba la dirección y que sea accesible desde internet).";
        }

        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            return "El sistema de {$system} rechazó la clave.";
        }
        if ($status === 404) {
            return "El sistema de {$system} no tiene la ruta /v1/{$resource} (respondió 404).";
        }
        if ($status !== 200) {
            return "El sistema de {$system} respondió {$status}.";
        }
        if (strlen($response->body()) > self::MAX_BYTES) {
            return "El sistema de {$system} devolvió una respuesta demasiado grande.";
        }
        if ($error = $this->contract->error($schema, $response->body())) {
            return "El sistema de {$system} no responde con el formato de la API estándar ({$error}).";
        }

        return null;
    }
}
