<?php

namespace App\ERP\WooCommerce;

use App\ERP\Connections\CompanyUrl;
use App\ERP\Connections\ConnectionTestResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Probar conexión" de una tienda WooCommerce: pide un pedido a su API REST
 * (`/wp-json/wc/v3/orders?per_page=1`) con las claves de API de la tienda
 * (Basic por HTTPS, como el servidor MCP) y comprueba que responde una
 * lista de pedidos. Mismas protecciones que la API estándar (CompanyUrl).
 */
class WooCommerceTester
{
    public const string API_PATH = '/wp-json/wc/v3';

    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(private readonly CompanyUrl $url)
    {
    }

    public function rejectUrl(string $url): ?string
    {
        return $this->url->reject($url);
    }

    public function test(string $siteUrl, string $consumerKey, string $consumerSecret): ConnectionTestResult
    {
        if ($reason = $this->rejectUrl($siteUrl)) {
            return ConnectionTestResult::failed($reason);
        }

        $url = rtrim($siteUrl, '/') . self::API_PATH . '/orders';

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, 'curl' => $this->url->pinned($url)])
                ->withBasicAuth($consumerKey, $consumerSecret)
                ->accept('application/json')
                ->withUserAgent('ai-mail-automation/1')
                ->get($url, ['per_page' => 1]);
        } catch (ConnectionException $e) {
            Log::info('WooCommerce: sin conexión', ['host' => parse_url($url, PHP_URL_HOST), 'error' => class_basename($e)]);

            return ConnectionTestResult::failed('La tienda no responde (comprueba la dirección y que sea accesible desde internet).');
        }

        $error = match (true) {
            in_array($response->status(), [401, 403], true) => 'La tienda rechazó las claves de API (comprueba que sean correctas y tengan permiso de lectura).',
            $response->status() === 404 => 'No se encuentra la API de WooCommerce en esa dirección (¿es la dirección de la tienda? ¿Están activados los enlaces permanentes de WordPress?).',
            $response->status() !== 200 => "La tienda respondió {$response->status()}.",
            strlen($response->body()) > self::MAX_BYTES => 'La tienda devolvió una respuesta demasiado grande.',
            ! $this->looksLikeOrders($response->json()) => 'La tienda no responde como la API de pedidos de WooCommerce.',
            default => null,
        };

        return $error === null ? ConnectionTestResult::passed(['orders']) : ConnectionTestResult::failed($error, ['orders' => $error]);
    }

    private function looksLikeOrders(mixed $data): bool
    {
        return is_array($data) && array_is_list($data)
            && collect($data)->every(fn ($o) => is_array($o) && isset($o['number'], $o['status']));
    }
}
