<?php

use App\AI\Remote\EventContract;
use App\AI\Remote\Exceptions\InvalidEventException;

/*
 * Los mismos ejemplos que recorren los tests de ai-service (Python): si un
 * lado cambia el contrato sin el otro, falla el CI.
 */

function contractExamples(string $kind): array
{
    $cases = [];
    // Al cargar el archivo la app aún no existe (sin base_path): ruta relativa.
    foreach (glob(dirname(__DIR__, 4) . "/contracts/events/examples/{$kind}/*.json") as $file) {
        $name = basename($file, '.json');
        $cases[$name] = [explode('--', $name)[0], file_get_contents($file)];
    }

    return $cases;
}

it('accepts every valid example of the shared contract', function (string $event, string $json) {
    $data = app(EventContract::class)->validate($event, $json);

    expect($data['tenant_id'])->toBeGreaterThan(0);
})->with(contractExamples('valid'));

it('rejects every invalid example of the shared contract', function (string $event, string $json) {
    app(EventContract::class)->validate($event, $json);
})->with(contractExamples('invalid'))->throws(InvalidEventException::class);

it('explains which field breaks the contract', function () {
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.received.v1.json')), true);
    unset($event['tenant_id']);

    expect(fn () => app(EventContract::class)->validate('email.received.v1', $event))
        ->toThrow(InvalidEventException::class, 'email.received.v1 no cumple el contrato');
});

it('rejects payloads that are not JSON objects', function () {
    app(EventContract::class)->validate('email.received.v1', '{no es json');
})->throws(InvalidEventException::class, 'no es un objeto JSON');
