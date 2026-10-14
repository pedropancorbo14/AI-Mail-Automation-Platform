<?php

use Illuminate\Support\Facades\Route;

// Panel de administración (Fase 10): SPA de Vue. Cualquier ruta que no sea
// de la API, de Sanctum, de Horizon o el health check la resuelve el router
// del panel en el navegador.
Route::view('/{path?}', 'panel')
    ->where('path', '^(?!(api|sanctum|horizon|up)(/|$)).*$')
    ->name('panel');
