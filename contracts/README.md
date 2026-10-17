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

## Añadir un ejemplo

Un fichero por caso, con el nombre `<evento>.v<N>--<caso>.json` (sin
`--<caso>` para el ejemplo principal). Los tests de los dos lados lo
recogen solos.
