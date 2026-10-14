<?php

it('serves the panel on the root and on any panel route', function (string $path) {
    $this->withoutVite()
        ->get($path)
        ->assertOk()
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/', '/emails', '/emails/12', '/configuracion/ia']);

it('does not hide the api, horizon or the health check behind the panel', function () {
    $this->withoutVite()->getJson('/api/v1/no-existe')->assertNotFound()->assertJson(['message' => 'Recurso no encontrado.']);
    $this->withoutVite()->getJson('/api/v1/health')->assertOk();
    $this->withoutVite()->get('/up')->assertOk()->assertDontSee('<div id="app"></div>', escape: false);
});
