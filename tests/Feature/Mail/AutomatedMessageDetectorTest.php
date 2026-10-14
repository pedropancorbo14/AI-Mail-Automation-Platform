<?php

use App\Mail\Support\AutomatedMessageDetector;

it('flags automated emails', function (array $headers, ?string $from, string $reason) {
    expect(AutomatedMessageDetector::reason($headers, $from))->toContain($reason);
})->with([
    'respuesta automática (RFC 3834)' => [['Auto-Submitted' => 'auto-replied'], 'ana@proveedor.test', 'Auto-Submitted: auto-replied'],
    'generado por un sistema' => [['auto-submitted' => 'Auto-Generated; owner-email="x@y.test"'], 'ana@proveedor.test', 'auto-generated'],
    'rebote' => [['Content-Type' => 'multipart/report; report-type=delivery-status'], 'ana@proveedor.test', 'rebote'],
    'envío masivo' => [['Precedence' => 'bulk'], 'ofertas@tienda.test', 'Precedence: bulk'],
    'lista de correo' => [['List-Id' => '<clientes.lista.test>'], 'ana@proveedor.test', 'Lista de correo'],
    'boletín' => [['List-Unsubscribe' => '<https://tienda.test/baja>'], 'ofertas@tienda.test', 'boletín'],
    'cabecera X-Autoreply' => [['X-Autoreply' => 'yes'], 'ana@proveedor.test', 'x-autoreply'],
    'noreply' => [[], 'noreply@banco.test', 'no acepta respuestas'],
    'no-reply con sufijo' => [[], 'no-reply+avisos@banco.test', 'no acepta respuestas'],
    'donotreply' => [[], 'DoNotReply@banco.test', 'no acepta respuestas'],
    'mailer-daemon' => [[], 'MAILER-DAEMON@mx.proveedor.test', 'no acepta respuestas'],
    'postmaster' => [[], 'postmaster@proveedor.test', 'no acepta respuestas'],
]);

it('treats emails written by a person as not automated', function (array $headers, ?string $from) {
    expect(AutomatedMessageDetector::reason($headers, $from))->toBeNull();
})->with([
    'sin cabeceras' => [[], 'cliente@cliente.test'],
    'Auto-Submitted: no' => [['Auto-Submitted' => 'no'], 'cliente@cliente.test'],
    'cabeceras vacías o nulas' => [['Precedence' => null, 'List-Id' => null, 'Auto-Submitted' => ''], 'cliente@cliente.test'],
    'Precedence normal' => [['Precedence' => 'first-class'], 'cliente@cliente.test'],
    'correo normal con adjuntos' => [['Content-Type' => 'multipart/mixed; boundary=x'], 'cliente@cliente.test'],
    'nombre que contiene noreply' => [[], 'noreplyjuan@cliente.test'],
    'sin remitente' => [[], null],
    'remitente sin @' => [[], 'raro'],
]);
