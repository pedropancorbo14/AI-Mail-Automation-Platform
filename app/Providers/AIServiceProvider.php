<?php

namespace App\Providers;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\AI\Contracts\LLMProvider;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Providers\Anthropic\AnthropicProvider;
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
        $this->app->singleton(LLMProvider::class, fn () => match (config('ai.provider')) {
            'anthropic' => new AnthropicProvider(
                client: self::lazyAnthropicClient(),
                defaultModel: config('ai.anthropic.model'),
                fallbacks: (bool) config('ai.anthropic.fallbacks'),
            ),
            default => throw new LLMConfigurationException('Proveedor de IA no soportado: ' . config('ai.provider') . '.'),
        });
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
