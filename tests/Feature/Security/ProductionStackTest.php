<?php

use Symfony\Component\Yaml\Yaml;

/*
 * docker-compose.prod.yml (Fase 16): lo que no puede cambiar sin que nadie se
 * dé cuenta. El stack se arranca de verdad en el CI (job "production"); aquí
 * se vigilan las reglas.
 */

function productionCompose(): array
{
    return Yaml::parseFile(base_path('docker-compose.prod.yml'));
}

it('only publishes caddy to the internet, the rest only on localhost', function () {
    $published = collect(productionCompose()['services'])
        ->flatMap(fn (array $service, string $name) => array_map(fn (string $port) => [$name, $port], $service['ports'] ?? []));

    $published->each(function (array $entry) {
        [$service, $port] = $entry;

        if ($service === 'caddy') {
            expect($port)->toMatch('/^\$\{HTTP_BIND:-0\.0\.0\.0\}:(80|443):(80|443)(\/udp)?$/');
        } else {
            expect($port)->toStartWith('127.0.0.1:', "{$service}: {$port}");
        }
    });

    expect($published->where(0, 'caddy'))->toHaveCount(3);
});

it('does not mount the source code nor run development services', function () {
    $services = productionCompose()['services'];

    expect($services)->not->toHaveKeys(['vite', 'greenmail']);

    foreach ($services as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume)->not->toStartWith('./:', "{$name} monta el código")
                ->and($volume)->not->toMatch('/^\.\/(app|routes|config|src):/');
        }
    }
});

it('pins every third-party image to a version', function () {
    foreach (productionCompose()['services'] as $name => $service) {
        $image = $service['image'] ?? '';

        expect($image)->not->toBeEmpty("{$name} sin imagen")
            ->and($image)->not->toEndWith(':latest');

        if (! str_contains($image, '${APP_VERSION')) {
            expect($image)->toMatch('/:[A-Za-z0-9._-]+$/', "{$name}: {$image}");
        }
    }
});

it('builds the own images with the deployed version', function () {
    $services = productionCompose()['services'];

    foreach (['app', 'horizon', 'scheduler', 'ai-results', 'nginx', 'ai-service', 'ai-mcp'] as $name) {
        expect($services[$name]['image'])->toContain('${APP_VERSION');
    }

    expect($services['app']['build']['target'])->toBe('production')
        ->and($services['nginx']['build']['target'])->toBe('web');
});

it('requires the secrets instead of falling back to development values', function () {
    $raw = file_get_contents(base_path('docker-compose.prod.yml'));

    foreach (['APP_DOMAIN', 'DB_PASSWORD', 'REDIS_PASSWORD', 'ANTHROPIC_API_KEY', 'AI_SERVICE_CHECKPOINTS_DB_PASSWORD', 'AI_SERVICE_MCP_TOKEN', 'AI_SERVICE_INTERNAL_TOKEN', 'AI_SERVICE_DATA_TOKEN', 'GRAFANA_PASSWORD'] as $secret) {
        expect($raw)->toContain('${' . $secret . ':?');
    }

    // Ningún valor de desarrollo como respaldo.
    expect($raw)->not->toMatch('/:-(secret|local-[a-z-]+-token|checkpoints-secret)\}/');
});

it('fixes in the compose the settings that must never be wrong in production', function () {
    $env = productionCompose()['services']['app']['environment'];

    expect($env)->toMatchArray([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_SECURE_COOKIE' => 'true',
        'MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS' => 'false',
        'DB_HOST' => 'postgres',
        'REDIS_HOST' => 'redis',
    ])->and($env['APP_URL'])->toStartWith('https://');

    $ai = productionCompose()['services']['ai-service']['environment'];
    expect($ai)->toMatchArray(['AI_SERVICE_ENVIRONMENT' => 'production', 'AI_SERVICE_LLM_PROVIDER' => 'anthropic', 'AI_SERVICE_DEBUG_ENDPOINTS' => 'false']);
});

it('trusts only caddy as proxy', function () {
    $compose = productionCompose();

    expect($compose['services']['caddy']['networks']['edge']['ipv4_address'])->toBe('${CADDY_IP:-172.31.250.10}')
        ->and($compose['services']['app']['environment']['TRUSTED_PROXIES'])->toBe('${CADDY_IP:-172.31.250.10}');
});

it('blocks internal routes and metrics at the edge', function () {
    $caddyfile = file_get_contents(base_path('docker/caddy/Caddyfile'));

    expect($caddyfile)->toContain('@interno path /internal /internal/* /metrics /metrics/*')
        ->toContain('respond @interno 404')
        ->toContain('reverse_proxy nginx:80');
});

it('reads the metrics token from a secret and scrapes the same as in development', function () {
    $production = Yaml::parseFile(base_path('docker/prometheus/prometheus.prod.yml'));
    $development = Yaml::parseFile(base_path('docker/prometheus/prometheus.yml'));

    $laravel = collect($production['scrape_configs'])->firstWhere('job_name', 'laravel');
    expect($laravel['authorization'])->toBe(['type' => 'Bearer', 'credentials_file' => '/run/secrets/metrics_token'])
        ->and(file_get_contents(base_path('docker/prometheus/prometheus.prod.yml')))->not->toContain('local-metrics-token');

    // Lo mismo que en desarrollo y, además, Alertmanager (solo existe en producción).
    expect(array_values(array_diff(array_column($production['scrape_configs'], 'job_name'), ['alertmanager'])))->toBe(array_column($development['scrape_configs'], 'job_name'))
        ->and($production['rule_files'])->toBe($development['rule_files'])
        ->and($production['alerting']['alertmanagers'][0]['static_configs'][0]['targets'])->toBe(['alertmanager:9093']);

    expect(productionCompose()['secrets']['metrics_token'])->toBe(['environment' => 'METRICS_TOKEN']);
});

it('generates every secret of the production example', function () {
    $example = file_get_contents(base_path('.env.production.example'));

    preg_match_all('/^([A-Z0-9_]+)=__SECRET__$/m', $example, $secrets);

    expect($secrets[1])->toContain('DB_PASSWORD', 'REDIS_PASSWORD', 'METRICS_TOKEN', 'AI_SERVICE_INTERNAL_TOKEN', 'AI_SERVICE_DATA_TOKEN', 'AI_SERVICE_MCP_TOKEN', 'AI_SERVICE_CHECKPOINTS_DB_PASSWORD', 'GRAFANA_PASSWORD')
        ->and($example)->toContain('APP_KEY=__APP_KEY__', 'APP_DOMAIN=__DOMAIN__');
});

it('keeps secrets and local files out of the image', function () {
    $ignored = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

    expect($ignored)->toContain('.env', '.env.*', 'vendor', 'node_modules', '.git', 'public/hot', 'bootstrap/cache/*.php', 'ai-service')
        ->and($ignored)->not->toContain('!.env.production');
});
