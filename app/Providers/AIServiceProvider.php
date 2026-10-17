<?php

namespace App\Providers;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Embeddings\OllamaEmbeddingProvider;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Prompts\PromptRegistry;
use App\AI\Providers\Anthropic\AnthropicProvider;
use App\AI\Providers\Simulated\SimulatedLLMProvider;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\AI\Remote\RedisEventStream;
use App\Automation\QueueEmailProcessing;
use App\Documents\Chunking\TextChunker;
use App\ERP\Contracts\OrderProvider;
use App\ERP\Providers\DemoOrderProvider;
use App\Mail\Ingestion\Events\EmailIngested;
use App\Tenancy\TenantService;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor de IA activo según config/ai.php. El cliente del SDK se crea
 * al primer uso: sin ANTHROPIC_API_KEY la app arranca igual y solo falla
 * (LLMConfigurationException) quien intente llamar a la IA.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Caché de versiones de prompt durante toda la vida del worker.
        $this->app->singleton(PromptRegistry::class);

        // Motor remoto (ai-service): streams de Redis y validación del contrato.
        $this->app->singleton(EventStream::class, RedisEventStream::class);
        $this->app->singleton(EventContract::class);

        $this->app->singleton(LLMProvider::class, fn () => match (config('ai.provider')) {
            'anthropic' => new AnthropicProvider(
                client: self::lazyAnthropicClient(),
                defaultModel: config('ai.anthropic.model'),
                fallbacks: (bool) config('ai.anthropic.fallbacks'),
            ),
            // IA de pruebas: nunca en producción/staging (respondería emails reales sin modelo).
            'simulated' => $this->app->environment(['local', 'testing'])
                ? new SimulatedLLMProvider((int) config('ai.simulated.delay_ms'))
                : throw new LLMConfigurationException('La IA simulada solo está permitida en local (AI_PROVIDER=simulated).'),
            default => throw new LLMConfigurationException('Proveedor de IA no soportado: ' . config('ai.provider') . '.'),
        });

        $this->app->singleton(EmbeddingProvider::class, fn () => match (config('ai.embeddings.provider')) {
            'ollama' => new OllamaEmbeddingProvider(
                baseUrl: config('ai.embeddings.ollama.base_url'),
                model: config('ai.embeddings.ollama.model'),
                dimensions: (int) config('ai.embeddings.dimensions'),
                timeout: (int) config('ai.embeddings.ollama.timeout'),
                batchSize: (int) config('ai.embeddings.ollama.batch_size'),
            ),
            default => throw new LLMConfigurationException('Proveedor de embeddings no soportado: ' . config('ai.embeddings.provider') . '.'),
        });

        // Fuente de pedidos para las herramientas (demo hasta las integraciones de la Fase 14).
        // "scoped", no singleton: depende de TenantService (scoped); un singleton se quedaría
        // en un worker con el TenantService del primer job y vería la empresa equivocada.
        $this->app->scoped(OrderProvider::class, fn ($app) => match (config('erp.orders.provider')) {
            'demo' => new DemoOrderProvider($app->make(TenantService::class), config('erp.orders.demo_file')),
            default => throw new \InvalidArgumentException('Proveedor de pedidos no soportado: ' . config('erp.orders.provider') . '.'),
        });

        // Herramientas de la IA: solo las de config('ai.tools.available'), construidas desde el
        // contenedor. "scoped" por lo mismo: las herramientas guardan servicios del tenant.
        $this->app->scoped(ToolRegistry::class, fn ($app) => new ToolRegistry(
            array_map(fn (string $class) => $app->make($class), config('ai.tools.available', [])),
        ));

        $this->app->bind(TextChunker::class, fn () => new TextChunker(
            (int) config('ai.rag.chunk_max_chars'),
            (int) config('ai.rag.chunk_overlap_chars'),
        ));
    }

    public function boot(): void
    {
        // Ingesta completa → procesamiento automático (clasificación → agente → envío o persona).
        Event::listen(EmailIngested::class, QueueEmailProcessing::class);
    }

    /** @return \Closure(): Client */
    private static function lazyAnthropicClient(): \Closure
    {
        $client = null;

        return function () use (&$client): Client {
            if ($client !== null) {
                return $client;
            }

            $apiKey = (string) config('ai.anthropic.api_key');

            // Sin clave no se crea el cliente: el SDK buscaría credenciales en el
            // entorno (perfiles de "ant auth login"...) y no queremos eso.
            if ($apiKey === '') {
                throw new LLMConfigurationException('No hay API key de Anthropic configurada (ANTHROPIC_API_KEY).');
            }

            return $client = new Client(
                apiKey: $apiKey,
                requestOptions: RequestOptions::with(
                    timeout: (float) config('ai.anthropic.timeout'),
                    maxRetries: (int) config('ai.anthropic.max_retries'),
                ),
            );
        };
    }
}
