<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KnowledgeSearchController;
use App\Http\Controllers\Api\V1\MailConnectionController;
use App\Http\Controllers\Api\V1\MailOAuthController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
// Panel de administración: sesión por cookie (Sanctum SPA) en vez de token.
Route::post('/auth/session', [AuthController::class, 'session'])->middleware('throttle:login');

// Vuelta del navegador desde Microsoft/Google: pública (sin nuestro token);
// empresa y usuario salen del "state" guardado al iniciar el flujo.
Route::get('/mail-connections/oauth/{provider}/callback', [MailOAuthController::class, 'callback'])
    ->middleware('throttle:30,1');

// Todo lo que requiere usuario autenticado (token Sanctum o cookie SPA) y
// se ejecuta dentro de su tenant.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/tenant', [TenantController::class, 'show']);
    Route::patch('/tenant', [TenantController::class, 'update']);

    Route::apiResource('users', UserController::class);

    Route::apiResource('mail-connections', MailConnectionController::class)
        ->parameters(['mail-connections' => 'mailConnection']);
    Route::post('/mail-connections/oauth/{provider}/start', [MailOAuthController::class, 'start']);
    Route::post('/mail-connections/{mailConnection}/test', [MailConnectionController::class, 'test'])
        ->middleware('throttle:mail-connection-test');

    // Base de conocimiento (RAG): documentación de la empresa y búsqueda.
    Route::apiResource('documents', DocumentController::class)->except(['update']);
    Route::post('/documents/{document}/reindex', [DocumentController::class, 'reindex']);
    Route::post('/knowledge-base/search', KnowledgeSearchController::class)->middleware('throttle:30,1');
});
