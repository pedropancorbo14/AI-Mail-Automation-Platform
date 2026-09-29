# AI Mail Automation Platform — Contexto del proyecto

Este documento es la especificación completa del proyecto tal como se
definió al inicio, más el estado real de avance. Léelo entero antes de
proponer o escribir código: fija decisiones de arquitectura, alcance de
cada fase, y la forma de trabajar acordada.

---

## 1. Objetivo del proyecto

Plataforma de automatización inteligente de emails para empresas. Una
empresa conecta sus cuentas de correo y la plataforma:

1. Recibe emails.
2. Analiza y clasifica automáticamente cada email mediante IA.
3. Entiende la intención del usuario.
4. Consulta información de sistemas internos de la empresa: CRM, ERP,
   sistema de pedidos, sistema de facturación, documentación interna,
   sistema de tickets, etc.
5. Utiliza herramientas (tools) para obtener o modificar información.
6. Genera una respuesta utilizando toda esa información.
7. Decide si: responde automáticamente, genera un borrador para un
   trabajador, o escala el caso a una persona.
8. Registra absolutamente todo lo relevante para poder auditar qué hizo
   la IA.
9. Muestra estadísticas y rendimiento mediante Grafana.

No es un chatbot. Es una plataforma de IA integrada con los sistemas
reales de una empresa, capaz de automatizar procesos de comunicación.

## 2. Ejemplo del flujo principal

Cliente: "Hola, ¿dónde está mi pedido #45821?"

```
Email → Proveedor de correo → Laravel → Queue/Redis → AI Engine →
Clasificación de intención → Extracción de información → Tool Calling →
CRM/ERP/API → Obtiene pedido → Obtiene estado de envío → LLM genera
respuesta → Evalúa confianza/reglas → Enviar automáticamente o enviar a
revisión humana → Registrar resultado → Métricas para Grafana
```

Otro ejemplo: "Quiero devolver el producto que compré hace dos semanas."
La IA detecta la intención, consulta la política de devoluciones en la
documentación interna (RAG), consulta el pedido del cliente y genera una
respuesta apropiada. Si el caso es ambiguo o peligroso, lo envía a un
humano.

## 3. Email

La plataforma debe trabajar con diferentes proveedores de correo, sin
integración específica solo para Outlook o Gmail — arquitectura basada en
protocolos (IMAP/SMTP), no en clientes de correo (Thunderbird NO es un
proveedor, es un cliente).

- Inicialmente: IMAP (recepción) + SMTP (envío).
- Después: Microsoft Graph/365, Gmail API, OAuth.
- Interfaces intercambiables: `MailProvider`, `MailService`,
  `MailProviderFactory`. Implementaciones: `IMAPProvider`,
  `MicrosoftGraphProvider`, `GmailProvider`.

## 4. Stack tecnológico

- **Backend:** PHP 8.4, Laravel 11/12 (actualmente en Laravel 13), Redis,
  Laravel Queues, Laravel Horizon, REST API, Laravel Sanctum.
- **Frontend/admin:** Vite, Vue, Naive UI.
- **Infraestructura:** Docker, Docker Compose, Nginx.
- **IA:** OpenAI inicialmente, Anthropic/Gemini posteriormente —
  Structured Outputs, Tool Calling, Embeddings, RAG.
- **Observabilidad:** Prometheus, Grafana, Loki, OpenTelemetry.
- **Testing:** Pest/PHPUnit.
- **CI/CD:** GitHub Actions.

## 5. Docker

Arquitectura inicial simple, sin microservicio por cada cosa: monolito
modular en Laravel. Servicios: Laravel/PHP-FPM, Nginx, PostgreSQL, Redis,
Queue Worker, Scheduler, Prometheus, Grafana, Loki. El worker y el
scheduler usan la misma imagen que la app.

## 6. PostgreSQL

Base de datos principal, preparada desde el inicio para PostgreSQL +
pgvector (RAG sin BD vectorial aparte). Tablas futuras (fase a fase, no de
golpe): tenants, users, mail_connections, emails, email_recipients,
email_attachments, ai_requests, ai_prompt_versions, documents,
document_chunks, ai_tool_calls, audit_logs, automation_rules, etc.

