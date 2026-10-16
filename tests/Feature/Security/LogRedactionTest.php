<?php

use App\Security\Redactor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/*
 * Secretos fuera de los logs: por nombre de campo y por forma, en todos los
 * canales, y trazas de excepciones sin argumentos.
 */

it('hides secrets by field name, also nested', function () {
    expect(Redactor::redact([
        'password' => 'x', 'Current_Password' => 'x', 'client_secret' => 'x', 'access_token' => 'x',
        'credentials' => ['imap' => ['password' => 'x']], 'Authorization' => 'x', 'cookie' => 'x', 'ANTHROPIC_API_KEY' => 'x',
        'email' => 'ana@acme.test', 'nested' => ['host' => 'imap.acme.test', 'smtp_password' => 'x'], 'count' => 3,
    ]))->toBe([
        'password' => '[oculto]', 'Current_Password' => '[oculto]', 'client_secret' => '[oculto]', 'access_token' => '[oculto]',
        'credentials' => '[oculto]', 'Authorization' => '[oculto]', 'cookie' => '[oculto]', 'ANTHROPIC_API_KEY' => '[oculto]',
        'email' => 'ana@acme.test', 'nested' => ['host' => 'imap.acme.test', 'smtp_password' => '[oculto]'], 'count' => 3,
    ]);
});

it('hides secrets recognizable by their shape inside texts', function (string $text, string $expected) {
    expect(Redactor::redactString($text))->toBe($expected);
})->with([
    'bearer' => ['Authorization: Bearer eyJhbGciOi.abc-123_x', 'Authorization: Bearer [oculto]'],
    'anthropic key' => ['clave sk-ant-api03-AbCdEf123456_xyz rechazada', 'clave [oculto] rechazada'],
    'sanctum token' => ['token 12|' . str_repeat('a', 40) . ' caducado', 'token [oculto] caducado'],
    'url password' => ['imaps://soporte:MiClave123@imap.acme.test:993', 'imaps://soporte:[oculto]@imap.acme.test:993'],
    'nothing secret' => ['Pedido #45821 enviado a ana@acme.test', 'Pedido #45821 enviado a ana@acme.test'],
]);

it('never writes secrets to the log files', function (string $channel) {
    $path = storage_path('logs/testing-redaction-' . $channel . '.log');
    @unlink($path);
    config(["logging.channels.{$channel}.path" => $path]);
    Log::forgetChannel($channel);
    Context::add('api_token', 'contexto-secreto');

    Log::channel($channel)->warning('Fallo con Bearer abcdefghijklmnop y sk-ant-api03-SECRETO123', [
        'password' => 'contraseña-secreta',
        'connection' => ['credentials' => ['refresh_token' => 'refresco-secreto'], 'host' => 'imap.acme.test'],
        'url' => 'smtp://user:clave-url@smtp.acme.test',
    ]);

    $written = (string) file_get_contents(glob(dirname($path) . '/testing-redaction-' . $channel . '*.log')[0]);
    array_map('unlink', glob(dirname($path) . '/testing-redaction-' . $channel . '*.log'));

    expect($written)->toContain('imap.acme.test')->toContain('[oculto]')
        ->not->toContain('abcdefghijklmnop')
        ->not->toContain('SECRETO123')
        ->not->toContain('contraseña-secreta')
        ->not->toContain('refresco-secreto')
        ->not->toContain('clave-url')
        ->not->toContain('contexto-secreto');
})->with(['single', 'daily', 'json']);

it('keeps function arguments out of exception traces', function () {
    $login = function (#[SensitiveParameter] string $user, string $password): never {
        throw new RuntimeException('fallo de conexión');
    };

    try {
        $login('ana', 'contraseña-en-argumento');
    } catch (RuntimeException $e) {
        expect(ini_get('zend.exception_ignore_args'))->toBe('1')
            ->and($e->getTraceAsString())->not->toContain('contraseña-en-argumento')
            ->and($e->getTrace()[0])->not->toHaveKey('args');
    }
});
