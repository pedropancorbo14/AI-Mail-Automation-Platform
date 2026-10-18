<?php

namespace App\Providers;

use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\AI\Remote\RedisEventStream;
use App\AI\ToolCatalog;
use App\Automation\QueueEmailProcessing;
use App\Mail\Ingestion\Events\EmailIngested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Integración con ai-service, el único motor de IA: streams de Redis,
 * validación de los contratos de eventos y catálogo de herramientas. Laravel
 * no llama a ningún modelo ni calcula embeddings.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventStream::class, RedisEventStream::class);
        $this->app->singleton(EventContract::class);
        $this->app->singleton(ToolCatalog::class);
    }

    public function boot(): void
    {
        // Ingesta completa → procesamiento automático (filtros → ai-service → envío o persona).
        Event::listen(EmailIngested::class, QueueEmailProcessing::class);
    }
}