## 7. Multi-tenant

Preparado desde el principio para múltiples empresas. Tenant = empresa. Un
usuario pertenece a un tenant. Los datos de una empresa nunca deben poder
ser consultados por otra. Se tiene en cuenta desde: modelos, migraciones,
servicios, policies, middleware, queries, tests, APIs. No construir
primero single-tenant y convertir después.

## 8. AI Engine

Capa de abstracción para proveedores de IA: `LLMProvider`, con
implementaciones `OpenAIProvider`, `AnthropicProvider`, `GeminiProvider`.
Servicios: `AIService`, `AgentService`, `PromptService`, `ToolRegistry`,
`ToolExecutor`. Cada ejecución de IA se registra en `ai_requests`:
tenant_id, provider, model, prompt/version, tokens entrada/salida, coste,
latencia, status, error, intención detectada, confidence, email
relacionado.

## 9. Tools / Tool Calling

La IA NUNCA accede directamente a la base de datos de la empresa — usa
herramientas controladas por el backend. Ejemplos: `get_customer`,
`get_customer_orders`, `get_order`, `get_order_status`,
`search_company_documents`, `create_support_ticket`, `get_invoice`,
`send_email`. Interfaz `AITool` + `ToolRegistry` (registra herramientas
disponibles) + `ToolExecutor` (valida parámetros, comprueba permisos,
ejecuta, controla errores, registra la ejecución en `ai_tool_calls`:
ai_request_id, tool, arguments, result, status, duration, error).

## 10. RAG

La IA puede consultar documentación interna (PDFs, manuales, políticas,
FAQs, procedimientos, contratos). Flujo: Documento → Extracción de texto →
Chunking → Embeddings → PostgreSQL + pgvector → Semantic Search →
Contexto para LLM. Capa: `KnowledgeBaseService`, `DocumentService`,
`EmbeddingService`, `SemanticSearchService`. La respuesta de la IA debe
poder indicar de qué documentación procede la información.

## 11. AI Agent

El sistema evoluciona desde clasificación simple hasta un agente capaz de
usar varias herramientas encadenadas (identificar pedido → consultar
pedido → consultar envío → consultar política de cancelación vía RAG →
generar respuesta). Debe existir: límite de iteraciones, timeout, control
de errores, validación de outputs, control de herramientas, confidence
threshold, human-in-the-loop.

## 12. Human in the loop

La IA no tiene control absoluto. Estados: RECEIVED → PROCESSING →
AI_REVIEW → HUMAN_REVIEW → APPROVED → SENT → FAILED. Si la IA no tiene
suficiente confianza o el caso requiere intervención humana, va a
HUMAN_REVIEW. El trabajador puede ver el email, la intención detectada, la
respuesta propuesta, la información consultada, las tools utilizadas;
puede editar, aprobar, rechazar, enviar. Toda decisión humana queda
registrada.

## 13. Seguridad

Credenciales de email cifradas, OAuth tokens protegidos, API keys
protegidas, RBAC, Policies, tenant isolation, rate limiting, validación de
inputs, audit logs, control de acceso a tools, protección contra prompt
injection, límites de herramientas, límites de iteraciones del agente,
timeouts, logs, gestión de secretos, políticas de retención y eliminación
de datos. La IA nunca debe poder ejecutar SQL arbitrario, comandos del
sistema o acciones no autorizadas.

## 14. Grafana / Observabilidad

Grafana como panel visual, Prometheus recopila métricas, Loki
almacena/consulta logs, OpenTelemetry para tracing. Visualizar:
- **Emails:** recibidos, procesados, automatizados, enviados a humanos,
  errores, tiempo medio de procesamiento.
- **IA:** número de requests, tokens, coste, latencia, errores, modelos
  usados, confidence, tool calls, tool errors.
- **Negocio:** % automatizado, % escalado a humanos, intenciones más
  frecuentes, tiempo ahorrado, volumen por empresa.
- **Costes:** por modelo, diario, mensual, tokens.
Dashboards separados: General, AI, Emails, Errors, Costs, Performance.

