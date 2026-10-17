<?php

use App\AI\Remote\RedisEventStream;
use Illuminate\Support\Facades\Redis;

/*
 * Contra Redis real (BD REDIS_STREAMS_DB, 14 en los tests, vaciada en cada
 * test): mismas operaciones que usa el servicio Python con redis-py.
 */

beforeEach(function () {
    try {
        Redis::connection('streams')->client()->flushDb();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis no disponible: ' . $e->getMessage());
    }
    $this->streams = new RedisEventStream;
});

it('publishes, reads with a group, acknowledges and deletes', function () {
    $this->streams->ensureGroup('test.v1', 'laravel');
    $this->streams->ensureGroup('test.v1', 'laravel'); // idempotente
    $this->streams->publish('test.v1', ['event_id' => 'a', 'payload' => '{"x":1}']);

    [$message] = $this->streams->read(['test.v1'], 'laravel', 'c1', 10, 100);

    expect($message->fields)->toBe(['event_id' => 'a', 'payload' => '{"x":1}'])
        ->and($message->payload())->toBe('{"x":1}')
        ->and($message->deliveries)->toBe(1);

    $this->streams->ack($message, 'laravel');

    expect(Redis::connection('streams')->client()->xLen('test.v1'))->toBe(0);
});

it('uses stream names without the Laravel key prefix', function () {
    $this->streams->publish('email.processed.v1', ['payload' => '{}']);

    // rawCommand no aplica prefijos: la clave existe con el nombre exacto que lee Python.
    expect(Redis::connection('streams')->client()->rawCommand('EXISTS', 'email.processed.v1'))->toBe(1);
});

it('reads what was published before the group existed', function () {
    $this->streams->publish('test.v1', ['payload' => '{}']);
    $this->streams->ensureGroup('test.v1', 'laravel');

    expect($this->streams->read(['test.v1'], 'laravel', 'c1', 10, 100))->toHaveCount(1);
});

it('reclaims abandoned messages counting deliveries', function () {
    $this->streams->ensureGroup('test.v1', 'laravel');
    $this->streams->publish('test.v1', ['payload' => '{}']);
    $this->streams->read(['test.v1'], 'laravel', 'c1', 10, 100); // c1 lo lee y "muere"
    usleep(20_000);

    [$claimed] = $this->streams->claimAbandoned('test.v1', 'laravel', 'c2', 10, 10);

    expect($claimed->deliveries)->toBe(2);
});

it('moves messages to the dead letter stream with the reason', function () {
    $this->streams->ensureGroup('test.v1', 'laravel');
    $this->streams->publish('test.v1', ['payload' => 'roto']);
    [$message] = $this->streams->read(['test.v1'], 'laravel', 'c1', 10, 100);

    $this->streams->deadLetter($message, 'laravel', 'no cumple el contrato');

    $client = Redis::connection('streams')->client();
    $dead = array_values($client->xRange('test.v1.dlq', '-', '+'));
    expect($dead[0]['reason'])->toBe('no cumple el contrato')
        ->and($dead[0]['payload'])->toBe('roto')
        ->and($client->xLen('test.v1'))->toBe(0);
});

it('trims entries older than the retention exactly', function () {
    $client = Redis::connection('streams')->client();
    $old = (int) ((microtime(true) - 7200) * 1000);
    $client->xAdd('test.v1', "{$old}-0", ['payload' => 'viejo']);
    $this->streams->publish('test.v1', ['payload' => 'nuevo']);

    $this->streams->trim('test.v1', (int) ((microtime(true) - 3600) * 1000));

    expect($client->xLen('test.v1'))->toBe(1);
});
