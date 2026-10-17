<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra los tokens de Sanctum caducados hace más de 24 h (config sanctum.expiration).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Retención (Fase 12): emails y auditoría que superan su plazo; trabajos fallidos de más de 30 días.
Schedule::command('data:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();

// Sincroniza los buzones activos (un FetchEmailsJob único por buzón en la cola "mail").
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping();

// Motor de IA remoto (ai-service): emails sin respuesta → republicar o failed.
Schedule::command('ai:reconcile-remote')->everyFiveMinutes()->withoutOverlapping();
