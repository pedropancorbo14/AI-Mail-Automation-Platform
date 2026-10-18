<?php

return [

    /*
    | Proveedor de IA activo (App\AI\Contracts\LLMProvider): "anthropic" o
    | "simulated" (IA de pruebas sin coste, solo local/testing: ver abajo).
    | OpenAI/Gemini se añadirían como otra implementación.
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
    | IA SIMULADA (AI_PROVIDER=simulated): reglas por palabras clave, sin API
    | ni coste real, para probar el flujo completo, las métricas y Grafana en
    | local. Prohibida en producción y staging (AIServiceProvider).
    */
    'simulated' => [
        'model' => 'simulated',
        // Espera media por llamada (ms) para que latencias y tiempos parezcan reales.
        'delay_ms' => (int) env('AI_SIMULATED_DELAY_MS', 800),
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
        // IA simulada: precio FICTICIO (el de Opus 5.5) para ver costes en las métricas.
        // Su modelo se llama "simulated": nunca se confunde con gasto real.
        'simulated' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        // Embeddings locales (Ollama): sin coste por token. Precio 0 (no null) para que cuenten como gratis.
        'bge-m3' => ['input' => 0.0, 'output' => 0.0, 'cache_write' => 0.0, 'cache_read' => 0.0],
    ],

    /*
    | Embeddings (vectores para la búsqueda semántica del RAG). Se calculan
    | en local con Ollama (contenedor "ollama", solo red interna): los
    | documentos de las empresas no salen del servidor.
    */
    'embeddings' => [
        'provider' => env('AI_EMBEDDINGS_PROVIDER', 'ollama'),

        // Dimensiones del vector: deben coincidir con la columna vector(N) de
        // document_chunks. Cambiarlas exige migración y recalcular todo.
        'dimensions' => 1024,

        'ollama' => [
            'base_url' => env('OLLAMA_URL', 'http://ollama:11434'),
            // bge-m3: multilingüe, 1024 dimensiones. Cambiar de modelo obliga a
            // recalcular los embeddings (no son comparables entre modelos).
            'model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
            // En CPU un lote grande tarda: margen amplio.
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
            'batch_size' => (int) env('OLLAMA_BATCH_SIZE', 16),
        ],
    ],

    'rag' => [
        // Tamaño de cada trozo (~4 caracteres por token en español: 2000 ≈ 500 tokens).
        'chunk_max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2000),
        // Texto repetido entre trozos seguidos para no partir una idea por la mitad.
        'chunk_overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 200),

        // Disco PRIVADO para los ficheros originales de los documentos.
        'disk' => env('RAG_DOCUMENTS_DISK', 'local'),

        // Tamaño máximo de un documento subido (MB).
        'max_upload_mb' => (int) env('RAG_MAX_UPLOAD_MB', 20),

        // Conexión de cola del indexado: redis-long (retry_after amplio). En tests, sync.
        'queue_connection' => env('RAG_QUEUE_CONNECTION', 'redis-long'),

        // Trozos devueltos por búsqueda y similitud mínima (coseno, 0–1) para
        // considerarlos relevantes. Con bge-m3: relacionado ≈ 0,5; sin relación ≈ 0,35.
        'search_limit' => (int) env('RAG_SEARCH_LIMIT', 5),
        'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.45),
    ],

    'tools' => [
        // Herramientas que la IA puede usar (App\Tools\Contracts\AITool). Una
        // herramienta que no esté aquí no existe para la IA.
        'available' => [
            App\Tools\Builtin\SearchCompanyDocumentsTool::class,
            App\Tools\Builtin\GetOrderTool::class,
            App\Tools\Builtin\GetCustomerOrdersTool::class,
        ],

        // Activadas para todas las empresas salvo que una las desactive
        // (tabla tenant_tools). El resto hay que activarlas por empresa.
        'default_enabled' => [
            'search_company_documents',
        ],

        // Tamaño máximo (caracteres JSON) del resultado que se devuelve al
        // modelo: protege el contexto y el coste. Si se supera, se recorta y se avisa.
        'max_result_chars' => (int) env('AI_TOOLS_MAX_RESULT_CHARS', 8000),
    ],

    'agent' => [
        // Esfuerzo de razonamiento del agente (uso de herramientas en varios pasos: "medium").
        'effort' => env('AI_AGENT_EFFORT', 'medium'),

        // Tokens por paso (incluye el razonamiento interno y la respuesta final).
        'max_tokens' => (int) env('AI_AGENT_MAX_TOKENS', 8000),

        // Límites duros por email: al superarlos no hay propuesta y va a una persona.
        'max_iterations' => (int) env('AI_AGENT_MAX_ITERATIONS', 6),
        'max_tool_calls' => (int) env('AI_AGENT_MAX_TOOL_CALLS', 10),
        'timeout_seconds' => (int) env('AI_AGENT_TIMEOUT_SECONDS', 120),
        'max_cost_usd' => (float) env('AI_AGENT_MAX_COST_USD', 0.25),

        // Por debajo de esta confianza, la respuesta siempre la revisa una persona.
        'min_confidence' => (float) env('AI_AGENT_MIN_CONFIDENCE', 0.75),
    ],

    'automation' => [
        // Procesar solo cada email al terminar su ingesta (clasificación →
        // agente → envío o revisión humana). AI_CLASSIFY_ON_INGEST es el
        // nombre antiguo (Fase 5) y se sigue aceptando.
        'process_on_ingest' => (bool) env('AI_PROCESS_ON_INGEST', env('AI_CLASSIFY_ON_INGEST', true)),

        // Conexión de cola de los jobs de IA (cola "ai"): redis-long porque
        // procesar un email (clasificación + agente con reintentos del SDK)
        // puede superar los 300 s de retry_after de "redis".
        'queue_connection' => env('AI_QUEUE_CONNECTION', 'redis-long'),

        // Spam y respuestas automáticas solo se descartan sin persona con
        // esta confianza mínima de la clasificación; por debajo, revisión humana.
        'ignore_min_confidence' => (float) env('AI_IGNORE_MIN_CONFIDENCE', 0.8),

        // Freno de emergencia de la plataforma: false = ninguna empresa
        // envía respuestas sin revisión humana (aunque esté en modo "auto").
        'auto_send_enabled' => (bool) env('AI_AUTO_SEND', true),

        // Intenciones a las que la IA responde sola en modo "auto" si la
        // empresa no ha elegido otras (ai:automation --intents=...).
        'default_auto_send_intents' => ['order_status', 'product_question'],

        // Anti-bucles: respuestas automáticas máximas a una misma dirección
        // en 24 h. A partir de ahí, las siguientes las revisa una persona.
        'max_auto_replies_per_address' => (int) env('AI_MAX_AUTO_REPLIES_PER_ADDRESS', 3),

        // Una respuesta más larga no sale sola (ReplyContentGuard): las
        // respuestas seguras son cortas; un texto enorme suele ser un error.
        'max_auto_reply_chars' => (int) env('AI_MAX_AUTO_REPLY_CHARS', 3000),
    ],

    // Motor de IA (App\AI\Remote\AIEngine): "laravel" = el de las Fases 5–9
    // en este proceso; "python" = el microservicio ai-service por eventos en
    // Redis Streams (ver ai-service/README.md y contracts/README.md).
    'engine' => env('AI_ENGINE', 'laravel'),

    // Comunicación con ai-service (solo con AI_ENGINE=python).
    'remote' => [
        // Prefijo de los streams (igual que AI_SERVICE_STREAM_PREFIX del servicio).
        'stream_prefix' => env('AI_STREAM_PREFIX', ''),
        // Consumer group de Laravel en los streams de resultados y nombre de
        // este consumidor (único por proceso consumidor).
        'consumer_group' => env('AI_STREAM_GROUP', 'laravel'),
        'consumer_name' => env('AI_STREAM_CONSUMER', gethostname() ?: 'laravel'),
        // Un email que lleva más de esto en processing sin respuesta se vuelve a
        // publicar (servicio caído, Redis caído al publicar, evento perdido)...
        'reconcile_after_minutes' => (int) env('AI_REMOTE_RECONCILE_MINUTES', 15),
        // ...hasta este número de intentos; después, failed (lo revisa una persona).
        'max_publish_attempts' => (int) env('AI_REMOTE_MAX_ATTEMPTS', 3),
        // Consumidor de resultados: reclamar mensajes abandonados, entregas
        // máximas antes de la cola de errores y espera de cada lectura.
        'claim_idle_ms' => (int) env('AI_REMOTE_CLAIM_IDLE_MS', 60_000),
        'max_deliveries' => (int) env('AI_REMOTE_MAX_DELIVERIES', 3),
        'block_ms' => (int) env('AI_REMOTE_BLOCK_MS', 5_000),
        // Retención en los streams (llevan datos personales).
        'retention_hours' => (int) env('AI_REMOTE_RETENTION_HOURS', 168),
        // Contraseña del rol de PostgreSQL de solo lectura de ai-service
        // (ai_service_reader: base de conocimiento, con RLS por empresa).
        'db_password' => env('AI_SERVICE_DB_PASSWORD', 'reader-secret'),
        // Contraseña del rol de los checkpoints de LangGraph de ai-service
        // (ai_service_checkpoints: solo su esquema ai_service).
        'checkpoints_db_password' => env('AI_SERVICE_CHECKPOINTS_DB_PASSWORD', 'checkpoints-secret'),
        // API interna síncrona de ai-service (búsqueda en la documentación para
        // el panel, herramientas disponibles por empresa). Solo red interna.
        'service_url' => env('AI_SERVICE_URL', 'http://ai-service:8000'),
        'internal_token' => env('AI_SERVICE_INTERNAL_TOKEN'),
        'http_timeout' => (int) env('AI_SERVICE_HTTP_TIMEOUT', 15),
        // Caducidad del enlace firmado con el que ai-service descarga un documento
        // para indexarlo, y cuándo se vuelve a publicar uno que sigue "Indexando".
        'document_link_minutes' => (int) env('AI_DOCUMENT_LINK_MINUTES', 30),
        'document_reconcile_after_minutes' => (int) env('AI_DOCUMENT_RECONCILE_MINUTES', 30),
    ],

    // Freno del gasto (App\Security\AiSpendGuard): superado un límite, el
    // email va a revisión humana sin llamar a la IA.
    'limits' => [
        // Presupuesto diario por empresa en USD (desde las 00:00 de la zona
        // horaria de la app). Vacío = sin límite. Una empresa puede tener
        // otro (ai:budget <empresa> --daily=...).
        'tenant_daily_budget_usd' => ($budget = env('AI_TENANT_DAILY_BUDGET_USD', 10)) === null || $budget === '' ? null : (float) $budget,

        // Emails de una misma dirección que pasan por la IA en 24 h (contra
        // inundaciones y bucles con otros sistemas). 0 = sin límite.
        'max_ai_emails_per_sender_per_day' => (int) env('AI_MAX_EMAILS_PER_SENDER', 20),
    ],

    'classification' => [
        // Esfuerzo de razonamiento: clasificar es sencillo → "low" (menos tokens y latencia).
        'effort' => env('AI_CLASSIFICATION_EFFORT', 'low'),

        // Incluye el razonamiento interno del modelo además del JSON.
        'max_tokens' => (int) env('AI_CLASSIFICATION_MAX_TOKENS', 4096),

        // Caracteres del cuerpo enviados al modelo. Si se recorta, queda
        // registrado (no se trunca en silencio) y se avisa al modelo.
        'max_body_chars' => (int) env('AI_CLASSIFICATION_MAX_BODY_CHARS', 20000),
    ],

];
