# Contrato de eventos entre Laravel y ai-service

Fuente de verdad del intercambio de mensajes por **Redis Streams**. La usan
los dos lados:

- **ai-service (Python):** modelos Pydantic en
  `ai-service/src/ai_service/application/contracts/events.py` y tests en
  `ai-service/tests/contract/`.
- **Laravel (PHP):** validación con `opis/json-schema` y tests en Pest.

Los tests de los dos lados recorren **los mismos ejemplos**
(`events/examples/valid/` y `events/examples/invalid/`): si alguien cambia
un esquema o un modelo sin el otro, falla el CI.

| Evento | Stream | Lo publica | Lo consume |
|---|---|---|---|
| [`email.received.v1`](events/email.received.v1.schema.json) | `email.received.v1` | Laravel | ai-service |
| [`email.processed.v1`](events/email.processed.v1.schema.json) | `email.processed.v1` | ai-service | Laravel |
| [`email.processing_failed.v1`](events/email.processing_failed.v1.schema.json) | `email.processing_failed.v1` | ai-service | Laravel |
| [`email.review_resumed.v1`](events/email.review_resumed.v1.schema.json) | `email.review_resumed.v1` | Laravel | ai-service |

Definiciones comunes en [`events/common.schema.json`](events/common.schema.json).

## Reglas

- **Sobre común en todos:** `event_id` (UUID; quien consume no procesa dos
  veces el mismo: Redis Streams entrega *al menos una vez*), `event_type`,
  `event_version`, `occurred_at` (RFC 3339), `trace_id` (para seguir un
  email en Loki de punta a punta), `tenant_id` y `email_id`.
- **`tenant_id` siempre.** Quien procesa un email solo puede usar datos de
  esa empresa, y Laravel comprueba al recibir un resultado que el email es
  de ese `tenant_id` (si no, lo rechaza).
- **Versionado:** añadir un campo **opcional** no rompe la v1 (los
  consumidores ignoran lo que no conocen: `additionalProperties: true` en
  el nivel superior). Quitar, renombrar o hacer obligatorio un campo =
  `v2`, con su propio stream, conviviendo con la v1 mientras se migra.
- **La `decision` es una recomendación.** Laravel la pasa por sus reglas
  (`AutoSendPolicy`, `ReplyContentGuard`) antes de enviar nada.
- **Costes:** `usage.calls` lleva los tokens de cada llamada al modelo.
  El coste lo calcula Laravel con su tabla de precios (`config/ai.php`):
  una sola tabla de precios, y cada llamada queda en `ai_requests` como las
  del motor de Laravel (costes en Grafana y freno de gasto diario).
- **Sin decisión "ignorar":** para spam y respuestas automáticas el
  servicio devuelve `draft` vacío y `human_review`; Laravel aplica su regla
  de siempre (spam/auto_reply con confianza de clasificación ≥ 0,8 →
  `ignored`).
- `sources` (lo que cita la respuesta) y `tool_calls` (herramientas usadas,
  la búsqueda en la documentación incluida, con su resultado): Laravel los
  guarda como una ejecución de agente (`ai_agent_runs` + `ai_tool_calls`),
  que es lo que su revisión del texto acepta como datos.
- Cada llamada lleva `prompt` (`clave@versión`): cambiar el texto de un
  prompt obliga a subir su versión.
- Los mensajes de error nunca llevan secretos ni el contenido del email.

## Acciones con aprobación humana (Fase 4 de ai-service)

Las herramientas que **modifican** datos de la empresa (crear un ticket…)
nunca se ejecutan solas:

1. ai-service las propone en `email.processed.v1` → `pending_action`
   (`action_id`, `tool`, `arguments` construidos por el backend —nunca por
   el modelo—, `summary` para la persona) y deja el grafo **en pausa**
   (checkpoint de LangGraph) con su `thread_id`. Con acción pendiente la
   `decision` nunca es `auto_send`.
2. Una persona la aprueba o la rechaza en el panel → Laravel publica
   `email.review_resumed.v1` (`thread_id`, `action_id`, `approved`).
3. ai-service reanuda ese grafo (comprueba que el `thread_id` es de esa
   empresa y ese email, y que `action_id` es la acción pendiente), ejecuta
   la acción solo si se aprobó, redacta la respuesta y publica un **nuevo**
   `email.processed.v1` con `causation_id` = el `event_id` de la
   reanudación. Si la pausa ya no existe (caducó o ya se resolvió) publica
   `email.processing_failed.v1` con `error_code: expired`.

**Reprocesar con una indicación** no reanuda nada: Laravel publica otro
`email.received.v1` con `review_instruction` y el grafo vuelve a empezar
(con datos frescos de pedidos y documentación).

`expired` es un valor nuevo de un enum (no un campo opcional): se acepta
porque los dos lados se actualizan en el mismo cambio.

## Especialistas y modo sombra (Fase 5 de ai-service)

Campos opcionales (la v1 no se rompe):

- `email.processed.v1` → **`specialists`**: los especialistas que
  consultaron datos (`orders`, `billing`, `support`, `general`); el primero
  es el principal. Sin repetir.
- `tool_call.origin` (en `email.processed.v1` → `tool_calls`): quién
  decidió la llamada. `plan` = el backend, según la intención; `model` = el
  modelo al redactar (solo herramientas de lectura de los especialistas del
  email); `approval` = una acción aprobada por una persona. Sin el campo
  (eventos anteriores) se entiende `plan`.
- **`mode`** (`live` por defecto, o `shadow`) en `email.received.v1`,
  `email.processed.v1` y `email.processing_failed.v1`. En **modo sombra**
  el email lo procesa el motor de Laravel y ai-service procesa una copia
  solo para comparar los dos motores. Una copia en sombra no lleva
  `review_instruction`, nunca deja acciones pendientes ni pausas
  (`pending_action` y `thread_id` a null) y **Laravel nunca la aplica al
  email**: solo guarda el resultado para la comparación.

## Herramientas por empresa (retirada del motor de Laravel)

- **`tools/catalog.json`** (validado por `tools/catalog.schema.json`) es el
  **catálogo único** de herramientas de la IA: nombre, etiqueta y
  descripción para el panel, `kind` (`read` o `write`: las de escritura solo
  se proponen y las aprueba una persona), especialista, si viene activada
  por defecto y si necesita al cliente. Laravel lo usa para el panel y la
  activación por empresa (`tenant_tools`); ai-service, para planificar,
  ofrecer al modelo y ejecutar. Tests en los dos lados comprueban que
  coinciden con lo que implementa cada uno (y con el servidor MCP).
- **`email.received.v1` → `enabled_tools`**: las herramientas activadas en la
  empresa (las calcula Laravel). ai-service **solo usa esas**; ausente o
  null = solo las activadas por defecto en el catálogo (hoy
  `search_company_documents`: nunca datos de clientes sin que la empresa lo
  decida).

## Añadir un ejemplo

Un fichero por caso, con el nombre `<evento>.v<N>--<caso>.json` (sin
`--<caso>` para el ejemplo principal). Los tests de los dos lados lo
recogen solos.