## 15. Arquitectura

```
Mail Provider → Mail Ingestion → Queue → AI Engine → Classification →
RAG/Tools → Agent → Decision → Human Review o Send → Observability
```
Para integraciones externas: `CRMProvider`/`CRMService`,
`ERPProvider`/`ERPService`, `TicketProvider`/`TicketService` — el núcleo de
la app no depende directamente de sistemas externos concretos.

## 16. Fases del proyecto

1. Base del proyecto (repo, Laravel, PHP 8.4, Docker, Postgres, Redis,
   Nginx, env vars, Sanctum, Queue, Horizon, logging, API /api/v1,
   Pest/PHPUnit, GitHub Actions, README).
2. Empresas y usuarios (tenants, users, roles, permisos, login/logout,
   Sanctum, middleware tenant, policies, tests de aislamiento).
3. Conexiones de email (MailProvider, MailProviderFactory,
   mail_connections, IMAP, SMTP, credenciales cifradas, test connection,
   carpetas, OAuth después).
4. Ingesta de emails (emails, recipients, attachments, sincronización,
   deduplicación por Message-ID, jobs, retries, errores).
5. AI Engine (LLMProvider, OpenAI, AIService, ai_requests, prompt
   versions, tokens, costes, latencia, Structured Outputs, clasificación,
   confidence).
6. RAG (documents, document_chunks, extracción, chunking, embeddings,
   pgvector, semantic search, KnowledgeBaseService).
7. Tools (AITool, ToolRegistry, ToolExecutor, validación, permisos,
   ai_tool_calls, primeras herramientas).
8. AI Agent (AgentService, system prompt, contexto de email, tools, RAG,
   múltiples tool calls, límites, timeout, confidence).
9. Automatización de emails (EmailProcessingJob, clasificación, RAG,
   tools, respuesta, decisión automática/humana, envío, retries, estados).
10. Panel de administración (dashboard, usuarios, empresas, conexiones,
    emails, detalle del email, clasificación, confidence, respuesta,
    tools, documentación, configuración IA).
11. Grafana (Prometheus, Loki, OpenTelemetry, métricas, dashboards,
    alertas).
12. Seguridad (RBAC, audit logs, tenant isolation, prompt injection,
    secrets, rate limits, retención).
13. Human in the loop (bandeja de revisión, aprobar, rechazar, editar,
    historial).
14. Integraciones (CRM, ERP, Helpdesk, APIs externas).
15. Evaluación de IA (dataset de emails, casos esperados, clasificación,
    hallucinations, respuestas incorrectas, escalaciones, comparación de
    modelos/prompts).
16. Producción (servidor, HTTPS, backups, monitoring, CI/CD, staging,
    producción, rollback).
17. SaaS (planes, límites, uso, billing, onboarding, documentación).

## 17. MVP

No construir las 17 fases de golpe. Primer objetivo, sólido, con una única
empresa de prueba:
```
EMAIL → IMAP → LARAVEL → REDIS/QUEUE → AI → CLASIFICACIÓN → RAG/TOOLS →
GENERACIÓN DE RESPUESTA → CONFIDENCE → SEND o HUMAN REVIEW
```

## 18. Forma de trabajar

Paso a paso. No 200 archivos de golpe. Para cada fase: explicar brevemente
el objetivo, dividir en tareas pequeñas, dar una tarea (o grupo pequeño)
cada vez, indicar archivos exactos a crear/modificar, código completo,
dónde colocarlo, comandos exactos, cómo comprobar que funciona. Si aparece
un error, diagnosticar antes de continuar. **No avanzar a la siguiente
fase hasta que la actual esté funcionando de verdad**, no solo escrita.
Tomar decisiones técnicas razonables y explicar las importantes.

## 19. Principios de arquitectura

Prioridades: seguridad, mantenibilidad, separación de responsabilidades,
testabilidad, observabilidad, escalabilidad, simplicidad. **Sin
sobreingeniería** — no 15 microservicios desde el día uno; Laravel modular
+ PostgreSQL + Redis + Docker, separando servicios solo cuando exista una
razón real. Usar: interfaces, services, DTOs cuando aporten valor, jobs,
events/listeners cuando tenga sentido, policies, form requests,
repositories solo cuando sean realmente útiles, dependency injection,
tests.

