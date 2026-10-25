<?php

/*
| IA de la plataforma. Toda la lógica de IA (clasificación, agente,
| herramientas, búsqueda e indexado de documentos) está en el microservicio
| ai-service (ai-service/README.md); Laravel le publica los emails por Redis
| Streams, aplica sus resultados con sus propias reglas de envío y registra
| las llamadas y su coste. Aquí: precios, automatización, límites y la
| comunicación con ai-service.
*/

return [

    /*
    | Precios en USD por millón de tokens (tarifa de la API de Anthropic,
    | consultada 2026-09-25). cache_write = escritura de caché de 5 min
    | (1,25 × entrada). Con ellos Laravel calcula el coste de cada llamada que
    | hace ai-service (CostCalculator: una sola tabla de precios). Un modelo
    | sin precio aquí registra el coste como null.
    */
    'pricing' => [
        'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 0.25],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        // Haiku 5.5: tarifa de prompts de hasta 100K tokens (encima, 0,50/2,50 $).
        // Aquí nunca se llega: el cuerpo del email va recortado y cada email
        // tiene un límite de tokens en ai-service.
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
        // IA simulada de ai-service (AI_SERVICE_LLM_PROVIDER=simulated): precio FICTICIO (el de Opus 5.5) para ver costes en las métricas.
        // Su modelo se llama "simulated": nunca se confunde con gasto real.
        'simulated' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        // Embeddings locales (Ollama): sin coste por token. Precio 0 (no null) para que cuenten como gratis.
        'bge-m3' => ['input' => 0.0, 'output' => 0.0, 'cache_write' => 0.0, 'cache_read' => 0.0],
    ],

    'rag' => [
        // Disco PRIVADO para los ficheros originales de los documentos (los
        // indexa ai-service descargándolos con un enlace firmado).
        'disk' => env('RAG_DOCUMENTS_DISK', 'local'),

        // Tamaño máximo de un documento subido (MB; el mismo que
        // AI_SERVICE_DOCUMENT_MAX_MB de ai-service).
        'max_upload_mb' => (int) env('RAG_MAX_UPLOAD_MB', 20),
    ],

    'automation' => [
        // Procesar solo cada email al terminar su ingesta (filtros → ai-service →
        // envío o revisión humana). AI_CLASSIFY_ON_INGEST es el nombre antiguo
        // (Fase 5) y se sigue aceptando.
        'process_on_ingest' => (bool) env('AI_PROCESS_ON_INGEST', env('AI_CLASSIFY_ON_INGEST', true)),

        // Conexión de cola de ProcessEmailJob (cola "ai"): solo aplica los
        // filtros y publica el email para ai-service (rápido). En tests, sync.
        'queue_connection' => env('AI_QUEUE_CONNECTION', 'redis'),

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

        // Un correo cuya fecha (cabecera Date) tiene más de estos días no va a
        // la IA: lo revisa una persona. Pasa al mover un correo antiguo a una
        // carpeta que se sincroniza (en IMAP recibe un UID nuevo y se importa
        // como nuevo): la IA respondería a una conversación vieja. Reprocesarlo
        // a mano sí lo manda a la IA. 0 = sin límite.
        'max_email_age_days' => (int) env('AI_MAX_EMAIL_AGE_DAYS', 7),
    ],

    // Comunicación con ai-service (eventos en Redis Streams, ver
    // contracts/README.md, y su API interna).
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
        // ...hasta este número de intentos; después, revisión humana (modo degradado).
        'max_publish_attempts' => (int) env('AI_REMOTE_MAX_ATTEMPTS', 3),
        // Consumidor de resultados: reclamar mensajes abandonados, entregas
        // máximas antes de la cola de errores y espera de cada lectura.
        'claim_idle_ms' => (int) env('AI_REMOTE_CLAIM_IDLE_MS', 60_000),
        'max_deliveries' => (int) env('AI_REMOTE_MAX_DELIVERIES', 3),
        'block_ms' => (int) env('AI_REMOTE_BLOCK_MS', 5_000),
        // Retención en los streams (llevan datos personales).
        'retention_hours' => (int) env('AI_REMOTE_RETENTION_HOURS', 168),
        // Contraseña del rol de los checkpoints de LangGraph de ai-service
        // (ai_service_checkpoints: solo su esquema ai_service, donde también
        // guarda los fragmentos de la base de conocimiento).
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
        // Solo para mostrar en el panel y en security:check: los decide
        // ai-service (mismas variables del .env que lee su contenedor).
        'llm_provider' => env('AI_SERVICE_LLM_PROVIDER', 'anthropic'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'classify_model' => env('ANTHROPIC_CLASSIFY_MODEL') ?: env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'draft_model' => env('ANTHROPIC_DRAFT_MODEL') ?: env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'min_confidence' => (float) env('AI_SERVICE_MIN_CONFIDENCE', 0.85),
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
        // Caracteres del cuerpo que se mandan a ai-service. Si se recorta, va
        // marcado en el evento (body_truncated): nunca en silencio.
        'max_body_chars' => (int) env('AI_CLASSIFICATION_MAX_BODY_CHARS', 20000),
    ],

];
