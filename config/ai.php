<?php

return [

    /*
    | Proveedor de IA activo (App\AI\Contracts\LLMProvider). De momento solo
    | "anthropic"; OpenAI/Gemini se añadirían como otra implementación.
    */
    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        // Sin clave la IA queda desactivada: la ingesta sigue y la clasificación se omite.
        'api_key' => env('ANTHROPIC_API_KEY'),

        // Modelo por defecto. Cambiarlo es solo cambiar esta variable (y tener su precio abajo).
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),

        // Segundos por petición y reintentos del SDK (429, 5xx, red).
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 60),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),

        // Fallbacks de servidor: si los clasificadores de seguridad rechazan la
        // petición, Anthropic la reintenta con otro modelo en la misma llamada.
        'fallbacks' => (bool) env('ANTHROPIC_FALLBACKS', true),
    ],

    /*
    | Precios en USD por millón de tokens (tarifa de la API de Anthropic,
    | consultada 2026-09-25). cache_write = escritura de caché de 5 min
    | (1,25 × entrada). Un modelo sin precio aquí registra el coste como null.
    */
    'pricing' => [
        'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 0.25],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
    ],

    'classification' => [
        // Clasificar al guardar cada email (evento EmailIngested).
        'on_ingest' => (bool) env('AI_CLASSIFY_ON_INGEST', true),

        // Esfuerzo de razonamiento: clasificar es sencillo → "low" (menos tokens y latencia).
        'effort' => env('AI_CLASSIFICATION_EFFORT', 'low'),

        // Incluye el razonamiento interno del modelo además del JSON.
        'max_tokens' => (int) env('AI_CLASSIFICATION_MAX_TOKENS', 4096),

        // Caracteres del cuerpo enviados al modelo. Si se recorta, queda
        // registrado (no se trunca en silencio) y se avisa al modelo.
        'max_body_chars' => (int) env('AI_CLASSIFICATION_MAX_BODY_CHARS', 20000),
    ],

];
