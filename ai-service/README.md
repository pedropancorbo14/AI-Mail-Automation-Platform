# ai-service: el cerebro de IA de la plataforma

Microservicio en **Python** que decide qué hacer con cada email: lo
clasifica, consulta la documentación y los sistemas de la empresa (más
adelante), redacta una respuesta, calcula su confianza y recomienda si se
envía sola, la revisa una persona o se escala. **Laravel** sigue siendo el
dueño de todo lo demás: ingesta IMAP, empresas, usuarios, panel, estados
del email, reglas finales y envío.

> **Estado:** Fase 1 **completa y probada de punta a punta** (IMAP real →
> Laravel → Redis Streams → este servicio → Laravel → respuesta por SMTP)
> con la IA simulada. El motor por defecto sigue siendo el de Laravel
> (`AI_ENGINE=laravel`): este servicio aún no tiene RAG ni tools (Fases 2
> y 3), así que se activa con `AI_ENGINE=python` cuando se quiera.

## En una entrevista (resumen de 1 minuto)

- **Qué es:** el "cerebro de IA" de una plataforma de atención al cliente
  por email, extraído de un monolito Laravel a un microservicio Python.
- **Cómo se comunica:** eventos versionados (`email.received.v1` →
  `email.processed.v1`) por **Redis Streams con consumer groups**; contrato
  en **JSON Schema compartido** y probado en los dos lados con los mismos
  ejemplos. Entrega al menos una vez → idempotencia en ambos lados.
- **Arquitectura:** **hexagonal + DDD** (dominio puro sin dependencias,
  puertos como `Protocol`, adaptadores para Claude y Redis), comprobada
  por **import-linter** en el CI.
- **IA:** grafo de **LangGraph** (classify → router determinista → draft →
  validate) con **Claude** por langchain-anthropic: structured outputs
  nativos, `effort`, fallbacks de servidor, `stop_reason`.
- **Seguridad:** la IA **recomienda**, Laravel **decide** (reglas
  deterministas antes de enviar nada); reglas del dominio que nunca dejan
  prometer reembolsos; `tenant_id` en cada evento y Laravel rechaza
  resultados de otra empresa; el cuerpo del email se borra de Redis al
  confirmarlo.
- **Operación:** imagen multi-stage sin root, métricas Prometheus,
  dashboard de Grafana, alertas probadas con `promtool`, logs JSON en Loki
  con `trace_id` de punta a punta; CI con ruff, mypy estricto, pytest con
  Redis real, pip-audit y build de la imagen.
- **Algo que salió al probar de verdad:** redis-py corta las lecturas
  bloqueantes de `XREADGROUP` con su timeout de socket por defecto; los
  tests (con `BLOCK` de 100 ms) no lo veían. Ahora hay test de regresión.

## Índice

