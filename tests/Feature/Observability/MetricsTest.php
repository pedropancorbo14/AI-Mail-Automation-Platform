<?php

use App\Observability\Metrics;
use Illuminate\Support\Facades\Log;
use Prometheus\Storage\InMemory;

function memoryMetrics(bool $enabled = true): Metrics
{
    $storage = new InMemory();

    return new Metrics(fn () => $storage, 'aimail', $enabled);
}

it('renders counters and histograms in the Prometheus text format', function () {
    $metrics = memoryMetrics();
    $metrics->increment('emails_ingested_total', ['tenant' => 'acme', 'provider' => 'imap', 'automated' => false]);
    $metrics->increment('emails_ingested_total', ['tenant' => 'acme', 'provider' => 'imap', 'automated' => false], 2);
    $metrics->observe('ai_request_duration_seconds', 1.7, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'purpose' => 'email_agent']);

    expect($metrics->render())
        ->toContain('# HELP aimail_emails_ingested_total Emails guardados en la ingesta (sin duplicados).')
        ->toContain('# TYPE aimail_emails_ingested_total counter')
        ->toContain('aimail_emails_ingested_total{tenant="acme",provider="imap",automated="false"} 3')
        ->toContain('aimail_ai_request_duration_seconds_bucket{provider="anthropic",model="claude-opus-5-5",purpose="email_agent",le="2.5"} 1')
        ->toContain('aimail_ai_request_duration_seconds_bucket{provider="anthropic",model="claude-opus-5-5",purpose="email_agent",le="1"} 0')
        ->toContain('aimail_ai_request_duration_seconds_count{provider="anthropic",model="claude-opus-5-5",purpose="email_agent"} 1');
});

it('fills missing labels with "none" and ignores non-positive increments', function () {
    $metrics = memoryMetrics();
    $metrics->increment('email_replies_failed_total', ['tenant' => null]);
    $metrics->increment('email_replies_sent_total', ['tenant' => 'acme', 'automatic' => true], 0);

    expect($metrics->render())
        ->toContain('aimail_email_replies_failed_total{tenant="none"} 1')
        ->not->toContain('email_replies_sent_total');
});

it('rejects metrics and labels that are not declared', function () {
    expect(fn () => memoryMetrics()->increment('nope_total'))->toThrow(LogicException::class, 'Métrica no declarada: nope_total.')
        ->and(fn () => memoryMetrics()->increment('emails_ingested_total', ['email' => 'x@y.z']))->toThrow(LogicException::class, 'Etiquetas no declaradas')
        ->and(fn () => memoryMetrics()->observe('emails_ingested_total', 1))->toThrow(LogicException::class, 'es de tipo counter');
});

it('never breaks business code when the storage fails', function () {
    Log::spy();
    $metrics = new Metrics(fn () => throw new RedisException('Connection refused'));

    $metrics->increment('email_replies_failed_total', ['tenant' => 'acme']);

    Log::shouldHaveReceived('warning')->with('No se pudo registrar una métrica', Mockery::on(
        fn (array $context) => $context['metric'] === 'email_replies_failed_total' && $context['detail'] === 'Connection refused',
    ));
});

it('records nothing when disabled', function () {
    $metrics = memoryMetrics(enabled: false);
    $metrics->increment('email_replies_failed_total', ['tenant' => 'acme']);

    expect($metrics->render())->toBe('');
});

it('shares the values between processes through Redis', function () {
    config(['metrics.storage' => 'redis', 'metrics.redis.database' => 15]);
    $worker = Metrics::fromConfig();
    $worker->wipe();
    $scraper = Metrics::fromConfig(); // otra instancia = otro proceso

    $worker->increment('ai_cost_usd_total', ['tenant' => 'acme', 'model' => 'claude-opus-5-5', 'purpose' => 'email_agent'], 0.0425);
    $worker->increment('ai_cost_usd_total', ['tenant' => 'acme', 'model' => 'claude-opus-5-5', 'purpose' => 'email_agent'], 0.0075);

    expect($scraper->render())->toContain('aimail_ai_cost_usd_total{tenant="acme",model="claude-opus-5-5",purpose="email_agent"} 0.05');

    $worker->wipe();
});

describe('GET /metrics', function () {
    beforeEach(function () {
        config(['metrics.token' => 'secreto-de-prometheus']);
        app(Metrics::class)->increment('email_replies_failed_total', ['tenant' => 'acme']);
    });

    it('serves the metrics with the token', function () {
        $this->withToken('secreto-de-prometheus')->get('/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->assertSee('aimail_email_replies_failed_total{tenant="acme"} 1', false);
    });

    it('hides the endpoint without the right token', function (?string $token) {
        $request = $token === null ? $this : $this->withToken($token);

        $request->get('/metrics')->assertNotFound()->assertDontSee('aimail_');
    })->with(['sin token' => [null], 'token incorrecto' => ['otro']]);

    it('hides the endpoint when no token is configured or metrics are disabled', function (array $config) {
        config($config);

        $this->withToken('secreto-de-prometheus')->get('/metrics')->assertNotFound();
    })->with([
        'sin token configurado' => [['metrics.token' => null]],
        'desactivadas' => [['metrics.enabled' => false]],
    ]);

    it('does not start a session or set cookies', function () {
        $this->withToken('secreto-de-prometheus')->get('/metrics')
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    });
});

it('never declares the labels Prometheus reserves for its targets', function () {
    foreach (Metrics::DEFINITIONS as $name => $definition) {
        expect(array_intersect($definition['labels'], ['job', 'instance']))->toBe([], "La métrica {$name} usa una etiqueta reservada");
    }
});
