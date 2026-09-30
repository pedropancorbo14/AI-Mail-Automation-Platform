<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MailConnectionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

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
});