## 20. Estructura del proyecto

```
app/
├── AI/
├── Mail/
├── Tenancy/
├── CRM/
├── ERP/
├── Tools/
├── Documents/
├── Observability/
└── Support/
```
Controllers finos; la lógica de negocio va en Services/use cases.

## 21. Objetivo profesional

Proyecto serio de portfolio, con potencial de convertirse en producto
real. Decisiones técnicas defendibles en entrevista técnica y capaces de
evolucionar hacia producción. Áreas de aprendizaje: AI Agents, RAG, Tool
Calling, integración de LLMs, arquitectura de IA, automatización
empresarial, observabilidad de IA, seguridad de aplicaciones con IA,
integración con APIs, Laravel avanzado, Docker, PostgreSQL, Redis,
Prometheus, Grafana. Ante varias opciones técnicas, compararlas
brevemente y recomendar según simplicidad, seguridad y posibilidades
reales de producción.

---

## ESTADO ACTUAL (actualizado 2026-09-29)

**Decisión ya tomada, distinta al plan original:** empezar la Fase 5 (AI
Engine) con un único proveedor de IA — **Claude/Anthropic** — en vez de
OpenAI primero, por presupuesto ajustado ahora mismo. OpenAI/Gemini quedan
como interfaces intercambiables para el futuro, no implementadas todavía.

### Fase 1 (base del proyecto): completada en código, PENDIENTE DE PROBAR

Todo lo siguiente ya está escrito y commiteado al repo, pero aún no se ha
ejecutado de extremo a extremo — pendiente de Docker en un ordenador
disponible:

- **Docker Compose:** app (PHP-FPM 8.4), nginx, horizon, scheduler,
  postgres (imagen `pgvector/pgvector:pg16`, init script que crea
  extensiones pgcrypto/uuid-ossp/vector y la BD `app_testing`), redis (AOF
  + requirepass + maxmemory), postgres-exporter, redis-exporter,
  prometheus, loki, promtail, grafana (datasources provisionados).
- **Entornos** local/staging/producción vía `.env` distintos +
  `docker-compose.prod.yml` de override.
- **Sanctum:** tokens de API + cookies SPA
  (`EnsureFrontendRequestsAreStateful`), CORS configurado, estructura
  `/api/v1` con manejo de excepciones en JSON (422/401/404/500) y rate
  limiting (60 req/min).
- **Queue** (Redis, `retry_after=300` para dar margen a llamadas de IA
  futuras) + **Horizon** (supervisor genérico Fase 1 sobre la cola
  `default` — deliberadamente sin colas específicas por tipo todavía; eso
  se decide en Fase 4/5 cuando existan jobs reales) + Gate del dashboard
  `/horizon`.
- **Logging** con rotación diaria (`LOG_STACK=daily`), leído por Promtail.
- **Pest** configurado con `RefreshDatabase`, BD de test separada
  (`app_testing`, no SQLite, por pgvector), tests de ejemplo (health
  check, auth Sanctum).
- **CI** en GitHub Actions: tests contra Postgres+pgvector y Redis reales
  en cada push/PR a `main`.
- `README.md` y `PENDIENTE-SETUP-DOCKER.md` con el checklist detallado de
  todos los comandos de esta fase.

**Siguiente paso real:** en cuanto haya Docker disponible, probar Fase 1
completa de principio a fin (levantar el stack, migrar, verificar
Horizon, Sanctum, tests, CI) **antes** de escribir nada de Fase 2 — regla
autoimpuesta: no avanzar de fase sin verificar que la actual funciona de
verdad.

### Fase 2 (tenants y usuarios): NO empezada todavía.

### Reglas de trabajo que aplican en TODO momento

Ver secciones 18 y 19 de este documento: paso a paso, tareas pequeñas,
código completo con ruta exacta, comandos de verificación, sin
sobreingeniería (no construir para escala/casos de uso que aún no
existen), comparar opciones técnicas cuando las haya.