1. [Por qué un microservicio](#por-qué-un-microservicio)
2. [Arquitectura](#arquitectura)
3. [Contrato de eventos](#contrato-de-eventos)
4. [Cómo ejecutarlo](#cómo-ejecutarlo)
5. [Calidad: tests, tipos y reglas de capas](#calidad-tests-tipos-y-reglas-de-capas)
6. [Observabilidad](#observabilidad)
7. [Decisiones tomadas](#decisiones-tomadas)
8. [Hoja de ruta](#hoja-de-ruta)

## Por qué un microservicio

La plataforma nació como monolito modular en Laravel (y lo sigue siendo
para todo lo que no es IA). Se separa solo la IA porque:

- **El ecosistema de agentes está en Python** (LangGraph, SDK de MCP,
  evaluación de modelos): construirlo en PHP es ir a contracorriente.
- **Carga distinta:** una llamada a Claude tarda segundos y cuesta dinero;
  escala por número de emails, no por peticiones web. Separado, se escala y
  se limita por su cuenta (réplicas del consumidor, presupuesto).
- **Frontera clara:** Laravel publica un evento y recibe un resultado. El
  servicio de IA no toca las tablas de Laravel ni envía correos, así que un
  fallo de la IA nunca puede enviar algo que las reglas de Laravel no
  aprueben.

Lo que **no** se separa: estados, panel, envío, auditoría, revisión humana.
Ahí Laravel ya funciona y está probado.

## Arquitectura

### Flujo completo (objetivo)

```mermaid
flowchart LR
    subgraph Laravel
        ING[Ingesta IMAP] --> PROC[EmailProcessor<br/>RECEIVED → PROCESSING]
        PROC -->|filtros baratos<br/>automáticos, modo off,<br/>presupuesto| PUB[Publica<br/>email.received.v1]
        CONS[Consumidor<br/>email.processed.v1] --> POL[AutoSendPolicy +<br/>ReplyContentGuard]
        POL -->|segura| SEND[APPROVED → SENT]
        POL -->|dudosa| HR[HUMAN_REVIEW<br/>bandeja Fase 13]
    end

    subgraph Redis[Redis Streams]
        S1[(email.received.v1)]
        S2[(email.processed.v1)]
        DLQ[(*.dlq)]
    end

    subgraph AI[ai-service · Python]
        C[Consumidor] --> G[Grafo LangGraph]
        G --> C2[Publica resultado]
    end

    PUB --> S1 --> C
    C2 --> S2 --> CONS
    C -. falla N veces .-> DLQ
```

### Grafo de LangGraph

Objetivo (las Fases 2 y 3 añaden `retrieve` y `tools`):

```mermaid
flowchart LR
    START((inicio)) --> classify
    classify --> router{router<br/>determinista}
    router --> retrieve[retrieve<br/>pgvector · Fase 2]
    retrieve --> tools[tools<br/>MCP · Fase 3]
    tools --> draft
    router --> draft
    draft --> validate[validate<br/>reglas puras]
    validate -->|auto_send| END((fin))
    validate -->|dudosa| human_review[human_review]
    human_review --> END
```

Grafo **actual** (Fase 2), generado por LangGraph con
`agent.graph.get_graph().draw_mermaid()` (las líneas discontinuas son
aristas condicionales):

```mermaid
graph TD;
	__start__([inicio]):::first
	classify(classify)
	retrieve(retrieve)
	draft(draft)
	validate(validate)
	human_review(human_review)
	__end__([fin]):::last
	__start__ --> classify;
	classify -. router .-> retrieve;
	classify -. router .-> validate;
	retrieve --> draft;
	draft --> validate;
	validate -. auto_send .-> __end__;
	validate -. human_review / escalate .-> human_review;
	human_review --> __end__;
```

| Nodo | Qué hace |
|---|---|
| `classify` | `LLMPort.classify` (structured outputs) |
| `router` (arista condicional) | **Determinista, sin IA**: sospecha de manipulación o categoría que no se contesta (spam, respuesta automática) → directo a `validate` sin buscar ni redactar; el resto → `retrieve` |
| `retrieve` | Documentación de **la empresa del email** (RAG). Se registra como la herramienta `search_company_documents`. Si Ollama o la BD fallan, el email sigue y va a revisión |
| `draft` | `LLMPort.draft` con la clasificación y la documentación (`<documentacion>` con fuentes citables); el borrador devuelve `sources` |
| `validate` | `DecisionPolicy` con `Evidence` (lo consultado de verdad): **una cita a algo que no se consultó → revisión humana**, y cualquier problema al consultar también |
| `human_review` | Nodo final: la revisión la hace una persona en el panel de Laravel (Fase 13). `interrupt` + checkpointing en la Fase 4 |

Detalles (`agents/email_graph.py`):

- El estado acumula **todas las llamadas al modelo** (reductor `operator.add`):
  si falla un nodo, `AgentError` lleva también las de los anteriores (se
  facturaron).
- Tope de tiempo del grafo entero (`AI_SERVICE_AGENT_TIMEOUT_SECONDS`, 240 s)
  → error reintentable; `recursion_limit` 10 (el grafo no tiene ciclos).
- El grafo solo importa puertos y dominio (import-linter lo comprueba).

### Capas (arquitectura hexagonal + DDD)

```mermaid
flowchart TB
    E[entrypoints<br/>consumidor de eventos · FastAPI] --> A[agents<br/>grafo LangGraph]
    E --> I[infrastructure<br/>Anthropic · Redis · pgvector · MCP]
    A --> P[application<br/>casos de uso + puertos]
    I --> P
    P --> D[domain<br/>entidades, value objects, reglas puras]
```

| Carpeta | Qué contiene | Puede depender de |
|---|---|---|
| `domain/` | `Email`, `Classification`, `Confidence`, `Decision` y las reglas de negocio (p. ej. confianza < 0,85 → revisión humana) | Nada: solo la biblioteca estándar |
| `application/` | Casos de uso y **puertos** (`LLMPort`, `VectorStorePort`, `ToolPort`, `EventBusPort`) | `domain` |
| `agents/` | Grafo de LangGraph; usa los puertos, nunca un adaptador concreto | `application`, `domain` |
| `infrastructure/` | **Adaptadores**: Anthropic, Redis Streams, pgvector, cliente MCP, logs, métricas | `application`, `domain` |
| `mcp_server/` | Servidor MCP con las tools de CRM, ERP y tickets (Fase 3) | `application`, `domain` |
| `entrypoints/` | Consumidor de eventos y app HTTP: aquí se conectan las piezas | Todo |

Las reglas de la tabla no son una convención: **`lint-imports`** las
comprueba en cada commit y en el CI (ver `[tool.importlinter]` en
`pyproject.toml`). Por ejemplo, si `domain/` importa Pydantic o `agents/`
importa el adaptador de Anthropic, falla.

## Contrato de eventos

JSON Schema en [`/contracts/events/`](../contracts/README.md), **compartido
con Laravel**: los dos lados validan con los mismos esquemas y sus tests
recorren los mismos ejemplos válidos e inválidos. Aquí se reflejan en
modelos Pydantic (`application/contracts/events.py`).

| Evento | Lo publica | Campos principales |
|---|---|---|
| `email.received.v1` | Laravel | `email_id`, `tenant_id`, `from`, `subject`, `body`, `received_at` (+ opcionales `from_name`, `body_truncated`, `attachment_names`, `tenant_name`) |
| `email.processed.v1` | ai-service | `category`, `draft`, `confidence`, `decision` (`auto_send` \| `human_review` \| `escalate`), `tools_used`, `language`, `reasons`, `model`, `classification` (lo que muestra el panel), `usage.calls` (tokens de cada llamada), `sources` (lo que cita la respuesta), `tool_calls` (herramientas usadas con su resultado, la búsqueda incluida), `duration_ms` |
| `email.processing_failed.v1` | ai-service | `error_code` (`llm_config`, `refusal`, `invalid_output`, `timeout`, `internal`), `message`, `retryable`, `usage` |

Todos llevan un sobre común: `event_id` (idempotencia: Redis Streams
entrega *al menos una vez*), `event_type`, `event_version`, `occurred_at` y
`trace_id` (para seguir un email en Loki de punta a punta).

- **Lector tolerante:** campos desconocidos en el nivel superior se
  ignoran; añadir campos opcionales no rompe la v1. Quitar o renombrar
  exige v2.
- **Validación ligera del remitente** (`algo@algo`): Laravel ya aceptó el
  correo; un validador estricto (como `EmailStr`, que rechaza los dominios
  `.test` de desarrollo) dejaría emails reales sin procesar.
- **El coste lo calcula Laravel** con sus precios a partir de los tokens:
  una sola tabla de precios.

## Dominio y regla de decisión

`domain/` es Python puro (dataclasses inmutables, sin bibliotecas): se
explica y se prueba sin nada más.

| Objeto | Qué es |
|---|---|
| `Email` | El email a procesar. Su contenido es un **dato no fiable** |
| `TenantId`, `EmailAddress` | Value objects (empresa; dirección normalizada) |
| `Category` | Intención: las 14 de Laravel. `needs_reply` es falso para spam y respuestas automáticas |
| `Confidence` | 0–1; lo que devuelve un modelo se acota (`clamped`), lo que crea el código se valida |
| `Classification` | Categoría, confianza, resumen, tono, urgencia, datos extraídos. Con sospecha de manipulación siempre pide una persona |
| `Draft` | Borrador: texto, confianza, si pide persona y acciones pendientes |
| `Decision` / `DecisionOutcome` | `auto_send`, `human_review` o `escalate`, con **todos** los motivos |

**`DecisionPolicy.decide(email, clasificación, borrador)`** (reglas
deterministas, sin IA; se devuelven todos los motivos para la bandeja):

- **`escalate`** (a una persona con prioridad): sospecha de prompt
  injection (sin borrador) · amenaza legal (abogado, denuncia, demanda,
  OCU, *lawyer*, *lawsuit*…) · queja con urgencia alta.
- **`human_review`**: categoría que no se contesta (spam, respuesta
  automática; Laravel decide si la ignora) · clasificación o respuesta con
  confianza **< 0,85** (configurable) · la clasificación o la IA piden una
  persona · acciones pendientes · email recortado · sin borrador · **el
  borrador promete un reembolso o confirma una cancelación**
  (`domain/commitments.py`, español e inglés; informar de la política no
  cuenta).
- **`auto_send`** si no se cumple nada de lo anterior. Sigue siendo una
  recomendación: Laravel aplica después sus reglas (modo de la empresa,
  intenciones permitidas, anti-bucles, revisión del texto).

## Puertos y caso de uso

`application/ports.py` define lo que la aplicación necesita, **en el
lenguaje del dominio** (como `Protocol`: un adaptador o un fake no hereda
de nada):

| Puerto | Operaciones | Lo implementa |
|---|---|---|
| `LLMPort` | `classify(email)`, `draft(email, clasificación)` | `infrastructure/llm/` (Anthropic) |
| `EmailAgentPort` | `run(email)` → `AgentOutcome` | `agents/` (grafo de LangGraph) |
| `EventBusPort` | `publish(evento)` | `infrastructure/events/` (Redis Streams) |

`LLMPort` no es "genera texto con este prompt": los prompts y el formato
de salida son detalle del adaptador, así cambiar de proveedor no toca el
grafo. `VectorStorePort` y `ToolPort` llegan con RAG y tools (Fases 2 y 3).

**`ProcessEmail`** (`application/use_cases/process_email.py`): evento →
`Email` del dominio → agente → `email.processed.v1`. Errores:

| Error | Qué hace | Por qué |
|---|---|---|
| Definitivo del modelo (clave/saldo, rechazo, respuesta inválida) | Publica `email.processing_failed.v1` con los tokens gastados | Reintentar cuesta dinero y no cambia nada |
| Temporal (red, 429, 529) | Relanza: el consumidor no confirma el mensaje y se reintenta | Suele resolverse solo |
| Dato imposible (`DomainError`) | Fallo definitivo (`internal`) | |
| Falla la publicación | Relanza: el mensaje no se confirma | Mejor reprocesar que perder el resultado |

Los adaptadores traducen sus errores a `LLMError` y subclases
(`application/errors.py`): fuera del adaptador nunca aparece una
excepción del SDK de Anthropic o de Redis.

## Adaptador de Claude

`infrastructure/llm/anthropic.py` implementa `LLMPort` con
**langchain-anthropic** (`ChatAnthropic`) sobre el SDK oficial:

| Qué | Cómo | Por qué |
|---|---|---|
| Salida estructurada | `with_structured_output(..., method="json_schema", include_raw=True)` → `output_config.format` | En Claude Opus 5.5 **forzar `tool_choice` da 400**, y el método por defecto de langchain (function calling) lo fuerza. `include_raw` da acceso a `stop_reason` y tokens |
| Razonamiento | `reasoning_effort`: `low` al clasificar, `medium` al redactar | En Opus 5.5 el razonamiento no se puede desactivar; `effort` es el control de coste/calidad |
| Rechazos de seguridad | `fallbacks: "default"` + beta `server-side-fallback-2026-07-01`; siempre se mira `stop_reason` (`refusal` → `LLMRefusalError`, `max_tokens` → respuesta inválida) | Anthropic reintenta con otro modelo en la misma llamada; con fallback, **cada modelo que intervino es una llamada** (`usage.iterations`) con sus tokens |
| Errores | 429, 5xx/529 y red → `LLMUnavailableError` (reintentable); 401/403 → configuración; otros 4xx → configuración con el mensaje de la API (p. ej. "credit balance is too low") | Ninguna excepción del SDK sale del adaptador; nunca la clave en un mensaje |
| Valores del modelo | Confianza acotada a 0–1; intención desconocida → respuesta inválida | Structured outputs no admite `minimum`/`maximum` |

**Prompts versionados** (`infrastructure/llm/prompts.py`): portados de
Laravel. `email_classification@1` (igual que el clasificador de Laravel) y
`email_draft@1` (redactar **sin herramientas ni documentación** en la
Fase 1: no afirma pedidos, plazos ni precios; si hacen falta, pide una
persona con confianza baja). Cambiar el texto o el esquema obliga a subir
la versión: un test compara el hash de cada versión, y cada llamada lleva
`prompt: "clave@versión"` en el evento. El contenido del email va dentro de
`<email>` como dato no fiable, y el propio texto no puede cerrar el bloque
(`</email>` → `[/email]`).

> En la Fase 1, sin herramientas, la mayoría de emails que preguntan por
> datos concretos acabarán en revisión humana. Es intencionado: el motor
> de Laravel (con RAG y tools) sigue siendo el de por defecto
> (`AI_ENGINE=laravel`) hasta que el servicio llegue a las Fases 2 y 3.

Variables nuevas:

| Variable | Por defecto | Para qué |
|---|---|---|
| `AI_SERVICE_ANTHROPIC_API_KEY` | — | Clave de Anthropic (sin ella el adaptador no arranca) |
| `AI_SERVICE_ANTHROPIC_MODEL` | `claude-opus-5-5` | Modelo |
| `AI_SERVICE_ANTHROPIC_FALLBACKS` | `true` | Fallbacks de servidor ante rechazos |
| `AI_SERVICE_CLASSIFY_EFFORT` / `AI_SERVICE_DRAFT_EFFORT` | `low` / `medium` | `effort` de cada operación |
| `AI_SERVICE_CLASSIFY_MAX_TOKENS` / `AI_SERVICE_DRAFT_MAX_TOKENS` | `4096` / `8000` | Incluyen el razonamiento |
| `AI_SERVICE_LLM_PROVIDER` | `anthropic` | `anthropic` o `simulated` (solo local/testing) |
| `AI_SERVICE_LLM_TIMEOUT_SECONDS` / `AI_SERVICE_LLM_MAX_RETRIES` | `60` / `2` | Timeout y reintentos del SDK (429, 5xx, red) |
| `AI_SERVICE_MAX_BODY_CHARS` | `20000` | Recorte del cuerpo (avisando al modelo) |
| `AI_SERVICE_MIN_CONFIDENCE` | `0.85` | Umbral de `DecisionPolicy` |
| `AI_SERVICE_AGENT_TIMEOUT_SECONDS` | `240` | Tope del grafo entero |
| `AI_SERVICE_DEBUG_ENDPOINTS` | `false` | Activa `POST /debug/process` (nunca en producción) |

**Tests:** `tests/integration/test_anthropic_adapter.py` usa langchain y el
SDK reales con un **transporte HTTP simulado** (`httpx2.MockTransport`):
comprueba la petición exacta (structured outputs, effort, fallbacks, sin
`tool_choice`) y cada tipo de respuesta y error. Nunca se llama a la API.

## RAG: documentación de la empresa (Fase 2)

**Opción A (decidida):** el servicio usa el **mismo motor de embeddings**
que Laravel (Ollama + `bge-m3`, 1024 dimensiones, vectores normalizados)
pero **solo para buscar**. La indexación (subir, extraer, trocear,
embeddings y guardar) sigue en Laravel, que es el único que escribe en
`documents` y `document_chunks`. Nada del RAG de Laravel se borra: lo usa
el motor por defecto y el panel.

```mermaid
flowchart LR
    Q[asunto + cuerpo<br/>máx. 2000 caracteres] --> O[Ollama bge-m3<br/>EmbeddingPort]
    O --> V[vector normalizado]
    V --> P[(PostgreSQL + pgvector<br/>rol ai_service_reader)]
    P -->|RLS: SET LOCAL app.tenant_id<br/>+ WHERE tenant_id| R[fragmentos de ESA empresa<br/>con su fuente]
```

| Pieza | Dónde | Qué hace |
|---|---|---|
| `EmbeddingPort` → `OllamaEmbeddings` | `infrastructure/embeddings/ollama.py` | `POST /api/embed` igual que Laravel (`truncate`, normalizado, dimensiones comprobadas). Cada búsqueda es una `LLMCall` `ai_service.knowledge_search` (coste 0 en Laravel) |
| `VectorStorePort` → `PgVectorStore` | `infrastructure/knowledge/pgvector.py` | SQLAlchemy 2 async + psycopg. `tenant_id` obligatorio en la firma |
| `KnowledgeRetriever` | `application/services/knowledge.py` | Pregunta → vector → búsqueda (5 resultados, similitud ≥ 0,45). Si Ollama o PostgreSQL fallan, **no lanza**: devuelve el motivo y el email sigue (irá a revisión) |
| `KnowledgeChunk` | `domain/knowledge.py` | Fragmento con su fuente citable, igual que Laravel: "Título, pág. 3" / "Título, sección «X»" |

**Aislamiento entre empresas (tres barreras):**

1. **Rol de solo lectura `ai_service_reader`** con permisos **por columna**:
   ni la ruta del fichero ni el hash, y ninguna otra tabla. No puede escribir.
2. **Row Level Security**: en cada búsqueda `SET LOCAL app.tenant_id`; la
   política solo deja ver esa empresa y **sin valor no ve nada**.
3. **`WHERE tenant_id = :tenant`** en la propia consulta (y solo documentos
   `ready` indexados con el mismo modelo).

El SQL de permisos y RLS está en
[`/contracts/database/`](../contracts/database/README.md): lo aplica una
migración de Laravel y lo ejecutan también los tests de este servicio, así
que no pueden divergir.

**Cómo lo ve Laravel:** cada resultado se registra como una **ejecución de
agente** (`ai_agent_runs`) con sus peticiones y sus herramientas
(`ai_tool_calls`; la búsqueda con los fragmentos encontrados). Así la traza
"Lo que hizo la IA" del panel funciona igual que con el motor de Laravel, y
`ReplyContentGuard` compara la respuesta con lo consultado: un precio citado
de la documentación (14,95 €) deja enviarla sola; uno inventado (9,99 €) la
manda a revisión. El prompt sube a **`email_draft@2`**.

**Tests** (`tests/integration/test_pgvector_store.py`, PostgreSQL + pgvector
real con el rol y RLS): resultados ordenados con su fuente, **una empresa
nunca ve documentos de otra** (ni con el mismo vector), RLS como segunda
barrera aunque la consulta olvidara el filtro, el lector no puede escribir
ni leer otras columnas, solo `ready` y mismo modelo, umbral y límite.
Necesitan `AI_SERVICE_TEST_DATABASE_URL` (el CI levanta
`pgvector/pgvector:pg16`):

```bash
AI_SERVICE_TEST_DATABASE_URL=postgresql+psycopg://app:secret@127.0.0.1:5432/ai_service_test uv run pytest
```

## Eventos: Redis Streams y el worker

**Proceso `ai-service-worker`** (`entrypoints/worker.py`): un solo proceso
asyncio con el **consumidor** y la **app HTTP de operación** (`/health`,
`/ready`, `/metrics`). Van juntos porque las métricas importantes las
genera el consumidor; cada réplica expone las suyas.

```mermaid
sequenceDiagram
    participant L as Laravel
    participant R as Redis (BD 3)
    participant W as ai-service-worker
    L->>R: XADD email.received.v1 {event_id, event_type, payload}
    W->>R: XAUTOCLAIM (abandonados) + XREADGROUP ai-service >
    W->>W: grafo (classify → draft → validate)
    W->>R: XADD email.processed.v1
    W->>R: SET aiservice:processed:<event_id> (24 h)
    W->>R: XACK + XDEL
    L->>R: XREADGROUP laravel (tarea 1.9)
```

| Situación | Qué hace el consumidor |
|---|---|
| Procesado (o fallo definitivo publicado) | `XACK` + `XDEL` (el cuerpo es dato personal: no se queda en Redis) y marca el `event_id` como procesado |
| Entrega repetida del mismo `event_id` | Lo confirma **sin volver a pagar** al modelo |
| No cumple el contrato | A `email.received.v1.dlq` con el motivo; se confirma |
| Error temporal (red, 429, 529, timeout) | No confirma: pasado `claim_idle_ms` (5 min) otra vuelta o réplica lo reclama con `XAUTOCLAIM`. Esa espera hace de *backoff* |
| Error temporal en la última entrega (`max_deliveries`, 3) | Publica `email.processing_failed.v1` (`timeout`) y confirma |
| Error inesperado | Igual; en la última entrega, fallo `internal` + cola de errores |

- **Consumer group** `ai-service` creado desde el principio del stream
  (`id=0`): lo que Laravel publicó antes de arrancar el servicio también se
  procesa. Cada réplica es un consumidor distinto (hostname = pod).
- **Concurrencia:** hasta `concurrency` (4) emails a la vez por réplica
  (`asyncio.gather`); escalar = más réplicas.
- **Retención:** `XTRIM MINID` **exacto** cada minuto (7 días) en el stream
  y en la cola de errores; los eventos que publica se limitan con
  `MAXLEN ~ 100000`.
- **Parada limpia:** SIGTERM → deja de leer, termina lo que tiene en curso
  y cierra la app HTTP (las señales las gestiona el worker, no uvicorn).
- **`/ready`** = Redis responde **y** el bucle gira (latido de menos de
  60 s): Kubernetes deja de mandarle tráfico (y, con la *liveness*, lo
  reinicia) si se cuelga.
- Sin clave de Anthropic el worker **no arranca** y lo dice en el log.

Métricas nuevas (prefijo `aiservice_`): `events_total{result}`,
`event_processing_seconds{result}`, `decisions_total{decision,category}`,
`llm_calls_total{purpose,model,status}`, `llm_tokens_total{purpose,model,type}`,
`llm_call_seconds{purpose,model}`, `stream_pending`, `stream_lag` y
`consumer_heartbeat_timestamp_seconds`.

| Variable | Por defecto | Para qué |
|---|---|---|
| `AI_SERVICE_REDIS_URL` | `redis://localhost:6379/3` | Redis (BD propia, sin prefijo; en el compose con contraseña) |
| `AI_SERVICE_STREAM_PREFIX` | vacío | Prefijo de los streams (p. ej. para entornos compartidos) |
| `AI_SERVICE_CONSUMER_GROUP` / `AI_SERVICE_CONSUMER_NAME` | `ai-service` / hostname | Grupo y nombre del consumidor |
| `AI_SERVICE_CONCURRENCY` | `4` | Emails en paralelo por réplica |
| `AI_SERVICE_CLAIM_IDLE_MS` | `300000` | Cuándo se reclama un mensaje abandonado (mayor que el tope del agente) |
| `AI_SERVICE_MAX_DELIVERIES` | `3` | Entregas antes de darlo por fallido |
| `AI_SERVICE_STREAM_RETENTION_HOURS` | `168` | Retención en los streams |

## Cómo ejecutarlo

Requisitos: [uv](https://docs.astral.sh/uv/) (instala él mismo Python 3.12).

```bash
cd ai-service
uv sync                      # crea .venv con dependencias exactas (uv.lock)
uv run ai-service-http       # solo la app HTTP en http://localhost:8000
# El worker (consumidor + /health, /ready, /metrics) necesita Redis y la clave:
AI_SERVICE_ANTHROPIC_API_KEY=sk-ant-... uv run ai-service-worker
curl localhost:8000/health   # {"status":"ok","service":"ai-service",...}
curl localhost:8000/metrics  # formato Prometheus
```

### Con Docker Compose (stack completo)

ai-service y el consumidor de resultados de Laravel (`ai-results`) van en
el **perfil `ai-service`**: solo se levantan si usas el motor de Python.

```bash
# .env (raíz del proyecto)
AI_ENGINE=python
COMPOSE_PROFILES=ai-service
ANTHROPIC_API_KEY=sk-ant-...        # la usa ai-service, no Laravel

docker compose up -d --build ai-service ai-results
docker compose exec app php artisan migrate     # columnas ai_event_id...
docker compose exec horizon php artisan horizon:terminate   # recoge AI_ENGINE
curl -s 127.0.0.1:8001/ready                    # {"status":"ok",...}
docker compose logs -f ai-service ai-results
```

| Servicio | Imagen | Qué hace |
|---|---|---|
| `ai-service` | `ai-service/Dockerfile` | Worker de Python: consume `email.received.v1`, ejecuta el grafo y publica el resultado. `/health`, `/ready`, `/metrics` en `127.0.0.1:8001` |
| `ai-results` | la de Laravel | `php artisan ai:consume-results`: aplica los resultados (estados, borrador, `ai_requests`, envío) |

**Imagen** (`ai-service/Dockerfile`, multi-stage): la etapa *builder*
instala uv desde PyPI y las dependencias exactas de `uv.lock` (capa
cacheada aparte del código); la de *runtime* solo lleva Python y el
entorno virtual, con un **usuario sin privilegios (uid 10001)** y
`HEALTHCHECK` sobre `/health`. En el compose corre con el sistema de
archivos de **solo lectura**, sin capacidades (`cap_drop: ALL`) y
`no-new-privileges`; `stop_grace_period` de 60 s para terminar los emails
en curso.

**Observabilidad en el stack:**

- **Prometheus** descubre `ai-service` por DNS (job `ai-service`): sin el
  perfil no hay target ni alertas.
- **Promtail** etiqueta los logs de los contenedores con `service` (el del
  compose) y, para `ai-service`, parsea el JSON: `level` como etiqueta.
  Seguir un email: `{service="ai-service"} | json | trace_id="..."`.
- **Grafana**: dashboard **"AI Mail · ai-service"** (emails procesados,
  fallos, reintentos, % recomendado para enviar solo, tiempos, latencia
  del modelo, tokens, categorías, resultados aplicados en Laravel y logs).
- **Alertas** (`docker/prometheus/alerts.yml`, grupo `ai-service`, probadas
  con `promtool`): `ServicioIANoResponde`, `ConsumidorIAParado` (latido de
  más de 2 min), `ColaIAAtascada` (más de 50 sin leer durante 10 min),
  `EmailsIAFallidos`, `ResultadoIAEmpresaIncorrecta` (crítica: Laravel
  descartó un resultado con otra empresa) y `ResultadosIAEnColaDeErrores`.

### Configuración

Variables de entorno con prefijo `AI_SERVICE_` (o un `.env` en esta
carpeta). Valores no válidos hacen fallar el arranque, nunca se ignoran.

| Variable | Por defecto | Para qué |
|---|---|---|
| `AI_SERVICE_ENVIRONMENT` | `local` | `local`, `testing` o `production` (en producción no hay `/docs`) |
| `AI_SERVICE_LOG_LEVEL` | `INFO` | `DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL` |
| `AI_SERVICE_HTTP_HOST` | `0.0.0.0` | Interfaz de la app HTTP (dentro del contenedor) |
| `AI_SERVICE_HTTP_PORT` | `8000` | Puerto de la app HTTP |

### Endpoints HTTP

FastAPI **no procesa emails** (eso lo hace el consumidor de eventos): solo
sirve para operar el servicio.

| Ruta | Para qué |
|---|---|
| `GET /health` | *Liveness*: el proceso responde (Kubernetes lo reinicia si falla) |
| `GET /metrics` | Métricas Prometheus |
| `GET /docs` | Documentación OpenAPI (no en producción) |
| `POST /debug/process` | Procesa un email de prueba con el modelo **real** y devuelve la decisión, el borrador y las llamadas, sin eventos. Solo con `AI_SERVICE_DEBUG_ENDPOINTS=true` y nunca en producción (cuesta dinero) |

`/ready` (Redis disponible) llega con el consumidor en la tarea 1.7.

```bash
AI_SERVICE_DEBUG_ENDPOINTS=true AI_SERVICE_ANTHROPIC_API_KEY=sk-ant-... uv run ai-service-http
curl -s localhost:8000/debug/process -H 'Content-Type: application/json' \
  -d '{"from": "cliente@cliente.test", "subject": "Envíos", "body": "¿Enviáis a Canarias?"}'
```

## Calidad: tests, tipos y reglas de capas

```bash
uv run pytest               # tests
uv run ruff check .         # lint (incluye reglas de seguridad de bandit)
uv run ruff format --check .
uv run mypy                 # tipos en modo estricto (src y tests)
uv run lint-imports         # reglas de la arquitectura hexagonal
```

- `tests/unit/`: sin red ni servicios.
- `tests/contract/`: los modelos Pydantic frente a los JSON Schema
  compartidos, con los ejemplos de `/contracts/events/examples/`.
- `tests/integration/`: piezas reales conectadas: app HTTP, adaptador de
  Claude con HTTP simulado y **Redis real** (consumer group, reintentos con
  `XAUTOCLAIM`, cola de errores, duplicados, recorte, worker completo).
  Necesitan `AI_SERVICE_TEST_REDIS_URL` (el CI lo define; sin ella se
  saltan):

  ```bash
  AI_SERVICE_TEST_REDIS_URL=redis://127.0.0.1:6379/15 uv run pytest
  ```
- **El LLM siempre se simula en los tests**: nunca se llama a la API real
  en el CI.

**CI** (job `ai-service` de `.github/workflows/tests.yml`): ruff, formato,
mypy, import-linter, pytest con Redis real, **pip-audit** (vulnerabilidades
conocidas en las dependencias de `uv.lock`) y **construcción y arranque de
la imagen Docker** (comprueba `/ready`, `/health` y que corre sin root).

## Observabilidad

- **Logs:** JSON, una línea por entrada, a stdout. Campos: `timestamp`
  (UTC), `level` (en mayúsculas, como `level_name` de Laravel, para filtrar
  igual en Grafana), `logger`, `service`, `message` y los datos de cada
  línea. Durante el procesamiento de un email se añaden `trace_id`,
  `tenant_id` y `email_id` a todas las líneas (`bind_context`). Los logs de
  uvicorn y de otras bibliotecas también salen en JSON.
- **Métricas:** prefijo `aiservice_`, en `/metrics`. De momento
  `aiservice_build_info` y las del proceso (CPU, memoria, GC); cada tarea
  añade las suyas.

## Decisiones tomadas

| Decisión | Alternativas | Por qué |
|---|---|---|
| **uv** para dependencias | pip + venv, Poetry | Un solo binario, `uv.lock` reproducible, muy rápido en CI y en Docker |
| Paquete en `src/ai_service` | Carpeta plana | Los tests importan el paquete instalado, no archivos sueltos: detecta errores de empaquetado |
| **structlog** sobre la biblioteca estándar | `python-json-logger`, solo stdlib | Contexto por tarea con `contextvars` (`trace_id` en todas las líneas sin pasarlo a mano) y JSON también para uvicorn |
| JSON Schema compartido + modelos Pydantic escritos a mano + tests de contrato con los mismos ejemplos | Generar código desde el esquema (datamodel-code-generator); Pact | Sin generador que mantener; el esquema lo lee también PHP; los ejemplos compartidos detectan cualquier divergencia |
| Dominio con **dataclasses** inmutables | Pydantic en el dominio | El dominio no depende de ninguna biblioteca (import-linter lo prohíbe); Pydantic se queda en los bordes (contratos y config) |
| Reglas de decisión **deterministas** | Preguntar al modelo si es seguro enviar | Explicables, auditables, sin coste y sin poder ser manipuladas por el contenido del email |
| Puertos con `typing.Protocol` | Clases abstractas (ABC) | Tipado estructural: los adaptadores y los fakes no dependen de la capa de aplicación para heredar |
| `LLMPort` con operaciones del dominio | Puerto genérico `generate(prompt)` | Prompts y structured outputs quedan en el adaptador; el grafo no cambia si cambia el proveedor |
| **langchain-anthropic** con `method="json_schema"` | SDK oficial directo; langchain con function calling | Encaja con LangGraph y lo pedía la oferta; se comprobó (inspeccionando la petición) que envía `output_config` con `effort` y `format` y que no fuerza `tool_choice`. Si una función nueva de la API no llegara a langchain, el adaptador puede pasar al SDK sin tocar el grafo |
| Una `LLMCall` por modelo con fallback | Sumar todo en una | Cada modelo factura a su tarifa: Laravel calcula bien el coste |
| Router **determinista** | Que el modelo elija el siguiente paso | Predecible, gratis y no manipulable por el contenido del email; la IA se usa donde aporta (clasificar, redactar) |
| Raíz de composición en `entrypoints/container.py` | Inyección con un framework de DI | Pocas piezas: una función por grafo de objetos basta y se lee de un vistazo |
| **Redis Streams** con consumer groups | Colas de Laravel (Horizon) compartidas; Kafka; RabbitMQ | Redis ya está en la plataforma; los streams dan grupos, confirmación, reclamación y reproceso sin otra pieza. Kafka sería infraestructura de más para este volumen |
| Reintento = no confirmar + `XAUTOCLAIM` | Re-publicar el mensaje con un contador | Una sola copia del mensaje; el contador de entregas lo lleva Redis |
| Idempotencia con `SET event_id` (24 h) además del estado en Laravel | Solo la de Laravel | Si el proceso muere entre publicar y confirmar, no se vuelve a pagar al modelo |
| Consumidor y HTTP de operación en el mismo proceso | Dos procesos | Las métricas son del consumidor; un contenedor = un proceso = una réplica |
| Perfil de Compose `ai-service` | Servicios siempre activos | Quien sigue con el motor de Laravel no levanta nada nuevo ni ve alertas de un servicio que no usa |
| uv instalado con `pip` en la imagen | `COPY --from=ghcr.io/astral-sh/uv` | Una dependencia de registro menos (y en algunos entornos ghcr está bloqueado) |
| RAG: Python solo **lee** (opción A) | Mover también la indexación a Python | Un solo escritor de las tablas, sin romper el motor de Laravel ni el panel; la indexación se moverá con eventos cuando se retire el motor de Laravel |
| RLS + rol por columnas + filtro en la consulta | Solo el filtro en la consulta | Un bug en una consulta no puede filtrar datos de otra empresa: la base de datos lo impide |
| Registro de métricas **propio** por app | Registro global de `prometheus_client` | Tests aislados, sin "métrica duplicada" |
| **import-linter** para las capas | Revisión manual | La arquitectura hexagonal se rompe en silencio; así falla el CI |
| mypy **estricto** con plugin de Pydantic, también en los tests | Solo `src`, modo normal | Los tests son código: un fake mal tipado oculta errores |
| FastAPI solo para operar | Endpoint de procesamiento HTTP | El trabajo entra por eventos (desacoplado, con reintentos); HTTP síncrono acoplaría Laravel a la latencia de Claude |

Decisiones de diseño acordadas para las siguientes tareas:

- **Laravel tiene la última palabra sobre el envío.** La `decision` de
  Python es una recomendación: Laravel la pasa por `AutoSendPolicy` y
  `ReplyContentGuard` (modo de la empresa, intenciones permitidas,
  anti-bucles, límites por remitente), que Python no conoce. Defensa en
  profundidad.
- **`email.received.v1` se publica tras los filtros baratos** de Laravel
  (correos automáticos, empresa en modo `off`, presupuesto agotado): nunca
  se paga IA por ellos.
- **`usage` y `model` en el evento**: Laravel registra cada uso en
  `ai_requests`, del que dependen los costes en Grafana y el freno de
  gasto diario. Python no escribe en tablas de Laravel.
- **`human_review` es un nodo final en la Fase 1.** El `interrupt` de
  LangGraph + checkpointing llega en la Fase 4 para lo que sí lo necesita:
  reprocesar con una indicación de la persona y aprobar tools que
  modifican datos. Si se interrumpiera en cada revisión, quedarían miles de
  hilos suspendidos que nadie reanuda (con datos personales).

## Prueba de punta a punta (2026-10-17)

Stack de la sesión: PostgreSQL + Redis + GreenMail reales, Laravel
(`AI_ENGINE=python`, "Empresa Demo" en modo `auto`, buzón IMAP de
GreenMail) y el worker con la **IA simulada**
(`AI_SERVICE_LLM_PROVIDER=simulated`). Cuatro correos por SMTP → `mail:sync`
→ ingesta → `email.received.v1` → worker → `email.processed.v1` →
`ai:consume-results`:

| Email | Recomendación de ai-service | Lo que hizo Laravel |
|---|---|---|
| "¿Hacéis envíos a Canarias?" | `auto_send` (0,90) | processing → ai_review → approved → **sent**: llegó al buzón del cliente como "Re: …", en el hilo (`In-Reply-To`) y con `Auto-Submitted: auto-replied` |
| "¿Dónde está mi pedido #45821?" | `human_review` (sin datos del pedido en la Fase 1) | **human_review** con el borrador y los motivos |
| "Has ganado un premio…" | spam, sin borrador | **ignored** (spam con confianza ≥ 0,8) |
| "Es inaceptable… mi abogado" | `escalate` (amenaza legal) | **human_review** con todos los motivos, incluido que la empresa no deja responder solas a las quejas |

7 llamadas al modelo registradas en `ai_requests` con su coste (precio
ficticio del modelo `simulated`). **Bug encontrado y corregido:** el
timeout de socket de redis-py (ver el resumen de arriba).

Reproducirlo en tu equipo:

```bash
# .env: AI_ENGINE=python, COMPOSE_PROFILES=ai-service,
#       AI_SERVICE_ENVIRONMENT=local, AI_SERVICE_LLM_PROVIDER=simulated
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan ai:automation 1 --mode=auto
docker compose exec horizon php artisan horizon:terminate
# Envía un correo a soporte@demo.test (GreenMail, SMTP 127.0.0.1:3025) y mira:
docker compose logs -f ai-service ai-results
```

### IA simulada

`AI_SERVICE_LLM_PROVIDER=simulated` (`infrastructure/llm/simulated.py`):
las mismas reglas por palabras clave que la IA simulada de Laravel, sin red
ni coste; modelo `simulated` (Laravel le da un precio ficticio para ver
costes en Grafana). Solo responde con confianza a preguntas generales
(`product_question`). **Prohibida fuera de `local`/`testing`.**

## Hoja de ruta

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Camino mínimo: evento → classify → draft → validate → evento → Laravel actualiza el estado | ✅ Hecha (probada de punta a punta con la IA simulada; pendiente de que la pruebes tú) |
| 2 | RAG con pgvector (rol de solo lectura, RLS por empresa) | Pendiente |
| 3 | Servidor MCP (CRM, ERP, tickets simulados) y tools | Pendiente |
| 4 | Checkpointing, `interrupt` y límites del agente | Pendiente |
| 5 | Supervisor con subagentes y modo *shadow* (comparar con el motor de Laravel) | Pendiente |
| 6 | Kubernetes (probado en kind) | Pendiente |
