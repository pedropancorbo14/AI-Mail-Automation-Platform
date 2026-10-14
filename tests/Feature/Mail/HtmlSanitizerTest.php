<?php

use App\Mail\Support\HtmlSanitizer;

function cleanHtml(string $html): string
{
    return app(HtmlSanitizer::class)->sanitize($html);
}

it('removes everything that could run code or send data', function (string $dangerous, string $forbidden) {
    expect(cleanHtml("<p>Hola</p>{$dangerous}"))->toContain('<p>Hola</p>')->not->toContain($forbidden);
})->with([
    'script' => ['<script>fetch("/api/v1/users")</script>', 'script'],
    'evento' => ['<img src="https://x.test/a.png" onerror="alert(1)">', 'onerror'],
    'onclick' => ['<div onclick="robar()">clic</div>', 'onclick'],
    'enlace javascript' => ['<a href="javascript:alert(1)">pulsa</a>', 'javascript:'],
    'enlace data' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">pulsa</a>', 'data:'],
    'iframe' => ['<iframe src="https://malo.test"></iframe>', 'iframe'],
    'formulario' => ['<form action="https://malo.test"><input name="password"></form>', 'form'],
    'estilos globales' => ['<style>body{display:none}</style>', 'display:none'],
    'css con url' => ['<p style="background:url(https://rastreo.test/p.gif)">x</p>', 'rastreo'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://malo.test">', 'refresh'],
    'base' => ['<base href="https://malo.test/">', 'base'],
]);

it('keeps the formatting of the email', function () {
    $html = cleanHtml(<<<'HTML'
        <h2>Pedido #45821</h2>
        <p style="color: #333333; font-weight: bold">Hola <b>Ana</b>, <em>gracias</em>.</p>
        <ul><li>Lámpara</li></ul>
        <table border="1"><tr><td colspan="2">Total: 89,90 €</td></tr></table>
        <img src="https://cdn.tienda.test/logo.png" alt="Logo" width="120">
        HTML);

    expect($html)->toContain('<h2>Pedido #45821</h2>')
        ->toContain('style="color:#333333;font-weight:bold;"')
        ->toContain('<b>Ana</b>')
        ->toContain('<li>Lámpara</li>')
        ->toContain('colspan="2"')
        ->toContain('Total: 89,90 €')
        ->toContain('src="https://cdn.tienda.test/logo.png"');
});

it('opens links in a new tab without giving access to the panel', function () {
    $html = cleanHtml('<a href="https://tienda.test/seguimiento">Seguir pedido</a> <a href="mailto:ayuda@tienda.test">correo</a>');

    expect($html)->toContain('href="https://tienda.test/seguimiento"')
        ->toContain('target="_blank"')
        ->toContain('noopener')
        ->toContain('noreferrer')
        ->toContain('href="mailto:ayuda@tienda.test"');
});

it('detects remote images that could be tracking pixels', function () {
    expect(HtmlSanitizer::hasRemoteImages('<p><img src="https://rastreo.test/p.gif" width="1"></p>'))->toBeTrue()
        ->and(HtmlSanitizer::hasRemoteImages('<img alt="x" src="//cdn.test/a.png">'))->toBeTrue()
        ->and(HtmlSanitizer::hasRemoteImages('<p>Sin imágenes</p>'))->toBeFalse();
});
