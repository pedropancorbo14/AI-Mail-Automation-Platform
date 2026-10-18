<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Revisa la configuración antes de desplegar en producción: modo debug,
 * HTTPS y cookies, contraseñas por defecto o débiles, token de métricas de
 * desarrollo, IA simulada, proxies... Sale con error si algo falla, así se
 * puede usar en el despliegue (Fase 16).
 *
 * Fuera de producción solo avisa (en local lo normal es que fallen varias),
 * salvo con --production: comprueba como si lo fuera.
 */
#[Signature('security:check {--production : Comprobar como si fuera producción aunque no lo sea}')]
#[Description('Comprueba que la configuración es segura para producción')]
class SecurityCheck extends Command
{
    /** Contraseñas que nunca deberían llegar a producción. */
    private const array WEAK = ['', 'secret', 'password', 'admin', 'root', '123456', 'changeme', 'local-metrics-token'];

    public function handle(): int
    {
        $production = app()->isProduction() || $this->option('production');
        $https = str_starts_with((string) config('app.url'), 'https://');

        $checks = [
            ['APP_KEY definida', filled(config('app.key')), 'php artisan key:generate'],
            ['APP_DEBUG desactivado', ! config('app.debug'), 'Con debug, un error muestra trazas, rutas y configuración.'],
            ['APP_URL con HTTPS', $https, 'Sin HTTPS, la sesión y las contraseñas viajan en claro.'],
            ['Cookie de sesión solo por HTTPS', ! $https || config('session.secure') === true, 'SESSION_SECURE_COOKIE=true'],
            ['Contraseña de PostgreSQL fuerte', $this->strong(config('database.connections.pgsql.password')), 'DB_PASSWORD: 16+ caracteres aleatorios.'],
            ['Contraseña de Redis fuerte', $this->strong(config('database.redis.default.password')), 'REDIS_PASSWORD: 16+ caracteres aleatorios.'],
            ['Token de métricas propio', ! config('metrics.enabled') || filled(config('metrics.token')) && $this->strong(config('metrics.token'), 32), 'METRICS_TOKEN: 32+ caracteres aleatorios (y el mismo en prometheus.yml).'],
            ['IA real (no simulada)', config('ai.remote.llm_provider') !== 'simulated', 'AI_SERVICE_LLM_PROVIDER=anthropic: la simulada respondería a clientes reales.'],
            ['Buzones solo en servidores públicos', ! config('mail_connections.allow_private_hosts'), 'MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=false (anti-SSRF).'],
            ['Proxies de confianza acotados', config('app.trusted_proxies') !== '*', 'TRUSTED_PROXIES con las IPs del proxy, no "*": cualquiera podría falsear su IP.'],
            ['Tokens de API con caducidad', config('sanctum.expiration') !== null, 'SANCTUM_TOKEN_EXPIRATION en minutos.'],
            ['Logs sin nivel debug', config('logging.channels.daily.level') !== 'debug', 'LOG_LEVEL=info o superior.'],
        ];

        $failed = array_filter($checks, fn (array $check) => ! $check[1]);

        $this->table(['Comprobación', 'Estado', 'Cómo arreglarlo'], array_map(fn (array $check) => [
            $check[0],
            $check[1] ? 'OK' : ($production ? 'FALLA' : 'aviso'),
            $check[1] ? '' : $check[2],
        ], $checks));

        if ($failed === []) {
            $this->info('Configuración lista para producción.');

            return self::SUCCESS;
        }

        if (! $production) {
            $this->warn(count($failed) . ' avisos (normal en local). Usa --production para comprobarlo como en producción.');

            return self::SUCCESS;
        }

        $this->error(count($failed) . ' comprobaciones fallan: no despliegues así.');

        return self::FAILURE;
    }

    private function strong(mixed $secret, int $length = 16): bool
    {
        return is_string($secret) && mb_strlen($secret) >= $length && ! in_array(strtolower($secret), self::WEAK, true);
    }
}
