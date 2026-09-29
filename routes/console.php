<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra los tokens de Sanctum caducados hace más de 24 h (config sanctum.expiration).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
