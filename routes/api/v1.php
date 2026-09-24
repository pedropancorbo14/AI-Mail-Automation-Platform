<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Aquí se irán añadiendo, fase a fase:
// Route::apiResource('tenants', TenantController::class);
// Route::apiResource('mail-connections', MailConnectionController::class);