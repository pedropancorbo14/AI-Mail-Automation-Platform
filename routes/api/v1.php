<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Todo lo que requiere usuario autenticado (token Sanctum o cookie SPA).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Aquí se irán añadiendo, fase a fase:
    // Route::apiResource('mail-connections', MailConnectionController::class);
});
