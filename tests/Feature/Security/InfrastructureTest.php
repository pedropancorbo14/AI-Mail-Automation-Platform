<?php

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * Infraestructura: puertos solo en local, proxies de confianza y la
 * comprobación de configuración antes de desplegar.
 */

afterEach(fn () => TrustProxies::flushState());

/** Configuración "de producción" correcta. */
function productionConfig(): void
{
    config([
        'app.debug' => false,
        'app.url' => 'https://mail.acme.example',
        'session.secure' => true,
        'database.connections.pgsql.password' => str_repeat('p4ss', 6),
        'database.redis.default.password' => str_repeat('r3d1', 6),
        'metrics.enabled' => true,
        'metrics.token' => str_repeat('t0k3n', 8),
        'ai.provider' => 'anthropic',
        'mail_connections.allow_private_hosts' => false,
        'app.trusted_proxies' => '10.0.0.5',
        'sanctum.expiration' => 10080,
        'logging.channels.daily.level' => 'info',
    ]);
}

it('publishes every port of docker compose only on localhost by default', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    $published = collect($compose['services'])
        ->flatMap(fn (array $service, string $name) => array_map(fn (string $port) => [$name, $port], $service['ports'] ?? []));

    expect($published)->not->toBeEmpty();
    $published->each(fn (array $entry) => expect($entry[1])->toMatch('/^(127\.0\.0\.1|\$\{APP_BIND:-127\.0\.0\.1\}):/', "{$entry[0]}: {$entry[1]}"));
});

it('hardens grafana', function () {
    $grafana = Yaml::parseFile(base_path('docker-compose.yml'))['services']['grafana']['environment'];

    expect($grafana)->toMatchArray([
        'GF_USERS_ALLOW_SIGN_UP' => 'false',
        'GF_AUTH_ANONYMOUS_ENABLED' => 'false',
        'GF_SECURITY_DISABLE_GRAVATAR' => 'true',
        'GF_SNAPSHOTS_EXTERNAL_ENABLED' => 'false',
    ]);
});

it('takes the client ip from X-Forwarded-For only behind a trusted proxy', function () {
    Route::get('/api/__ip', fn () => ['ip' => request()->ip()])->middleware('api');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', '203.0.113.7')
        ->getJson('/api/__ip')->assertJsonPath('ip', '10.0.0.5');

    TrustProxies::at(['10.0.0.5']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', '203.0.113.7')
        ->getJson('/api/__ip')->assertJsonPath('ip', '203.0.113.7');

    // Otro origen no puede falsear su IP.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeader('X-Forwarded-For', '203.0.113.7')
        ->getJson('/api/__ip')->assertJsonPath('ip', '198.51.100.9');
});

it('passes the security check with a production configuration', function () {
    productionConfig();

    $this->artisan('security:check', ['--production' => true])
        ->expectsOutputToContain('Configuración lista para producción.')
        ->assertSuccessful();
});

it('fails the security check with the local defaults in production', function (string $key, mixed $value, string $check) {
    productionConfig();
    config([$key => $value]);

    $this->artisan('security:check', ['--production' => true])
        ->expectsOutputToContain($check)
        ->expectsOutputToContain('no despliegues así')
        ->assertFailed();
})->with([
    ['app.debug', true, 'APP_DEBUG desactivado'],
    ['app.url', 'http://mail.acme.example', 'APP_URL con HTTPS'],
    ['session.secure', null, 'Cookie de sesión solo por HTTPS'],
    ['database.connections.pgsql.password', 'secret', 'Contraseña de PostgreSQL fuerte'],
    ['database.redis.default.password', 'secret', 'Contraseña de Redis fuerte'],
    ['metrics.token', 'local-metrics-token', 'Token de métricas propio'],
    ['ai.provider', 'simulated', 'IA real (no simulada)'],
    ['mail_connections.allow_private_hosts', true, 'Buzones solo en servidores públicos'],
    ['app.trusted_proxies', '*', 'Proxies de confianza acotados'],
    ['sanctum.expiration', null, 'Tokens de API con caducidad'],
    ['logging.channels.daily.level', 'debug', 'Logs sin nivel debug'],
]);

it('only warns outside production', function () {
    config(['app.debug' => true]);

    $this->artisan('security:check')->expectsOutputToContain('normal en local')->assertSuccessful();
});
