<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de todas las respuestas (panel, API, métricas):
 *
 *  - nosniff: el navegador no "adivina" tipos (un adjunto no se ejecuta como HTML).
 *  - Nadie puede meter la app en un iframe (clickjacking): X-Frame-Options +
 *    frame-ancestors.
 *  - Referrer-Policy, Permissions-Policy (sin cámara, micrófono, ubicación,
 *    pagos...), Cross-Origin-Opener-Policy.
 *  - HSTS solo si la petición llegó por HTTPS (en local no hay).
 *  - Content-Security-Policy: JSON → no carga nada; panel → solo recursos
 *    del propio origen (un XSS no puede cargar scripts de fuera ni enviar
 *    datos a otro dominio). En desarrollo se añade el servidor de Vite.
 *    Horizon (scripts en línea propios) se queda sin CSP: solo lo ve el
 *    operador, protegido por su Gate.
 */
class SecurityHeaders
{
    public function __construct(private readonly Vite $vite)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $headers->has('Content-Security-Policy') && ($policy = $this->contentSecurityPolicy($request, $response)) !== null) {
            $headers->set('Content-Security-Policy', $policy);
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request, Response $response): ?string
    {
        if ($request->is('horizon', 'horizon/*')) {
            return null;
        }

        $type = (string) $response->headers->get('Content-Type');

        if (! str_contains($type, 'text/html')) {
            return "default-src 'none'; frame-ancestors 'none'";
        }

        // Naive UI inyecta sus estilos en <style> en tiempo de ejecución: style-src necesita 'unsafe-inline'.
        // El HTML de los emails va en un iframe srcdoc con sandbox y su propia CSP (Fase 10).
        $dev = $this->vite->isRunningHot() ? $this->viteOrigins() : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'{$dev}",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** Servidor de Vite en desarrollo (public/hot), también por WebSocket para HMR. */
    private function viteOrigins(): string
    {
        $url = trim((string) @file_get_contents($this->vite->hotFile()));
        $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (($port = parse_url($url, PHP_URL_PORT)) ? ":{$port}" : '');
        $ws = preg_replace('/^http/', 'ws', $origin);

        return " {$origin} {$ws}";
    }
}
