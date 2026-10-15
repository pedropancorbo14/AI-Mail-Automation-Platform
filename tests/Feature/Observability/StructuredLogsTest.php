<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('writes one JSON line per entry with the Laravel Context for Loki', function () {
    $dir = storage_path('framework/testing/json-logs-' . uniqid());
    config(['logging.channels.json.path' => "{$dir}/laravel.log"]);

    Context::add('tenant_id', 3);
    Log::channel('json')->warning('Herramienta de la IA', ['tool' => 'get_order', 'status' => 'error']);

    $line = json_decode(trim(File::get(File::files($dir)[0]->getPathname())), true);

    expect($line)->toMatchArray([
        'message' => 'Herramienta de la IA',
        'level_name' => 'WARNING',
        'context' => ['tool' => 'get_order', 'status' => 'error'],
        'extra' => ['tenant_id' => 3],
    ])->and($line['datetime'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/');

    File::deleteDirectory($dir);
});

it('gives every request an id, in the response and in the log Context', function () {
    Route::get('/api/test-request-id', fn () => response()->json(['request_id' => Context::get('request_id')]));

    $response = $this->getJson('/api/test-request-id')->assertOk();

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('keeps a safe request id sent by a proxy and ignores an unsafe one', function (string $sent, bool $kept) {
    $id = $this->withHeader('X-Request-Id', $sent)->getJson('/api/v1/health')->headers->get('X-Request-Id');

    expect($id === $sent)->toBe($kept)
        ->and($id)->toMatch('/^[A-Za-z0-9._-]{8,64}$/');
})->with([
    'id de un proxy' => ['abc123-def456', true],
    'con saltos de línea' => ["abc\nFALSO log", false],
    'demasiado corto' => ['abc', false],
]);
