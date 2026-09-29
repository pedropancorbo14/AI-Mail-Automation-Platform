<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Route;

// Simula producción: con APP_DEBUG=false el manejo genérico de errores no
// debe tapar los códigos HTTP concretos.
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::get('/boom', fn () => throw new RuntimeException('detalle interno'));
        Route::get('/forbidden', fn () => throw new AuthorizationException());
        Route::get('/hidden', fn () => Response::denyAsNotFound()->authorize());
        Route::get('/users/{user}', fn (User $user) => $user);
    });
});

it('hides unexpected errors behind a generic 500', function () {
    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Error interno del servidor.']);
});

it('returns 403 for authorization errors', function () {
    $this->getJson('/api/v1/_test/forbidden')
        ->assertForbidden()
        ->assertExactJson(['message' => 'No autorizado.']);
});

it('returns 404 when a policy denies as not found', function () {
    $this->getJson('/api/v1/_test/hidden')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Recurso no encontrado.']);
});

it('returns 404 without leaking the model name', function () {
    $this->getJson('/api/v1/_test/users/999999')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Recurso no encontrado.']);
});

it('keeps 405 for a wrong http method', function () {
    $this->postJson('/api/v1/health')->assertStatus(405);
});

it('keeps 429 when the rate limit is exceeded', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/health');
    }

    $this->getJson('/api/v1/health')->assertStatus(429);
});
