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

## ESTADO ACTUAL (actualizado 2026-10-19)

**Decisión ya tomada, distinta al plan original:** empezar la Fase 5 (AI
Engine) con un único proveedor de IA — **Claude/Anthropic** — en vez de
OpenAI primero, por presupuesto ajustado ahora mismo. OpenAI/Gemini quedan
como interfaces intercambiables para el futuro, no implementadas todavía.

**Decisión tomada (2026-10-17), distinta al plan original (secciones 5,
8 y 19):** el "cerebro de IA" se extrae a un **microservicio en Python**
(`ai-service/`, ver su sección "AI-Service" más abajo). Razones: el
ecosistema de agentes (LangGraph, MCP) está en Python, la carga de la IA es
distinta a la web, y el usuario prepara una oferta que pide Python,
LangGraph, MCP, hexagonal/DDD, eventos y Kubernetes. Laravel sigue siendo
el monolito de todo lo demás. El motor de IA de Laravel (Fases 5–9)
convivió un tiempo con un interruptor `AI_ENGINE=laravel|python` y **se
retiró el 2026-10-18** (pedido por el usuario): toda la lógica de IA vive en
ai-service; Laravel conserva la configuración de cada empresa, las reglas de
envío, el control de gasto y el registro de costes (ver "Retirada del motor
de IA de Laravel" más abajo).

### Fase 1 (base del proyecto): VERIFICADA EN LOCAL (2026-09-29)

Probada de extremo a extremo con Docker Desktop (Windows) en entorno
`local`. Staging/producción quedan fuera por ahora (ver "Pendiente").

- **Docker Compose:** app (PHP-FPM 8.4), nginx, horizon, scheduler,
  postgres (imagen `pgvector/pgvector:pg16`, init script que crea
  extensiones pgcrypto/uuid-ossp/vector y la BD `app_testing`), redis (AOF
  + requirepass + maxmemory), postgres-exporter, redis-exporter,
  prometheus, loki, promtail, grafana (datasources provisionados).
  Sin `container_name` fijos (chocaban con contenedores de otros
  proyectos): nombres `ai-mail-automation-platform-<servicio>-1`; se usa
  siempre `docker compose exec <servicio>`.
- **Entornos:** solo `local` en uso. `docker-compose.prod.yml`,
  `.env.staging.example` y `.env.production.example` NO existen en el
  repo (nunca se commitearon) aunque el README los mencione.
- **Sanctum:** tokens de API + cookies SPA
  (`EnsureFrontendRequestsAreStateful`), CORS configurado, estructura
  `/api/v1` con manejo de excepciones en JSON (422/401/404/500) y rate
  limiting (60 req/min, aplicado con `$middleware->throttleApi()`;
  `redirectGuestsTo` devuelve null en `api/*` para dar 401 JSON en vez de
  500 por la ruta `login` inexistente).
- **Queue** (Redis, `retry_after=300` para dar margen a llamadas de IA
  futuras) + **Horizon** (supervisor genérico Fase 1 sobre la cola
  `default` — deliberadamente sin colas específicas por tipo todavía; eso
  se decide en Fase 4/5 cuando existan jobs reales) + Gate del dashboard
  `/horizon`.
- **Logging** con rotación diaria (`LOG_STACK=daily`), leído por Promtail.
- **Pest** configurado con `RefreshDatabase`, BD de test separada
  (`app_testing`, no SQLite, por pgvector). En `phpunit.xml` las variables
  críticas (APP_ENV, DB_*, CACHE/QUEUE/SESSION/MAIL) van como `<server>`,
  no `<env>`: el contenedor ya las define en `$_SERVER` y con `<env>` los
  tests corrían contra la BD de desarrollo `app`.
  `DatabaseConnectionTest` vigila que sea pgsql + `app_testing`. 9 tests
  en verde: health, auth Sanctum (actingAs, token real, 401 con y sin
  Accept JSON), rate limit 429, BD de test, ejemplos.
- **CI** en GitHub Actions: tests contra Postgres+pgvector y Redis reales
  en cada push/PR a `main`.
- `README.md` y `PENDIENTE-SETUP-DOCKER.md` con el checklist detallado de
  todos los comandos de esta fase.

**Correcciones hechas al probar (2026-09-29):** `postgres`/`redis`
duplicados en el compose (no parseaba), falta de `pcntl` en el Dockerfile
(Horizon), `phpunit.xml` usando SQLite/BD de desarrollo, throttle no
aplicado, 500 en `/api/v1/user` sin token, `redis-exporter` sin
contraseña, config de Loki 3.x (`delete_request_store`), job de
Prometheus `laravel-app` eliminado (no hay `/metrics` hasta Fase 11).

**Verificado en local:** stack completo arriba; `migrate`; extensiones
vector/pgcrypto/uuid-ossp en `app` y `app_testing`; Redis exige
contraseña y AOF activo; `/api/v1/health` 200, 404/401 en JSON; token
Sanctum por curl 200; 61ª petición → 429; `TestQueueJob` procesado por
Horizon (`horizon:status` running, `/horizon` 200); log diario
`laravel-YYYY-MM-DD.log`; Prometheus targets prometheus/postgres/redis UP;
datasources Prometheus y Loki OK en Grafana; Loki recibe logs de Laravel
(`{job="laravel"}`) y de los contenedores.

**Notas de arranque en un equipo nuevo:** tras `docker compose up -d`
hay que hacer `docker compose exec app composer install` (el bind-mount
tapa el `vendor` de la imagen) y `php artisan key:generate`, y después
`docker compose up -d` otra vez para que los contenedores recojan
`APP_KEY` (el compose la pasa como variable de entorno).

**CI verificado:** GitHub Actions en verde tras el commit `d20b69b`
(2026-09-29). **Fase 1 CERRADA** en local. Staging/producción aparcados
(solo local por ahora; se retoman en la Fase 16).

### Fase 2 (tenants y usuarios): CERRADA (2026-09-29)

100 tests en verde (Pest, Postgres `app_testing`), prueba de humo con
curl contra el stack Docker (login, `/auth/me`, crear/listar usuarios,
404 a usuario de otra empresa, 422 credenciales, logout → 401) y GitHub
Actions en verde (push hecho por el usuario).

**Después del cierre:**
- Fix nginx (`20d5d34`): `fastcgi_pass` con variable + `resolver
  127.0.0.11` para que resuelva `app` en cada petición. Antes, recrear el
  contenedor `app` (p. ej. tras `key:generate` + `up -d`) dejaba nginx
  apuntando a la IP antigua → 502. Detectado al probar el arranque desde
  cero con un clon limpio (`docker compose -p readmecheck`).
- README reescrito para alguien nuevo en el proyecto: arranque desde cero
  (comandos verificados en un clon limpio), arquitectura, cómo funciona
  el multi-tenant y reglas para código nuevo, roles/permisos, estructura,
  API y errores, tests (aviso `<server>` en phpunit.xml), problemas
  frecuentes. `PENDIENTE-SETUP-DOCKER.md` y `README-docker.md` quedan
  como notas históricas (parcialmente desactualizadas).

**Endpoints `/api/v1` actuales:** `GET /health` · `POST /auth/login` ·
(con `auth:sanctum` + `tenant`) `GET /auth/me`, `POST /auth/logout`,
`GET|PATCH /tenant`, `GET|POST /users`, `GET|PATCH|DELETE /users/{user}`.

Forma de trabajo pedida por el usuario: **un commit por tarea**, cada
una probada con tests (solo se commitea si `php artisan test` pasa
entero) y registrando el avance en este archivo en cada commit.
**Nunca hacer `git push`** (ni a `main` ni a otra rama): solo crear los
commits; el push y la revisión del CI los hace el usuario.

**Decisiones tomadas:**
- Aislamiento: **una BD con `tenant_id`** (no schema/BD por tenant).
- El tenant de una petición sale **siempre del usuario autenticado**,
  nunca de cabeceras/subdominio/parámetros del cliente.
- Email de usuario **único global** (login solo con email+password; un
  usuario pertenece a una única empresa).
- FKs `tenant_id`/`role_id` con `restrictOnDelete` (no se borra un tenant
  con usuarios ni un rol en uso; borrado de datos explícito en Fase 12).
- Roles y permisos: **tablas propias** (sin Spatie) como **catálogo
  global** (owner/admin/agent), un rol por usuario (`users.role_id`),
  pivote `permission_role`. Catálogo y asignación en
  `RolesAndPermissionsSeeder` (idempotente, se ejecuta en cada test vía
  `$seed` en `tests/TestCase.php`). `role_id`/`tenant_id` no son
  asignables en masa.
- Permisos actuales: `users.view/create/update/delete`,
  `tenant.view/update`. owner: todos · admin: todos menos
  `tenant.update` · agent: `users.view`, `tenant.view`.
- Tokens Sanctum caducan a los 7 días (`SANCTUM_TOKEN_EXPIRATION`),
  limpieza diaria `sanctum:prune-expired` en el scheduler.
- `TenantService` registrado como `scoped` (se reinicia por petición y
  por job). Trait `BelongsToTenant` + `TenantScope` para todos los
  modelos futuros con `tenant_id`: filtra por tenant y **falla cerrado**
  (`MissingTenantException` si no hay tenant en contexto). `User` NO lo
  usa (Sanctum resuelve el usuario antes de conocer el tenant): los
  usuarios se protegen con policies. Consultas globales deliberadas:
  `withoutGlobalScope(TenantScope::class)`.
- Middleware `tenant` (`ResolveTenant`) va tras `auth:sanctum` y **antes
  de `SubstituteBindings`** (prioridad configurada en `bootstrap/app.php`);
  bloquea con 403 tokens de empresas desactivadas y añade `tenant_id` al
  `Context` (logs + jobs).
- Excepciones API: handler único por código HTTP (403 "No autorizado.",
  404 "Recurso no encontrado." sin nombre de modelo); el 500 genérico ya
  no tapa 403/404/405/429 con `APP_DEBUG=false` (bug de Fase 1 corregido).

**Tareas (commit):**
- [x] 1. Tabla `tenants` (`f5f6f16`)
- [x] 2. `tenant_id` en `users` (`2a0363e`)
- [x] 3. Relación tenant → users (`5bbb516`)
- [x] 4. Tabla `roles` + `users.role_id` (`5fa0431`)
- [x] 5. Tabla `permissions` + `permission_role` (`438bb39`)
- [x] Fix excepciones API con debug desactivado (`250a95e`)
- [x] 6. Login `POST /api/v1/auth/login` (throttle 5/min email+IP, mismo
  error para email inexistente y password incorrecta, 403 si empresa
  desactivada) (`63c3da3`)
- [x] 7. Logout `POST /api/v1/auth/logout` (revoca solo el token actual)
  (`43d0dd6`)
- [x] 8. Auth Sanctum: `GET /api/v1/auth/me` (sustituye a `/user`), rutas
  protegidas agrupadas, caducidad y limpieza de tokens (`9e4acd4`)
- [x] 9. `TenantService` + `BelongsToTenant` (`2f8a8ec`)
- [x] 10. Middleware de tenant (`99b81fd`)
- [x] 11. Roles y permisos: `User::hasRole()`/`hasPermission()`, cada
  permiso registrado como Gate (`can:users.create`), permisos en
  `/auth/me`. Catálogo movido a `Role::CATALOG`, `Role::PERMISSIONS` y
  `Permission::CATALOG` (el seeder solo lo guarda en BD) (`c4051a0`)
- [x] 12. Policies: `UserPolicy` y `TenantPolicy` + endpoints
  `apiResource users` (usuarios de la propia empresa) y `GET/PATCH
  /tenant` (sin id en la URL). Reglas: otro tenant → 404 (antes que el
  permiso); solo owner crea/edita/promociona/borra owners; nadie se borra
  ni cambia su propio rol (siempre queda un owner). Autorización de
  store/update en `authorize()` del FormRequest (va antes que la
  validación: un 422 no delata usuarios de otra empresa). El tenant de un
  usuario nuevo sale de `TenantService`, nunca del body. La empresa solo
  puede cambiar su `name` (no `slug` ni `is_active`) (`5853fde`)
- [x] 13. Tests de aislamiento (`tests/Feature/Tenancy/TenantIsolationTest.php`):
  owner de Acme con token real de login contra Globex → no lista, no lee
  (mismo 404 que un id inexistente, sin enumeración), no edita (ni email/
  password para robar la cuenta; 404 y no 422 con datos inválidos), no
  borra, no crea usuarios dentro de Globex (tenant_id del body ignorado),
  no renombra Globex; desactivar una empresa o hacer logout no afecta a
  la otra

Usuario de desarrollo (seeder): `test@example.com` / `password`, owner
de "Empresa Demo" (`php artisan migrate:fresh --seed`).

### Fase 3 (conexiones de correo): CERRADA — COMPLETADA EN LOCAL (2026-09-30), CI en verde (2026-10-14)

Misma forma de trabajo que la Fase 2: un commit por tarea, tests antes de
cada commit, este archivo actualizado en cada commit, sin push.

**Resumen:** 24 tareas en 25 commits (`8494c8e`..`d40a157` + docs), 314
tests en verde (IMAP/SMTP contra GreenMail real; Microsoft/Google con
APIs simuladas). Prueba de humo con curl contra el stack Docker:
crear conexión IMAP a GreenMail (respuesta sin contraseña) → test
`active` → contraseña mala → IMAP falla y SMTP OK por separado → OAuth
sin configurar → 422 claro. Conexión demo en la BD de desarrollo:
`soporte@demo.test` (GreenMail). Endpoints nuevos: `apiResource
/mail-connections`, `POST /mail-connections/{id}/test`, `POST
/mail-connections/oauth/{provider}/start`, `GET
/mail-connections/oauth/{provider}/callback` (público).

**Decisiones tomadas:**
- Todo en `app/Mail/` (contratos en `Contracts/`, DTOs `readonly` en
  `Data/`, excepciones en `Exceptions/`). El resto de la app solo usa la
  interfaz `MailProvider` y los DTOs, nunca clases de librerías/APIs.
- Interfaz única `MailProvider`: `testConnection()` (no lanza; resultado
  separado recepción/envío), `folders()`, `messages()`, `message()`,
  `downloadAttachment()` (contenido aparte de los metadatos),
  `newMessages($folder, ?$cursor)` (cursor opaco por proveedor: UID+
  UIDVALIDITY en IMAP, deltaLink en Graph, historyId en Gmail; `null` =
  fijar punto de partida sin traer histórico) y `send()`.
- `OutgoingMessage` no permite elegir remitente (siempre la cuenta de la
  conexión: evita suplantación). Exige destinatario y cuerpo.
- Errores: los proveedores traducen todo a `MailProviderException` y
  subclases (`MailAuthenticationException`, `MailConnectionException`,
  `MailNotFoundException`) con mensajes aptos para el usuario, sin
  credenciales.
- IMAP con **directorytree/imapengine** (PHP puro; `ext-imap` ya no viene
  en PHP 8.4). SMTP con **Symfony Mailer** (incluido en Laravel).
  Tests de IMAP/SMTP contra **GreenMail** real en Docker (local y CI).
  OAuth Microsoft/Google **manual con `Http`** (sin Socialite), probado
  con `Http::fake` (pendiente de probar con apps reales registradas).
- `tests/Fakes/FakeMailProvider`: proveedor en memoria para testear el
  código que usa proveedores sin servidor.

**Tareas (commit)** — Arquitectura reordenada por dependencias
(interfaz → tabla → credenciales → modelo → factory) + API de conexiones:
- [x] 1. Interfaz `MailProvider` + DTOs + excepciones (`8494c8e`)
- [x] 2. Tabla `mail_connections`: `tenant_id` (restrict), `created_by`
  (nullOnDelete), `name`, `provider`, `email`, `credentials` (text, JSON
  cifrado), `status` pending/active/failing/disabled, `last_tested_at`,
  `last_error`. Único (`tenant_id`, `email`); la misma cuenta sí puede
  estar en empresas distintas (`f80e830`)
- [x] 3. Credenciales cifradas (`app/Mail/Credentials/`): objetos tipados
  `ImapSmtpCredentials` (IMAP y SMTP por separado; enum `MailEncryption`
  ssl/starttls/none) y `OAuthCredentials` (tokens, `expiresAt`, scopes,
  `isExpired()`), interfaz `MailCredentials`. Cast `EncryptedCredentials`:
  JSON `{type, data}` cifrado con `Crypt` (APP_KEY, AES-256+MAC; rotación
  con `APP_PREVIOUS_KEYS`); manipular el valor → `DecryptException`.
  Trait `RedactsSecrets`: `json_encode`/`var_dump`/`print_r`/logs muestran
  `********`; solo `toArray()` da los secretos. Contraseñas/tokens con
  `#[SensitiveParameter]` (fuera de las trazas) (`f9887fc`)
- [x] 4. Modelo `MailConnection`: primer modelo real con `BelongsToTenant`;
  enums `MailProviderType` (imap/microsoft/google, `credentialsClass()`,
  `usesOAuth()`) y `MailConnectionStatus`; `credentials` en `#[Hidden]`;
  al guardar valida que las credenciales encajan con el proveedor;
  `creator()`, `Tenant::mailConnections()`. Solo `name`/`email` son
  fillable (provider y credentials se asignan explícitamente). Factory con
  estados `microsoft()`/`google()`. Helper global de tests
  `inTenant($tenant, fn)` en `tests/Pest.php` (crear/leer modelos con
  tenant fuera de peticiones HTTP) (`665e078`)
- [x] 5. `App\Mail\MailProviderFactory` (singleton): registro de resolvers
  por tipo con `extend(MailProviderType, fn (MailConnection) => provider)`
  en `App\Providers\MailProvidersServiceProvider`; `make($connection)`
  lanza `UnsupportedMailProviderException` si el tipo no está registrado.
  En tests: `extend(tipo, fn () => new FakeMailProvider(...))` (`9bd8913`)
- [x] 6. API de conexiones (añadida): `apiResource mail-connections`
  (parámetro `{mailConnection}`), `MailConnectionPolicy` (tenant → 404,
  luego permiso), permisos nuevos `mail_connections.view` (todos los
  roles) y `mail_connections.manage` (owner, admin). Por la API solo se
  crean conexiones **IMAP** (Microsoft/Google vía OAuth). Resource sin
  secretos (muestra host/puerto/cifrado/usuario). Edición: contraseña no
  enviada se conserva; cambiar credenciales → `pending`; `enabled`
  true/false → `pending`/`disabled`. **Anti-SSRF** `MailHostGuard` +
  regla `AllowedMailHost`: rechaza hosts que resuelven a IPs privadas,
  loopback, reservadas o no resolubles (Redis/Postgres/169.254.169.254);
  se repetirá al conectar (DNS rebinding). Desactivable solo en local con
  `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` (config
  `config/mail_connections.php`, también `timeout`) (`3030bf9`)
- [x] 7. Conexión IMAP: `composer require directorytree/imapengine`;
  `App\Mail\Providers\Imap\ImapClient` (envuelve `Mailbox` de ImapEngine,
  conexión perezosa y reutilizada, ssl/starttls/sin cifrar, timeout,
  **re-chequeo anti-SSRF antes de conectar**, errores de red →
  `MailConnectionException` sin contraseña en el mensaje). **GreenMail**
  (`greenmail/standalone:2.1.5`) añadido a docker-compose (IMAP 3143,
  SMTP 3025 sin TLS, API 8080 interna; expuesto en 127.0.0.1; cuenta demo
  `soporte@demo.test`/`secret`) y como servicio en GitHub Actions
  (`GREENMAIL_HOST=127.0.0.1` + espera a la API). Helper
  `tests/Support/GreenMail` (crea una cuenta nueva por test con la API
  REST, vía Guzzle para no chocar con `Http::fake`). Tests de GreenMail
  en el grupo `greenmail` (`bed9dac`)
- [x] 8. Autenticación IMAP: `ImapCommandException` del comando
  LOGIN/AUTHENTICATE → `MailAuthenticationException` ("rechazó el usuario
  o la contraseña de X", sin contraseña); fallo de otros comandos
  (STARTTLS) → `MailConnectionException`. `ImapClient::verify()` (`62de024`)
- [x] 9. Carpetas IMAP: nace `ImapSmtpProvider` (registrado en la factory
  para `imap`; operaciones aún no hechas lanzan `MailProviderException`
  explícita). `folders()` omite `\Noselect`; rol: INBOX por nombre, resto
  por atributos RFC 6154 (`\Sent`, `\Drafts`, `\Trash`, `\Junk`,
  `\Archive`). Wrapper `call()` traduce cualquier excepción de ImapEngine.
  Helpers `GreenMail::imapClient()`/`provider()` (en Pest las funciones de
  un test son globales: no redeclarar helpers entre archivos) (`2ef2c6d`)
- [x] 10. Lectura de mensajes IMAP: `messages($folder, $limit)` (más
  recientes primero: `newest()` + orden explícito por UID desc, porque la
  colección vuelve ascendente) y `message($folder, $uid)`.
  `ImapMessageMapper` → `MailMessage` (Message-ID/In-Reply-To/References
  sin `< >`, from/to/cc/reply-to, fecha, texto/HTML, flag `\Seen`).
  **Leer no marca como leído** (BODY.PEEK por defecto en ImapEngine).
  Carpeta/UID inexistente o id no numérico → `MailNotFoundException`.
  Helper `GreenMail::append()` (IMAP APPEND de un `Symfony\Mime\Email`) (`cb29142`)
- [x] 11. Adjuntos IMAP: metadatos en `MailMessage::$attachments` (id =
  posición en el orden de la estructura MIME, no el de `attach()`;
  nombre, tipo, tamaño, inline, Content-ID) y
  `downloadAttachment($folder, $uid, $id)` byte a byte (probado con
  binario). Límite `mail_connections.max_attachment_mb` (25 MB,
  `MAIL_CONNECTIONS_MAX_ATTACHMENT_MB`) → `MailProviderException` (`f443a7b`)
- [x] 12. Detección de nuevos emails IMAP: cursor `v1:<UIDVALIDITY>:<último
  UID>` por carpeta. `null` → solo fija punto de partida (UIDNEXT-1, sin
  histórico). UIDVALIDITY distinta o cursor ilegible → `resynced=true` y
  punto de partida nuevo. Primero busca solo UIDs (barato), filtra
  `uid > último` (IMAP `UID n:*` siempre devuelve el último mensaje) y
  trae contenido solo del lote (`newMessagesBatch`, 100) con
  `NewMessages::$hasMore` (DTO ampliado). Probado: sin duplicados, lotes
  sin pérdidas, carpetas independientes, no marca como leído (`1181c22`)
- [x] 13. Conexión SMTP (incluye autenticación): `SmtpClient` con
  `EsmtpTransport` de Symfony Mailer por conexión (no el mailer global).
  ssl → TLS directo; starttls → `setRequireTls(true)` (nunca en claro si
  el servidor no ofrece STARTTLS); none → `setAutoTls(false)`. Timeout,
  re-chequeo anti-SSRF, código 535/"Failed to authenticate" →
  `MailAuthenticationException`, resto → `MailConnectionException`.
  GreenMail sí valida la contraseña SMTP (verificado) (`4366435`)
- [x] 14. Envío SMTP: `ImapSmtpProvider` recibe `ImapClient`, `SmtpClient`
  y `MailAddress $from` (email + nombre de la conexión = único remitente).
  `send()` construye `Symfony\Mime\Email` (to/cc/bcc, texto/HTML,
  adjuntos, `In-Reply-To`/`References` para hilos) y devuelve el
  Message-ID asignado. Rechazo al enviar → `MailProviderException` con
  código SMTP (535 → autenticación). **Pendiente de decidir (Fase 9):**
  guardar copia en "Enviados" vía IMAP APPEND (Gmail ya la guarda →
  duplicados). `GreenMail::provider()` acepta nombre/lote/límite
  adjuntos. Probado leyendo el buzón del destinatario por IMAP (bcc no
  expuesto, adjuntos binarios intactos, hilo) (`7d75da0`)
- [x] 15. Prueba de conexión: `ImapSmtpProvider::testConnection()` prueba
  IMAP y SMTP por separado sin lanzar. `App\Mail\MailConnectionService::
  test()` guarda status active/failing (disabled se mantiene),
  `last_tested_at`, `last_error` y lo registra en el log (con tenant_id
  del Context); proveedor no disponible = prueba fallida. Endpoint
  `POST /api/v1/mail-connections/{id}/test` (permiso manage, 404 otra
  empresa) con **throttle `mail-connection-test` 10/min** (evita usarlo
  como escáner de puertos). Respuesta: `data` (conexión) + `result`
  (ok, incoming_ok/outgoing_ok, errores) (`d05a2f1`)
- [x] 16. Tests IMAP/SMTP: además de los tests por tarea
  (`tests/Feature/Mail/Imap/`, `.../Smtp/`), `ImapSmtpEndToEndTest`:
  flujo completo con `MailConnection` en BD (credenciales cifradas) →
  factory → proveedor real: cliente escribe por SMTP con adjunto →
  soporte lo detecta (cursor) → lo lee y descarga el adjunto → responde
  en el hilo → el cliente recibe la respuesta enlazada. Y ninguna
  operación (ni la cadena de `previous`) revela la contraseña (`6e4cec7`)
- [x] 17. Microsoft Graph: `App\Mail\OAuth\Contracts\TokenSource`
  (`accessToken()`, `refresh()`), `StaticTokenSource` (token guardado, sin
  renovar; se sustituye en la tarea 18). `App\Mail\OAuth\OAuthApiClient`
  (base común Graph/Gmail): bearer, 3 intentos en 429/503/504 (en
  Laravel `retry($n)` cuenta el intento inicial), ante 401 renueva el
  token **una vez** y reintenta, traduce 401/403 → autenticación, 404 →
  not found, 429/5xx → conexión, resto → proveedor (sin tokens en
  mensajes). **Bug evitado:** no pasar `query: []` en GET (Guzzle
  sustituiría la query de la URL, p. ej. el `$skiptoken` de un nextLink).
  `GraphClient` (+ `collect()` que sigue `@odata.nextLink` hasta un
  límite). `MicrosoftGraphProvider` registrado en la factory:
  `testConnection()` lee la bandeja (recepción) y comprueba el scope
  `Mail.Send` (envío, sin mandar nada); resto de operaciones pendientes.
  Tests con `Http::fake` + `preventStrayRequests` + `Sleep::fake` (`bc26c69`)
- [x] 18. OAuth Microsoft: interfaz `OAuthClient` (`configured()`,
  `authorizationUrl($state, $challenge)`, `exchangeCode()`, `refresh()`),
  `OAuthTokenEndpoint` (común: canje/renovación, `invalid_grant` →
  autenticación, conserva el refresh token si no llega uno nuevo),
  `MicrosoftOAuthClient` (scopes mínimos `offline_access User.Read
  Mail.ReadWrite Mail.Send`, `prompt=select_account`, email de Graph
  `/me`: `mail` o `userPrincipalName`), registro `OAuthClients`.
  `MailOAuthService`: `start()` genera **state** (40 chars, un solo uso,
  caché 10 min con hash como clave) + **PKCE S256**; `complete()` saca
  empresa/usuario del state, **revalida** empresa activa y permiso
  manage, canjea el código y crea la conexión en esa empresa (runAs) o
  **reconecta** la existente (mismo email) sin duplicar; si el email ya
  está con otro proveedor → error. `OAuthTokenManager` (TokenSource real
  en la factory de Microsoft): renueva al caducar, guarda cifrado, **lock
  por conexión** (`Cache::lock`, Microsoft rota refresh tokens) y reusa
  el token si otro worker ya renovó; revocado → conexión `failing` +
  "vuelve a conectarla". Rutas: `POST /mail-connections/oauth/{provider}/
  start` (auth, manage; solo proveedores OAuth, resto 404) y `GET
  .../oauth/{provider}/callback` (**público**, throttle 30/min). Config
  `services.microsoft.*` (`MICROSOFT_CLIENT_ID/SECRET/TENANT/
  REDIRECT_URI`), `mail_connections.oauth_redirect_to` (vuelve al panel
  con `?status=`; vacío = JSON) y `oauth_state_ttl` (`106b2a9`)
- [x] 19. Lectura Microsoft: `folders()` (primer nivel + subcarpetas
  directas "Padre/Hija"; roles vía nombres conocidos de Graph
  inbox/sentitems/drafts/deleteditems/junkemail/archive en **una sola
  petición `$batch`**; como `$folder` se aceptan ids o esos nombres).
  `messages()`/`message()` con `$select` + `$expand` de adjuntos
  (`GraphMessageMapper`; In-Reply-To/References desde
  `internetMessageHeaders`). Graph da el cuerpo en un solo formato:
  texto derivado del HTML con `App\Mail\Support\HtmlToText`. Adjuntos:
  solo `fileAttachment` (item/reference → error explícito), tamaño
  comprobado con una petición de metadatos **antes** de descargar.
  **Decisión cambiada:** `newMessages()` NO usa delta query (obligaría a
  recorrer todo el buzón al empezar) sino cursor `v1:base64(json{since,
  seen})` = `receivedDateTime` del último + ids vistos en ese segundo
  (`ge` + filtro de vistos: sin pérdidas ni duplicados), lotes con
  `hasMore`. Limitación: correos **movidos** a la carpeta (fecha antigua)
  no se detectan; valorar delta en Fase 4 si hace falta (`f50382b`)
- [x] 20. Envío Microsoft: `sendMail` no devuelve Message-ID ni admite
  In-Reply-To → flujo de **borrador**: si hay `inReplyTo` busca el
  original por `internetMessageId` y usa `createReply` + PATCH (Outlook
  lo enlaza en la conversación; si no está, borrador nuevo), sube
  adjuntos (`fileAttachment` base64) y `/send`; devuelve el
  `internetMessageId` del borrador. Sin campo `from` (Graph envía como el
  dueño del buzón). HTML → `contentType HTML`, si no `Text`.
  **Pendiente:** adjuntos > 3 MB (requieren upload session): se rechazan
  con error claro antes de crear nada (`38b0ad6`)
- [x] 21. Gmail API: `GmailClient` (base `OAuthApiClient`, URL
  `gmail.googleapis.com/gmail/v1/users/me`, detalle de error =
  `error.status` de Google). `GmailProvider` registrado en la factory
  (de momento con `StaticTokenSource`): `testConnection()` lee `/profile`
  (recepción) y comprueba el scope `gmail.send` (envío). Scopes mínimos
  previstos: `gmail.readonly` + `gmail.send` (no `gmail.modify`: no se
  marca ni mueve nada) (`8555909`)
- [x] 22. OAuth Google: `GoogleOAuthClient` (mismo flujo común que
  Microsoft: state, PKCE, reconexión, lock). `access_type=offline` +
  `prompt=consent select_account` (sin ellos Google no da refresh token
  al reconectar); email desde Gmail `/profile` (sin scopes de identidad);
  al renovar Google no devuelve refresh token nuevo → se conserva.
  Factory de Google con `OAuthTokenManager`. Config `services.google.*`
  (`GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`). **Producción:**
  `gmail.readonly` es scope restringido → verificación de la app en
  Google Cloud (en pruebas solo usuarios de prueba) (`de05f8b`)
- [x] 23. Lectura Gmail: refactor → `App\Mail\Support\MimeMessageMapper`
  (MIME ya parseado → MailMessage con id/seen explícitos, adjuntos por
  posición, `download()` con límite) usado por IMAP
  (`ImapMessageMapper` queda como adaptador UID/\Seen) y por Gmail.
  Gmail pide `format=raw` y parsea con `FileMessage` de ImapEngine
  (mismo parser que IMAP). Carpetas = etiquetas: sistema INBOX/SENT/
  DRAFT/TRASH/SPAM con rol + etiquetas de usuario (resto de sistema
  omitidas). Leído = sin etiqueta `UNREAD`. `newMessages()` con **History
  API**: cursor `v1:base64(json{history, pending})` (pending = ids
  detectados que no cupieron en el lote), `null` → historyId actual del
  perfil, 404 (historyId caducado) o cursor ilegible → `resynced`;
  mensajes borrados entre detección y lectura se ignoran.
  `messages()` = 1 petición de lista + 1 por mensaje (sin batch
  multipart: suficiente con límites de 50) (`cff0bef`)
- [x] 24. Envío Gmail: refactor → `App\Mail\Support\MimeMessageBuilder`
  (OutgoingMessage + remitente → `Symfony\Mime\Email`) común a SMTP y
  Gmail. `GmailProvider` recibe `MailAddress $from` (cuenta de la
  conexión). `messages/send` con `raw` base64url; si hay `inReplyTo`
  busca el hilo con `q=rfc822msgid:` y envía con `threadId`; relee el
  Message-ID definitivo (`format=metadata`) porque Gmail puede
  reescribirlo; límite 35 MB. **Bug evitado:** Symfony elimina la
  cabecera `Bcc` al generar el MIME (en SMTP van por el sobre) pero Gmail
  lee los Bcc de esa cabecera → se añade explícitamente solo para Gmail
  (`d40a157`)
- [x] Documentación: README con sección "Conexiones de correo"
  (proveedores, cómo encaja, seguridad, cómo conectar IMAP y OAuth,
  limitaciones, cómo añadir un proveedor), API, estructura, tests, CI y
  problemas frecuentes (este commit)

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14; el
CI levanta también GreenMail como servicio). Probar Microsoft/Google con cuentas reales requiere registrar
las apps (Azure / Google Cloud) y rellenar `MICROSOFT_*`/`GOOGLE_*`.

### Fase 4 (ingesta de emails): CERRADA — COMPLETADA EN LOCAL (2026-09-30), CI en verde (2026-10-14)

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Resumen:** 12 tareas en 12 commits (`d7492dd`..tarea 12), 386 tests en
verde (incluida ingesta E2E contra IMAP real de GreenMail). **Prueba de
humo en el stack Docker:** migraciones aplicadas en la BD de desarrollo;
el scheduler encoló `FetchEmailsJob` para el buzón demo
`soporte@demo.test` antes de migrar → Horizon lo reintentó con el backoff
real (fallo 15:43:04, +30 s fallo, +120 s `DONE`) y fijó el cursor sin
importar histórico; correo enviado por SMTP a GreenMail con adjunto → el
scheduler lo guardó solo en <1 min (remitente, destinatario, asunto,
cuerpos, fecha, `ingested_at`, adjunto `stored` en
`tenants/1/emails/1/1` con contenido idéntico).

**Objetivo:** cada buzón conectado se sincroniza solo. Un job periódico
pide los correos nuevos al proveedor (`newMessages()` con el cursor
guardado por conexión+carpeta), guarda cada correo una sola vez
(remitente, destinatarios, asunto, cuerpos, fecha, adjuntos en disco
privado), registra los errores y reintenta los fallos temporales.

**Decisiones tomadas:**
- Código de la fase en `app/Mail/Ingestion/` (sin módulo nuevo); modelos
  en `app/Models`, enums en `app/Mail/Enums`.
- **Deduplicación:** único `(mail_connection_id, dedup_hash)`; el hash es
  SHA-256 del Message-ID o, si falta, de una huella del correo. No se usa
  el UID de IMAP (cambia con UIDVALIDITY). El mismo correo en dos buzones
  de la empresa se guarda en ambos (son buzones distintos).
- **"Procesado" en esta fase = ingesta completa** (`ingested_at`, con
  adjuntos). `status` (received → processing → ai_review → human_review →
  approved → sent | failed, sección 12) existe desde ya pero lo mueve la
  Fase 9. **No se marca nada en el buzón del cliente** (Gmail con scope de
  solo lectura; el cliente ve su correo como siempre).
- `emails.mail_connection_id` con `restrictOnDelete`: un buzón con correos
  guardados no se borra (se desactiva); borrado/retención en Fase 12.
- HTML guardado tal cual: sanearlo SIEMPRE antes de mostrarlo (Fase 10).

**Tareas (commit):**
- [x] 1. Tabla `emails`: tenant, conexión, carpeta + id en el proveedor,
  Message-ID/In-Reply-To/References (jsonb), `dedup_hash`, remitente,
  asunto, cuerpos texto/HTML (longText), `sent_at` (cabecera Date),
  `status` (default received), `ingested_at`. Índices por tenant+status,
  tenant+sent_at, tenant+message_id (hilos)
- [x] 2. Tabla `email_recipients`: tipo to/cc/bcc/reply_to, email, nombre,
  posición en la cabecera; `tenant_id` redundante a propósito (consultas
  directas también filtradas por empresa); se borran con el email (cascade);
  índice tenant+email (correos en los que aparece una dirección)
- [x] 3. Tabla `email_attachments`: metadatos (id en el proveedor, nombre,
  tipo, tamaño bigint, inline, Content-ID) + `status` pending/stored/
  skipped/failed, `disk`/`path`/`sha256` del fichero y `error`. El
  contenido va a disco privado, no a la BD. El nombre del remitente nunca
  se usa como ruta. Cascade con el email (borra la fila, no el fichero:
  limpieza en Fase 12). Único email+id de adjunto
- [x] 4. Modelo `Email` (`BelongsToTenant`, factory) + enum `EmailStatus`
  (estados de la sección 12). `status`/`ingested_at`/`tenant_id` no son
  asignables en masa. `MailConnection::emails()`. Borrar por la API una
  conexión con correos → **409** "desactívala en lugar de borrarla"
  (antes daría 500 por el `restrictOnDelete`)
- [x] 5. Modelos `EmailRecipient` (sin timestamps) y `EmailAttachment`,
  ambos con `BelongsToTenant`; enums `RecipientType` y `AttachmentStatus`.
  `Email::recipients()` (orden tipo+posición) y `attachments()`. En los
  adjuntos `status`/`disk`/`path`/`sha256`/`error` no son asignables en
  masa y `disk`/`path` están ocultos en JSON
- [x] 6. Tabla y modelo `mail_sync_states`: una fila por conexión+carpeta
  (único) con `cursor` (texto, no secreto), `last_synced_at` (intento),
  `last_success_at`, `last_error`, `consecutive_failures` (base para
  alertas Fase 11) y `resynced_at` (el proveedor invalidó el cursor).
  `recordSuccess($cursor, $resynced)` avanza el cursor y limpia el error;
  `recordFailure($error)` NO avanza el cursor. Cascade con la conexión.
  `MailConnection::syncStates()`
- [x] 7. `App\Mail\Ingestion\EmailIngestionService::ingest($connection,
  MailMessage)`: guarda email + destinatarios (to/cc/reply_to en orden) +
  metadatos de adjuntos (pending) en una transacción; idempotente
  (`dedupHash()`: `mid:`+Message-ID o huella `fp:` de remitente/fecha/
  asunto/cuerpo); devuelve el existente si ya estaba
  (`wasRecentlyCreated=false`). Carrera entre workers → captura la
  violación del único **fuera** de la transacción (en Postgres un error la
  aborta). Exige estar en el tenant de la conexión (`LogicException`).
  Texto saneado (`mb_scrub` + sin `\0`: Postgres rechaza NUL/UTF-8
  inválido) y recortado al tamaño de columna en vez de fallar
- [x] 8. `EmailIngestionService::complete($provider, $email)`: descarga los
  adjuntos `pending` al disco privado (`mail_connections.attachments_disk`,
  `MAIL_ATTACHMENTS_DISK`, por defecto `local` = storage/app/private) en
  `tenants/{tenant}/emails/{email}/{adjunto}` (nunca el nombre del
  remitente), con sha256 y tamaño real. Más grande que
  `max_attachment_mb` → `skipped` sin descargar; error del proveedor no
  reintentable → `failed` (+ `error`) y sigue; `MailConnectionException`/
  `MailAuthenticationException` → se propagan (reintento) y el email queda
  a medias (`ingested_at` null). Un reintento continúa sin duplicar. Sin
  pendientes → `Email::markIngested()` + evento
  `App\Mail\Ingestion\Events\EmailIngested(tenantId, emailId)` **una sola
  vez** (ids, no el modelo: un listener en cola lo recarga con `runAs`).
  `FakeMailProvider` registra descargas y puede simular fallos
  (`failDownloadsWith`)
- [x] 9. `App\Mail\Ingestion\MailSyncService::sync($connection)` →
  `SyncResult` (stored, duplicates, hasMore, resynced). Se ejecuta con
  `runAs` del tenant de la conexión (+ `tenant_id` en `Context`); las
  conexiones `disabled` no se sincronizan. Carpetas: por ahora **solo la
  bandeja de entrada**, buscada una vez por rol INBOX en `folders()` y
  guardada en `mail_sync_states`. Bucle por lotes: `newMessages(cursor)` →
  `ingest` + `complete` de cada mensaje → **solo entonces**
  `recordSuccess(cursor)`; hasta `mail_connections.sync_max_batches`
  (`MAIL_SYNC_MAX_BATCHES`, 5) por pasada, el resto en la siguiente. Primera
  sincronización = punto de partida sin histórico. Error → cursor sin
  mover, `recordFailure` (mensaje del proveedor, o "Error interno..." si no
  es `MailProviderException`: el detalle solo al log), auth →
  conexión `failing` + `last_error`; se relanza para que el job decida.
  Logs: info por buzón sincronizado, warning si hubo resync, error al
  fallar. `FakeMailProvider` con lotes (`batchSize`), resync (cursor no
  numérico) y `failNewMessagesWith`; helper de tests `incomingMessage()`
  movido a `tests/Pest.php`
- [x] 10. `App\Mail\Ingestion\FetchEmailsJob($mailConnectionId)` en la cola
  **`mail`**. Recibe el **id**, no el modelo (al deserializar no hay
  tenant y `BelongsToTenant` fallaría): carga la conexión con
  `withoutGlobalScope(TenantScope)` y `MailSyncService` entra en su tenant.
  `ShouldBeUnique` por conexión (`uniqueFor` 900 s): no se apilan syncs del
  mismo buzón (en cola, ejecutándose o esperando reintento). **Reintentos:**
  `tries=3`, `backoff [30, 120]`, `timeout 240` (< `retry_after` 300);
  errores temporales se relanzan → la cola reintenta. **Credenciales
  rechazadas → `fail()` sin reintentar** (repetir logins fallidos puede
  bloquear la cuenta; la conexión ya queda `failing`). Buzón borrado o
  desactivado o empresa desactivada mientras esperaba → termina sin hacer
  nada. `failed()` deja un warning en el log. Tests con
  `withFakeQueueInteractions()`
- [x] 11. Comando `php artisan mail:sync {connection?}`
  (`app/Console/Commands/SyncMailboxes.php`): encola un `FetchEmailsJob`
  por buzón **`active` de empresa activa** (`pending` = sin verificar;
  `failing` = credenciales mal, reintentarlo cada minuto podría bloquear la
  cuenta: vuelve a `active` al probarlo de nuevo). Consulta global
  deliberada (`withoutGlobalScope`) con `chunkById`. Scheduler: **cada
  minuto** con `withoutOverlapping` (el job único evita acumular). Horizon:
  **supervisor propio `supervisor-mail`** para la cola `mail` (un servidor
  de correo lento no retrasa `default`; timeout 250 > 240 del job; local 2
  procesos, producción hasta 5) + `waits.redis:mail` 120 s. Verificado en
  el stack: `schedule:list` lo muestra y `horizon:supervisors` levanta
  `supervisor-mail` (tras `horizon:terminate`)
- [x] 12. `ImapIngestionEndToEndTest` (grupo `greenmail`): `mail:sync` →
  job (cola sync) → IMAP real: correo previo no importado, 2 nuevos
  guardados con remitente/cc/cuerpos/fecha, adjunto binario idéntico en
  disco, mismo Message-ID entregado dos veces → 1 fila, 2ª pasada sin
  nada nuevo, **buzón sin modificar** (siguen sin leer); contraseña
  cambiada → conexión `failing` (mensaje sin contraseña), error en
  `mail_sync_states` y el scheduler deja de encolarla. Prueba de humo en
  el stack (ver resumen). README: sección "5. Ingesta de emails" (cómo
  funciona, qué se guarda, duplicados, errores y reintentos, probarlo en
  local), estado, estructura, API (409), tests, problemas frecuentes;
  secciones siguientes renumeradas (API = #7)

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14).

**Después:** PostgreSQL publicado en `127.0.0.1:${DB_FORWARD_PORT:-5433}`
para verlo con un cliente gráfico (el usuario usa **HeidiSQL**; tipo
PostgreSQL, `app`/`secret`, BD `app`). 5433 porque en el equipo del
usuario el 5432 ya lo usa otro PostgreSQL. Solo loopback, nunca la red.

### Fase 5 (AI Engine con Claude/Anthropic): CERRADA — COMPLETADA Y PROBADA CON CLAUDE REAL (2026-10-08), CI en verde (2026-10-14)

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push. **El usuario aún no
tiene la API key de Anthropic**: la pondrá en `.env`
(`ANTHROPIC_API_KEY`); hasta entonces todo se prueba con HTTP simulado y
la prueba real queda pendiente.

**Objetivo:** clasificar cada email ingerido con Claude (intención,
confianza, idioma, resumen, si necesita una persona, datos extraídos) y
registrar cada llamada a la IA en `ai_requests` (proveedor, modelo,
versión de prompt, tokens, coste, latencia, estado, error, intención,
confianza, email). Punto de entrada: evento `EmailIngested(tenantId,
emailId)` de la Fase 4.

**Decisiones tomadas:**
- **SDK oficial `anthropic-ai/sdk`** (PHP, v0.54; cliente HTTP PSR-18 →
  Guzzle). En tests se le inyecta un transporte PSR-18 simulado: nunca
  se llama a la API real. `phpunit.xml` fuerza `ANTHROPIC_API_KEY` vacía.
- Código en `app/AI/` (sección 20). El resto de la app solo usa la
  interfaz `LLMProvider` y DTOs propios, nunca clases del SDK.
- **Modelo por defecto `claude-opus-5-5`** ($4/$20 por MTok),
  configurable con `ANTHROPIC_MODEL` (más barato: `claude-sonnet-5-5`
  $2/$10, `claude-haiku-4-5` $1/$5; decisión del usuario). En Opus 5.5 el
  razonamiento no se puede desactivar (400): se controla con `effort`
  (clasificación: `low`). Sin prefill (400): formato con **structured
  outputs** (`outputConfig.format` json_schema).
- **Fallbacks de servidor activados** (`fallbacks: "default"` + beta
  `server-side-fallback-2026-07-01`, `ANTHROPIC_FALLBACKS`): si los
  clasificadores de seguridad rechazan, Anthropic reintenta con otro modelo
  en la misma llamada. Siempre se comprueba `stop_reason` (`refusal`).
- **Sin API key = IA desactivada**: la ingesta sigue; la clasificación se
  omite con aviso en el log.
- `config/ai.php`: proveedor, clave, modelo, timeout 60 s, 2 reintentos
  del SDK, fallbacks, **precios por modelo** (USD/MTok, consultados
  2026-09-25; `cache_write` = 1,25 × entrada) y opciones de clasificación
  (`on_ingest`, `effort` low, `max_tokens` 4096 — incluye razonamiento —,
  `max_body_chars` 20000: si se recorta queda registrado y se avisa al
  modelo, nunca en silencio).

**Tareas (commit):**
- [x] 1. `composer require anthropic-ai/sdk:^0.54` + `config/ai.php` +
  variables en `.env.docker.example` (`ANTHROPIC_API_KEY`,
  `ANTHROPIC_MODEL`, `AI_CLASSIFY_ON_INGEST`)
- [x] 2. `App\AI\Contracts\LLMProvider` (`name()`, `generate(LLMRequest):
  LLMResponse`). DTOs `readonly` en `app/AI/Data`: `LLMRequest` (system,
  messages — empieza por user —, maxTokens, `jsonSchema` para structured
  outputs, `effort` low..max, `model` opcional; `single()`), `LLMUsage`
  (entrada, salida, escritura y lectura de caché) y `LLMResponse`
  (proveedor, modelo que respondió de verdad, texto, `data` JSON,
  usage, stopReason, latencia ms, requestId, `usedFallback`,
  `usageByModel` para costes con fallback). Excepciones en
  `app/AI/Exceptions` con `$retryable` y `?LLMResponse` (tokens facturados
  aunque falle): `LLMRateLimitException` y `LLMUnavailableException`
  (reintentables), `LLMConfigurationException`, `LLMRefusalException`,
  `LLMInvalidResponseException` (no). `tests/Fakes/FakeLLMProvider`
  (respuestas/excepciones encoladas, guarda peticiones). **Lección Pest:**
  en datasets, si el parámetro es `Closure`, Pest pasa el closure sin
  evaluarlo → un solo nivel `fn () => ...`
- [x] 3. Prompts **en código** (`App\AI\Prompts\Prompt`: `key()`,
  `version()`, `system()`, `outputSchema()`), revisables en git. Tabla
  global (sin tenant: los prompts son de la plataforma)
  `ai_prompt_versions` (key, version, system_prompt, output_schema jsonb,
  `content_hash`, único key+version) y modelo `AiPromptVersion`
  (inmutable, sin updated_at). `PromptRegistry::resolve($prompt)` guarda
  la versión la primera vez que se usa (caché por proceso, carrera entre
  workers controlada) y **falla si el contenido de una versión ya guardada
  cambió** (hash de system + esquema): hay que subir `version()`; así
  `ai_requests` siempre apunta al prompt exacto que se envió
- [x] 4. Tabla `ai_requests` (una fila por llamada, **también las
  fallidas**: los tokens se facturan igual): tenant (restrict), `email_id`
  (nullOnDelete: el coste se conserva), `ai_prompt_version_id`, `purpose`
  (= clave del prompt), provider, `requested_model` / `model` (el que
  respondió) / `used_fallback`, tokens de entrada/salida/escritura y
  lectura de caché, `cost_usd` decimal(12,6) (null sin precio),
  `latency_ms`, `status` success/failed/refused (enum
  `App\AI\Enums\AiRequestStatus`), `stop_reason`, `error`,
  `provider_request_id`, `intent`, `confidence` decimal(4,3), `response`
  jsonb. Índices tenant+created_at, tenant+status, email_id. Modelo
  `AiRequest` con `BelongsToTenant`, relaciones `email()` y
  `promptVersion()`
- [x] 5. `App\AI\CostCalculator`: `forResponse()` (suma por modelo de
  `usageByModel`: con fallback cada modelo factura sus tokens a su tarifa)
  y `forUsage($model, $usage)`. Entrada, salida, escritura y lectura de
  caché × precio de `config/ai.php`; USD con 6 decimales (string, columna
  decimal). Modelo sin precio → **null** (desconocido), nunca 0
- [x] 6. `App\AI\Providers\Anthropic\AnthropicProvider` con el SDK:
  `$client->beta->messages->create(model, maxTokens, system, messages,
  outputConfig: {effort, format: json_schema}, fallbacks: 'default',
  betas: [server-side-fallback-2026-07-01])` (sin `thinking` ni
  `temperature`: en Opus 5.5 darían 400). Respuesta: texto de los bloques
  `text` (los `thinking` se ignoran), JSON decodificado, modelo que
  respondió, `requestId` (id del mensaje), latencia (hrtime), tokens; con
  fallback, `usage.iterations` (`message` / `fallback_message`) →
  `usageByModel` + `usedFallback`. `stop_reason` `refusal` →
  `LLMRefusalException` (con categoría y tokens), `max_tokens` o JSON
  inválido → `LLMInvalidResponseException`. Errores del SDK: 429 →
  rate limit; 5xx/529 y otros estados → unavailable; 401/403 →
  configuración ("revisa ANTHROPIC_API_KEY"); 400/404/422 → configuración
  con el tipo y mensaje de error de la API; red/timeout → unavailable;
  nunca la clave en el mensaje. `App\Providers\AIServiceProvider`
  (registrado en `bootstrap/providers.php`): `LLMProvider` singleton
  según `ai.provider`; el `Client` del SDK se crea **al primer uso** y
  sin clave lanza `LLMConfigurationException` (no deja al SDK buscar
  credenciales en el entorno); timeout y reintentos del SDK desde config.
  Tests con el SDK real y un `GuzzleHttp\Client` con `MockHandler` como
  transporte PSR-18 (`RequestOptions::with(transporter: ...)`,
  `maxRetries: 0`), historial en un `ArrayObject` (una propiedad de test
  de Pest no sirve por referencia)
- [x] 7. `App\AI\AIService::run($prompt, $userContent, $maxTokens,
  $effort, ?$email)` → `AIResult` (registro + respuesta). Única puerta a
  la IA: resuelve la versión del prompt, construye la `LLMRequest`
  (system + esquema del prompt) y **siempre** crea la fila en
  `ai_requests` (dentro del tenant): éxito (tokens, coste, latencia,
  modelo, fallback, stop_reason, id, respuesta; `intent`/`confidence` si
  la respuesta los trae, confianza acotada a 0–1), `LLMException` →
  `failed` o `refused` con los tokens/coste que se facturaron y su
  mensaje, error inesperado → `failed` con "Error interno al llamar a la
  IA." (detalle solo al log); después relanza. Log por petición
  (`ai_request_id`, coste, latencia...). `AIService::enabled()` = hay API
  key. `PromptRegistry` singleton. Fakes de tests: `FakePrompt`
- [x] 8. Clasificador en `app/AI/Classification/`: enum `EmailIntent` (14:
  order_status, return_request, cancellation, invoice_request,
  billing_issue, product_question, technical_support, complaint,
  sales_inquiry, account_change, feedback, auto_reply, spam, other; con
  descripción para el prompt). `EmailClassificationPrompt` **v1** (en
  español, resumen en español; añadir intenciones o cambiar texto/esquema
  = subir VERSION). Esquema structured outputs: intent (enum), confidence,
  language, summary, needs_human + reason, sentiment, urgency,
  `injection_suspected`, entities (order_numbers, invoice_numbers,
  customer_name); `additionalProperties: false`, todo `required`,
  nullables con `anyOf`; **sin `minimum/maximum/maxLength`** (no los
  admite structured outputs: la confianza se acota en código).
  `EmailClassification::fromArray()` valida (intención desconocida →
  `LLMInvalidResponseException`) y **fuerza `needs_human` si hay sospecha
  de prompt injection**. `EmailClassifier::classify($email)`: contenido
  `<email>…</email>` como dato no fiable (De/Para/Cc/Responder a/Asunto/
  Fecha/nombres de adjuntos + cuerpo; texto o, si no hay, HTML →
  `HtmlToText`); las etiquetas `<email>`/`</email>` del contenido se
  neutralizan (`[email]`/`[/email]`) para que no pueda cerrar el bloque;
  cuerpo recortado a `max_body_chars` **avisando al modelo** y
  `bodyTruncated` en el resultado; `effort` y `max_tokens` de config;
  `aiRequestId` en el resultado. Helper de tests `classificationData()`
  en `tests/Pest.php`
- [x] 9. Columnas de clasificación **actual** en `emails` (el historial de
  intentos ya está en `ai_requests`): `intent`, `intent_confidence`
  decimal(4,3), `language`, `summary`, `needs_human` + reason,
  `sentiment`, `urgency`, `injection_suspected`, `ai_entities` jsonb,
  `classification_ai_request_id` (FK nullOnDelete), `classified_at`;
  índices tenant+intent y tenant+needs_human.
  `Email::recordClassification($classification)` (forceFill; **no cambia
  `status`**, eso es Fase 9), relaciones `classificationRequest()` y
  `aiRequests()`; nada de esto es asignable en masa. Nota: `jsonb`
  reordena las claves (en tests comparar con `toEqualCanonicalizing`)
- [x] 10. `App\AI\Classification\ClassifyEmailJob($tenantId, $emailId,
  $force)` en la cola **`ai`**: recibe ids (entra en el tenant con
  `runAs` + `tenant_id` en `Context`), `ShouldBeUnique` por email (1800
  s), no repite emails ya clasificados salvo `force` (**no se paga dos
  veces**), empresa inactiva o email borrado → no hace nada. `tries=4`,
  `backoff [30, 120, 300]`, `timeout 240` (incluye los reintentos del
  SDK; < `retry_after` 300). **Solo reintenta `LLMException`
  reintentables** (429, 529/5xx, red); rechazo, clave mala o respuesta
  inválida → `fail()` sin reintentar (cada intento cuesta dinero). Listener
  `QueueEmailClassification` (registrado en `AIServiceProvider::boot`)
  sobre `EmailIngested`: encola el job si `AI_CLASSIFY_ON_INGEST` y hay API
  key; sin clave deja un `notice` en el log y el email sin clasificar.
  Horizon: **`supervisor-ai`** para la cola `ai` (local 1 proceso,
  producción hasta 3: pocos a propósito para limitar gasto y 429; timeout
  250) + `waits.redis:ai` 300 s
- [x] 11. Comando `php artisan ai:classify {email?} {--pending} {--force}
  {--now}` (`app/Console/Commands/ClassifyEmails.php`): sin API key falla
  con un mensaje claro; un email → encola `ClassifyEmailJob` o, con
  `--now`, lo ejecuta al momento y muestra tabla de la petición (estado,
  modelo, tokens, coste, latencia) y de la clasificación (o el error);
  `--pending` encola todos los emails **ingeridos** sin clasificar (p. ej.
  los que llegaron sin API key); `--force` reclasifica. Consultas
  globales deliberadas (`withoutGlobalScope`), el job entra en el tenant
- [x] 12. Prueba en el stack **sin API key** (2026-10-06): migraciones
  aplicadas en la BD de desarrollo; `horizon:supervisors` levanta
  `supervisor-ai` junto a `supervisor-1` y `supervisor-mail`; correo
  enviado por SMTP a `soporte@demo.test` → ingerido por el scheduler
  (email **id 2**, "Quiero devolver el producto del pedido #45821") y
  dejado sin clasificar con el `notice` "IA desactivada" en el log;
  `ai:classify 2 --now` → "añade ANTHROPIC_API_KEY". README: sección
  "6. Inteligencia artificial: clasificación de emails" (activarla paso a
  paso, variables, cómo funciona, qué devuelve, structured outputs,
  prompts versionados, prompt injection, reintentos, costes con consulta
  SQL de ejemplo, cómo añadir otro proveedor), estado, comandos,
  estructura, tests, problemas frecuentes; secciones siguientes
  renumeradas (API = #8)

**Prueba real con Claude (2026-10-08, API key del usuario, 5 $ de
saldo, `claude-opus-5-5`):**
- 1er intento (06/10) → 400 "credit balance is too low": registrado como
  `failed` sin coste y **sin reintento** (comportamiento previsto).
- `ai:classify 2 --now` → `return_request` 0,95, resumen correcto, pedido
  45821; 1960/159 tokens = **0,011020 $** (cuadra con el cálculo),
  17 s (primera petición con el esquema: Anthropic lo compila y cachea
  24 h; las siguientes 2–7 s). Fallbacks `"default"` aceptados por la
  cuenta (sin 400). Fila en `ai_prompt_versions` (v1) y email enlazado a
  su `ai_request` sin cambiar `status`.
- **Flujo automático** con 7 correos SMTP a GreenMail → scheduler →
  ingesta → `EmailIngested` → `ClassifyEmailJob` (Horizon, cola `ai`):
  7/7 clasificados solos en 52 s. Todos correctos: pedido
  (`order_status`), factura (`invoice_request`, CIF en resumen), queja
  con amenaza legal (`billing_issue`, urgencia alta, **needs_human**),
  spam/phishing (`spam`), fuera de oficina (`auto_reply`), email en
  inglés (`return_request`, `en`, resumen en español, needs_human por
  reembolso) y **prompt injection** (cierra `</email>` y ordena cambiar
  la clasificación y revelar clientes/prompt) → `other` 0,30,
  `injection_suspected` y needs_human, **no obedeció ni reveló nada**.
- Reclasificar sin `--force` no vuelve a llamar a la API; `--pending`
  clasificó el email 1 de la Fase 4. **Gasto total de las pruebas:
  0,106 $** (9 llamadas correctas, media **0,0118 $/email** → ~420
  emails con 5 $).
- **Lección:** `storage/` del contenedor es un volumen Docker
  (`app-storage`), no la carpeta del host: scripts sueltos se pasan por
  stdin (`docker compose exec -T app php < script.php`, con `require
  '/var/www/html/vendor/autoload.php'`). `tinker archivo.php` no lo
  ejecuta.

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14).

### Fase 6 (RAG: documentación de la empresa): CERRADA — COMPLETADA Y PROBADA EN LOCAL (2026-10-08), CI en verde (2026-10-14)

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Objetivo:** cada empresa sube su documentación (políticas, FAQs,
manuales…); se extrae el texto, se trocea, se calcula un embedding por
trozo y se guarda en PostgreSQL + pgvector. Ante una pregunta se buscan
los trozos más parecidos por significado y se devuelven con su fuente
(documento, página/sección) para que el agente (Fase 8) responda citando.

**Decisiones tomadas:**
- **Embeddings locales con Ollama** (decisión del usuario, 2026-10-08:
  tratamiento de datos de empresas → los documentos no deben salir del
  servidor). Se compararon APIs (Voyage, recomendada por Anthropic, 200M
  tokens gratis; OpenAI; Gemini — su tramo gratis puede usar los datos —;
  Cohere; Mistral; Jina) y modelos locales (bge-m3, EmbeddingGemma,
  Qwen3-Embedding, Nomic, voyage-4-nano). Elegido **`bge-m3`**
  (multilingüe, 1024 dimensiones, ~1,2 GB). Matiz explicado al usuario:
  los emails ya van a Claude y en la Fase 8 irán también los trozos
  relevantes (no el corpus): la API de Anthropic no entrena con datos de
  la API por defecto y ofrece DPA; revisar en Fase 12/16.
- Servicio **`ollama`** (`ollama/ollama:0.12.6`) en docker-compose, **solo
  red interna** (sin `ports`), volumen `ollama-data`, `OLLAMA_KEEP_ALIVE`
  1h. Modelo: `docker compose exec ollama ollama pull bge-m3` (una vez).
  Docker del usuario: 12 CPU, 15,5 GB (el stack usa <1 GB).
- Interfaz `App\AI\Embeddings\EmbeddingProvider` (`name`, `model`,
  `dimensions`, `embed($texts, EmbeddingInputType)` → `EmbeddingResult`:
  vectores, modelo, tokens, latencia, peticiones), intercambiable por una
  API si algún día se quiere. Errores: se reutiliza la jerarquía
  `LLMException` (reintentable o no).
- pgvector con el **soporte nativo de Laravel 13**: `$table->vector()`,
  `vectorIndex()` (HNSW + `vector_cosine_ops`), `whereVectorSimilarTo()`
  / `orderByVectorDistance()` (operador `<=>`), cast `AsVector`. Pasar
  siempre arrays (con string Laravel intentaría generar embeddings él).
- Coste de embeddings locales = 0 (precio `bge-m3` a 0 en `ai.pricing`,
  no null: cuentan como gratis); se registran igualmente en `ai_requests`
  (tokens, latencia).

**Tareas (commit):**
- [x] 1. `config/ai.php` → `embeddings` (provider `ollama`, dimensiones
  1024, `ollama.base_url` `http://ollama:11434`, `model` bge-m3, timeout
  120 s, `batch_size` 16) + precio 0; `OLLAMA_URL` /
  `OLLAMA_EMBEDDING_MODEL` en `.env.docker.example`; servicio `ollama` en
  docker-compose; interfaz `EmbeddingProvider`, `EmbeddingResult`, enum
  `EmbeddingInputType` (query/document); `tests/Fakes/FakeEmbeddingProvider`
  (bolsa de palabras con hashing, normalizada y determinista: textos con
  palabras en común se parecen; registra llamadas, `failWith`)
- [x] 2. `App\AI\Embeddings\OllamaEmbeddingProvider` con `Http` de Laravel:
  `POST {base_url}/api/embed` (`model`, `input` = lote, `truncate: true`),
  lotes de `batch_size` manteniendo el orden, tokens =
  `prompt_eval_count`, **vectores normalizados** a longitud 1, valida un
  vector por texto y las dimensiones (≠ 1024 → `LLMInvalidResponseException`
  "la base de datos espera 1024"). Errores: sin conexión →
  `LLMUnavailableException` ("¿está levantado el servicio ollama?"), 404 →
  `LLMConfigurationException` con el comando `ollama pull`, 5xx →
  unavailable, resto 4xx → configuración. Singleton `EmbeddingProvider`
  en `AIServiceProvider` según `ai.embeddings.provider`. Tests con
  `Http::fake` + `preventStrayRequests`. **Prueba real** (bge-m3
  descargado, 1,2 GB): 1024 dimensiones; similitud política de
  devoluciones ↔ "quiero devolver la lámpara rota" 0,52, ↔ mismo email en
  inglés 0,49, política de envíos ↔ email de devolución 0,37
  (multilingüe y con sentido); primera llamada 3,3 s (carga del modelo)
- [x] 3. Tabla `documents`: tenant (restrict), `uploaded_by` (users,
  nullOnDelete), `title`, `original_filename` (solo para mostrar, nunca
  como ruta), `mime_type`, `size`, `disk`/`path` del original en disco
  privado, `sha256` (**único por tenant**: la misma empresa no sube dos
  veces el mismo fichero; otra empresa sí), `status`
  pending/processing/ready/failed, `error`, `chunks_count`,
  `embedding_model` (para saber qué reindexar si cambia el modelo),
  `processed_at`. Código del módulo en **`app/Documents/`** (sección 20):
  enum `App\Documents\Enums\DocumentStatus`. Modelo `Document`
  (`BelongsToTenant`, factory, solo `title` asignable en masa,
  `disk`/`path` ocultos; `markProcessing()`, `markReady($chunks, $model)`,
  `markFailed($error)`, `uploader()`)
- [x] 4. Tabla `document_chunks`: tenant, `document_id` (cascade),
  `position` (único por documento), `content`, `token_count`, `metadata`
  jsonb (página/sección para citar), **`embedding vector(1024)`** e índice
  **HNSW `vector_cosine_ops`** (`$table->vectorIndex()`), `created_at`.
  Modelo `DocumentChunk` (`BelongsToTenant`, cast `AsVector`, `embedding`
  oculto en JSON), `Document::chunks()` ordenado por posición.
  Comprobado: Postgres rechaza vectores de otra dimensión,
  `orderByVectorDistance` encuentra el trozo más cercano. **pgvector
  0.8.6** en la imagen: soporta *iterative scans* (necesario porque HNSW
  filtra `tenant_id` después de buscar vecinos → se usará en la tarea 9)
- [x] 5. `composer require smalot/pdfparser` (v2.12.5, PHP puro, licencia
  LGPL-3.0: uso como librería en app propia/SaaS sin problema).
  `App\Documents\Extraction\DocumentTextExtractor::extract($content,
  $mime)` → lista de `DocumentSection` (texto + metadata para citar): PDF
  → una por página (`page`, páginas vacías fuera), Markdown → una por
  encabezado `#` (`heading`, el encabezado se queda en el texto), HTML →
  `HtmlToText`, TXT tal cual. Texto saneado (UTF-8 válido, sin NUL) y
  espacios normalizados. `DocumentProcessingException` (mensaje para el
  usuario, no reintentable): formato no soportado (Word aún no), vacío,
  **PDF sin texto (escaneado: OCR no soportado)**, PDF dañado o con
  contraseña. `mimeFor($filename, $detected)`: manda la extensión (los
  navegadores envían `.md` como text/plain). Helper de tests
  `tests/Support/Pdf::make([...páginas])` (PDF válido generado al vuelo,
  WinAnsi: acentos OK; sin binarios en el repo)
- [x] 6. `App\Documents\Chunking\TextChunker::chunk($sections)` →
  `TextChunk` (texto, metadata de su sección, tokens estimados ≈ chars/4).
  Agrupa **párrafos completos** hasta `ai.rag.chunk_max_chars` (2000 ≈ 500
  tokens); párrafo mayor → por frases; frase mayor → por palabras; palabra
  mayor → corte bruto. **Solapamiento** `chunk_overlap_chars` (200): cada
  trozo empieza con el final del anterior (desde una palabra completa).
  **Nunca mezcla secciones** (páginas/encabezados): la cita es exacta.
  Cuenta caracteres (mb_*), no bytes. Registrado en el contenedor con los
  tamaños de config (`RAG_CHUNK_MAX_CHARS`, `RAG_CHUNK_OVERLAP_CHARS`)
- [x] 7. `App\AI\Embeddings\EmbeddingService::embed($texts, $type,
  $purpose, ?$email)`: única puerta a los embeddings (como `AIService`
  para el LLM). Registra **cada llamada en `ai_requests`** (dentro del
  tenant) con `purpose` `document_indexing` o `knowledge_search`
  (constantes), proveedor/modelo, `input_tokens`, `cost_usd` (0 en local,
  vía `CostCalculator::forUsage`), latencia y `response` (nº textos,
  peticiones, dimensiones); fallo → `failed` con el mensaje (o "Error
  interno..." si no es `LLMException`) y relanza
- [x] 8. `App\Documents\DocumentService`: `upload($content, $filename,
  ?$title, ?$uploader, ?$detectedMime)` (tipo por extensión/MIME, máx.
  `ai.rag.max_upload_mb` 20, vacío y **duplicado por sha256** →
  `DocumentProcessingException`; original en disco privado
  `ai.rag.disk` en `tenants/{t}/documents/{uuid}`, nunca con el nombre
  subido; título = el dado o el nombre sin extensión; encola el indexado);
  `process($document)` (processing → extraer → trocear → embeddings con
  **contexto "Documento: título / Sección: …"** delante de cada trozo,
  pero se guarda solo el trozo → **reemplazo atómico de los trozos** en
  transacción → `ready` con nº de trozos y modelo; error del documento o
  de configuración → `failed` sin reintento; error temporal del proveedor
  → se relanza); `delete()` (fichero + documento; trozos en cascada).
  `App\Documents\ProcessDocumentJob($tenantId, $documentId)`: cola
  `documents` en la conexión **`redis-long`** (`retry_after` 1900 s,
  `config/queue.php`) porque indexar en CPU puede tardar minutos y con
  `retry_after` 300 Redis relanzaría el job en marcha; `timeout` 1800,
  `tries` 3, `backoff [60, 300]`, único por documento, entra en el tenant
  con `runAs`; `failed()` deja el documento `failed` con el error.
  Conexión configurable `ai.rag.queue_connection`
  (`RAG_QUEUE_CONNECTION`, `sync` en `phpunit.xml`). Horizon:
  **`supervisor-documents`** (redis-long, timeout 1850, 1 proceso local / 2
  producción, 512 MB) + `waits.redis-long:documents` 900
- [x] 9. `App\Documents\Search\SemanticSearchService::search($query,
  ?$limit, ?$minSimilarity, ?$email)` → lista de `SearchResult` (chunkId,
  documentId, título, contenido, metadata, `similarity` 0–1 = 1 −
  distancia coseno; `source()` → "Título, pág. N" / "Título, sección
  «X»"). Pregunta → embedding (`knowledge_search` en `ai_requests`,
  enlazado al email si lo hay) → `DocumentChunk` (scope de tenant) join
  `documents` **solo `ready`** con `selectVectorDistance` +
  `whereVectorDistanceLessThan(1 − min)` + `orderByVectorDistance` +
  `limit`, dentro de una transacción con **`SET LOCAL
  hnsw.iterative_scan = relaxed_order`** (HNSW filtra el tenant después
  de buscar vecinos: así completa el límite dentro de la empresa) y
  reordenado en PHP (relaxed_order puede alterar el orden). Pregunta vacía
  → [] sin llamar al modelo. Config `ai.rag.search_limit` 5 y
  `min_similarity` 0,45 (bge-m3: relacionado ≈ 0,5, sin relación ≈ 0,35;
  `RAG_SEARCH_LIMIT`, `RAG_MIN_SIMILARITY`). Test de aislamiento: los
  documentos de otra empresa nunca aparecen
- [x] 10. `App\Documents\KnowledgeBaseService` (lo usará el agente de la
  Fase 8): `contextFor($question, ?$limit, ?$email)` y
  `contextForEmail($email)` (pregunta = asunto + cuerpo — texto o HTML →
  texto —, máx. 2000 caracteres; búsqueda enlazada al email en
  `ai_requests`) → `KnowledgeContext`: `toPrompt()` = bloque
  `<documentacion><fuente id="N" origen="Título, pág./sección">…</fuente>
  </documentacion>` como **dato** (las etiquetas
  `documentacion`/`fuente` del propio contenido se neutralizan a
  `[...]`: un documento no puede salirse del bloque; atributo escapado);
  sin resultados lo dice explícitamente; `sources()` (id, fuente,
  document_id, chunk_id, similitud) para citar en la respuesta
- [x] 11. API: permisos nuevos **`documents.view`** (todos los roles) y
  **`documents.manage`** (owner, admin) en `Permission::CATALOG` /
  `Role::PERMISSIONS`; `DocumentPolicy` (otra empresa → 404 antes que el
  permiso). Rutas (auth + tenant): `GET|POST /documents` (multipart
  `file` + `title` opcional; `max` = `ai.rag.max_upload_mb`; errores de
  `DocumentService` → **422 en `file`** con el motivo), `GET|DELETE
  /documents/{id}`, `POST /documents/{id}/reindex` (vuelve a `pending` y
  encola), `POST /knowledge-base/search` (`query` máx. 2000, `limit` 1–20,
  throttle 30/min; respuesta `data[]` con `source`, `document_id`,
  `document_title`, `content`, `metadata`, `similarity`; proveedor de
  embeddings caído → **503** "La búsqueda no está disponible…").
  `DocumentResource` sin disco/ruta/hash. Tests de aislamiento: otra
  empresa → 404 en ver/reindexar/borrar y su contenido nunca sale en la
  búsqueda
- [x] 12. Comandos `php artisan kb:import <ruta> --tenant=<id>
  [--title=]` (sube un fichero del contenedor; errores claros) y
  `kb:search "<pregunta>" --tenant=<id> [--limit=] [--min=]` (tabla
  similitud / fuente / texto). Documentación de ejemplo realista de
  "Empresa Demo" en `database/seeders/knowledge/*.md` (política de
  devoluciones, envíos, garantía, FAQ de pagos y facturas: plazos, costes,
  procesos — base para el agente de la Fase 8) y `KnowledgeBaseSeeder`
  (idempotente por sha256; título = primer `#`). Se ejecuta **aparte**:
  `php artisan db:seed --class=KnowledgeBaseSeeder`, no desde
  `DatabaseSeeder`, porque este usa `WithoutModelEvents` y
  `BelongsToTenant` necesita el evento `creating`
- [x] Fix detectado en la prueba real: en Markdown, un encabezado sin
  texto debajo (`# Título` seguido de `## Apartado`) generaba un trozo
  con solo el título (inútil y que salía en las búsquedas); ahora se une
  a la sección siguiente
- [x] 13. **Prueba real en el stack (2026-10-08)** con Ollama + bge-m3:
  migraciones en la BD de desarrollo, `RolesAndPermissionsSeeder` (permisos
  nuevos), `horizon:supervisors` con `supervisor-documents` en
  `redis-long:documents`; `KnowledgeBaseSeeder` → 4 documentos indexados
  por Horizon en **14 s** (1,5–3,7 s por documento, coste 0). Búsquedas
  reales (`kb:search`): producto roto → "Política de devoluciones,
  sección «Productos que llegan rotos o defectuosos»" 0,53; envío a
  Canarias → "Coste del envío" 0,74; doble cobro → "Me habéis cobrado dos
  veces" 0,62; factura de empresa 0,73; **inglés** ("How long do I have
  to return an item?") → "Plazo para devolver" 0,72; sin relación
  ("¿tienda física en Madrid?") máx. 0,40 → **vacío con el umbral 0,45**.
  Fix de encabezados vacíos (commit anterior) y **reindexado por la API**
  (`POST /documents/{id}/reindex`) → 0 trozos de solo título. API con
  curl: subida de PDF (2 páginas, ready), duplicado → 422, búsqueda del PDF
  → "Programa de puntos, pág. 2" 0,68, **usuario de otra empresa: búsqueda
  vacía y 404 al documento**. 18 llamadas de embeddings en `ai_requests`
  (coste 0, búsqueda media 0,7 s). `EXPLAIN` forzado confirma que el
  orden por `<=>` usa `document_chunks_embedding_vectorindex` (HNSW); con
  pocos trozos el planificador prefiere filtrar por tenant y ordenar (es
  lo más barato). README: sección "7. Base de conocimiento (RAG)" (cómo
  funciona, privacidad, aislamiento, fuentes, umbral, formatos, coste,
  probarlo, resultados reales, detalles técnicos), paso `ollama pull
  bge-m3` y `KnowledgeBaseSeeder` en el arranque, servicio `ollama`,
  estructura, API, tests, problemas frecuentes; secciones renumeradas
  (API = #9)

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14).

### Fase 7 (Tools): CERRADA — COMPLETADA Y PROBADA EN LOCAL (2026-10-14), CI en verde (2026-10-14)

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Objetivo:** herramientas controladas por el backend que la IA puede
pedir (sección 9): la IA nunca accede a datos por su cuenta. `AITool` +
`ToolRegistry` + `ToolExecutor` (único que ejecuta: valida argumentos con
JSON Schema, permisos, errores, límites, registro en `ai_tool_calls`) y
primeras herramientas. El bucle de tool calling con Claude (agente) es la
Fase 8.

**Decisiones tomadas:**
- Código en **`app/Tools/`** (sección 20). Errores esperados de una
  herramienta = `ToolResult::error()` (el modelo lo lee y decide), no
  excepciones.
- **`ToolContext` lo construye el backend, nunca la IA**: empresa, email
  procesado, remitente, `aiRequestId`. Las herramientas acotan los datos
  con el contexto, no con argumentos que el modelo podría inventar.
- **Datos de pedidos:** aún no hay integraciones reales (Fase 14) →
  interfaz `OrderProvider` en `app/ERP/` (sección 20) con un **proveedor
  demo** que lee pedidos de ejemplo de un JSON para "Empresa Demo", con
  los mismos números que los emails de prueba (#45821, #78123, #76540,
  #75555). En la Fase 14 se sustituye por Shopify/WooCommerce/API del ERP
  sin tocar las herramientas.
- **Herramientas de pedidos atadas al remitente del email**: un pedido de
  otro cliente se responde como "no encontrado" (un email malicioso no
  puede sacar datos de otros clientes).

**Tareas (commit):**
- [x] 1. `App\Tools\Contracts\AITool` (`name()` snake_case,
  `description()` para el modelo, `inputSchema()` JSON Schema,
  `execute($arguments, ToolContext)`), `App\Tools\ToolContext` (tenant,
  ?email, ?aiRequestId; `senderEmail()` normalizado en minúsculas) y
  `App\Tools\ToolResult` (`success($data)` / `error($message)`,
  `toArray()` = lo que verá el modelo)
- [x] 2. Tabla `ai_tool_calls` (sección 9): tenant (restrict),
  `ai_request_id` y `email_id` (nullOnDelete: la auditoría se conserva),
  `tool`, `arguments` jsonb, `result` jsonb (lo que vio el modelo),
  `status` **success / error / rejected** (enum
  `App\Tools\Enums\ToolCallStatus`; rejected = ni se ejecutó: herramienta
  desconocida o desactivada, argumentos inválidos o sin permiso), `error`,
  `duration_ms`, `created_at`. Índices tenant+created_at, tenant+tool,
  ai_request_id. Modelo `AiToolCall` (`BelongsToTenant`), relación
  `AiRequest::toolCalls()`
- [x] 3. `App\Tools\ToolRegistry`: catálogo de herramientas (`register`,
  `get`, `has`, `all`, `definitions()` → nombre/descripción/`input_schema`
  en **orden alfabético estable**, bueno para la caché de prompts). Valida
  nombres **únicos** en `snake_case` (máx. 64) y esquemas `type: object`
  con `additionalProperties: false`. Singleton construido desde
  **`config('ai.tools.available')`** (lista de clases; una herramienta
  que no esté ahí no existe para la IA) + `ai.tools.max_result_chars`
  (8000, `AI_TOOLS_MAX_RESULT_CHARS`). `tests/Fakes/FakeTool`
  (nombre, esquema y manejador configurables; registra llamadas)
- [x] 4. `composer require opis/json-schema:^2.6` (Apache-2.0).
  `App\Tools\ToolExecutor::execute($name, $arguments, ToolContext)`:
  **único punto de ejecución**, nunca lanza (devuelve `ToolResult`).
  Dentro de `runAs($context->tenant)`: 1) herramienta desconocida →
  `rejected`; 2) argumentos validados con **JSON Schema** (opis; primer
  error legible "Argumentos no válidos para X: /campo: …" para que el
  modelo se corrija; argumentos inventados rechazados por
  `additionalProperties: false`) → `rejected`; 3) interfaz marcadora
  **`App\Tools\Contracts\RequiresSender`**: sin remitente en el contexto
  → `rejected`; 4) ejecución, excepción inesperada → `error` genérico
  "Error interno al ejecutar X…" (detalle **solo al log**); 5) resultado
  > `ai.tools.max_result_chars` → se recorta (`truncated`, `note`,
  `partial`). Siempre registra en `ai_tool_calls` (argumentos, resultado
  que vio el modelo, estado, error, duración, ai_request_id, email_id) +
  log
- [x] 5. Herramientas en `app/Tools/Builtin/`. **`search_company_documents`**
  (`SearchCompanyDocumentsTool`, registrada en `ai.tools.available`):
  argumentos `query` (obligatorio) y `limit` (por defecto 5, **acotado a
  1–8 en código**: el esquema no usa `minimum/maximum` porque Claude no
  los admite en herramientas estrictas). Devuelve `results[]` (`source`
  citable, `document_id`, `similarity`, `content`) vía
  `SemanticSearchService` (búsqueda enlazada al email del contexto); sin
  resultados → `results: []` + `note` "No inventes…"; embeddings caídos →
  error "La búsqueda en la documentación no está disponible…". La
  descripción le pide al modelo responder solo con lo que digan los
  fragmentos y citar la fuente
- [x] 6. Módulo **`app/ERP/`**: interfaz `Contracts\OrderProvider`
  (`findOrder($number)` — admite "#" —, `ordersForCustomer($email,
  $limit)` del más reciente al más antiguo; siempre la empresa del
  `TenantService`), DTOs `readonly` `Data\Order` (número, cliente, estado,
  fecha, total, moneda, líneas, envío, ciudad/país — sin dirección
  completa: no hace falta para responder —; `belongsTo($email)` sin
  mayúsculas/espacios), `OrderItem`, `Shipment` (transportista,
  seguimiento, fechas) y enum `OrderStatus` (pending_payment, processing,
  shipped, delivered, cancelled, returned; `label()` en español).
  **`Providers\DemoOrderProvider`**: lee `database/seeders/demo/orders.json`
  (`{slug: [pedidos]}`); solo "empresa-demo" tiene pedidos, coherentes con
  los emails de prueba (#45821 y #44102 Cliente Pérez, #78123 y #74400
  Laura, #77001 Talleres Ruiz, #76540 Marcos 349 €, #75555 John, lámpara).
  `config/erp.php` (`ORDERS_PROVIDER=demo`, `ORDERS_DEMO_FILE`). **Bug
  evitado:** `OrderProvider` y `ToolRegistry` registrados como
  **`scoped`** y no singleton: dependen (directa o indirectamente) de
  `TenantService`, que es scoped; un singleton se quedaría en el worker
  con el `TenantService` del primer job y vería la empresa equivocada
  (test con `forgetScopedInstances()`)
- [x] 7. **`get_order`** y **`get_customer_orders`** (`app/Tools/Builtin/`,
  ambas `RequiresSender`, registradas en `ai.tools.available`).
  `get_order(order_number)`: número normalizado (primer fragmento con
  dígitos: "#45821", "nº 45821", "pedido 45821", "ES-1234"); **pedido
  inexistente y pedido de otro cliente → misma respuesta** ("No hay
  ningún pedido con ese número asociado a este cliente": ni siquiera se
  puede averiguar si un número existe); detalle vía `OrderPresenter`
  (estado + `status_label`, fecha, total, líneas, envío con seguimiento y
  fechas, ciudad/país). `get_customer_orders(limit?)`: **sin parámetro de
  email** (siempre el remitente del contexto; un `email` en los
  argumentos se rechaza por el esquema), resúmenes del más reciente al más
  antiguo, límite acotado a 1–10 en código, `note` si no tiene pedidos.
  Tests: otro cliente/otra empresa/sin remitente nunca ven datos; todo
  queda en `ai_tool_calls`
- [x] 8. Comando `php artisan ai:tool {name?} [--args=JSON]
  [--email=<id>|--tenant=<id>] [--list]` (`RunAiTool`): `--list` (o sin
  nombre) → tabla herramienta / necesita email / descripción; ejecutar →
  **siempre vía `ToolExecutor`** (validación, permisos y registro en
  `ai_tool_calls` igual que con la IA) con el contexto del email indicado
  (empresa + remitente; consulta global deliberada) o de la empresa;
  imprime OK/ERROR + el JSON que vería el modelo. **Lecciones:** en
  comandos no llamar a un método `context()` (choca con un método
  protegido de `Command`), y no poner llaves `{}` dentro de la firma (ni
  como valor por defecto ni en la descripción de una opción)
- [x] 9. **Prueba en el stack (2026-10-14)** con los emails reales de la
  BD de desarrollo (migración `ai_tool_calls` aplicada): `ai:tool --list`
  muestra las 3 herramientas; email 1 (Cliente Pérez) → `get_order
  #45821` con productos, SEUR y seguimiento; email 1 pidiendo el 76540 (de
  Marcos) → "No hay ningún pedido…"; email 3 (Laura) →
  `get_customer_orders` 78123 (en preparación) y 74400 (cancelado); email
  9 (el de la prompt injection) con `{"email": "cliente@cliente.test"}` →
  `rejected` por el esquema; email 6 (spam) → sin pedidos;
  `search_company_documents` con el email en inglés de John → "Productos
  que llegan rotos o defectuosos" 0,55 (Ollama real). Las 6 llamadas en
  `ai_tool_calls` con estado, duración y error (~0,7 s por llamada es el
  arranque de cada proceso `artisan`; la búsqueda 6,3 s por la carga del
  modelo en Ollama tras inactividad). README: sección "8. Herramientas de
  la IA (Tools)" (herramientas, cómo se controlan, datos solo del propio
  cliente, argumentos inventados, pedidos de demostración, probarlas,
  añadir una herramienta), estado, comandos, estructura; secciones
  renumeradas (API = #10)

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14).
### Fase 8 (AI Agent): CERRADA — COMPLETADA Y PROBADA CON CLAUDE REAL (2026-10-14), CI en verde (2026-10-14)

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Objetivo:** dado un email, Claude usa las herramientas en cadena
(pedido → política vía RAG → …) y redacta una **propuesta de respuesta**
con confianza y fuentes. **No se envía nada**: queda como borrador
(`email_replies`); decidir envío automático o revisión humana y enviar es
la Fase 9.

**Decisiones tomadas:**
- **Bucle propio** (no el Tool Runner del SDK): control de cada paso,
  límites, auditoría por iteración y ejecución siempre vía `ToolExecutor`.
- **Historial solo-añadir (preserved thinking):** la cuenta del usuario
  es posterior al 2026-08-31 → Opus 5.5 aplica la comprobación de
  historial (editar turnos anteriores = 400). `system` y conjunto de
  herramientas congelados durante la conversación; el turno del
  asistente se devuelve **tal cual** (`content` del SDK, con sus bloques
  `thinking`); resultados como bloques `tool_result` (`toolUseID`,
  `isError`) en un único mensaje de usuario por turno.
- **Sin `tool_choice` forzado** (400 en Opus 5.5): `auto` + instrucción
  en el prompt; herramientas con **`strict: true`**. Respuesta final con
  structured outputs (`output_config.format`) junto con `tools`
  (verificar en la prueba real; alternativa: herramienta `submit_reply`).
- **Límites duros:** iteraciones, llamadas a herramientas, tiempo y gasto
  por email; superarlos → revisión humana.
- **Revisión humana obligatoria** si: confianza < umbral, Claude lo pide,
  sospecha de prompt injection, la clasificación ya lo marcó, falla una
  herramienta clave o se agota un límite.
- Auditoría: `ai_agent_runs` (pasos, tokens, coste, duración, motivo de
  parada) enlazada a sus `ai_requests` y `ai_tool_calls`.

**Tareas (commit):**
- [x] 1. **Herramientas por empresa** (acordado con el usuario
  2026-10-14): tabla `tenant_tools` (tenant cascade, `tool`, `enabled`,
  único tenant+tool) y modelo `TenantTool` (`BelongsToTenant`). Sin fila
  manda **`ai.tools.default_enabled`** (hoy solo
  `search_company_documents`); el resto se activa por empresa. Interfaz
  **`App\Tools\Contracts\HasAvailability`** (`isAvailable()`, dentro del
  tenant): `get_order`/`get_customer_orders` solo si
  `OrderProvider::isConfigured()` (nuevo; demo: la empresa está en el
  JSON). **`App\Tools\TenantToolService`**: `toolsFor($tenant)` (activadas
  **y** disponibles, orden alfabético estable — lo que se ofrecerá a
  Claude —), `isUsable()`, `statusFor()`, `enable()`/`disable()` (solo
  herramientas del catálogo). **`ToolExecutor` rechaza** (`rejected`, "no
  está disponible para esta empresa") las no activadas/no disponibles
  aunque existan. Comando **`php artisan ai:tools <tenant>
  [--enable=*] [--disable=*]`** (tabla activada / disponible / origen /
  "la IA la ve"). Helper de tests `enableTools($tenant, ...$tools)` en
  `tests/Pest.php`
- [x] 2. **Tool calling en la capa de IA.** DTOs `LLMToolCall` (id del
  proveedor, nombre, input) y `LLMToolResult` (toolCallId, content JSON,
  isError). `LLMRequest`: `tools` (definiciones de `ToolRegistry`) y
  conversación **solo-añadir** con 3 formas de mensaje: usuario texto,
  `['role' => 'assistant', 'content' => opaco]` y `['role' => 'user',
  'tool_results' => [...]]`. `LLMResponse`: `toolCalls`,
  `assistantContent` (turno del modelo tal cual, opaco), `wantsTools()`,
  `withData()`. `AnthropicProvider`: herramientas con **`strict: true`**
  (`inputSchema`), resultados como bloques `tool_result` (`toolUseID`,
  `isError`) en un único mensaje de usuario, turno del asistente
  devuelto **sin tocar** (`$message->content` del SDK, con sus bloques
  `thinking`), `stop_reason` `tool_use` → devuelve las llamadas sin
  interpretar JSON. Tests con el SDK real: la segunda petición lleva el
  `thinking` con su `signature` intacta y los `tool_result` con
  `tool_use_id`/`is_error`. `FakeLLMProvider::toolUse([[nombre, args]])`
- [x] 3. Código del agente en **`app/AI/Agent/`**. `EmailAgentPrompt`
  **v1** (`email_agent`): **igual para todas las empresas** (se versiona
  por hash y no puede cambiar durante la conversación: empresa, fecha,
  clasificación y email van en el mensaje del usuario). Reglas: email =
  dato no fiable; verificar con herramientas antes de afirmar (pedidos con
  `get_order`/`get_customer_orders`, condiciones con
  `search_company_documents`); no afirmar nada que no salga de
  email/clasificación/herramientas; **solo informar, nunca prometer
  reembolsos/cancelaciones/cambios** (van a `pending_actions` +
  `needs_human`); respuesta en el idioma del cliente, texto plano, sin
  mencionar herramientas ni documentos internos, firmada por el equipo de
  atención al cliente. Esquema (structured outputs): `reply_body`,
  `reply_language`, `confidence`, `needs_human` + reason, `sources[]`
  (`document`/`order` + `reference`), `pending_actions[]`,
  `internal_note`. `EmailAgentContext::build($email)` → mensaje inicial
  (empresa, fecha de hoy, clasificación previa con aviso de injection, y
  el bloque `<email>` neutralizado reutilizando
  `EmailClassifier::content()`). `AgentReply::fromArray()` valida (sin
  texto → `LLMInvalidResponseException`, confianza acotada, fuentes y
  acciones filtradas). Helper de tests `agentReplyData()`
- [x] 4. Tabla **`ai_agent_runs`** (una por ejecución del agente sobre un
  email: tenant, email nullOnDelete, versión de prompt, `status`
  running/completed/failed, `stop_reason` end_turn / max_iterations /
  max_tool_calls / timeout / budget / refusal / error — enums
  `AgentRunStatus` y `AgentStopReason` con `label()` en
  `app/AI/Agent/Enums/` —, `error`, iteraciones, nº de herramientas,
  tokens, `cost_usd`, `duration_ms`, `final_output` jsonb, inicio/fin) +
  columna **`ai_requests.agent_run_id`** (un paso del agente por
  petición; las herramientas ya cuelgan de cada `ai_request`: se puede
  reconstruir todo lo que hizo la IA). Tabla **`email_replies`**
  (propuesta de respuesta: email cascade, `agent_run_id`, `body`,
  `language`, `confidence`, `needs_human` + reason, `sources`,
  `pending_actions`, `internal_note`, `status` draft/approved/rejected/sent
  — enum `EmailReplyStatus`, no asignable en masa; lo moverán las Fases 9
  y 13). Modelos `AiAgentRun` (`aiRequests()`, `reply()`, `email()`) y
  `EmailReply` (`email()`, `agentRun()`); `Email::replies()` /
  `agentRuns()`, `AiRequest::agentRun()`
- [x] 5. `AIService::converse($prompt, $messages, $maxTokens, $effort,
  $tools, ?$email, ?$agentRunId)` (paso de conversación con herramientas;
  `run()` lo usa por dentro; si el modelo pide herramientas, `response` =
  las llamadas pedidas). **`App\AI\Agent\AgentService::run($email)`**
  (dentro del tenant del email): herramientas = `TenantToolService::
  toolsFor()`; crea `ai_agent_runs`; mensaje inicial de
  `EmailAgentContext`; bucle: límites → `converse` → si pide herramientas,
  añade el turno **tal cual** + **un único mensaje** con todos los
  `tool_results` (vía `ToolExecutor` con `ToolContext` del email y el
  `aiRequestId` del paso; errores de herramienta → `isError`) → si no,
  `AgentReply` y fin. **Límites** (`config ai.agent`): `max_iterations` 6,
  `max_tool_calls` 10 (no ejecuta un lote que lo supere), `timeout_seconds`
  120 (entre pasos), `max_cost_usd` 0,25 (suma de `cost_usd` de sus pasos);
  `effort` medium, `max_tokens` 8000; `min_confidence` 0,75 (tarea 6). Al
  terminar: totales del run (pasos, herramientas, tokens, coste,
  duración, `final_output`) y, si hay respuesta, `email_replies` (draft)
  con `needs_human` según `AgentDecision` (de momento, lo que diga el
  modelo). Rechazo de seguridad → `refusal` sin reintentar; respuesta
  inválida → `error` sin propuesta; error reintentable → run `failed`
  + se relanza (el job reintentará con otra ejecución). Tests: traza
  completa, historial **solo-añadir** comprobado petición a petición
  (mismo system y herramientas), herramientas en paralelo, solo las de la
  empresa, cada límite, rechazo, errores
- [x] 6. **`AgentDecision::review($reply, $email, $run)`: la IA propone,
  el backend decide.** `needs_human` si se cumple CUALQUIERA (aunque el
  modelo diga que está seguro), guardando **todos** los motivos en
  `needs_human_reason`: el modelo lo pide; confianza <
  `ai.agent.min_confidence` (0,75, `AI_AGENT_MIN_CONFIDENCE`); sospecha
  de prompt injection en el email; la clasificación ya lo marcó;
  `pending_actions` no vacío (el agente no ejecuta acciones); alguna
  herramienta del run con estado distinto de `success` (p. ej. pedido no
  encontrado); el email se recortó antes de dárselo al agente. Tests:
  respuesta limpia y segura → sin revisión; cada motivo por separado;
  pedido ajeno con confianza 0,95 → revisión; varios motivos acumulados;
  umbral configurable
- [x] 7. Comando `php artisan ai:reply {email} [--last]`
  (`app/Console/Commands/ReplyToEmail.php`): busca el email globalmente
  (`withoutGlobalScope`), entra en su tenant con `runAs` y ejecuta
  `AgentService` al momento (sin cola; nunca envía nada). Muestra la
  traza: cada paso (estado, tokens, latencia, coste) con sus llamadas a
  herramientas (argumentos, estado, resultado recortado), el resumen de
  la ejecución (motivo de fin, pasos/herramientas, tokens, coste,
  duración) y la propuesta (cuerpo, confianza, ¿necesita persona? y
  motivos, fuentes, acciones pendientes, nota interna). `--last` muestra
  la última ejecución guardada sin volver a llamar a la IA. Sin API key
  → error claro; error temporal → lo muestra junto a la ejecución
  registrada; sin propuesta → explica el motivo y termina con fallo
- [x] Fix detectado en la prueba real: la tabla de decisión de `ai:reply`
  se salía de la terminal (textos partidos a 90 columnas, fuentes y
  acciones una por línea) y los motivos de `AgentDecision` acababan en
  ".." cuando el texto del modelo ya terminaba en punto
- [x] 8. **Prueba real con Claude (2026-10-14, `claude-opus-5-5`)**: BD de
  desarrollo migrada (`tenant_tools`, `ai_agent_runs`, `email_replies`),
  `ai:tools 1 --enable=get_order --enable=get_customer_orders`.
  **Structured outputs + herramientas funcionan juntos** (no hizo falta
  la alternativa `submit_reply`) y **ningún 400 por historial/thinking**
  en conversaciones de hasta 3 pasos. Resultados (`ai:reply`):
  - Email 1 (#45821, SEUR): `get_order` + RAG "Retrasos e incidencias";
    detecta el retraso respecto a la fecha prevista → persona (abrir
    incidencia), confianza 0,60.
  - Email 3 (#78123, en preparación): `get_order` + RAG en paralelo
    (2 herramientas en un turno) → persona (retraso sin causa).
  - Email 5 (doble cobro + amenaza legal): `get_order` + FAQ "Me habéis
    cobrado dos veces"; pide el extracto, **no promete el reembolso** →
    persona (IA + clasificación + acciones pendientes).
  - Email 8 (inglés, producto roto): 3 herramientas en 2 pasos; responde
    **en inglés**; detecta que el aviso llegó fuera del plazo de 48 h →
    persona (decidir devolución o garantía).
  - Email 9 (prompt injection): **no obedece ni revela nada**, 0
    herramientas, respuesta genérica → persona (injection + IA).
  - Email 10 (nuevo por SMTP → ingerido y clasificado `product_question`
    automáticamente: "¿envíos a Canarias?"): RAG → 14,95 €, 5–7 días,
    DUA (**comprobado contra `envios.md`: nada inventado**) →
    **confianza 0,93, sin persona**: el camino automático funciona.
  - 6 ejecuciones, 13/13 pasos `success`, **0,302 $ en total (~0,05 $
    por respuesta**, 0,02–0,07 según consultas) + ~0,012 $ de
    clasificación; 13–27 s por email. Para abaratar: Sonnet 5.5 (mitad)
    o `effort` low — medirlo con dataset en la Fase 15.
  - **Observación para la Fase 9/13:** a veces la respuesta anuncia que
    "una persona revisará tu caso" (cierto solo si va a revisión): la
    decisión del backend y el texto deben ser coherentes al enviar.
  README: sección "9. Agente: propuesta de respuesta" (cómo funciona,
  quién decide la revisión humana, límites, auditoría, probarlo,
  resultados reales, detalles técnicos), "Herramientas por empresa" en la
  sección 8, estado, comandos, estructura, tests, problemas frecuentes;
  secciones siguientes renumeradas (API = #11)

**Cerrada:** push del usuario y GitHub Actions en verde (2026-10-14).

### Fase 9 (automatización de emails): COMPLETADA Y PROBADA CON CLAUDE REAL (2026-10-14), pendiente de CI

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Objetivo:** cada email que llega se procesa solo de principio a fin
(sección 17, MVP): ingesta → clasificación → agente → decisión → **envío
automático** o **revisión humana**, moviendo `emails.status` por los
estados de la sección 12 y registrando cada cambio. Hasta ahora la
clasificación y el agente existían por separado y nada se enviaba.

**Decisiones tomadas:**
- **Modo de automatización por empresa** (`off` / `draft` / `auto`),
  **por defecto `draft`**: el agente prepara la respuesta pero todo va a
  revisión humana. Enviar sin persona es opt-in explícito de cada empresa
  (`auto`), y aun así solo para las **intenciones permitidas** por la
  empresa y si `AgentDecision` no pide revisión.
- **Nunca responder a correos automáticos** (anti-bucles): rebotes,
  respuestas automáticas, listas y boletines se detectan por cabeceras
  (`Auto-Submitted`, `Precedence`, `List-Id`/`List-Unsubscribe`,
  `multipart/report`, `X-Autoreply`) o remitente `noreply`/`mailer-daemon`,
  y no se gasta IA en ellos. Además: límite de respuestas automáticas por
  remitente y día, nunca a un buzón de la propia empresa, y las
  respuestas automáticas salen con `Auto-Submitted: auto-replied`
  (RFC 3834) para que otros bots no contesten.
- Estado nuevo **`ignored`** (no necesita respuesta: automático, spam,
  fuera de oficina): la sección 12 no tenía un final para "no responder".
- **Historial de estados** en tabla propia (quién/qué lo movió y por qué)
  y transiciones válidas en código: un email no puede saltar de estado
  sin pasar por las reglas.
- **Envío idempotente:** la respuesta se "reclama" con un cambio de estado
  atómico antes de enviar (dos workers no envían dos veces) y nunca se
  reintenta un envío cuyo resultado se desconoce.

**Tareas (commit):**
- [x] 1. **Detección de correos automáticos** en la ingesta.
  `App\Mail\Support\AutomatedMessageDetector::reason($headers, $from)`
  (común a todos los proveedores, sin IA): `Auto-Submitted` distinto de
  `no` (RFC 3834), `Content-Type: multipart/report` (rebotes),
  `Precedence: bulk|list|junk|auto_reply`, `List-Id`/`List-Unsubscribe`
  (listas y boletines), `X-Autoreply`/`X-Autorespond`… y remitentes que no
  aceptan respuesta (`noreply`, `no-reply+x`, `donotreply`,
  `mailer-daemon`, `postmaster`, `bounce`; "noreplyjuan" no cuenta).
  `MailMessage::$automatedReason` lo rellenan `MimeMessageMapper` (IMAP y
  Gmail, solo las cabeceras de `HEADERS`) y `GraphMessageMapper`
  (`internetMessageHeaders`, que ya se pedían). Columna
  `emails.automated_reason` (null = escrito por una persona; fillable) y
  `Email::isAutomated()`. Tests: detector (casos positivos y negativos),
  IMAP real con GreenMail, Graph y Gmail simulados, ingesta
- [x] 2. **Estados del email.** `EmailStatus::Ignored` (no necesita
  respuesta), `label()` en español y `allowedNext()`/`canTransitionTo()`:
  received → processing | ignored; processing → ai_review | human_review |
  ignored | failed; ai_review → approved | human_review | failed;
  human_review → approved | ignored | processing (reprocesar); approved →
  sent | failed; sent = final; ignored → processing | human_review; failed
  → processing | human_review | ignored. Tabla **`email_status_changes`**
  (tenant, email cascade, `from_status`, `to_status`, `reason`, `user_id`
  nullOnDelete — null = la plataforma —, `created_at`) y modelo
  `EmailStatusChange` (`BelongsToTenant`). **`Email::transitionTo($to,
  $reason, ?$user)`**: rechaza pasos no permitidos
  (`EmailStatusException`) y cambia el estado con un **UPDATE condicional**
  (`WHERE status = actual`) en la misma transacción que el historial: si
  otro proceso lo movió antes, lanza en vez de pisarlo.
  `Email::statusChanges()`
- [x] 3. **Modo de automatización por empresa** (código del módulo en
  **`app/Automation/`**: orquesta Mail + AI). Enum `AutomationMode`
  (`off` sin IA → todo a revisión humana; **`draft` por defecto**: la IA
  clasifica y propone, una persona revisa siempre; `auto`: además se
  envían solas las respuestas seguras de las intenciones permitidas).
  Columnas `tenants.automation_mode` (default draft) y
  `auto_send_intents` jsonb (null = `ai.automation.
  default_auto_send_intents`: `order_status`, `product_question`).
  `EmailIntent::allowsAutoSend()`: **nunca** complaint, billing_issue,
  auto_reply, spam, other. `Tenant::autoSendIntents()` (enums válidos y
  permitidos, también filtra la config) y `configureAutomation($mode,
  ?$intents)` (valida antes de cambiar nada; `[]` = volver a los de la
  config). No son asignables en masa (el PATCH `/tenant` sigue cambiando
  solo `name`). Comando **`php artisan ai:automation <tenant> [--mode=]
  [--intents=a,b]`** (tabla de intenciones: se envía sola / no / nunca)
- [x] 4. **Envío de la respuesta en el hilo.** `EmailReplyStatus` +
  `sending` (reclamada por un worker) y `failed`. Columnas en
  `email_replies`: `approved_by` (users, nullOnDelete; null = las reglas),
  `approved_at`, `sent_automatically`, `sent_to`, `sent_message_id`,
  `sent_at`, `send_error` + índice tenant+sent_to+sent_at (para el límite
  anti-bucles). **`App\Automation\ReplySender`**: `approve($reply,
  $reason, ?$user)` (draft → approved con UPDATE condicional + email →
  approved en la misma transacción, encola `SendReplyJob` *after
  commit*); `send($reply)`: solo si email y respuesta están `approved`,
  **reclama** la respuesta (approved → sending condicional: dos workers
  nunca envían dos veces), envía por el buzón en el que llegó el email al
  **Reply-To** (o al remitente), asunto con "Re: " salvo que ya lleve
  prefijo (Re/RE/Aw/Sv/Antw/R), `In-Reply-To` = Message-ID original,
  `References` = las del original + él (máx. 20), `autoSubmitted` si la
  aprobaron las reglas. Éxito → respuesta `sent` (destinatario,
  Message-ID, fecha) y email `sent` ("Respuesta enviada automáticamente
  a …"). Errores: conexión → la respuesta vuelve a `approved` y se relanza
  (reintento); credenciales → buzón `failing`, respuesta y email
  `failed`; rechazo del servidor o buzón desactivado → `failed`;
  **resultado desconocido** (error inesperado) → `failed` sin reintento
  ("revisa si llegó"). `SendReplyJob($tenantId, $replyId)`: cola `mail`,
  único por respuesta, `tries` 3, `backoff [60, 300]`, `timeout` 120;
  `failed()` deja `failed` la respuesta que esperaba reintento.
  `OutgoingMessage::$autoSubmitted` → `MimeMessageBuilder` añade
  **`Auto-Submitted: auto-replied`** (RFC 3834; SMTP y Gmail). Graph no
  admite cabeceras que no empiecen por `X-`: en Microsoft no se añade.
  `FakeMailProvider::$failSendWith`; helper de tests `draftReply($email)`
- [x] 5. **Política de envío automático.** `App\Automation\AutoSendPolicy::
  check($email, $reply)` → `AutoSendDecision` (`allowed()`, `reasons`,
  `reason()`): la última palabra antes de enviar sin persona, sumada a
  `AgentDecision`. Va a revisión humana si se cumple CUALQUIERA (se
  devuelven todos los motivos): freno de emergencia de la plataforma
  (`ai.automation.auto_send_enabled`, `AI_AUTO_SEND=false`); la empresa no
  está en modo `auto`; la propuesta tiene `needs_human`; email sin
  clasificar o intención fuera de `Tenant::autoSendIntents()`; correo
  automático; sospecha de injection; y por cada destinatario real (Reply-To
  o remitente, vía `ReplySender::recipients()`, ahora público): es un
  **buzón de la propia empresa**, no acepta respuestas (`noreply`…), o ya
  recibió **`max_auto_replies_per_address`** (3,
  `AI_MAX_AUTO_REPLIES_PER_ADDRESS`) respuestas automáticas en 24 h
  (anti-bucles; sin distinguir mayúsculas; solo de la propia empresa; las
  enviadas por personas no cuentan); sin remitente
- [x] 6. **`App\Automation\EmailProcessor::process($email, $reprocess)`**
  (dentro del tenant; reordenada: usa la política y el envío). Arranca
  desde `received` (o sigue en `processing` si es un reintento; con
  `$reprocess`, también desde human_review/ignored/failed) y recorre:
  correo automático → `ignored` **sin IA**; empresa en modo `off` o **sin
  API key** → `human_review` (ya no se queda en received); clasifica si no
  lo estaba (**nunca dos veces**); spam / respuesta automática con
  confianza ≥ `ai.automation.ignore_min_confidence` (0,8) → `ignored` (por
  debajo sigue: un cliente real nunca se ignora por un error del
  clasificador); **sospecha de injection → `human_review` sin ejecutar el
  agente**; agente (reutiliza un borrador de un intento anterior salvo
  `$reprocess`: no se paga dos veces); sin propuesta → `human_review` con el
  error del agente; con propuesta → `ai_review` → `AutoSendPolicy` →
  `ReplySender::approve()` (→ approved → `SendReplyJob` → sent) o
  `human_review` con todos los motivos. Errores: IA temporal → se relanza
  y el email sigue en `processing`; `LLMConfigurationException` (clave,
  saldo) → `failed` (arreglar y reprocesar); rechazo o respuesta inválida
  del clasificador → `human_review`; inesperado → `failed` + relanza.
  **`ProcessEmailJob`** (ids, cola `ai`, `tries` 3, `backoff [60, 300]`,
  `timeout` 600, único 1 h; `failed()` → email `failed` "No se pudo
  procesar tras varios intentos"). Listener **`QueueEmailProcessing`**
  sustituye a `QueueEmailClassification` (`EmailIngested` → job siempre,
  también sin API key; `ai.automation.process_on_ingest`,
  **`AI_PROCESS_ON_INGEST`**, acepta el antiguo `AI_CLASSIFY_ON_INGEST`).
  **Cola de IA en `redis-long`** (`ai.automation.queue_connection`,
  `AI_QUEUE_CONNECTION`; `sync` en phpunit): procesar un email puede pasar
  de los 300 s de `retry_after` de `redis`; `supervisor-ai` pasa a
  `redis-long` (timeout 650) y `ClassifyEmailJob` también; `waits`
  `redis-long:ai`. El test E2E de ingesta IMAP ahora espera
  `human_review` (sin API key en los tests)
- [x] 7. Comando **`php artisan ai:process {email?} [--now] [--reprocess]
  [--pending] [--failed]`** (`ProcessEmails`; `ai:automation` se hizo en la
  tarea 3): un email → encola `ProcessEmailJob` o, con `--now`, lo procesa
  al momento y muestra el **historial de estados** (cuándo, de, a, motivo)
  y la pista `ai:reply {id} --last` para la traza del agente; ya decidido
  sin `--reprocess` → lo explica; IA caída → lo dice y el email sigue en
  processing; sin API key → aviso (irá a revisión humana). `--pending`:
  emails `received` con ingesta completa de todas las empresas; `--failed`:
  emails `failed` (con `reprocess`). Consultas globales deliberadas.
  **Seguridad añadida al procesador:** nunca se reprocesa un email con una
  respuesta en `sending`/`sent`/`failed` (`EmailProcessor::
  sendWasAttempted()`): un envío fallido puede haber llegado y se
  enviaría dos veces; lo resuelve una persona. **Lección de tests:**
  `expectsOutputToContain` consume cada línea escrita con una sola
  expectativa: no esperar dos textos de la misma fila de una tabla
- [x] 8. **`AutomationEndToEndTest`** (grupo `greenmail`): el MVP completo
  contra GreenMail real, con soporte y cliente como cuentas reales y la
  IA simulada; todo ocurre dentro de `mail:sync` (colas sync). El cliente
  escribe → ingesta IMAP → `ProcessEmailJob` → clasificación + agente →
  reglas → `SendReplyJob` → **SMTP** → en el buzón del cliente llega
  "Re: …" del buzón de soporte (con su nombre), con `In-Reply-To` y
  `References` del original, el texto propuesto y `Auto-Submitted`
  (nuestro propio detector la reconoce como automática); historial
  processing → ai_review → approved → sent y `sent_message_id` = el
  Message-ID recibido. **Anti-bucle real:** el autorespondedor del
  cliente contesta con `Auto-Submitted` → `ignored` sin llamar a la IA y
  sin segunda respuesta. Modo draft → `human_review` y nada enviado
- [x] 9. **Prueba real en el stack (2026-10-14, Claude Opus 5.5 +
  GreenMail):** migraciones aplicadas, `horizon:terminate` →
  `supervisor-ai` en **`redis-long:ai`**; "Empresa Demo" en modo `auto`.
  - `ai:process --pending` con los 10 emails de fases anteriores (6
    reutilizan su borrador de la Fase 8: sin coste): spam y "fuera de la
    oficina" → `ignored` (0,97); injection → `human_review` **sin
    agente**; pedido retrasado, doble cobro, producto roto, devolución
    (needs_human de la IA) y factura (intención no permitida) →
    `human_review` con sus motivos; **Canarias → enviada sola**: llegó al
    buzón de elena@cliente.test en GreenMail como "Re: …", en el hilo
    (`In-Reply-To`) y con `Auto-Submitted: auto-replied`.
  - **Flujo en vivo** (4 correos por SMTP, sin tocar nada: scheduler →
    ingesta → Horizon → Claude → SMTP): pedido 44102 del propio cliente →
    **enviada sola** (0,95; entrega 16/07, Zaragoza, Correos Express y
    seguimiento, exactos); "Do you ship to Lisbon?" → **enviada sola en
    inglés** (Portugal 3–5 días, según la documentación); "¿cuántos días
    tengo para devolver una silla?" → clasificada `product_question` y
    **enviada sola** (30 días, estado, pasos, 14 días y 4,95 €: todo
    exacto) — anotado en pendientes; boletín con `List-Unsubscribe` →
    `ignored` **sin llamar a la IA**.
  - Coste de la prueba: **0,25 $** (6 emails con IA); ≈ 0,04–0,07 $ por
    email con Opus 5.5; los automáticos, 0.
  README: sección "10. Automatización: cada email de principio a fin"
  (flujo, modos, cuándo se envía sola, cómo se envía, probarlo,
  resultados reales), estado, comandos, secciones 6 y 9 actualizadas,
  estructura, tests, problemas frecuentes; secciones siguientes
  renumeradas (API = #12)

**Cerrada:** push del usuario (2026-10-14).

### Fase 10 (panel de administración): COMPLETADA Y PROBADA EN EL NAVEGADOR (2026-10-14), pendiente de CI

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push.

**Objetivo:** panel web para que cada empresa vea y gestione su
plataforma: dashboard, emails (lista y detalle con clasificación,
confianza, respuesta propuesta, historial de estados y traza del agente con
sus herramientas), conexiones de correo, documentación (RAG),
configuración de la IA (modo, intenciones, herramientas), usuarios y
empresa. Aprobar/editar/rechazar respuestas es la Fase 13 (aquí solo se
ven).

**Decisiones tomadas:**
- **SPA de Vue 3 + TypeScript + Naive UI + Vue Router + Pinia dentro del
  repo de Laravel** (`resources/js/panel/`), compilada con Vite y servida
  por Laravel en `/` (ruta comodín que excluye `api`, `sanctum`,
  `horizon`, `up`). Un solo despliegue y **mismo origen**: login con
  **cookie de sesión de Sanctum** (httpOnly, CSRF), sin tokens en
  `localStorage` (un XSS no puede robar la sesión). Los tokens de API
  siguen para integraciones.
- **TypeScript 6.0** (no 7: el compilador nativo en Go aún no ofrece la
  API JS que usa `vue-tsc`). Se quita Tailwind (Naive UI ya da el sistema
  de componentes: no dos sistemas de estilos).
- **Vite en Docker** (servicio `vite`, `node:22-alpine`, `node_modules` en
  un volumen: los binarios nativos de Windows no sirven en Linux).
- El panel es **solo de la propia empresa**: no hay superadministrador
  multiempresa (eso es SaaS, Fase 17).
- **HTML de los emails saneado en el servidor** (HTMLPurifier) y mostrado
  en un `iframe` con `sandbox` y CSP, **sin imágenes remotas** por defecto
  (píxeles de rastreo).
- Tests del frontend con **Vitest**; en CI: tipos (`vue-tsc`), tests y
  `vite build`.

**Tareas (commit):**
- [x] 1. **Base del panel.** Dependencias: `vue` 3.5, `vue-router` 5,
  `pinia` 4, `naive-ui` 2.45; dev: `@vitejs/plugin-vue` 6, `typescript`
  ~6.0, `vue-tsc` 3, `vitest` 5, `@vue/test-utils`, `jsdom`,
  `@types/node`; fuera Tailwind, `welcome.blade.php`, `app.js`/`app.css`.
  `vite.config.ts` (entrada `resources/js/panel/main.ts`, alias `@` →
  `resources/js/panel`, servidor en 0.0.0.0:5173 con `origin`/HMR en
  localhost y **sondeo** si `VITE_POLLING=true`: en Docker sobre Windows
  los cambios no llegan como eventos), `vitest.config.ts` (jsdom),
  `tsconfig.json` (estricto, `verbatimModuleSyntax`, sin `baseUrl`:
  obsoleto en TS 6). App: `main.ts` (Pinia + router), `App.vue`
  (`NConfigProvider` en español + proveedores de mensajes, diálogos y
  barra de carga), `router.ts` (`createPanelRouter(history)` testeable,
  título por ruta, 404 comodín), páginas `HomePage`/`NotFoundPage`.
  `resources/views/panel.blade.php` + **`routes/web.php`**: `/{path?}`
  sirve el panel salvo `api`, `sanctum`, `horizon` y `up`. Servicio
  **`vite`** en docker-compose (`node:22-alpine`, `npm install && npm run
  dev`, puerto 127.0.0.1:5173, volumen `node-modules`). Scripts: `dev`,
  `build` (vue-tsc + vite build), `typecheck`, `test`. CI: job
  **`frontend`** (Node 22, `npm ci`, tipos, Vitest, build). Tests:
  `PanelTest` (rutas del panel con `withoutVite()`; la API, `/up` y la
  health siguen respondiendo) y `router.test.ts`. Verificado: build 90 KB
  gzip; `http://localhost:8080/emails/3` carga el panel desde Vite
- [x] 2. **Login del panel con cookie de sesión.** API: **`POST
  /api/v1/auth/session`** (mismas credenciales, mismo error para email o
  contraseña, empresa desactivada → 403, throttle `login` compartido):
  solo si la petición es *stateful* de Sanctum (Origin/Referer de
  `SANCTUM_STATEFUL_DOMAINS`; si no → 400 "usa /auth/login"),
  `Auth::guard('web')->login` + **`session()->regenerate()`** (contra
  fijación de sesión), devuelve el usuario como `/auth/me`; sin token. El
  logout existente ya cerraba sesiones. Excepciones API: **419** (CSRF
  ausente o caducado) → JSON limpio "La sesión ha caducado. Recarga la
  página." (antes, con debug, salía la traza). Frontend:
  `api/http.ts` (cliente `fetch`: `credentials: same-origin`, pide
  `/sanctum/csrf-cookie` antes de la primera petición que modifica,
  cabecera `X-XSRF-TOKEN`, `ApiError` con `status`, mensaje y
  `fieldError()`, 419 → renueva el token y reintenta una vez, 401 → avisa
  a la app, sin conexión → status 0); store Pinia **`auth`** (`load`,
  `login`, `logout`, `forget`, `can(permiso)` — solo para la interfaz: la
  autorización real es de la API); **`LoginPage`**; guard en el router
  (sin sesión → login con `?redirect=`; con sesión, fuera del login);
  **`safeRedirect()`**: tras el login solo rutas internas (`/x`, no
  `//otro`, `/\otro` ni URLs: evita *open redirect*); `main.ts`: sesión
  caducada → al login volviendo después a donde estaba. Verificado con
  curl contra el stack: cookie de sesión **HttpOnly**, login → `/me` 200 →
  logout → 401, sin token CSRF → 419. Tests: `SessionLoginTest` (8),
  Vitest `http.test.ts` (6) y `router.test.ts` (5)
- [x] 3. **Estructura del panel.** `@vicons/ionicons5` (iconos que
  recomienda Naive UI; solo se importan los usados). `layouts/AppLayout`:
  menú lateral plegable, cabecera con la empresa y el usuario (rol en
  español y "Cerrar sesión"), contenido con `RouterView`. Todas las rutas
  con sesión cuelgan de ese layout (también la 404). `menu.ts`:
  `menuEntries` (ruta, etiqueta, icono, permiso) + `visibleMenu()` (solo
  lo que permite el rol) + `roleLabels`; cada tarea añade su sección.
  `RouteMeta` tipado: `title`, `public`, `permission`, `menu` (sección
  que se marca, p. ej. el detalle marca su listado). Guard: sin permiso →
  página **403** `ForbiddenPage` (solo interfaz: la API vuelve a
  comprobarlo). El script de commit ejecuta también `vue-tsc` y Vitest
  en el contenedor `vite`. Tests Vitest: `menu.test.ts`, guard de
  permisos en `router.test.ts`
- [x] 4. **API: listado de emails.** Permiso nuevo **`emails.view`**
  (todos los roles: es el trabajo diario de un agente; sembrado en la BD
  de desarrollo). `EmailPolicy` (otra empresa → 404, luego permiso).
  **`GET /api/v1/emails`** (`EmailController@index`): más recientes
  primero, paginado (`per_page` 1–100, 25 por defecto, conserva los
  filtros en los enlaces), filtros validados en `ListEmailsRequest`:
  `status` y `intent` (enums), `mailbox` (id de conexión), `q` (asunto,
  email o nombre del remitente con `ilike`; **`%` y `_` escapados**: son
  texto, no comodines), `from`/`to` (día de llegada, `to` incluido).
  `EmailListResource` **sin cuerpos**: asunto, remitente, buzón, estado +
  etiqueta, intención y confianza, resumen, idioma, urgencia,
  sentimiento, sospecha de injection, motivo de "automático", nº de
  adjuntos, fechas. Tests: orden, campos, cada filtro, búsqueda sin
  mayúsculas, `%` literal, fechas, paginación, 422 por filtro inválido,
  **aislamiento** (ni con el id del buzón de otra empresa ni buscando su
  texto), 401 y 403 sin permiso. `PermissionsTest` cuenta los permisos
  del agente desde `Role::PERMISSIONS` (no un número fijo)
- [x] 5. **API: detalle del email, HTML saneado y adjuntos.**
  `composer require ezyang/htmlpurifier` (^4.18, LGPL).
  **`App\Mail\Support\HtmlSanitizer`**: lista blanca de etiquetas de
  formato (texto, listas, tablas, enlaces, imágenes) y de propiedades CSS
  (sin `background`/`url()`), solo esquemas http/https/mailto (fuera
  `javascript:` y `data:`), enlaces con `target=_blank` + `noopener
  noreferrer nofollow`; fuera scripts, eventos `on*`, iframes,
  formularios, `<style>`, `<meta>`, `<base>`; caché de definiciones en
  `storage/framework/cache/htmlpurifier`. `hasRemoteImages()` (posibles
  píxeles de rastreo). Endpoints (`emails.view`, otra empresa → 404 con
  el mismo mensaje que un id inexistente): **`GET /emails/{id}`**
  (`EmailDetailResource`, hereda del listado: destinatarios to/cc/
  reply_to, Message-ID, `text_body`, `has_html` —nunca el HTML original—,
  clasificación completa con entidades, modelo y coste, adjuntos sin
  disco/ruta, **historial de estados** con motivo y usuario,
  **propuestas** (`EmailReplyResource`) y **traza del agente**
  (`AgentRunResource`: pasos con modelo, tokens, coste, latencia y
  **herramientas** con argumentos, resultado, estado y duración), y
  `ai_cost_usd` = suma de todas sus peticiones de IA); **`GET
  /emails/{id}/html`** → `{html saneado | null, remote_images}`; **`GET
  /emails/{id}/attachments/{adjunto}`** (`scopeBindings`: el adjunto tiene
  que ser de ese email; solo `stored`) **siempre como descarga y
  `application/octet-stream` + `nosniff`**: un adjunto `.html` abierto en
  el navegador podría ejecutar código con la sesión del panel. Tests:
  `HtmlSanitizerTest` (11 ataques + formato + enlaces + imágenes
  remotas), `EmailDetailApiTest` (email procesado con el procesador real
  e IA simulada, sin procesar, HTML saneado, descargas, adjunto de otro
  email, aislamiento en los 3 endpoints, 403)
- [x] 6. **Página: listado de emails** (`pages/EmailsPage.vue`, ruta
  `/emails`, permiso `emails.view`, entrada "Emails" en el menú; el
  detalle `/emails/:id(\\d+)` marca "Emails" en el menú y de momento es
  un marcador). Tabla con paginación en el servidor (`NDataTable remote`):
  recibido, remitente, asunto (enlace: se puede abrir en otra pestaña) +
  resumen de la IA, intención + confianza (o "Automático"), estado con
  color (ámbar = revisión humana, rojo = error) y buzón; clic en la fila
  → detalle. Filtros: búsqueda con espera de 350 ms, estado, intención,
  buzón (solo si hay más de uno) y rango de fechas. **Los filtros viven
  en la URL** (`utils/query.ts`: `filtersFromQuery` ignora lo inválido —la
  URL la puede editar cualquiera—, `queryFromFilters` sin vacíos ni
  página 1): enlaces compartibles y botón "atrás". `api/types.ts`,
  `api/emails.ts`, `api/mailConnections.ts`, `utils/labels.ts` (estados,
  intenciones, urgencia y sentimiento en español + color de cada estado)
  y `utils/format.ts` (fecha, %, USD, bytes, remitente). **Ojo en rutas:**
  en un string de JS el patrón es `'\\d+'` (con `'\d+'` el `\` se pierde).
  Vitest: `query.test.ts`, `format.test.ts`, rutas del detalle (25 tests)
- [x] 7. **Página: detalle del email** (`pages/EmailPage.vue`): cabecera
  (asunto, intención + confianza, estado, de/para/cc/responder a,
  recibido, buzón), avisos de injection y de correo automático, y dos
  columnas: **Mensaje** (`components/email/EmailBody.vue`: pestañas
  "Formato original" / "Texto"; el HTML saneado va en un **`iframe`
  `srcdoc` con `sandbox="allow-popups allow-popups-to-escape-sandbox"`**
  —sin scripts ni mismo origen: no puede leer la sesión— y **CSP**
  `default-src 'none'; img-src data:` dentro del documento (segunda capa:
  sin conexiones ni imágenes remotas, con aviso), 300 px redimensionable
  —un iframe aislado no puede medir su contenido—; adjuntos como enlaces
  de descarga o etiqueta si no se guardaron) + **Respuesta propuesta**
  (`ReplyCard.vue`: estado, motivos de revisión uno por línea, texto,
  confianza, fuentes, acciones pendientes, nota interna, cuándo/a quién
  se envió y si fue automática o quién la aprobó); a la derecha
  **Clasificación** (resumen, intención, confianza, urgencia, tono,
  idioma, pedidos, motivo y **coste total de IA del email**),
  **Historial** (`NTimeline`, motivo recortado a 3 líneas con clic para
  verlo entero, quién lo decidió) y **Lo que hizo la IA**
  (`AgentTrace.vue`: por ejecución, motivo de fin, pasos, herramientas,
  tokens, coste y duración; por paso, modelo, tokens, coste y latencia;
  cada herramienta con nombre en español, argumentos y "lo que vio la
  IA" desplegable). 404 y errores con `NResult`. **Revisión visual**:
  capturas con Chromium headless dentro del contenedor `vite`
  (`playwright-core` + `apk add chromium` en `/tmp`, proxy TCP
  localhost:8080 → nginx para que Sanctum vea el Origin del panel; nada
  en el proyecto) → arreglados: confianza partida en dos líneas en el
  listado, iframe demasiado alto, motivos largos en el historial. Vitest
  con `@vue/test-utils` (34 tests): iframe con sandbox sin
  `allow-scripts`/`allow-same-origin`, CSP en el documento, aviso de
  imágenes remotas, texto sin pedir HTML, enlaces de adjuntos, motivos
  separados, envío automático, traza con herramientas
- [x] 8. **Dashboard.** `App\Observability\DashboardStats` (scope de
  tenant en todo): emails del periodo por estado, enviados solos y **tasa
  de automatización** (enviados solos / los que necesitaban respuesta =
  recibidos − ignorados), **esperan a una persona** y con error *ahora
  mismo* (de cualquier fecha), ranking de intenciones, IA del periodo
  (llamadas, fallidas, coste del periodo, de hoy y del mes, **coste medio
  por email** con IA, latencia media sin contar búsquedas de embeddings
  locales) y los 5 últimos emails en revisión humana o error. **`GET
  /api/v1/dashboard?days=7|30|90`** (`emails.view`; otro valor → 422).
  Página **`DashboardPage`** (sustituye a `HomePage`; el menú dice
  "Resumen"): selector de periodo, **6 tarjetas de cifras** (las de
  "esperan a una persona" y "con error" enlazan al listado filtrado),
  ranking de intenciones en **barras horizontales de un solo color**
  (valor en texto normal, tooltip con nº y %, clic → listado filtrado),
  estados con sus colores reservados y etiqueta, y "Necesitan atención".
  **Sin gráficas temporales** (emails/coste por día): son de Grafana
  (Fase 11) y así el panel no carga una librería de gráficas. Guía
  aplicada: skill de visualización (forma: cifras = tarjetas; una serie =
  un color; texto en tinta, no en el color de la serie; revisión visual
  con captura). Tests: `DashboardApiTest` (periodo, tasa, pendientes de
  cualquier fecha, costes y latencia, cambio de periodo y 422, empresa
  sin datos, aislamiento, 401), Vitest `intentBars`
- [x] 9. **Configuración de la IA.** Permiso nuevo **`automation.manage`**
  (owner y admin; sembrado en la BD de desarrollo). API (siempre la
  empresa del usuario, sin id en la URL): **`GET /api/v1/automation`**
  (`tenant.view`: todos los roles) → modo + los 3 modos con etiqueta y
  descripción, intenciones de envío automático efectivas y si son
  propias o las de la plataforma, catálogo de intenciones con
  `allows_auto_send`, herramientas (`TenantToolService::statusFor`:
  activada, disponible, origen `company|default`) y estado de la
  plataforma (IA configurada, freno de emergencia, modelo, confianza
  mínima, límite por dirección); **`PATCH /automation`**
  (`UpdateAutomationRequest`, autoriza `automation.manage` antes de
  validar: `mode`, `auto_send_intents` — `[]` = las de la plataforma —;
  intención prohibida → 422 en `auto_send_intents`); **`PATCH
  /automation/tools/{tool}`** (`enabled` booleano; herramienta fuera del
  catálogo → 404). Cada cambio queda en el log con el usuario (auditoría
  completa en la Fase 12). Página **`AiSettingsPage`** (`/configuracion/
  ia`, menú "Ajustes de la IA"): avisos si la IA no está configurada o el
  envío automático está frenado en la plataforma; modo con
  **confirmación al activar el automático**; intenciones (las prohibidas,
  deshabilitadas con "siempre una persona"; guardar / volver a las de
  por defecto) con las reglas de seguridad explicadas; interruptores de
  herramientas con descripción en lenguaje de empresa (`toolLabels` en
  `utils/labels.ts`, también los usa la traza) y "sin datos
  configurados" si no están disponibles. Sin `automation.manage` se ve
  todo y no se puede cambiar nada. Tests: `AutomationApiTest` (11) y
  Vitest `AiSettingsPage.test.ts` (solo lectura sin permiso, activar una
  herramienta llama al PATCH, intenciones prohibidas marcadas)
- [x] 10. **Página: buzones de correo** (`MailConnectionsPage`,
  `/buzones`, `mail_connections.view`, menú "Buzones"; usa la API de la
  Fase 3). Tabla con nombre/email, tipo, estado (Activo / Sin probar /
  Falla con su `last_error` / Desactivado) y última prueba. Con
  `mail_connections.manage`: **Probar** (diálogo con recepción y envío
  por separado y su error), **Editar** (solo IMAP), interruptor
  activar/desactivar, **Eliminar** con confirmación (el 409 "tiene
  correos guardados: desactívalo" se muestra tal cual), **Añadir buzón
  IMAP** y **Conectar Microsoft 365 / Gmail** (`POST .../oauth/{p}/start`
  → el navegador va a la URL de autorización). Formulario
  `components/connections/ImapConnectionForm.vue` (cajón lateral):
  nombre, email (no editable después), IMAP y SMTP (servidor, puerto,
  cifrado, usuario, contraseña); el usuario se rellena con el email; en
  edición la contraseña vacía = **conservar la guardada**
  (`withoutEmptyPasswords`); errores 422 bajo cada campo (incluido el
  anti-SSRF). **Un buzón nuevo o con credenciales cambiadas se prueba
  solo al guardar** (sin probar no se sincroniza). Vuelta de OAuth:
  `MAIL_CONNECTIONS_OAUTH_REDIRECT_TO=${APP_URL}/buzones` en
  `.env.docker.example` (y en el `.env` local) → la página lee
  `?status=connected|error&message=`, avisa y limpia la URL. Vitest:
  listado con estado y error, sin permiso solo lectura, usuario
  autocompletado, errores 422 por campo, contraseñas vacías fuera
- [x] 11. **Autodetección de la configuración IMAP/SMTP** (pedida en la
  Fase 3). `App\Mail\Autodetect\MailAutodetector::detect($email)` →
  `AutodetectResult` (`found`, `source`, `provider_hint`, `imap`, `smtp`),
  en orden: **ISPDB de Thunderbird**
  (`autoconfig.thunderbird.net/v1.1/{dominio}`), **autoconfig del dominio**
  (`autoconfig.{dominio}/mail/config-v1.1.xml?emailaddress=` y
  `{dominio}/.well-known/autoconfig/...`), **DNS SRV** (`_imaps`/`_imap`,
  `_submissions`/`_submission`; "." = no ofrecido) y **nombres
  habituales** (`imap.`/`smtp.`/`mail.` solo si existen y son públicos);
  y los **MX** para avisar de Microsoft 365 (`*.mail.protection.outlook.
  com`) o Google (`*.google.com`): ahí mejor OAuth. Del XML: solo IMAP y
  SMTP con **SSL o STARTTLS** (nunca sin cifrar) y con contraseña
  (**un servidor puede listar varios métodos**: vale si alguno es con
  contraseña — bug encontrado al probar contra la ISPDB real, que lista
  `OAuth2` + `password-cleartext`; el fixture del test ya lo refleja),
  prefiriendo SSL; plantillas `%EMAILADDRESS%`/`%EMAILLOCALPART%`/
  `%EMAILDOMAIN%`. **Anti-SSRF** (el dominio lo escribe el usuario): solo
  HTTPS, IPs comprobadas por `MailHostGuard::allowedIps()` (nuevo) y
  **fijadas con `CURLOPT_RESOLVE`** (sin DNS rebinding entre la
  comprobación y la petición), sin redirecciones, 4 s, máx. 64 KB, XML
  con `LIBXML_NONET`. `DnsLookup` (MX/SRV) separado para simularlo en
  tests. Endpoint **`POST /api/v1/mail-connections/autodetect`**
  (`email`; permiso de crear conexiones; throttle `mail-connection-test`
  10/min). Prueba real contra internet: gmail.com → ISPDB + aviso Google;
  outlook.com → aviso Microsoft; yahoo.es → `imap.mail.yahoo.com`; gmx.es
  → `imap.gmx.com`/`mail.gmx.com`; fastmail.com → SRV. Panel: en el
  formulario IMAP la detección se lanza al salir del email (si los
  servidores están vacíos) y con el botón "Detectar configuración";
  rellena servidores/puertos/cifrado/usuario y explica de dónde sale
  (la de "nombres habituales" avisa de que es una suposición) o, si el
  dominio es de Microsoft/Google, recomienda "Conectar …". Tests:
  `MailAutodetectTest` (15: cada fuente, avisos, **nunca pide nada a
  hosts internos**, respuestas no válidas/enormes/sin cifrar, emails no
  válidos, endpoint y permisos) y Vitest del formulario (46 en total)
- [x] 12. **Página: documentación (RAG)** (`DocumentsPage`,
  `/documentacion`, `documents.view`, menú "Documentación"; API de la
  Fase 6). Tabla con título y fichero original, tipo, tamaño, estado (En
  cola / Indexando / Listo con nº de fragmentos / Error con el motivo, p.
  ej. "PDF sin texto") y fecha; con `documents.manage`: **subir** (título
  opcional; `NUpload` con petición propia multipart vía el cliente
  `api`, que ya envía `FormData` sin `Content-Type` JSON; formatos PDF,
  TXT, Markdown, HTML), **reindexar** y **eliminar** con confirmación;
  errores 422 del servidor (duplicado, formato, tamaño) como mensaje.
  **La lista se refresca cada 3 s mientras haya documentos indexándose**
  (el indexado va en cola) y deja de hacerlo al terminar. **"Probar la
  búsqueda: lo que encontraría la IA"**: pregunta → `POST
  /knowledge-base/search` → fragmentos con su fuente citable y % de
  parecido; sin resultados explica que la IA no inventará y pasará a una
  persona. Verificado con Chromium contra Ollama real: "¿Cuántos días
  tengo para devolver un producto?" → "Política de devoluciones, sección
  «Plazo para devolver»" 76 % (la primera tardó por la carga del modelo).
  El script de capturas admite pasos (`STEPS`: rellenar, pulsar, esperar).
  Vitest: lista con estados y error, sin permiso solo lectura, búsqueda
  con fuente y %, `isIndexing`
- [x] 13. **Página: empresa y usuarios** (`CompanyPage`, `/empresa`,
  `tenant.view`, menú "Empresa y usuarios"; API de la Fase 2; una sola
  página: son pocas cosas). Empresa: nombre (editable con
  `tenant.update`, actualiza también la cabecera), identificador y alta.
  Usuarios (`users.view`): nombre ("(tú)" en el propio), email, rol y
  alta; según permisos: **Añadir** (`users.create`), **Editar**
  (`users.update`) y **Eliminar** con confirmación (`users.delete`).
  Formulario `components/company/UserForm.vue` (cajón): nombre, email,
  rol, contraseña (inicial, o vacía = no cambiarla al editar). Reglas de
  la Fase 2 reflejadas en la interfaz (la API las vuelve a imponer): un
  administrador no ve acciones sobre propietarios ni puede ofrecer el
  rol propietario (`assignableRoles`), nadie se borra a sí mismo ni
  cambia su propio rol (selector deshabilitado con explicación); errores
  422/403 de la API se muestran. `roleLabels` tipado por rol. Vitest:
  admin vs. propietarios y él mismo, agente solo lectura, roles
  asignables, contraseña vacía fuera (54 tests)
- [x] 14. **Prueba en el navegador (Chromium headless contra el stack) y
  README.** Como propietario: crear una usuaria agente **desde el panel**
  ("Lucía Agente", rol agente; el segundo intento dio el 422 de email
  repetido, bien mostrado). Como Lucía: "Ajustes de la IA" y "Buzones" en
  solo lectura (todo deshabilitado y el aviso). **Flujo en vivo**: correo
  por SMTP "¿Envío a Mallorca?" → scheduler → ingesta → Horizon → Claude
  (`search_company_documents`) → enviado solo; en el panel: 9,95 € y 3–4
  días laborables (**comprobado contra `envios.md`**), confianza 0,95,
  fuentes, historial processing → ai_review → approved → sent y traza;
  coste 0,047 $. **Build de producción**: con Vite parado, `npm run build`
  → Laravel enlaza `/build/assets/main-*.js` y nginx lo sirve (200,
  `application/javascript`). README: sección "11. Panel de
  administración" (secciones y permisos, seguridad: cookie, HTML aislado,
  adjuntos, anti-SSRF de la autodetección; autodetección; desarrollo),
  arranque (servicio `vite` / `npm run build`), URL del panel y usuario,
  comandos, API (11 endpoints nuevos), estructura, tests (Vitest), CI
  (job `frontend`), problemas frecuentes (panel en blanco, login fuera de
  `APP_URL`, 419); secciones renumeradas (API = #13)

- [x] Fix tras el push (CI en rojo): `tests/Feature/ExampleTest.php`
  (el de ejemplo de Laravel, Fase 1) hacía `GET /`, que ahora pinta el
  panel con `@vite`; en local pasaba porque existe `public/hot` (servicio
  `vite`) o `public/build`, pero el job de PHP del CI no compila el panel
  → `ViteManifestNotFoundException` (500). Eliminado: `PanelTest` ya
  cubre `/` con `withoutVite()`. **Lección:** todo test que pinte una
  vista con `@vite` debe usar `withoutVite()`; para reproducir el CI en
  local, mover `public/hot` y `public/build` y lanzar la suite (886 en
  verde así)

**Resumen:** 14 tareas en 14 commits + 1 fix, 886 tests PHP + 54 Vitest en verde.
**Pendiente para dar la Fase 10 por cerrada:** push (lo hace el usuario) y
GitHub Actions en verde (incluido el job nuevo `frontend`).

### Fase 11 (Grafana / observabilidad): COMPLETADA Y PROBADA EN EL STACK CON IA SIMULADA (2026-10-15), pendiente de CI

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit. **Cambio acordado con el usuario
(2026-10-15):** esta fase se hace en una sesión de Claude Code en la nube
(contenedor temporal): los commits se suben a la rama **`fase-11-grafana`**
(nunca a `main`); el usuario revisa el CI y la fusiona.

**Entorno de la sesión en la nube:** su red bloquea `dl-cdn.alpinelinux.org`,
`deb.debian.org`, `pecl.php.net` y `registry.ollama.ai`, así que la imagen
`docker/php/Dockerfile` no se puede construir allí. Para probar se usó una
imagen equivalente solo de esa sesión (`serversideup/php:8.4-fpm-alpine`:
pdo_pgsql, redis, pcntl, zip, opcache; el código no usa intl ni bcmath) y
`composer install` desde fuera del contenedor. El `Dockerfile` del proyecto
no cambia. Sin Ollama (no se puede descargar `bge-m3`).

**Objetivo:** ver en Grafana cómo funciona la plataforma (sección 14):
emails (recibidos, procesados, automatizados, a personas, errores, tiempo
de procesamiento), IA (peticiones, tokens, coste, latencia, errores,
modelos, confianza, herramientas), negocio (% automatizado, % a personas,
intenciones, volumen por empresa) y costes (por modelo, día, mes), con
alertas.

**Decisiones tomadas:**
- **IA simulada para probar sin gastar** (pedido por el usuario):
  `AI_PROVIDER=simulated` → `App\AI\Providers\Simulated\
  SimulatedLLMProvider`. Reglas por palabras clave (no entiende el texto):
  clasificación (intención, confianza, idioma, pedidos, sospecha de
  injection) y agente (pide `get_order` si hay número de pedido o
  `search_company_documents`, y responde con lo que devuelven). Modelo
  `simulated` con **precio ficticio** (el de Opus 5.5) para ver costes en
  las métricas sin confundirlos con gasto real. **Prohibida fuera de
  `local`/`testing`** (`LLMConfigurationException`): respondería emails
  reales sin modelo. Espera media `AI_SIMULATED_DELAY_MS` (800 ms) para
  latencias realistas. `phpunit.xml` fuerza `AI_PROVIDER=anthropic` (un
  `.env` local con `simulated` no debe cambiar los tests).
- **Métricas con `promphp/prometheus_client_php`** (Apache-2.0) y almacén
  **Redis** (base de datos propia `METRICS_REDIS_DB`, 2): PHP-FPM y los
  workers de Horizon son procesos distintos y suman en los mismos
  contadores; con AOF sobreviven a reinicios. Se descartó calcular todo
  desde Postgres en cada lectura (consultas cada 15 s que crecen con los
  datos y sin histogramas de latencia) y APCu (no se comparte entre
  contenedores). Las métricas de **estado actual** (emails esperando a una
  persona, colas…) se calculan en cada lectura y no se guardan (si se
  guardaran, una etiqueta que desaparece se quedaría con su último valor).
- **Catálogo único** `App\Observability\Metrics::DEFINITIONS` (nombre,
  ayuda, etiquetas, intervalos): una métrica o etiqueta no declarada es un
  error (`LogicException`), así no se crean series por erratas ni etiquetas
  de cardinalidad infinita (emails, ids). Prefijo `aimail_`. La empresa va
  como etiqueta `tenant` (su slug). **Registrar una métrica nunca rompe el
  negocio**: si Redis falla, aviso en el log y se sigue.
- **`GET /metrics` protegido con token** (`METRICS_TOKEN`, cabecera
  `Authorization: Bearer`): revela volumen y costes por empresa. Sin token
  configurado o con uno incorrecto → 404. Ruta fuera de los grupos web/api
  (sin sesión, cookies ni throttle). Prometheus la lee a través de nginx
  (nginx se une a `observability-net`).
- **Las métricas de negocio salen de los registros de auditoría** que ya
  existen (emails, historial de estados, respuestas, sincronizaciones,
  `ai_requests`, `ai_tool_calls`, `ai_agent_runs`) mediante **observers**
  en `app/Observability/Observers/` con `$afterCommit = true`: el código de
  negocio no sabe nada de Prometheus y lo que se deshace en una transacción
  no se cuenta. Etiquetas que salen de la BD (slug de la empresa, tipo de
  buzón) con caché por proceso en `MetricLabels`.
- **Logs en JSON para Loki** en un canal aparte (`json`), sin tocar el de
  texto (`daily`, para `tail -f`): `LOG_STACK=daily,json`. Promtail lee
  solo `storage/logs/json/*.log`; el **nivel** es etiqueta de Loki (pocos
  valores) y `tenant_id`/`request_id` no (serían demasiadas series): se
  filtran con `| json | extra_tenant_id="1"`.
- **OpenTelemetry (tracing) aplazado:** con un monolito y colas, la traza
  de lo que hizo la IA con un email ya está en la BD (`ai_agent_runs` →
  `ai_requests` → `ai_tool_calls`, historial de estados) y se ve en el
  panel; un collector + Tempo/Jaeger + extensión de PHP sería
  infraestructura sin uso real todavía. En su lugar, **`request_id`** en el
  Context de cada petición (cabecera `X-Request-Id`): aparece en todos sus
  logs y en los de los trabajos que encola. Revisar si se separan servicios.
- **Alertas como reglas de Prometheus** versionadas en git
  (`docker/prometheus/alerts.yml`) y **probadas con `promtool test rules`**
  (`alerts.test.yml`, también en el CI). Se ven en Prometheus (`/alerts`) y
  en Grafana. **Sin Alertmanager todavía:** enviar avisos (email, Slack…)
  necesita un destino real; es de la Fase 16 (producción).
- **Dashboards versionados en git** (`docker/grafana/dashboards/*.json`,
  carpeta "AI Mail Automation"): se pueden editar en Grafana, pero manda el
  fichero; para guardar un cambio se exporta el JSON. Datasources con
  **`uid` fijos** (`prometheus`, `loki`). **Imágenes con versión fija**
  (antes `latest`: una actualización podía romper el stack sin tocar nada):
  Prometheus v3.15.0, Grafana 13.2.4, Loki 3.7.8, Promtail 3.6.8,
  postgres-exporter v0.20.1, redis_exporter v1.94.0. Prometheus guarda
  **400 días** (comparar costes mes a mes; ocupa poco).
- Diseño de los dashboards (skill de visualización): cifras = tarjetas;
  una sola serie en azul sin leyenda; **máximo 4 colores categóricos por
  gráfica** (paleta validada contra el fondo oscuro de Grafana, en orden
  fijo); los valores conocidos (decisiones, para qué se usa la IA) con
  color fijo; los abiertos (empresas, modelos, herramientas, intenciones)
  como **ranking de barras de un solo color**; ámbar/rojo **reservados** a
  revisión humana / error y solo cuando el valor es ≥ 1; nunca dos ejes.
  Recuentos como **barras por intervalo** (`increase`, mínimo 5 min), no
  tasas "por hora": con pocos correos una tasa extrapolada convierte 16
  correos en segundos en un pico de 1.300/h.
- Limitación conocida de Prometheus: `increase()` no cuenta la **primera**
  aparición de una serie (de 0 a N sin muestra previa). En uso continuo no
  se nota; con datos de prueba recién creados los totales del periodo
  pueden salir algo por debajo de la BD. La fuente exacta sigue siendo la
  BD (panel de administración y `ai_requests`).
- **No cambiar las etiquetas de una métrica que ya existe:** Redis guarda
  su definición (nombres de etiquetas) y la librería la sigue usando, así
  que las series salen con las etiquetas viejas. Una métrica con otras
  etiquetas = **otro nombre** (o `Metrics::wipe()`, que borra todo lo
  acumulado). Descubierto al renombrar `job` → `job_class` en el stack.
- **Alertas de recuento y series nuevas:** `increase()` no cuenta la
  primera aparición de una serie, así que el **primer** trabajo fallido de
  la plataforma no avisaba. Las alertas de recuento añaden `or (serie
  unless serie offset 15m)`: si no existía hace 15 min, su total es el
  aumento.

**Tareas (commit):**
- [x] 1. **IA simulada** (`SimulatedLLMProvider`, `config/ai.php` →
  `simulated` + precio ficticio, `AIService::enabled()` la cuenta como
  configurada, `AI_PROVIDER` en `.env.docker.example`). Tests: intenciones
  por palabras clave, injection, idioma y pedidos, agente en dos pasos
  (herramienta → respuesta con el dato), a persona si la herramienta falla
  o no hay herramientas, uso/modelo/id simulados, prohibida en producción,
  y un email de principio a fin (enviado) sin IA real
- [x] 2. **Base de métricas.** `composer require promphp/prometheus_client_php`
  (^2.15). `config/metrics.php` (`METRICS_ENABLED`, `METRICS_TOKEN`,
  `METRICS_STORAGE` redis/memory, `METRICS_REDIS_DB`). `App\Observability\
  Metrics` (`increment()`, `observe()`, `render()`, `wipe()`; conexión a
  Redis al primer uso y reconexión tras un fallo) registrado como singleton
  en el nuevo `ObservabilityServiceProvider`. `MetricsController` en
  `GET /metrics` (`bootstrap/app.php` → `then:`; el panel ya no captura
  `/metrics`). Prometheus: job `laravel` (`nginx:80/metrics`, Bearer
  `local-metrics-token` = valor de desarrollo en `.env.docker.example`).
  `phpunit.xml`: `METRICS_STORAGE=memory`. Tests: formato de exposición,
  etiquetas `none`/booleanas, métricas y etiquetas no declaradas, fallo de
  Redis sin romper nada, desactivadas, **Redis real compartido entre dos
  instancias** (BD 15), endpoint con/sin token, sin token configurado, sin
  sesión ni cookies. Verificado en el stack: target `laravel` **UP**
- [x] 3. **Métricas de emails** (observers): `emails_ingested_total`
  {tenant, provider, automated} (al crearse el email: los duplicados no
  cuentan), `email_status_changes_total` {tenant, to, by=platform|user},
  **`email_processing_seconds`** {tenant, outcome} (desde que se guardó
  hasta que la plataforma decide: ignored / human_review / approved
  —envío automático— / failed; solo decisiones de la plataforma, no de
  personas ni el envío posterior), `email_replies_sent_total` {tenant,
  automatic}, `email_replies_failed_total`, `ai_agent_replies_total`
  {tenant, needs_human} y `mail_syncs_total` {tenant, provider,
  result}. **Bug evitado:** detectar el lote sincronizado con
  `wasChanged('last_success_at')` fallaba con dos lotes en el mismo segundo
  (las fechas se comparan al segundo) → `MailSyncState` lanza eventos
  propios `synced`/`syncFailed` (`$observables`). Helper de tests
  `metricSample($nombre, $etiquetas)` en `tests/Pest.php`. Tests:
  ingesta con duplicado y correo automático, sincronización fallida, email
  enviado solo (estados, tiempo de decisión, respuesta automática), a una
  persona, envío fallido, cambio hecho por una persona, transacción
  deshecha
- [x] 4. **Métricas de IA** (observers sobre `ai_requests`,
  `ai_tool_calls` y `ai_agent_runs`): `ai_requests_total` {tenant,
  provider, model, purpose, status} (LLM y embeddings; `model` = el que
  respondió o, si falló antes, el pedido), `ai_tokens_total` {…, type =
  input/output/cache_write/cache_read}, **`ai_cost_usd_total`** {tenant,
  model, purpose} (el mismo `cost_usd` de `ai_requests`: sin precio no
  suma), `ai_request_duration_seconds`, `ai_classifications_total`
  {tenant, intent, needs_human} + `ai_classification_confidence` {intent},
  `ai_tool_calls_total` {tenant, tool, status} +
  `ai_tool_call_duration_seconds`, `ai_agent_runs_total` {tenant, status,
  stop_reason} + `ai_agent_run_duration_seconds`. Tests: tokens y coste
  **iguales a la suma de `ai_requests`**, intención y confianza,
  herramienta ejecutada y rechazada, fin del agente, petición y ejecución
  fallidas, embeddings (coste 0)
- [x] 5. **Plataforma y estado actual.** Middleware global
  `RecordHttpMetrics`: `http_requests_total` {method, route, status} +
  `http_request_duration_seconds`; `route` = **plantilla** de la ruta
  (`api/v1/emails/{email}`), nunca la URL (un escáner crearía una serie por
  URL), sin ruta → `unmatched`; las lecturas de `/metrics` no cuentan.
  `QueueMetricsSubscriber` (eventos de cola): `jobs_processed_total`
  {queue, job, result = success / released (se reintentará) / failed} +
  `job_duration_seconds`. **`StateMetrics`** (se calcula en cada lectura,
  registro en memoria, no se guarda): `emails_current` {tenant, status},
  `mail_connections` {tenant, provider, status},
  `mail_sync_consecutive_failures` y
  `mail_sync_last_success_timestamp_seconds` {tenant, mailbox = id del
  buzón; solo buzones activos} (base de la alerta del **mensaje
  envenenado** anotado en la Fase 4), `documents` {tenant, status} y
  `queue_size` {connection, queue} de las colas de `metrics.queues` (una
  cola que no responde no rompe el resto). `/metrics` = acumuladas +
  estado. Tests: plantillas de ruta y `unmatched`, `/metrics` no cuenta,
  trabajos terminados y fallidos, emails por estado y empresa, buzones y
  fallos seguidos (solo activos), documentos, colas
- [x] 6. **Logs estructurados.** Canal `json` en `config/logging.php`
  (`Illuminate\Log\Formatters\JsonFormatter`, diario, mismos días que
  `daily`) y `LOG_STACK=daily,json` en `.env.docker.example`. Promtail
  (`docker/promtail/promtail-config.yaml`): `json` → etiqueta `level` y
  hora del propio log (`timestamp` RFC3339Nano). Middleware global
  **`AssignRequestId`** (primero de todos): `request_id` en el Context y en
  la cabecera `X-Request-Id`; acepta el de un proxy solo si es
  `[A-Za-z0-9._-]{8,64}` (no se mete texto arbitrario del cliente en los
  logs). Verificado en el stack: Loki devuelve la línea con
  `{job="laravel", level="WARNING"} | json | extra_tenant_id="1"`.
  Tests: línea JSON con mensaje, nivel, contexto, `extra.tenant_id` y fecha
  con microsegundos; id en la respuesta y en el Context; id de un proxy
  aceptado o rechazado (saltos de línea, demasiado corto)
- [x] 7. **Alertas** (`alerts.yml`, cargado en `prometheus.yml` →
  `rule_files` y montado en docker-compose). Plataforma:
  `MetricasNoDisponibles` (`up{job="laravel"} == 0` 2 min, critical),
  `TrabajosDeColaFallando` (fallos definitivos en 15 min),
  `ColaAtascada` (> 100 trabajos 10 min), `ErroresHttp5xx` (> 5 % 10 min).
  Correo: **`BuzonAtascado`** (≥ 5 sincronizaciones fallidas seguidas: el
  mensaje envenenado de la Fase 4), `BuzonSinSincronizar` (> 15 min sin
  éxito), `EmailsConError` (≥ 3 a error en 15 min por empresa),
  `RevisionHumanaAcumulada` (> 50 esperando 1 h, info). IA: `IAConErrores`
  (> 20 % con al menos 5 peticiones en 10 min: sin falsas alarmas con poco
  tráfico), `GastoIAPorHoraAlto` (> 5 $/h). Resúmenes y descripciones en
  español con qué mirar. **`alerts.test.yml`**: cada alerta dispara cuando
  toca (y no antes del `for`) con sus etiquetas y textos exactos, y no
  dispara en los casos límite (otro buzón sano, empresa con 1 error, poco
  tráfico, 3 $/h); comprobado que una expectativa errónea hace fallar la
  prueba. CI: job **`observability`** (`promtool check config` + `test
  rules`, `prom/prometheus:v3.15.0`). Verificado en el stack: 3 grupos, 10
  reglas `ok`
- [x] 8. **Dashboards de Grafana** (sección 14): **General** (recibidos,
  enviados solos, % automatizado, % a una persona, **tiempo ahorrado
  estimado** = enviadas solas × variable "Minutos por respuesta manual",
  coste; ahora mismo: esperan a una persona, con error, buzones con fallos,
  alertas activas; evolución de entrada y decisiones; intenciones y volumen
  por empresa), **Emails** (personas vs automáticos, decisiones, mediana y
  p95 hasta la decisión, estado actual, respuestas solas / por personas /
  fallidas, propuestas que piden persona, sincronizaciones y tabla de cada
  buzón con fallos seguidos y último éxito), **IA** (peticiones, % de
  error, tokens, confianza media, herramientas, ejecuciones; por propósito,
  latencia p95, errores, tokens por tipo, intenciones, distribución de la
  confianza, tabla de herramientas, cómo terminan las ejecuciones),
  **Costes** (periodo, 24 h, 30 días, coste medio por email, por día —
  siempre 30 días —, por hora y propósito, por modelo, por empresa, tokens
  por tipo), **Errores** (tabla de alertas disparadas, contadores de error
  ahora, evolución, trabajos fallidos y reintentos, **logs de Loki** de
  error y aviso mostrando solo el mensaje) y **Rendimiento** (HTTP, rutas
  más lentas, colas, duración y resultado de los trabajos, latencia de la
  IA por modelo, PostgreSQL y Redis). Variable **Empresa** (multi) en
  todos, enlaces entre ellos, el General como inicio de Grafana
  (`GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH`). **Bug evitado:**
  provisionar datasources con `uid` sobre un Grafana que ya los tenía con
  otro → "data source not found" y Grafana no arranca →
  `deleteDatasources` en el provisioning (se recrean en cada arranque). CI:
  validación de los dashboards (JSON, uid únicos, solo datasources
  provisionados). **Revisión visual** con Chromium headless contra el stack
  con datos reales de la IA simulada (capturas de los 6) → corregido: picos
  irreales por tasas "por hora", rojo en un 0, barras gigantes con una sola
  empresa, decimales en recuentos, NaN en rutas sin peticiones, columna
  cortada, logs en JSON crudo. Las **86 consultas** de los dashboards se
  ejecutaron contra Prometheus y Loki sin errores.
  **Arreglos detectados en la revisión:**
  - La etiqueta `job` de `jobs_processed_total`/`job_duration_seconds`
    chocaba con la que Prometheus pone a cada target (`job="laravel"`): la
    nuestra acababa como `exported_job` y la alerta `TrabajosDeColaFallando`
    agrupaba mal → renombrada a **`job_class`**; test que prohíbe declarar
    `job` o `instance`; las pruebas de alertas usan series con las
    etiquetas del target como las reales.
  - El panel de logs de errores mostró un **reinicio de PostgreSQL** en la
    sesión de la nube (un proceso terminó con "exit code 2" y la BD estuvo
    16 s en recuperación; no se repitió) y que durante ese rato
    `/metrics` fallaba entero → `StateMetrics` calcula **cada sección por
    separado** (si falla una, el resto y los contadores se siguen
    publicando) con `aimail_state_metrics_ok{section}` y la alerta
    **`EstadoNoDisponible`** (5 min)
- [x] 9. **Prueba de extremo a extremo en el stack y README.** IA simulada
  (`AI_PROVIDER=simulated`, `horizon:terminate`), buzón demo creado y
  probado por la API, "Empresa Demo" en `auto` con herramientas de
  pedidos. 32 correos por SMTP en dos tandas (pedidos propios y ajenos,
  inglés, devolución, doble cobro, factura, injection, spam, fuera de la
  oficina, boletín, `noreply`): 7 respuestas enviadas solas (pedidos del
  propio cliente, también en inglés; **el límite anti-bucles frenó la
  cuarta a la misma dirección**), el pedido ajeno, quejas, facturas,
  injection y preguntas sin documentación (sin Ollama la búsqueda falla →
  persona) a revisión humana, spam y automáticos ignorados sin IA.
  Métricas, dashboards y logs con esos datos. **Alertas reales:** con
  GreenMail parado, `BuzonAtascado` (≈ 10 min: 5 fallos + 5 min),
  `BuzonSinSincronizar` y `TrabajosDeColaFallando` (`FetchEmailsJob` agotó
  sus reintentos) se dispararon solas; al volver GreenMail, las de buzón se
  resolvieron en 20 s. Encontrado y corregido en esta prueba: el primer
  fallo de una serie no alertaba (ver decisiones) y las etiquetas viejas
  en Redis tras el renombrado. **Lecciones del entorno:** Horizon lee el
  `.env` al arrancar (cambiar `AI_PROVIDER` exige `horizon:terminate`);
  Prometheus no relee `alerts.yml` solo (`docker compose restart
  prometheus`); correos de prueba sin `Message-ID` ni `Date` tienen la
  misma huella y se descartan como duplicados (la deduplicación funciona).
  README: sección **"12. Observabilidad: métricas, Grafana y alertas"**
  (cómo encaja, actualizar un entorno anterior, dashboards, métricas, logs
  con consultas de Loki, alertas, probar con la IA simulada), estado,
  servicios, comandos, `AI_PROVIDER`, estructura, tests, CI, problemas
  frecuentes; secciones siguientes renumeradas (API = #14)
- [x] 10. **Costes y tokens solo para el operador** (pedido por el
  usuario, 2026-10-15): Grafana es del operador de la plataforma (ve todas
  las empresas, costes reales = su margen); el panel es de las empresas
  cliente. Se quitan **de la API del panel** (no solo de la pantalla:
  estarían en las respuestas JSON): `GET /dashboard` sin `cost_usd`,
  `cost_today_usd`, `cost_month_usd` ni `avg_cost_per_email_usd`;
  `GET /emails/{id}` sin `ai_cost_usd` ni `classification.cost_usd`;
  `AgentRunResource` sin tokens ni coste (ni de la ejecución ni de cada
  paso). Panel: las tarjetas de coste del Resumen pasan a **Ignorados**
  (automáticos, boletines, spam) y **Respuesta media de la IA**
  (latencia, consultas y fallidas); fuera el coste del detalle y los
  tokens/coste de la traza; `formatUsd` → `formatSeconds`. El modelo y la
  latencia sí se siguen mostrando; el `unit_price` de los pedidos que
  consultó la IA también (es dato del propio cliente). Siguen con costes
  las herramientas del operador: Grafana y los comandos `ai:reply`,
  `ai:classify`. Guarda de tests **`costOrTokenKeys()`** (`tests/Pest.php`):
  recorre la respuesta JSON y lista cualquier clave con cost/token/usd;
  Resumen y detalle del email deben dar `[]`. Vitest: la traza no muestra
  `$` ni tokens. Verificado con Chromium en el panel. CI: el workflow se
  lanza también con push a ramas **`fase-*`** (antes solo `main` y PR a
  `main`: una rama de fase publicada no pasaba el CI hasta abrir el PR)

- [x] **Revisión en local (2026-10-16) y fix de las métricas de colas.**
  `QueueMetricsSubscriber` contaba con `JobProcessed`/`JobFailed`, pero
  Laravel solo lanza `JobProcessed` si el trabajo termina **sin
  excepción**: los reintentos tras una excepción no se contaban como
  `released` (y su hora de inicio se quedaba en memoria del worker), y un
  trabajo que se marca fallido con **`$this->fail()`** (lo hacen
  `ClassifyEmailJob` y `FetchEmailsJob`) se contaba **dos veces** (failed y
  success). Ahora se usa **`JobAttempted`** (Laravel 13: al final de cada
  intento, en el worker y en la cola sync, con el estado ya decidido):
  `hasFailed()` → failed, `isReleased()` → released, si no success. Tests
  nuevos que fallaban con el código anterior: `fail()` cuenta una sola vez,
  y con el **worker real** (cola Redis, `queue:work --once --tries=2`) un
  trabajo que lanza cuenta 1 released y luego 1 failed. En el equipo del
  usuario: faltaba `composer install` (la dependencia de Prometheus) y en
  su `.env` `METRICS_TOKEN` y `LOG_STACK=daily,json` (añadidos): con ello
  los 4 targets de Prometheus **up**, `/metrics` 200 con token y 404 sin
  él, 11 reglas `ok`, `promtool test rules` SUCCESS, 6 dashboards y
  datasources con `uid` fijos. **Anotado para la Fase 12:** Prometheus
  (9090) y Grafana (3000, `admin`/`admin`) se publican en todas las
  interfaces (desde la Fase 1), y el token de métricas va escrito en
  `prometheus.yml`

**Resumen:** 10 tareas en 10 commits + 1 fix, 937+ tests PHP en verde
(Vitest sin cambios), alertas probadas con `promtool` (13 casos).
**Pendiente para dar la Fase 11 por cerrada:** que el usuario revise la
rama `fase-11-grafana`, el CI en verde (incluido el job nuevo
`observability`), la fusione, y la pruebe en su equipo (Docker Desktop):
seguir "Actualizar un entorno que ya existía" del README. Pendiente para la
Fase 16: Alertmanager (enviar avisos), token de métricas de producción,
migrar Promtail (en fin de soporte) a Grafana Alloy.

### Fase 12 (seguridad): COMPLETADA Y PROBADA CON ATAQUES EN EL STACK (2026-10-16), pendiente de CI

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit, sin push (el usuario sube y
revisa el CI).

**Objetivo:** cerrar los huecos de seguridad que quedan. Ya existen (fases
anteriores): roles y permisos con policies, aislamiento por empresa con
tests, credenciales y tokens cifrados, anti-SSRF, HTML saneado, detección
de prompt injection, límites del agente, rate limits de login y
operaciones caras. Falta: **registro de auditoría** de las acciones de las
personas, **protección del gasto de IA** (un atacante que inunda un buzón
no puede vaciar la cuenta), **revisión de lo que la IA envía** antes de
enviarlo, **cabeceras de seguridad**, **secretos fuera de los logs**,
**retención y borrado de datos**, endurecer la infraestructura y tests de
arquitectura que impidan regresiones.

**Decisiones tomadas:**
- Código en **`app/Security/`** (auditoría, cabeceras, redacción de
  secretos, protecciones de la IA).
- **Auditoría en tabla propia `audit_logs`**, escrita por un servicio
  (`AuditLogger`), no por observers genéricos de modelos: se registra la
  **intención** de una persona ("cambió el rol de X de agente a admin"),
  con quién, desde dónde (IP, navegador, `request_id`) y qué cambió,
  **nunca secretos** (contraseñas, tokens). Inmutable: el modelo no se
  puede editar ni borrar y un **trigger de PostgreSQL** impide los
  `UPDATE` (solo la retención borra lo antiguo). Los fallos de login sin
  empresa conocida también se guardan (solo los ve el operador).

**Tareas (commit):**
- [x] 1. **Registro de auditoría.** Tabla `audit_logs`: `tenant_id`
  nullable (restrict; null = sin empresa conocida, p. ej. login fallido
  con un email que no existe), `user_id` **sin FK a propósito** (con
  nullOnDelete, borrar un usuario modificaría sus registros y el trigger
  lo impediría) + copia `actor_email`, `action` ("objeto.verbo":
  `user.created`, `auth.login_failed`…), `subject_type` (nombre corto del
  modelo) / `subject_id` / `subject_label` (nombre legible), `changes`
  jsonb (`{campo: {from, to}}`), `metadata` jsonb, `ip`, `user_agent`,
  `request_id`, `created_at`; índices tenant+created_at, tenant+action,
  subject, user_id. **Trigger `audit_logs_no_update`** (función
  `audit_logs_immutable`, `CREATE OR REPLACE`: `migrate:fresh` borra las
  tablas pero no las funciones) rechaza todo `UPDATE`, también con SQL
  directo. Modelo `AuditLog` (sin `updated_at`, nada asignable en masa;
  `updating`/`deleting` → `LogicException`; **lectura con `TenantScope`**,
  falla cerrado, pero sin `BelongsToTenant` para escribir: hay registros
  sin empresa). **`App\Security\Audit\AuditLogger::record($action,
  ?$subject, ?$label, $changes, $metadata, ?$actor, ?$tenantId)`**: actor
  = el autenticado por defecto; empresa = la indicada, la del actor o la
  del contexto; IP y navegador solo si es una petición HTTP con ruta (no
  en comandos/jobs); `request_id` del Context. **Oculta secretos**
  (`[oculto]`) en `changes` y `metadata`, también anidados: claves que
  contengan password, secret, token, credential, api_key, authorization o
  cookie. `AuditLogger::changesOf($model, $campos)` (tras `save()`, con
  `getPrevious()`: el original ya está sincronizado; enums y fechas como
  texto). Tests (9): datos completos, desde HTTP (IP, navegador,
  `X-Request-Id`), desde un job sin usuario, sin empresa, secretos
  anidados, `changesOf`, inmutable en la app y en la BD, aislamiento y
  fallo cerrado. **Lección:** una ruta añadida en un test fuera de `/api`
  la captura el comodín del panel
- [x] 2. **Auditar las acciones** (donde se conoce la intención: los
  controladores, el login y el callback de OAuth). Sesiones:
  `auth.login` (`method` token/session, `device_name`), `auth.logout`,
  `auth.login_failed` (en `LoginRequest::authenticate`: con un email que
  existe va a **la auditoría de su empresa** con la cuenta como sujeto y
  sin actor; con uno desconocido, sin empresa: solo el operador; nunca la
  contraseña probada), `auth.login_blocked` (empresa desactivada).
  Usuarios: `user.created` (email, rol), `user.updated` (nombre, email,
  **rol de → a**, contraseña como `[oculto]`: consta que cambió, nunca el
  hash; sin cambios reales no se registra), `user.deleted`. Empresa:
  `tenant.updated`. Buzones: `mail_connection.created`, `.updated`
  (nombre, email, estado; credenciales nuevas como `[oculto]`), `.tested`
  (recepción/envío ok), `.deleted`, **`.oauth_connected`** (actor = quien
  inició el flujo, sacado del state; `reconnected`). IA:
  `automation.updated` (modo e intenciones de → a; sustituye al
  `Log::info` de la Fase 10) y `automation.tool_changed`. Documentación:
  `document.uploaded` (fichero, tipo, tamaño), `.reindexed`, `.deleted`.
  `changesOf()` aplica los casts también al valor anterior (antes el JSON
  de `auto_send_intents` salía como texto). Tests (`AuditedActionsTest`,
  11 + asserts en `MicrosoftOAuthTest`): cada acción con sus datos, sin
  contraseñas, hashes, tokens ni credenciales, y nada en otra empresa
- [x] 3. **API y página de auditoría.** Permiso nuevo **`audit.view`**
  (propietario y administrador; sembrado en la BD de desarrollo). **`GET
  /api/v1/audit-logs`** (`AuditLogController`, solo lectura: no existe
  ruta para cambiarlo) con filtros validados en `ListAuditLogsRequest`:
  `category` (auth, user, tenant, mail_connection, automation, document,
  email, retention →
  `action like 'x.%'`), `user`, `from`/`to`, `per_page` (50 por defecto);
  orden por fecha y luego id. `AuditLogResource`: acción, actor (nombre
  actual o, si ya no existe, el email que tenía), sujeto (tipo, id,
  nombre), cambios, metadata, IP, navegador, `request_id`. Los logins
  fallidos sin empresa nunca salen (TenantScope). Página **`AuditPage`**
  (`/auditoria`, menú "Auditoría"): filtros por tipo, persona y fechas;
  tabla cuándo / quién / acción en español con lo que cambió ("Rol: Agente
  → Administrador", "Contraseña: cambiada", "Se envían solas: las de la
  plataforma → …") y detalle (token o panel, email probado, resultado de
  la prueba del buzón, herramienta activada) / IP con el navegador en
  tooltip; fallos de login en rojo, borrados en ámbar (`utils/audit.ts`).
  Revisión visual con Chromium. **Nota:** las IP son el `REMOTE_ADDR` que
  nginx pasa por FastCGI (correcto en local); detrás de un balanceador
  habrá que configurar `trustProxies` (tarea 10). Tests: `AuditLogApiTest`
  (9: listado, usuario borrado, filtros, 422, paginación, permisos,
  aislamiento, sin rutas de escritura) y Vitest `AuditPage.test.ts` (5)
- [x] 4. **Cuenta propia.** Sesiones del panel: el middleware
  `AuthenticateSession` de Sanctum (ya activo en `config/sanctum.php`)
  guarda en cada sesión una huella de la contraseña y **cierra las que no
  coinciden**; la sesión que hace el cambio guarda la nueva al terminar la
  petición. `AccountController`: **`PUT /api/v1/auth/password`**
  (`ChangePasswordRequest`: actual, nueva confirmada y distinta) y
  **`POST /api/v1/auth/logout-other-devices`** (contraseña; la vuelve a
  cifrar con otra sal → cambia el hash y caen las demás sesiones). Ambos
  piden la **contraseña actual** (una sesión robada no basta), con
  **throttle 6/min**, revocan **los demás tokens de API** (no el de la
  petición), regeneran el id de sesión y quedan en la auditoría
  (`auth.password_changed`, `auth.logout_other_devices` con
  `revoked_count`). Si **otra persona** cambia la contraseña de un usuario
  (`PATCH /users/{id}`), se revocan sus tokens. **Política de contraseñas
  común** con `Password::defaults()` (`AppServiceProvider`): mínimo 10
  (antes 8), máximo 128 y, **en producción**, rechazo de contraseñas
  filtradas (Have I Been Pwned por k-anonimato); la usan alta, edición y
  cambio propio. **Lección:** la redacción de secretos oculta cualquier
  clave que contenga "token" (`revoked_tokens` salía `[oculto]`): es a
  propósito, se usa otro nombre. Panel: página **`AccountPage`**
  (`/cuenta`, opción "Mi cuenta" en el menú del usuario, sin permiso
  especial): datos propios, cambiar la contraseña (errores bajo cada
  campo) y cerrar las demás sesiones. Tests: `AccountTest` (12: token
  actual se mantiene y los demás no, contraseña actual obligatoria,
  validación, throttle, la sesión que cambia sigue y **otra sesión del
  panel cae** al cambiar la contraseña en otro sitio, cerrar las demás,
  reseteo por un administrador, el propio por la API de usuarios, 401) y
  Vitest `AccountPage.test.ts` (4)
- [x] 5. **Protección del gasto de IA** (`App\Security\AiSpendGuard`, en
  `EmailProcessor` justo antes de clasificar): sin ella, inundar un buzón
  (o un bucle con otro sistema) haría pagar clasificación + agente por
  cada correo. Superado un límite, el email va a **revisión humana sin
  llamar a la IA** con el motivo en el historial: **presupuesto diario
  por empresa** (suma de `ai_requests.cost_usd` desde las 00:00 de la zona
  horaria de la app; `ai.limits.tenant_daily_budget_usd`,
  `AI_TENANT_DAILY_BUDGET_USD`, 10 $ por defecto, vacío = sin límite; es un
  freno, no un tope exacto: un email en marcha termina, y cada uno ya
  tiene su `ai.agent.max_cost_usd`) y **emails de un mismo remitente que
  pasaron por la IA en 24 h** (`max_ai_emails_per_sender_per_day`,
  `AI_MAX_EMAILS_PER_SENDER`, 20; sin distinguir mayúsculas, con índice
  funcional `(tenant_id, lower(from_email), created_at)`; los ya frenados
  no cuentan, así se recupera solo). Columna
  **`tenants.ai_daily_budget_usd`** (null = el de la plataforma; **la fija
  el operador**, no la empresa: no es asignable ni está en la API) y
  comando **`php artisan ai:budget <empresa> [--daily=25|default]`**
  (presupuesto, origen, gastado hoy, disponible). Métrica
  `ai_guard_blocks_total` {tenant, reason = budget|sender} y alerta
  **`IAFrenadaPorLimites`** (con prueba en `alerts.test.yml`, incluida la
  primera aparición de la serie). Tests: `AiSpendGuardTest` (7: sin
  presupuesto no llama a la IA, solo cuenta hoy y la propia empresa,
  presupuesto propio o ninguno, remitente que inunda sin distinguir
  mayúsculas, qué no cuenta, comando, la empresa no puede cambiarlo por
  la API)
- [x] 6. **Revisión del texto antes del envío automático**
  (`App\Security\ReplyContentGuard`, usado por `AutoSendPolicy`: sus
  problemas se suman a los motivos). Las reglas miraban intención,
  confianza y destinatario, pero no lo que la IA había escrito.
  Comprobaciones **deterministas, sin coste de IA**, contra lo que la IA
  tenía delante: "datos de la empresa" = resultados **con éxito** de las
  herramientas de esa ejecución (`ai_tool_calls` de sus `ai_requests`) +
  dominios de sus buzones; "pruebas" = eso + el email del cliente. A
  revisión humana si: está vacía o pasa de `ai.automation.
  max_auto_reply_chars` (3000, `AI_MAX_AUTO_REPLY_CHARS`); **promete que
  una persona revisará el caso** (es/en; la incoherencia anotada en la
  Fase 8: cierta solo si va a revisión); contiene **detalles internos**
  (nombres de herramientas, `<email>`, `<documentacion>`, `<fuente`,
  "instrucciones del sistema", `[oculto]`); **enlaces** cuyo dominio no es
  de un buzón de la empresa ni sale de sus datos (**los del email del
  cliente no valen**: una inyección podría hacer que la empresa reenvíe
  phishing con su nombre); **direcciones de email** que no son del
  cliente, de la empresa ni de sus datos; **números de pedido** ("pedido
  nº X", "order #X", "#X") o **importes con moneda** que no aparecen en
  las pruebas (alucinaciones; importes normalizados: "1.349,50" =
  "1349.5" = "1,349.50"). Nunca reescribe la respuesta. Tests:
  `ReplyContentGuardTest` (24 casos) y fixtures de
  `AutoSendPolicyTest`/`AutomationEndToEndTest` hechos realistas (antes
  proponían datos que no salían de ningún sitio y el guardián los paraba)
- [x] 7. **Cabeceras de seguridad.** Middleware global
  `App\Http\Middleware\SecurityHeaders` (en `bootstrap/app.php`):
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`
  (sin cámara, micrófono, ubicación, pagos, USB), `Cross-Origin-Opener-
  Policy: same-origin`, **HSTS solo si la petición llegó por HTTPS**, fuera
  `X-Powered-By`. **CSP**: respuestas no HTML (API, métricas) →
  `default-src 'none'; frame-ancestors 'none'`; **panel** → solo el propio
  origen (`script-src 'self'`, `connect-src 'self'`, `img-src 'self'
  data:`, `object-src 'none'`, `base-uri 'none'`, `frame-ancestors
  'none'`; `style-src` con `'unsafe-inline'` porque Naive UI inyecta sus
  estilos en tiempo de ejecución), y **solo mientras Vite está en marcha**
  (`public/hot`) se añade su servidor (http y ws, para HMR); **Horizon sin
  CSP** (tiene scripts en línea; solo lo ve el operador). nginx:
  `server_tokens off` y `fastcgi_hide_header X-Powered-By` (ya no se
  anuncian versiones; **reiniciar nginx** para aplicarlo). Revisado con
  Chromium: las 9 páginas del panel, incluido el iframe con el HTML del
  email, sin errores de CSP en la consola. Tests: `SecurityHeadersTest`
  (cabeceras en panel, API, 404 y métricas; CSP de la API y del panel;
  Vite solo en desarrollo, con un doble de `Vite` para no tocar el
  `public/hot` real del contenedor `vite`; HSTS solo con HTTPS).
  **Anotado para la tarea 10/producción:** cookie de sesión sin `secure`
  (`SESSION_SECURE_COOKIE=true` con HTTPS)
- [x] Fix de la tarea 6 con **respuestas reales**: pasando
  `ReplyContentGuard` por las 12 propuestas de Claude de la BD de
  desarrollo, "una persona de nuestro equipo **va a** revisar", "una
  persona del equipo **seguirá** tu caso" y "hemos **pasado tu caso** al
  equipo de facturación" no se detectaban. Patrones ampliados (hasta 40
  caracteres entre "una persona/un compañero/un miembro…" y el verbo;
  "hemos pasado/derivado/escalado tu caso"): ahora se detectan los 5
  borradores que lo prometían y **las 5 respuestas que sí se enviaron
  solas siguen pasando** (sin falsos positivos: "lo revisamos",
  "revisemos la silla", "Hemos revisado tu pedido"). Frases reales
  añadidas a los tests
- [x] 8. **Secretos fuera de los logs.** `App\Security\Redactor` (lista
  única de secretos, la usa también `AuditLogger`): oculta por **nombre de
  campo** (password, secret, token, credential, api_key, authorization,
  cookie; anidados) y por **forma** dentro de textos (`Bearer …`, claves
  `sk-ant-…`, tokens de Sanctum `id|40 caracteres`, contraseñas en URLs
  `esquema://usuario:contraseña@host`). "tap"
  `App\Security\Logging\RedactSecrets` en los canales `single`, `daily`,
  `json`, `monthly` y `stderr` (`config/logging.php`): procesador de
  Monolog que limpia mensaje, contexto y `extra` (el Context de Laravel)
  antes de escribir; el `stack` hereda los procesadores de sus canales.
  **Trazas sin argumentos:** `zend.exception_ignore_args` estaba a 0 en la
  imagen (las trazas incluían los argumentos de las funciones, p. ej. una
  contraseña); `ini_set('zend.exception_ignore_args', '1')` al principio de
  `bootstrap/app.php` (FPM, artisan y workers, sin reconstruir la imagen).
  Los mensajes de excepción no se tocan: los escribe nuestro código, que
  ya no incluye credenciales (Fases 3 y 5). Verificado en el stack: una
  línea con `Bearer` y `password` sale `[oculto]` en el log de texto y en
  el JSON de Loki. Tests: `LogRedactionTest` (por nombre, por forma, los
  3 canales escribiendo a fichero con Context incluido, trazas sin
  argumentos)
- [x] 9. **Retención y borrado de datos** (`app/Security/Retention/`).
  **`EmailEraser::erase($email)`**: borra el email con destinatarios,
  adjuntos, respuestas e historial (cascade), **los ficheros de los
  adjuntos** del disco (después del commit; pendiente desde la Fase 4) y
  **vacía el contenido personal de los registros de IA** sin borrarlos
  (`ai_requests.response` → null, `ai_tool_calls.arguments` → `{}` y
  `result` → null, `ai_agent_runs.final_output` → null): tokens, costes y
  la traza de qué herramientas se usaron se conservan. **Nunca** borra un
  email en `processing`/`approved` o con una respuesta `sending`.
  **`RetentionService`**: `pruneEmails($tenant)` (más antiguos que la
  retención de la empresa), `pruneAuditLogs()` (DELETE directo: el modelo
  no deja borrar y el trigger solo bloquea UPDATE) y
  `forgetAddress($tenant, $email)` (**derecho de supresión**: todos los
  emails donde la dirección es remitente o destinataria, sin distinguir
  mayúsculas). `config/retention.php`: `emails_days`
  (`RETENTION_EMAILS_DAYS`, 365; 0 = sin límite), límites por empresa
  30–3650 y `audit_days` (`RETENTION_AUDIT_DAYS`, 730). Columna
  **`tenants.email_retention_days`** (null = la de la plataforma) que **el
  propietario** cambia con `PATCH /tenant` (`tenant.update`; `name` pasa a
  `sometimes`) y que se ve en `TenantResource` (`retention`: días
  aplicados, de la plataforma y límites). Comandos **`data:prune`**
  (programado **cada día a las 03:30**, `withoutOverlapping`) y
  **`data:forget-address <empresa> <email> [--force]`** (sin `--force` solo
  cuenta); además `queue:prune-failed --hours=720` diario. Permiso nuevo
  **`emails.delete`** (propietario y administrador; sembrado) y **`DELETE
  /api/v1/emails/{id}`** (409 si está en marcha). Auditoría
  (`email.deleted`, `retention.emails_purged`,
  `retention.address_forgotten`) **sin datos del cliente**: del email
  borrado solo el dominio del remitente y la fecha; de la dirección
  olvidada, su SHA-256; categorías nuevas `email` y `retention` en el
  filtro. Panel: botón **"Borrar"** con confirmación en el detalle del
  email (con el permiso) y **"Conservar los emails (días)"** en Empresa y
  usuarios (solo lectura sin `tenant.update`). Comprobado en la BD de
  desarrollo: `data:prune` no borra nada (nada tiene más de 365 días) y
  `schedule:list` muestra las dos tareas nuevas. Tests: `RetentionTest`
  (11: borrado completo conservando costes, emails en marcha, retención
  por empresa / sin límite, auditoría antigua, supresión por dirección,
  API con permisos, 404/409, retención desde la API) y Vitest
  (`CompanyPage`: el propietario la cambia, sin permiso solo se ve)
- [x] 10. **Infraestructura.** **Todos los puertos del compose solo en
  127.0.0.1**: nginx (`${APP_BIND:-127.0.0.1}`, `APP_BIND=0.0.0.0` para
  abrir la app a la red), Prometheus 9090, **Loki 3100** y Grafana 3000
  estaban en todas las interfaces (Prometheus y Loki no tienen
  autenticación: cualquiera en la red podía leer métricas y logs o
  inventar logs). Grafana: `GF_AUTH_ANONYMOUS_ENABLED=false`, sin
  Gravatar, sin informes ni comprobación de versiones a grafana.com, sin
  snapshots externos, cookie `SameSite=strict`; `GRAFANA_USER/PASSWORD` en
  `.env.docker.example` (solo se aplica al primer arranque: `grafana cli
  admin reset-admin-password`). **Proxies de confianza**:
  `config('app.trusted_proxies')` (`TRUSTED_PROXIES`, vacío = ninguno) →
  `TrustProxies::at()` en `AppServiceProvider`: solo de ellos se acepta
  `X-Forwarded-For` (IP real en auditoría y límites; nadie más puede
  falsearla). **`php artisan security:check [--production]`**: APP_KEY,
  debug, HTTPS, cookie `secure`, contraseñas de PostgreSQL/Redis fuertes
  (16+, no "secret"…), token de métricas propio (32+), IA no simulada,
  anti-SSRF activo, proxies no `*`, caducidad de tokens y nivel de log; en
  producción (o con `--production`) **sale con error** si algo falla (para
  el despliegue de la Fase 16), en local solo avisa. `.env.docker.example`:
  sección Seguridad (`APP_BIND`, Grafana, `TRUSTED_PROXIES`,
  `SESSION_SECURE_COOKIE`, retención). `composer require --dev
  symfony/yaml` (tests del compose). Aplicado en el stack (`docker compose
  up -d`; la primera vez Docker Desktop tardó en liberar los puertos y
  hubo que repetirlo): todo responde en localhost y los 4 targets de
  Prometheus siguen `up`. **Se queda para la Fase 16:** el token de
  métricas está escrito en `prometheus.yml` (Prometheus no lee variables
  de entorno; en producción, `credentials_file` con un secreto).
  Tests: `InfrastructureTest` (puertos del compose solo en local, Grafana
  endurecido, `X-Forwarded-For` solo desde el proxy de confianza,
  `security:check` correcto con configuración de producción y fallando con
  cada valor por defecto, solo avisos en local)
- [x] 11. **Tests de arquitectura y auditoría de dependencias.**
  `tests/Feature/Security/ArchitectureTest.php` protege decisiones de
  fases anteriores: **todo modelo con columna `tenant_id` usa
  `BelongsToTenant`** (salvo `User` y `AuditLog`, excepciones
  documentadas; se comprueba con el esquema real, así un modelo nuevo que
  lo olvide hace fallar el CI); **ningún modelo deja asignar `tenant_id`
  en masa**; **toda ruta `/api/v1` lleva `auth:sanctum` + `tenant`** salvo
  las públicas a propósito (health, login, session, callback de OAuth);
  las rutas que comprueban credenciales o llaman fuera tienen **throttle
  propio**; el SDK de Anthropic solo en su proveedor, ImapEngine y Symfony
  Mailer solo en `app/Mail`; controladores sin la fachada `DB`; en `app/`
  ni restos de depuración (`dd`, `dump`, `var_dump`, `print_r`, `die`…),
  ni ejecución de comandos (`exec`, `shell_exec`, `system`, `proc_open`…),
  ni `eval`/`unserialize`/`assert`, ni `env()` fuera de `config/`. Con el
  **tokenizer de PHP** sobre `app/` (distingue `exec()` global de
  `$obj->exec()`): el plugin `arch()` de Pest analiza también `vendor/` y
  se quedaba sin memoria (128 MB); un test "sonda" escribe un fichero con
  infracciones y comprueba que se detectan. CI: **`composer audit`** (job
  de PHP) y **`npm audit --omit=dev --audit-level=high`** (job del panel)
  fallan si una dependencia tiene una vulnerabilidad conocida; hoy, 0 en
  ambos
- [x] Fix encontrado en la **prueba de ataques** (tarea 12): en local
  (`MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true`, necesario para GreenMail en
  la red de Docker) se pudo **crear un buzón apuntando a
  `169.254.169.254`** (metadatos de la nube): el permiso desactivaba todo
  el anti-SSRF. Ahora `MailHostGuard` **bloquea siempre**, con o sin
  permiso, las direcciones de enlace local (`169.254.0.0/16`, `fe80::/10`:
  ahí están los metadatos con credenciales; ningún servidor de correo vive
  ahí) y `0.0.0.0/8`, también escritas como IPv6 (`::ffff:169.254.169.254`;
  en producción también se normalizan antes de comprobar redes privadas).
  Y el permiso **solo vale en `local` y `testing`**
  (`MailProvidersServiceProvider`): en producción se ignora aunque se
  active. `allowedIps()` (autodetección) aplica las mismas reglas. Tests en
  `MailHostGuardTest`
- [x] 12. **Prueba de ataques contra el stack y README.** Script de
  `curl` desde el contenedor `app` contra nginx (`--connect-to
  localhost:8080:nginx:80`: mismo Host que el navegador, cookies y
  Sanctum), con un usuario temporal que se borra al final. **26/26
  comprobaciones OK**: otra empresa → 404 al leer, pedir el HTML o borrar
  un email, y su búsqueda no encuentra nada ajeno; agente → 403 en la
  auditoría; fuerza bruta → 429 al sexto intento (los fallidos quedan en
  la auditoría de la empresa); buzón hacia `169.254.169.254`,
  `::ffff:169.254.169.254`, `fe80::1` y `0.0.0.0` → 422 "dirección
  interna" (**fallaba antes del fix anterior**); `/metrics` sin token o
  con uno falso → 404; un intruso con la contraseña filtrada entra por el
  panel y por la API, la víctima cambia la contraseña → su sesión sigue y
  la sesión y el token del intruso caen; contraseña de 8 caracteres → 422;
  cabeceras (CSP, `X-Frame-Options`, sin `X-Powered-By` ni versión de
  nginx); `X-Forwarded-For` falso ignorado (la auditoría guarda la IP
  real). **Lecciones del script:** la fuerza bruta bloquea un minuto el
  email atacado (hacer login del propietario antes); sacar el id del
  usuario con el primer `"id"` del JSON (el último es el de la empresa:
  intentó borrar al propio propietario y la policy lo impidió, bien);
  Python en Windows escribe CRLF y `sh` no lo entiende. README: sección
  **"13. Seguridad"** (tabla riesgo → protección, auditoría, cuentas,
  freno del gasto, revisión del texto, retención y supresión,
  infraestructura, resultados de la prueba de ataques, actualizar un
  entorno anterior), estado, comandos, estructura (`app/Security/`), API
  (5 endpoints nuevos o ampliados), tests, CI, problemas frecuentes;
  secciones siguientes renumeradas (API = #15)

**Resumen:** 12 tareas en 14 commits (12 + 2 fixes encontrados al probar
con datos reales y ataques), 1078+ tests PHP + 65 Vitest en verde.
**Pendiente para dar la Fase 12 por cerrada:** push (lo hace el usuario) y
GitHub Actions en verde (ahora también con `composer audit` y `npm
audit`). En su equipo: seguir "Actualizar un entorno anterior a la Fase
12" del README. **Para la Fase 16:** token de métricas fuera de
`prometheus.yml` (`credentials_file`), `SESSION_SECURE_COOKIE` y
`TRUSTED_PROXIES` con HTTPS, `security:check --production` en el
despliegue.

### Fase 13 (human in the loop): COMPLETADA Y PROBADA EN EL NAVEGADOR CON LA IA SIMULADA (2026-10-17), pendiente de CI

Misma forma de trabajo: un commit por tarea, tests antes de cada commit,
este archivo actualizado en cada commit. Como en la Fase 11, se hace en una
sesión de Claude Code en la nube: los commits se suben a la rama
**`fase-13-human-in-the-loop`** (nunca a `main`); el usuario revisa el CI y
la fusiona. **Sin IA real**: para probar se usa la IA simulada
(`AI_PROVIDER=simulated`).

**Entorno de la sesión en la nube:** Docker Hub limita las descargas (429)
y GitHub solo es accesible por git (las descargas zip de Composer dan 403).
Se usó: PostgreSQL 16 local con **pgvector 0.6** de Ubuntu (sin *iterative
scans*: los tests de búsqueda semántica fallan solo aquí), Redis local,
GreenMail y PHP 8.4 (`serversideup/php:8.4-cli-alpine`) desde
`mirror.gcr.io`, y la caché de Composer rellenada con `git fetch --depth 1`
+ `git archive` de cada paquete del `composer.lock`. El proyecto no cambia.

**Objetivo:** que una persona resuelva los emails que la IA no puede
enviar sola (sección 12): una **bandeja de revisión** con los casos
pendientes, ver el email con lo que hizo la IA, **aprobar** la respuesta
(tal cual o **editada**), **descartarla**, **escribir una propia**,
**cerrar** el caso sin responder o **reprocesarlo** con la IA, asignarse
casos, y un **historial** de todas las decisiones humanas.

**Decisiones tomadas:**
- Código en **`app/Automation/Review/`** (orquesta respuestas y envío como
  el resto de `app/Automation`).
- Permiso nuevo **`emails.review`** para **todos los roles**: revisar y
  responder es el trabajo diario de un agente de atención al cliente.
- **Tabla propia `email_reviews`** (una fila por decisión: quién, qué, si
  editó el texto de la IA, motivo y cuánto esperó el caso) además del
  historial de estados y la auditoría: es el historial de la bandeja y la
  fuente de sus métricas (consultas por persona y por decisión sin
  analizar textos). Se borra con el email (retención).
- Al editar se guarda el **texto original de la IA** (`original_body`): se
  puede ver qué cambió la persona y medir cuánto se corrige a la IA.
- Un email "espera a una persona" en `human_review` **o `failed`** (un
  envío fallido o un error de procesamiento también los resuelve una
  persona).

**Tareas (commit):**
- [x] 1. **Modelo de datos de la revisión.** Migración
  `add_human_review`: en `email_replies`, `created_by` (users,
  nullOnDelete; null = la IA), `original_body` (texto de la IA si una
  persona lo editó), `edited_by`/`edited_at`, `rejected_by`/`rejected_at`/
  `rejection_reason`; en `emails`, `assigned_to` (users, nullOnDelete) +
  `assigned_at` e índice tenant+assigned_to; tabla **`email_reviews`**
  (tenant restrict, email cascade, `email_reply_id` y `user_id`
  nullOnDelete, `decision`, `edited`, `reason`, `waited_seconds`,
  `created_at`; índices tenant+created_at, tenant+user+created_at,
  email_id). Enum `App\Automation\Review\ReviewDecision` (approved,
  rejected, replied, closed, reprocessed; `label()` en español,
  `sendsReply()`). Modelo `EmailReview` (`BelongsToTenant`, sin
  `updated_at`), `Email::reviews()`/`assignee()`,
  `EmailReply::author()`/`editedBy()`/`rejectedBy()`/`wasEdited()`; nada
  de esto es asignable en masa. Permiso **`emails.review`** (los tres
  roles). Tests: `HumanReviewModelTest` (columnas, relaciones,
  aislamiento y fallo cerrado, cascade, sin asignación en masa)
- [x] 2. **`App\Automation\Review\HumanReviewService`** (dentro del
  tenant del email; cada decisión en una transacción y en `email_reviews`
  con `waited_seconds` = tiempo desde que el email entró en su estado
  actual): **`approve($reply, $user, ?$body)`** (con `$body` distinto —sin
  contar `\r\n` ni espacios finales, `normalize()`— guarda
  `original_body` la primera vez, `edited_by`/`edited_at`; descarta las
  demás propuestas en borrador; `ReplySender::approve()` con la persona →
  se envía **sin `Auto-Submitted`**; motivo "Respuesta de la IA aprobada
  (con cambios) por Ana"), **`reject($reply, $user, ?$reason)`** (la
  propuesta → `rejected` con quién/cuándo/motivo; el email sigue en
  `human_review`), **`reply($email, $user, $body)`** (respuesta escrita por
  la persona: `created_by`, sin `agent_run_id`; sustituye a los borradores
  y se envía), **`close($email, $user, ?$reason)`** (→ `ignored` "Cerrado
  sin responder por Ana: …"), **`reprocess($email, $user)`** (descarta los
  borradores, → `processing` con la persona y encola `ProcessEmailJob`
  con `reprocess` *after commit*: el procesador sigue desde `processing`
  como un reintento y repite el agente; **nunca** si ya se intentó un
  envío: `EmailProcessor::sendWasAttempted()`) y **`assign($email,
  ?$user)`** (no cambia el estado). Un email con error (`failed`) pasa
  primero a `human_review` ("Lo revisa Ana"): `failed → approved` no es un
  paso válido. Todo condicional: si el email ya no espera a una persona o
  la respuesta ya no es un borrador (otra persona se adelantó) →
  **`ReviewException`** con mensaje para el panel y no se cambia nada.
  Tests: `HumanReviewServiceTest` (16: aprobar tal cual y editada, saltos
  de línea no cuentan, otras propuestas descartadas, descartar, responder,
  responder a un envío fallido, cerrar, reprocesar y nunca tras un envío,
  estados no revisables, dos personas a la vez, asignar, fuera del tenant)
- [x] 3. **API de la revisión** (`EmailReviewController`, auth + tenant):
  **`POST /emails/{id}/replies/{reply}/approve`** (`body` opcional, máx.
  20.000: el texto que deja la persona), **`.../replies/{reply}/reject`**
  (`reason` opcional, máx. 1.000) —ambas con `scopeBindings`: la respuesta
  tiene que ser de ese email—, **`POST /emails/{id}/replies`** (`body`
  obligatorio: respuesta propia), **`POST /emails/{id}/close`** (`reason`),
  **`POST /emails/{id}/reprocess`** y **`PUT /emails/{id}/assignee`**
  (`user_id` o `null`; solo personas de la empresa con `emails.review`).
  `EmailPolicy::review` (otra empresa → 404, luego `emails.review`),
  comprobado en el `authorize()` de los FormRequest (`ReviewEmailRequest` y
  sus hijas) antes de validar. `ReviewException` → **409** con el motivo.
  Cada acción devuelve el detalle actualizado
  (`EmailDetailResource::for()`, que ahora también usa `show`). Detalle
  ampliado: `awaiting_person`, `send_attempted`, `assignee`/`assigned_at`,
  `reviews[]` (decisión + etiqueta, editada, motivo, espera, persona,
  fecha) y en cada respuesta `author`, `original_body`, `edited_by`/
  `edited_at`, `rejected_by`/`rejected_at`/`rejection_reason`; el listado
  incluye `assignee`. **Auditoría** `email.reply_approved` (`reply_id`,
  `edited`), `email.reply_rejected`, `email.replied`, `email.closed`,
  `email.reprocessed`, `email.assigned` (de quién a quién), **sin el texto
  de las respuestas ni los motivos**: pueden llevar datos del cliente y la
  auditoría dura más que el email (`email_reviews` sí guarda el motivo y se
  borra con él). Tests: `EmailReviewApiTest` (15: cada acción, edición sin
  datos del cliente en la auditoría, reprocesar tras un envío → 409,
  asignar/liberar y a quién se puede, 409 si otra persona se adelantó,
  validación, respuesta de otro email → 404, otra empresa → 404 en todo,
  sin permiso → 403, 401)
- [x] 4. **Bandeja de revisión y avisos sobre el texto.** **`GET
  /api/v1/review-queue`** (`ReviewQueueController`, `emails.view`): emails
  en `human_review` o `failed`, **urgentes primero** (`urgency` high →
  normal → low/sin clasificar) y, dentro de cada urgencia, **los que más
  llevan esperando** (`waiting_since` = fecha del último cambio de estado,
  subconsulta); cada caso con `waiting_reason` (motivo de ese cambio),
  `has_draft` (hay propuesta de la IA) y `assignee`; filtros `assigned`
  (`mine` / `unassigned`), `intent`, `mailbox`, `per_page`; `meta.counts`
  (total, mías, sin asignar, con error). **Avisos para la persona:**
  `ReplyContentGuard::problems(..., automatic: false)` omite el límite de
  longitud y la "promesa de revisión humana" (con una persona revisando ya
  no aplican) y deja el resto (enlaces y direcciones desconocidos, pedidos
  e importes que no salen de ningún dato, detalles internos);
  `HumanReviewService::warnings($email, $body, ?$basedOn)` los calcula
  contra los datos que consultó la IA para esa propuesta. En el detalle,
  cada propuesta en borrador de un email que espera a una persona trae
  `checks[]`; y **`POST /emails/{id}/review-checks`** (`body`, `reply_id`
  opcional de ese email) comprueba un texto editado antes de enviarlo. Los
  avisos **no impiden enviar**: decide la persona. Tests:
  `ReviewQueueApiTest` (7: orden urgencia + espera, propuesta y asignado,
  filtros y 422, aislamiento, 401, avisos en el detalle, comprobar un texto
  editado y propuesta de otro email → 422)
- [x] 5. **Historial de la revisión.** **`GET /api/v1/reviews`**
  (`ReviewHistoryController`, `emails.view`): decisiones de las personas,
  la más reciente primero, con el email (id, asunto, remitente, intención,
  estado) para enlazarlo; filtros `decision`, `user`, `from`/`to`,
  `per_page`. **`GET /api/v1/reviews/summary?days=7|30|90`**
  (`App\Automation\Review\ReviewStats`): casos pendientes ahora,
  decisiones por tipo, resueltos (aprobar, responder, cerrar, reprocesar;
  descartar una propuesta no resuelve el caso), **espera media y mediana**
  hasta resolver (`percentile_cont` de PostgreSQL), **qué pasa con las
  propuestas de la IA** que revisa una persona (aprobadas tal cual,
  corregidas, descartadas y `unchanged_rate`: la mejor medida de la calidad
  real de la IA) y **por persona** (total, decisiones, cuántas editó, espera
  media; `count(*) filter (where …)`). Todo con el scope de tenant. Tests:
  `ReviewHistoryApiTest` (6: orden y email, filtros y 422, resumen con
  espera/propuestas/personas y periodo, periodo vacío sin dividir por
  cero, aislamiento, 401)
- [x] 6. **Métricas, Grafana y alerta.** Observer
  `EmailReviewMetricsObserver` (`afterCommit`, sobre `email_reviews`):
  **`email_reviews_total`** {tenant, decision, edited} y
  **`email_review_wait_seconds`** {tenant, decision} (intervalos de 1 min
  a 3 días). Estado actual en `StateMetrics` (sección `emails`):
  **`email_review_oldest_waiting_seconds`** {tenant} = lo que lleva
  esperando el caso más antiguo en `human_review`/`failed` (desde su
  último cambio de estado). Dashboard **Emails**: fila nueva **"Revisión
  humana"** (esperan a una persona —ámbar ≥ 1—, caso más antiguo —ámbar ≥
  4 h—, decisiones del periodo, **% de propuestas aprobadas sin cambios**,
  % de respuestas corregidas, espera mediana hasta resolver; barras de
  decisiones por intervalo en 4 grupos de color fijo —aprobada, respondida
  por una persona, propuesta descartada, cerrada o reprocesada— y mediana /
  p95 de la espera); las filas de debajo se desplazan. Las 9 consultas
  nuevas se ejecutaron contra un Prometheus real sin errores. Alerta
  **`CasoSinAtender`** (warning): el caso más antiguo de una empresa pasa
  de 4 h esperando durante 5 min; probada en `alerts.test.yml` (aún no a
  los 5 min del `for`, sí después con el texto exacto —`humanizeDuration`
  → "4h 6m 20s"—, y otra empresa con 3 h no alerta). Tests:
  `ReviewMetricsTest` (decisiones y espera con sus intervalos, transacción
  deshecha no cuenta, caso más antiguo por empresa)
- [x] 7. **Página: bandeja de revisión** (`ReviewQueuePage`, `/revision`,
  `emails.view`; entrada **"Revisión"** del menú justo después de
  "Resumen" con el **número de casos pendientes** —`NBadge`— que mantiene
  el store Pinia `reviewQueue`: lo pide al montar el layout, cada minuto
  y al cargar la bandeja). Pestañas **Todos / Asignados a mí / Sin
  asignar** con sus recuentos y filtro de intención, **en la URL**
  (`?scope=mine&intent=complaint&page=2`; lo inválido se ignora). Tabla:
  cuánto lleva esperando ("hace 2 h", fecha exacta en el título) +
  etiqueta "Urgente", asunto (enlace al detalle) con remitente y **motivo
  de la espera**, intención + confianza, propuesta ("De la IA" / "Sin
  propuesta" / "Error") y asignado ("Tú", nombre o botón **"Asignarme"**
  con `emails.review`); clic en la fila → detalle. Cliente
  `api/reviews.ts` (bandeja, todas las decisiones, comprobación del texto,
  historial y resumen) y tipos nuevos en `api/types.ts`;
  `formatDuration()`/`formatSince()` en `utils/format.ts`;
  `reviewDecisionLabels`. **Arreglado de paso:** `urgencyLabels` tenía
  `medium`, pero el clasificador devuelve `low`/`normal`/`high` (la
  urgencia normal salía como "—"). Vitest: `ReviewQueuePage.test.ts` (6:
  casos con espera/motivo/propuesta/asignado, filtros de la URL a la API,
  filtros inválidos, asignarse, sin permiso, duraciones) y el menú
- [x] 8. **Revisión en el detalle del email.** Componente
  `components/email/ReviewPanel.vue` (tarjeta "Revisión", solo si el email
  espera a una persona y el usuario tiene `emails.review`): editor que
  **parte del texto de la IA** (o vacío si no hay propuesta), a quién y
  desde qué buzón se enviará, botón **"Aprobar y enviar"** que pasa a
  **"Enviar con mis cambios"** al editar (con confirmación; "Volver al
  texto de la IA"), **"Descartar propuesta"** y **"Cerrar sin responder"**
  (diálogo con motivo opcional), **"Reprocesar con la IA"** (oculto si ya
  se intentó un envío, con aviso de que puede haber llegado) y
  **asignación** (Asignarme / Quedármelo / Liberar). **Avisos de las
  comprobaciones automáticas**: los de la propuesta (`checks`) y, al
  editar, `POST .../review-checks` 600 ms después de dejar de escribir; no
  impiden enviar. Errores de la API (409 si otra persona se adelantó) como
  mensaje; cada acción devuelve el email actualizado y refresca el número
  del menú. `ReplyCard`: "Respuesta de Ana" si la escribió una persona (sin
  confianza), quién y por qué la descartó, **"Corregida por Ana: ver el
  texto original de la IA"** (desplegable) y no repite el texto si está en
  el editor. `EmailPage`: respuestas anteriores en un desplegable y
  **"Decisiones de las personas"** bajo el historial (decisión, con
  cambios, quién, cuándo, cuánto esperó, motivo). Auditoría del panel: las
  6 acciones nuevas en español ("Con cambios en el texto", "Ahora lo tiene
  Bea"). Vitest: `ReviewPanel.test.ts` (11: aprobar tal cual, corregir con
  aviso y comprobación, avisos de la propuesta, escribir sin propuesta,
  descartar con motivo, reprocesar y no tras un envío, asignar/liberar,
  409, `ReplyCard` con autor/corrección/descarte, auditoría)
- [x] 9. **Página: historial de la revisión** (`ReviewHistoryPage`,
  `/revision/historial`, `emails.view`, marca "Revisión" en el menú;
  enlace "Historial de decisiones" desde la bandeja). Selector 7/30/90
  días; **4 tarjetas**: esperan a una persona ahora (enlaza a la bandeja),
  casos resueltos, **espera mediana** (y media), **% de propuestas de la
  IA aprobadas sin cambios** con el desglose (tal cual / corregidas /
  descartadas); tabla **por persona** (decisiones, aprobadas, corregidas,
  respondidas, descartadas, cerradas, espera media); **todas las
  decisiones** paginadas (cuándo, quién, decisión "(con cambios)", email
  enlazado con el motivo debajo, cuánto esperó) con filtros de decisión y
  persona. Sin gráficas (las series temporales están en Grafana, como en
  el Resumen). Vitest: `ReviewHistoryPage.test.ts` (2: resumen y lista)
- [x] 10. **`HumanReviewEndToEndTest`** (grupo `greenmail`): la revisión
  humana de principio a fin con soporte y cliente como cuentas reales de
  GreenMail y la **IA simulada** (`ai.provider=simulated`, sin coste; la
  primera vez que un test E2E la usa en vez de `FakeLLMProvider`). El
  cliente pregunta por envíos a Canarias → `mail:sync` → ingesta →
  clasificación y agente simulados → empresa en modo borrador →
  `human_review` con propuesta. Por la API del panel, como Ana (agente):
  la bandeja la muestra con `has_draft`; **la corrige y la aprueba** → en
  el buzón del cliente llega "Re: …" del buzón de soporte, en el hilo
  (`In-Reply-To`), con el texto corregido y **sin `Auto-Submitted`** (la
  envió una persona); historial processing → ai_review → human_review →
  approved (Ana) → sent. **Descartar y escribir la suya** → llega su texto
  (decisiones rejected, replied). **Reprocesar** → la IA simulada prepara
  una propuesta nueva (cola sync) y la anterior queda descartada; nada
  enviado
- [x] Fix detectado en la prueba en el navegador: la IA simulada propone
  "A member of our team will review your message shortly" y, al revisarla
  una persona, la promesa de revisión humana no generaba ningún aviso
  (enviada por la persona solo es cierta si alguien hace ese seguimiento:
  la incoherencia anotada en la Fase 8). Ahora, para la persona, es un
  **recordatorio** ("si la envías así, asegúrate de que alguien hará ese
  seguimiento") y no impide enviar; para el envío automático sigue
  bloqueando igual. El menú: el número junto a "Revisión" iba pegado a la
  etiqueta (NMenu pinta la etiqueta fuera del ámbito de los estilos
  *scoped*): estilo en línea
- [x] 11. **Prueba en el stack con la IA simulada y README.** App con
  `php artisan serve` (puerto 8081: en la sesión de la nube el 8080 lo usa
  la API de GreenMail), Horizon, PostgreSQL y Redis locales, GreenMail con
  cuentas reales, `AI_PROVIDER=simulated` y `npm run build`. 5 correos por
  SMTP (pedido, Canarias, doble cobro, lámpara rota en inglés, factura) →
  `mail:sync` → Horizon → IA simulada → los 5 en `human_review` (modo
  borrador). Con **Chromium headless** (Playwright) como Lucía (agente):
  bandeja con espera, motivo, propuesta y "Asignarme"; en el pedido,
  asignarse, corregir con un pedido inventado (#99999) → aviso; corregir
  bien y enviar → **llegó al buzón de cliente@cliente.test** ("Re: …",
  `In-Reply-To` del original, **sin `Auto-Submitted`**, comprobado por
  IMAP) y el detalle enlaza el texto original de la IA; en la factura,
  descartar con motivo y escribir la respuesta → llegó a
  elena@cliente.test; cerrar el doble cobro con motivo. Como propietario:
  reprocesar la lámpara → propuesta nueva y la anterior en "Respuestas
  anteriores"; auditoría con las 6 acciones. Historial: decisiones por
  persona, esperas y % de propuestas sin cambios. Sin errores en la consola.
  **Grafana** (Prometheus + Grafana temporales contra la app): fila
  "Revisión humana" con datos → corregido: la serie "Cerrada o
  reprocesada" salía dos veces (faltaba `sum by (d)` tras el
  `label_replace`), títulos de tarjetas cortados (acortados, el detalle en
  la descripción) y decimales en esperas. README: sección **"14. Revisión
  humana (human in the loop)"** (bandeja, acciones, avisos, 409, historial
  y métricas, probarlo sin gastar, resultados, actualizar un entorno
  anterior), estado, introducción, tabla del panel, estructura, API (10
  endpoints nuevos y el 409), alertas, tests y problemas frecuentes;
  secciones siguientes renumeradas (API = #16)

- [x] Fix tras el push (CI en rojo): `RetentionTest` (Fase 12)
  comprobaba que la fila de auditoría de un email borrado no contuviera
  "ana@" (la remitente), pero esa fila guarda el **email del actor**, y el
  administrador del test tenía uno aleatorio de Faker: al salir algo como
  `juliana@example.org`, el test fallaba sin que hubiera datos del cliente.
  Test **inestable**, no un fallo del código: email fijo para el actor; lo
  mismo en `EmailReviewApiTest` (comprueba que no aparezca "45821").
  **Lección:** en aserciones "no contiene X" sobre registros que incluyen
  datos del actor, fijar esos datos en el test

**Resumen:** 11 tareas en 13 commits (11 + 1 fix encontrado en el
navegador + 1 fix de un test inestable), 1137 tests PHP (en esta sesión 25 fallan solo por el entorno:
pgvector 0.6 sin *iterative scans* y helpers compartidos entre archivos al
ejecutar en paralelo; pasan en el CI) + 85 Vitest en verde, alertas
probadas con `promtool` (`CasoSinAtender` incluida).
**Pendiente para dar la Fase 13 por cerrada:** que el usuario revise la
rama `fase-13-human-in-the-loop`, el CI en verde, la fusione y siga
"Actualizar un entorno anterior a la Fase 13" del README en su equipo
(migración, `RolesAndPermissionsSeeder` por el permiso `emails.review`,
reiniciar Prometheus/Grafana y Horizon).

### AI-Service (microservicio de IA en Python): Fase 1 COMPLETADA Y PROBADA DE PUNTA A PUNTA CON LA IA SIMULADA (2026-10-17), pendiente de que la pruebe el usuario

Rama **`fase-ai-service`** (sesión en la nube, como las Fases 11 y 13: los
commits se suben a esa rama, nunca a `main`; el usuario revisa el CI y la
fusiona). Un commit por tarea, tests en verde antes de cada commit, este
archivo y los README actualizados en cada commit. La numeración de fases
de esta sección es **propia del servicio** (Fase 1–6), no la de las 17
fases de la plataforma.

**Objetivo:** Laravel publica `email.received.v1`; el servicio Python
clasifica, (más adelante) consulta RAG y tools, redacta, calcula la
confianza y publica `email.processed.v1`; Laravel aplica el resultado.
Laravel se queda con ingesta, tenants, autenticación, panel, estados y
envío.

**Stack:** Python 3.12 + uv, FastAPI (solo salud, métricas y depuración),
Pydantic v2, LangGraph + langchain-anthropic (solo Claude), Redis Streams
con consumer groups, PostgreSQL + pgvector (SQLAlchemy 2 async / psycopg),
SDK oficial de MCP, pytest + pytest-asyncio, ruff, mypy estricto,
import-linter, logs JSON (structlog) y métricas Prometheus.

**Decisiones tomadas (aprobadas por el usuario):**
- **Laravel tiene la última palabra sobre el envío**: la `decision` de
  Python es una recomendación; Laravel la pasa por `AutoSendPolicy` +
  `ReplyContentGuard` (modo de la empresa, intenciones, anti-bucles,
  límites por remitente). `escalate` → `human_review` con su motivo.
- **Contrato ampliado** (JSON Schema único en `/contracts/events/`,
  validado en los dos lados): sobre común `event_id` (idempotencia: Redis
  Streams entrega al menos una vez), `event_type`, `event_version`,
  `occurred_at`, `trace_id` (= `request_id` de Laravel); en
  `email.processed.v1` además `language`, `reasons`, `model` y `usage`
  (tokens y coste: Laravel los guarda en `ai_requests`, de los que
  dependen los costes de Grafana y `AiSpendGuard`; Python no escribe en
  tablas de Laravel); evento aparte `email.processing_failed.v1`
  (`error_code`, `message`, `retryable`) para errores definitivos. Añadir
  campos opcionales no rompe la v1; quitar o renombrar exige v2.
- **`email.received.v1` se publica en `EmailProcessor::run()` tras los
  filtros baratos** (correo automático → ignored, modo `off`,
  `AiSpendGuard`), en el punto donde hoy se llama a la IA; el email sigue
  en PROCESSING mientras Python trabaja. Publicación *after commit* + un
  comando reconciliador que republica los que lleven N minutos en
  PROCESSING (outbox descartado en la Fase 1 por simplicidad).
- **`human_review` es un nodo final en la Fase 1.** `interrupt` +
  checkpointing (esquema propio `ai_service` en PostgreSQL) en la Fase 4,
  solo para reprocesar con una indicación de la persona
  (`email.review_resumed.v1`) y aprobar tools que modifican datos.
- Streams: conexión de Redis `streams` **sin el prefijo de claves** de
  Laravel; DLQ `*.dlq` tras N entregas; `XAUTOCLAIM` de pendientes;
  `XDEL` tras procesar y `XTRIM MINID` por antigüedad (el cuerpo del email
  es dato personal y la retención de la Fase 12 no llega a Redis).
- **Riesgo a comprobar en la tarea 1.5:** en Opus 5.5 forzar `tool_choice`
  da 400 y `with_structured_output` de langchain-anthropic fuerza una tool
  por defecto. Usar structured outputs nativos (`json_schema`) o, si no
  lo admite, el SDK oficial `anthropic` dentro del adaptador de `LLMPort`.
- Capas hexagonales comprobadas con **import-linter** (`lint-imports`):
  `entrypoints` → `agents | infrastructure | mcp_server` → `application` →
  `domain`; el dominio sin bibliotecas externas; el grafo sin adaptadores.
- Logs JSON con `level` en mayúsculas (como `level_name` de Laravel) y
  `timestamp` UTC; registro de métricas propio por app (prefijo
  `aiservice_`).

**Plan por fases del servicio:** 1 camino mínimo (abajo) · 2 RAG (rol de
PostgreSQL de solo lectura + RLS por `tenant_id`, embeddings bge-m3 en
Ollama, nodo `retrieve` con citas, tests de aislamiento) · 3 MCP + tools
(servidor MCP por streamable HTTP con CRM/ERP/tickets simulados; el cliente
inyecta `tenant_id` y remitente, el LLM nunca los elige; tools usadas →
`ai_tool_calls` en Laravel) · 4 checkpointing, `interrupt` y límites
(iteraciones, tiempo, coste) · 5 supervisor con subagentes (facturación,
pedidos, soporte) + modo `shadow` · 6 Kubernetes (Deployment, Service,
ConfigMap, Secret) probado en kind por el usuario (aquí `kubeconform`).

**Entorno de la sesión en la nube:** Python 3.12 y uv disponibles (PyPI
accesible por el proxy); Docker Hub limitado (429) y sin kind.

**Tareas de la Fase 1 (commit):**
- [x] 1.1 **Esqueleto de `ai-service/`**: proyecto uv (`pyproject.toml`,
  `uv.lock`, `.python-version` 3.12), paquete `src/ai_service` con las
  capas `domain`, `application`, `infrastructure`, `agents`, `mcp_server`,
  `entrypoints`; `config.py` (Pydantic Settings, prefijo `AI_SERVICE_`,
  inmutable, valores inválidos → error al arrancar); logs JSON con
  structlog (`infrastructure/observability/logging.py`: también uvicorn y
  la biblioteca estándar, `bind_context`/`clear_context`); métricas con
  registro propio (`metrics.py`, `aiservice_build_info`); app FastAPI
  (`entrypoints/http/app.py`: `GET /health`, `GET /metrics`, `/docs` solo
  fuera de producción) y comando `ai-service-http`. Calidad: ruff (con
  reglas de seguridad), mypy estricto en `src` y `tests`, pytest-asyncio,
  import-linter con 3 contratos. 15 tests. README del servicio
  (arquitectura con Mermaid, contrato, decisiones); README raíz (estado y
  arquitectura) y este archivo
- [x] 1.2 **Contrato de eventos** (`contracts/`, con su README): JSON
  Schema 2020-12 `common` (sobre, `category` = las 14 de `EmailIntent`,
  `llm_call`) y los 3 eventos; nivel superior `additionalProperties: true`
  (lector tolerante), `classification` y cada llamada sin extras;
  `auto_send` exige borrador (`if/then`). `email.received.v1` con
  opcionales `from_name`, `body_truncated`, `attachment_names`,
  `tenant_name`; `email.processed.v1` con `classification` (lo que guarda
  `Email::recordClassification`) y `usage.calls` (una por llamada al
  modelo → una fila de `ai_requests`). **Cambio sobre lo acordado:** el
  coste NO viaja en el evento, lo calcula Laravel con `CostCalculator`
  (una sola tabla de precios). Sin decisión "ignorar": spam/auto_reply →
  `draft` vacío + `human_review` y Laravel aplica su regla de siempre.
  Ejemplos compartidos en `contracts/events/examples/{valid,invalid}/`
  (`<evento>--<caso>.json`). Python: `domain/category.py`,
  `application/contracts/events.py` (Pydantic, `from` → `sender` por
  alias, `parse()` → `InvalidEventError`) y `tests/contract/` (esquemas
  válidos, ejemplos válidos e inválidos en esquema y modelo, categorías
  iguales al contrato, campos extra tolerados). **Lección:** `EmailStr`
  rechaza dominios `.test` (y otros reales): validación ligera `x@y` como
  el `format: email` del esquema. 41 tests
- [x] 1.3 **Dominio puro** (`domain/`, dataclasses inmutables, sin
  bibliotecas): `Email`, `TenantId`, `EmailAddress` (normalizada),
  `Category.needs_reply`, `Confidence` (validada; `clamped()` para lo del
  modelo), `Classification` (injection → `needs_human` forzado),
  `Draft` (con `pending_actions`), `Decision`/`DecisionOutcome`,
  `DomainError`. `commitments.forbidden_commitments()`: promesas de
  reembolso y cancelaciones confirmadas (es/en; informar de la política no
  cuenta). **`DecisionPolicy`** (`min_confidence` 0,85): `escalate` por
  injection, amenaza legal (regex afinada: "alta demanda" no cuenta) o
  queja urgente; `human_review` por categoría sin respuesta, clasificación
  o respuesta < umbral, la IA o la clasificación lo piden, acciones
  pendientes, email recortado, sin borrador o compromiso prohibido;
  todos los motivos acumulados, `escalate` gana. **Adelantado de la
  1.10:** job `ai-service` en GitHub Actions (uv 0.11.32 con caché,
  `uv sync --locked`, ruff, formato, mypy, lint-imports, pytest; Redis como
  servicio para los tests de la 1.7). 96 tests
- [x] 1.4 **Puertos y caso de uso** (`application/`): `ports.py`
  (`Protocol`: `LLMPort.classify/draft` en lenguaje del dominio — los
  prompts son del adaptador —, `EmailAgentPort.run`, `EventBusPort.publish`),
  `dto.py` (`LLMCall`, `ClassificationResult`, `DraftResult`,
  `AgentOutcome` con `model` = el que redactó), `errors.py` (`LLMError`
  con `code`/`retryable`/`call` facturada: `LLMUnavailableError`
  reintentable, `LLMConfigurationError`, `LLMRefusalError`,
  `LLMInvalidOutputError`; `AgentError` con todas las llamadas),
  `mappers.py` (evento ↔ dominio, recortes al tamaño del contrato) y
  `use_cases/process_email.py` (`ProcessEmail`: definitivo → publica
  `processing_failed`; temporal → relanza para reintento; `DomainError` →
  fallo `internal`; si falla la publicación se relanza). Fakes en
  `tests/fakes.py` (`FakeLLM` guionizado, `FakeAgent`,
  `InMemoryEventBus`) y `tests/support/contracts.py` (validar contra el
  esquema). 104 tests
- [x] Fix (CI en rojo en la 1.3): `test_defaults_are_safe_for_local_development`
  leía `AI_SERVICE_ENVIRONMENT=testing` del job. `tests/conftest.py`
  borra las `AI_SERVICE_*` de quien lanza los tests (salvo
  `AI_SERVICE_TEST_*` y `AI_SERVICE_CONTRACTS_DIR`). **Lección:** probar en
  local con las variables del CI exportadas
- [x] 1.5 **Adaptador de Claude** (`infrastructure/llm/anthropic.py`,
  langchain-anthropic 1.7.6 + anthropic 1.15): **comprobado** que
  `with_structured_output(method="json_schema", include_raw=True)` envía
  `output_config.format` (sin `tool_choice`) y `reasoning_effort` →
  `output_config.effort`; el esquema necesita `title`. `ChatAnthropic` por
  operación (clasificar `low`/4096, redactar `medium`/8000),
  `fallbacks: "default"` vía `model_kwargs` + beta
  `server-side-fallback-2026-07-01` (`AI_SERVICE_ANTHROPIC_FALLBACKS`).
  `stop_reason` `refusal` → `LLMRefusalError` (categoría de
  `stop_details`), `max_tokens`/JSON inválido/intención desconocida →
  `LLMInvalidOutputError`; errores del SDK → 429/5xx/529/red
  reintentables, 401/403 y otros 4xx → configuración con el mensaje de la
  API; siempre con la `LLMCall` facturada (o `failed` con 0 tokens). Con
  fallback, **una `LLMCall` por iteración** (`usage.iterations`; las
  anteriores a la última `refused`). **Cambio en los puertos:**
  `call` → `calls` (tupla) en resultados y `LLMError`; `LLMCall.prompt` y
  campo opcional `prompt` (`clave@versión`) en `llm_call` del contrato.
  Prompts versionados en `infrastructure/llm/prompts.py`:
  `email_classification@1` (portado de Laravel) y `email_draft@1` (Fase 1:
  sin herramientas, no afirma pedidos/plazos/precios → persona);
  `tests/unit/test_prompts.py` fija el hash de cada versión. Config
  nueva (`AI_SERVICE_ANTHROPIC_*`, efforts, max_tokens, timeout,
  reintentos, `MAX_BODY_CHARS`, `MIN_CONFIDENCE`). Tests con el SDK y
  langchain reales sobre `httpx2.MockTransport` (inyectado en el
  `_async_client` de `ChatAnthropic`; **anthropic 1.x usa httpx2**, no
  httpx). 129 tests
- [x] 1.6 **Grafo LangGraph** (`agents/email_graph.py`, langgraph 1.2):
  `LangGraphEmailAgent` (implementa `EmailAgentPort`) con `StateGraph`
  `EmailState` (TypedDict; `calls` y `visited` con reductor
  `operator.add`): `classify` → arista condicional **`router`**
  determinista (injection o spam/auto_reply → `validate` sin borrador;
  resto → `draft`) → `validate` (`DecisionPolicy`) → `END` si
  `auto_send`, si no `human_review` (nodo final) → `END`. Los nodos que
  llaman al modelo convierten `LLMError` en `AgentError` con todas las
  llamadas del estado; `asyncio.timeout` del grafo
  (`AI_SERVICE_AGENT_TIMEOUT_SECONDS`, 240) → `AgentError` reintentable;
  `recursion_limit` 10. **Lección mypy:** los nodos deben tipar el
  parámetro con nombre `state` (Protocol `Node`), un `Callable` no
  encaja en las sobrecargas de `add_node`. Raíz de composición
  `entrypoints/container.py` (`build_llm`, `build_agent`).
  **`POST /debug/process`** (`entrypoints/http/debug.py`): solo con
  `AI_SERVICE_DEBUG_ENDPOINTS=true` y fuera de producción; agente
  creado al primer uso; sin clave → 503; errores del modelo → 422/502.
  README con el diagrama Mermaid generado por LangGraph. 147 tests
- [x] 1.7 **Redis Streams y worker.** `infrastructure/events/redis_streams.py`:
  `RedisStreamsEventBus` (implementa `EventBusPort`; stream =
  `<prefijo><event_type>.v<versión>`, campos `event_id`/`event_type`/
  `payload`, `MAXLEN ~ 100000`) y `RedisStreamConsumer` (grupo creado con
  `id=0` + `MKSTREAM`, `XREADGROUP`, `XAUTOCLAIM` + nº de entregas con
  `XPENDING`, `ack` = `XACK`+`XDEL` en pipeline, `dead_letter` a
  `<stream>.dlq`, `XTRIM MINID` **exacto** — el aproximado no borraba
  nada con pocas entradas —, `XINFO GROUPS` pending/lag).
  `entrypoints/consumer.py` (`EventConsumer`): abandonados primero, luego
  nuevos (hasta `concurrency`), `gather`; duplicado por `event_id`
  (`aiservice:processed:<id>`, 24 h) → confirma sin llamar al modelo;
  contrato roto → DLQ; temporal → sin confirmar (reintento al reclamarlo
  pasado `claim_idle_ms`); última entrega → `processing_failed`
  (`timeout`) o `internal` + DLQ; logs con `trace_id`/`tenant_id`/
  `email_id`/`event_id`; métricas `aiservice_events_total`,
  `event_processing_seconds`, `decisions_total`, `llm_calls_total`,
  `llm_tokens_total`, `llm_call_seconds`, `stream_pending`, `stream_lag`,
  latido. `entrypoints/worker.py` (`ai-service-worker`): consumidor +
  uvicorn en el mismo bucle (señales propias: se anula
  `uvicorn.Server.capture_signals`), parada limpia con SIGTERM, `/ready`
  (Redis + latido < 60 s), sin clave → log `worker_misconfigured` y
  salida 1. Config `AI_SERVICE_REDIS_URL` (BD 3, sin prefijo) y del
  consumidor. Tests contra Redis real (`AI_SERVICE_TEST_REDIS_URL`, BD 15
  vaciada; sin ella se saltan): flujo completo, grupo creado tarde, DLQ,
  duplicados, fallo definitivo, reintentos con `XAUTOCLAIM`, error
  inesperado, concurrencia, empresa de cada resultado, recorte, parada y
  el worker completo con `/ready` y `/metrics`. 161 tests
- [x] 1.8 **Laravel: publicador** (`app/AI/Remote/`). `AIEngine`
  (`config ai.engine` = `AI_ENGINE`, laravel por defecto), interfaz
  `Contracts\EventStream` + `RedisEventStream` (phpredis 6.3 en la
  conexión **`streams`**: `REDIS_STREAMS_DB` 3, `options.prefix` vacío,
  `read_timeout` 30; `xAdd` con MAXLEN ~, `xGroup CREATE ... 0 MKSTREAM`,
  `xReadGroup`, `xAutoClaim` + `xPending` para las entregas, ack =
  `xAck`+`xDel` en MULTI, DLQ `<stream>.dlq`, `XTRIM MINID` exacto),
  `Data\StreamMessage`, `EventContract` (opis contra
  `contracts/events/*.schema.json` registrados por `$id`;
  `InvalidEventException`) y **`EmailEventPublisher`** (evento con texto
  plano — HTML → `HtmlToText` —, recorte a `ai.classification.max_body_chars`
  con `body_truncated`, adjuntos, `tenant_name`, `trace_id` = `request_id`
  del Context o uno nuevo; valida contra el contrato; guarda
  `ai_event_id`/`ai_requested_at`/`ai_request_attempts` y publica
  **after commit**; si Redis falla, warning y sigue en processing).
  Migración `add_remote_ai_to_emails` (+ índice status+ai_requested_at).
  **`EmailProcessor`**: con el motor remoto no exige `ANTHROPIC_API_KEY`
  en Laravel y, tras automático/modo off/`AiSpendGuard`, publica y deja el
  email en processing (un reintento del job no republica dentro del
  plazo; `reprocess` sí; sin remitente válido → human_review);
  `ignoreReason()` pasa a pública (la usará el consumidor). Comando
  **`ai:reconcile-remote`** (cada 5 min, `withoutOverlapping`): en
  processing con `ai_requested_at` > `ai.remote.reconcile_after_minutes`
  (15) → republica con evento nuevo; con `max_publish_attempts` (3)
  agotados → failed; si se volvió a `AI_ENGINE=laravel` → `ProcessEmailJob`
  (lo termina el motor de Laravel). **Contrato:** `causation_id` opcional
  (nullable) en `email.processed.v1` y `email.processing_failed.v1`
  (= `event_id` del `email.received.v1`; Python lo rellena). Tests:
  `EventContractTest` (los ejemplos compartidos), `RemotePublishingTest`
  (16, con `Tests\Fakes\InMemoryEventStream`) y `RedisEventStreamTest`
  (Redis real, BD 14 de `phpunit.xml`). **Lecciones:** en datasets de
  Pest no hay `base_path()` al cargar el archivo (ruta con
  `dirname(__DIR__, n)`); el facade `Redis` tapa la clase `\Redis`;
  **Pint no se aplica en este repo** (reformatea líneas ajenas: `' . '`
  → `'.'`), no pasarlo. Suite Laravel: 1156 en verde y los mismos 25
  fallos del entorno de la nube (pgvector 0.6, BD en paralelo)
- [x] 1.9 **Laravel: consumidor de resultados.**
  `App\AI\Remote\RemoteResultHandler`: valida contra el contrato, busca el
  email (global) y **exige que su empresa sea la del `tenant_id` del
  evento** (`TenantMismatchException` → cola de errores + `Log::critical`,
  nunca se aplica); dentro del tenant y en transacción con
  `lockForUpdate`, solo si sigue en processing (si no, `stale`); guarda
  **una fila de `ai_requests` por llamada** (coste con
  `CostCalculator::forUsage` = precios de Laravel; `response` =
  `{source: ai-service, prompt}`; así Grafana, la auditoría y
  `AiSpendGuard` ven el gasto del servicio); `processed` →
  `recordClassification` (con la petición de clasificación) → spam/
  auto_reply con confianza alta → ignored (`EmailProcessor::ignoreReason`)
  → sin borrador → human_review ("La IA lo escala…"/"pide revisión…") →
  con borrador: `email_replies` draft (`needs_human` si la decisión no es
  `auto_send`, motivos) → ai_review → **`AutoSendPolicy`** (la última
  palabra es de Laravel) → `ReplySender::approve` o human_review;
  `processing_failed` → `llm_config` failed, resto human_review.
  Comando **`ai:consume-results {--once}`** (proceso largo; `trap`
  SIGTERM/SIGINT): grupo `laravel` en `email.processed.v1` y
  `email.processing_failed.v1`, reclama abandonados (`claim_idle_ms`
  60 s), lee, aplica y confirma; contrato roto o empresa distinta → DLQ;
  error inesperado → sin confirmar y, con `max_deliveries` (3), DLQ;
  recorta los streams cada minuto (`retention_hours` 168). Métrica
  `ai_remote_results_total{event,result}`. Helpers de tests
  `processRemote()`/`remoteStatusPath()` movidos a `tests/Pest.php`
  (**lección:** los helpers definidos en otro archivo de test no existen
  si se lanza un archivo suelto). Tests `RemoteResultsTest` (13: envío
  automático con costes y clasificación, modo borrador manda, revisión
  con motivos, spam ignorado, escalado, fallos, empresa distinta,
  contrato roto, duplicado, email borrado, reintento → DLQ, el gasto del
  servicio cuenta para el presupuesto). Suite Laravel: 1169 en verde y
  los mismos 25 del entorno
- [x] Revisión del CI (pedida por el usuario, 2026-10-17): solo fallaron
  los pushes de la 1.3 y la 1.4 (el test de valores por defecto, ya
  arreglado en `4e7e678`); 1.5–1.9 en verde en los 4 jobs, 162 tests de
  ai-service sin saltos (Redis real en el CI). **Regla desde ahora:
  comprobar el CI tras cada push y arreglarlo antes de seguir.**
- [x] 1.10 **Docker, compose, observabilidad y CI.** `ai-service/Dockerfile`
  multi-stage (builder: `pip install uv==0.11.32` — ghcr bloqueado en la
  nube y una dependencia menos —, `uv sync --locked --no-dev
  --no-install-project` y luego el paquete, `--no-editable`; runtime
  `python:3.12-slim-bookworm` con el `.venv`, usuario `app` uid 10001,
  `HEALTHCHECK` /health, `CMD ai-service-worker`; ~370 MB) +
  `.dockerignore`. Construida y probada aquí (con una copia del
  Dockerfile que añade el CA del proxy): solo lectura, sin capacidades,
  uid 10001, `/ready` ok, parada limpia. Compose: servicios
  **`ai-service`** y **`ai-results`** (`php artisan ai:consume-results`)
  en el **perfil `ai-service`** (`COMPOSE_PROFILES=ai-service`);
  ai-service con `read_only`, `tmpfs /tmp`, `cap_drop: ALL`,
  `no-new-privileges`, `stop_grace_period` 60 s, puerto
  `127.0.0.1:8001`, Redis `redis://:<pass>@redis:6379/<REDIS_STREAMS_DB>`,
  clave = `ANTHROPIC_API_KEY` del `.env`. Prometheus: job `ai-service`
  por **DNS SD** (sin perfil no hay target). Promtail: etiqueta
  `service` (servicio del compose) y `match {service="ai-service"}` →
  JSON con `level` como etiqueta y hora del log. Dashboard
  **`ai-service.json`** (uid `aimail-ai-service`, 18 paneles, generado
  con un script; las 14 consultas probadas contra un Prometheus real).
  Alertas (grupo `ai-service`, 6 reglas con pruebas en
  `alerts.test.yml`, `promtool` SUCCESS): `ServicioIANoResponde`,
  `ConsumidorIAParado`, `ColaIAAtascada`, `EmailsIAFallidos`,
  `ResultadoIAEmpresaIncorrecta` (crítica), `ResultadosIAEnColaDeErrores`.
  CI del job `ai-service`: **pip-audit** (`uv export --no-emit-project`
  con hashes; 0 vulnerabilidades) y **construir + arrancar la imagen**
  (`/ready`, `/health`, uid 10001). `.env.docker.example`:
  `COMPOSE_PROFILES`, `AI_SERVICE_ENVIRONMENT`, `AI_SERVICE_LLM_PROVIDER`,
  `AI_SERVICE_DEBUG_ENDPOINTS`
- [x] 1.11 **IA simulada, prueba de punta a punta y README.**
  `infrastructure/llm/simulated.py` (`SimulatedLLM`, mismas reglas por
  palabras clave que el simulado de Laravel; modelo `simulated`; solo
  `product_question` con confianza 0,9) y `AI_SERVICE_LLM_PROVIDER`
  (`build_llm` lo prohíbe fuera de local/testing). **Prueba real en la
  sesión** (PostgreSQL + Redis + GreenMail, Laravel con
  `AI_ENGINE=python` en PHP 8.4 contra la BD `app`, worker con la IA
  simulada): 4 correos por SMTP → `mail:sync` → publicados → procesados
  → `ai:consume-results`: Canarias **enviada sola** (llegó al cliente con
  `In-Reply-To` y `Auto-Submitted`), pedido → human_review, spam →
  ignored, queja con abogado → escalate → human_review (y la empresa no
  permite `complaint`); 7 `ai_requests` con coste. **Bug encontrado:**
  redis-py 8 corta `XREADGROUP BLOCK 5000` por su timeout de lectura por
  defecto (los tests usaban BLOCK 100 ms) → `worker.redis_client()` con
  `socket_timeout = block + 10 s` + test de regresión (BLOCK 6 s).
  README del servicio: resumen para entrevista, resultados de la prueba,
  cómo reproducirla; README raíz (estado y arquitectura). **Lección:**
  `pkill -f <patrón>` mata también el propio shell si su línea contiene
  el patrón (guardar el PID). 174 tests de ai-service

**Resumen de la Fase 1:** 11 tareas + 1 fix de CI, 174 tests de Python
(contrato, dominio, aplicación, grafo, adaptador de Claude con HTTP
simulado, IA simulada, Redis real, worker), 39 tests nuevos de Pest
(contrato, publicación y reconciliador, resultados, Redis real) y 6
alertas probadas con `promtool`. **Pendiente para cerrarla:** que el
usuario la pruebe en su equipo (ver "Prueba de punta a punta" del README
del servicio) y, si quiere, con Claude real
(`AI_SERVICE_LLM_PROVIDER=anthropic`). Siguiente: Fase 2 (RAG) cuando lo
apruebe.

### AI-Service, Fases 2 (RAG) y 3 (MCP + tools): COMPLETADAS Y PROBADAS DE PUNTA A PUNTA CON LA IA SIMULADA (2026-10-17), pendiente de que las pruebe el usuario

Misma rama `fase-ai-service` (pedido por el usuario: son cambios sobre lo
que ya existe). Misma forma de trabajo: un commit por tarea, tests en
verde, **CI comprobado tras cada push**, documentación al día.

**Decisión (aprobada, opción A):** el RAG de Python usa el **mismo motor
de embeddings** (Ollama + `bge-m3`, 1024 dimensiones, vectores
normalizados, pregunta = asunto + cuerpo, máx. 2000 caracteres) pero
**solo para buscar**: la indexación (subir, extraer, trocear, embeddings y
guardar) **sigue en Laravel**, único escritor de `documents` y
`document_chunks`. Python **lee** con un rol de PostgreSQL de solo
lectura (`ai_service_reader`, permisos por columna) y **RLS por
`tenant_id`** (`SET LOCAL app.tenant_id`; sin él no ve nada), además del
filtro explícito por empresa en la consulta, y solo fragmentos cuyo
`documents.embedding_model` sea el suyo. **No se borra nada del RAG ni de
las tools de Laravel**: los usa el motor por defecto (`AI_ENGINE=laravel`)
y el panel. Se retirarán en una tarea aparte cuando el motor de Python
iguale al de Laravel (entonces se valorará mover la indexación a Python
con eventos `document.uploaded.v1` / `document.indexed.v1`).

**Tareas:**
- [x] 2.1 **Rol de solo lectura + RLS.** `contracts/database/knowledge_base_reader.sql`
  (con README): `GRANT SELECT` **por columna** a `ai_service_reader`
  (`documents`: id, tenant_id, title, status, embedding_model;
  `document_chunks`: id, tenant_id, document_id, position, content,
  metadata, embedding), `ENABLE ROW LEVEL SECURITY` en ambas tablas y
  política `ai_service_tenant_isolation` (`FOR SELECT TO
  ai_service_reader USING tenant_id = NULLIF(current_setting('app.tenant_id',
  true), '')::bigint`: sin valor no ve nada). Sin `FORCE`: no afecta al
  dueño (Laravel). Migración `add_knowledge_base_reader`: crea el rol
  (`LOGIN NOINHERIT`, contraseña `ai.remote.db_password` =
  `AI_SERVICE_DB_PASSWORD`) si no existe y el usuario puede crear roles
  (DO + `duplicate_object`: los tests en paralelo migran varias BD a la vez
  y el rol es del clúster); si no existe, aviso y no aplica permisos; luego
  ejecuta el SQL compartido. `down` quita políticas, RLS y permisos (el rol
  no se borra). Tests `KnowledgeBaseReaderTest` (12, con `SET LOCAL ROLE`
  dentro de la transacción del test): sin tenant no ve nada, solo su
  empresa (ni con `WHERE tenant_id` de otra), búsqueda vectorial posible,
  sin acceso a `path`/`sha256`/`uploaded_by`/otras tablas, no puede
  escribir, Laravel ve todo, columnas del contrato presentes. Suite: 1181
  en verde + los 25 del entorno
- [x] 2.2 **Python: embeddings y búsqueda.** `domain/knowledge.py`
  (`KnowledgeChunk` con `source` igual que Laravel), puertos
  `EmbeddingPort` (`model`, `embed_query`) y `VectorStorePort.search(tenant_id,
  vector, *, embedding_model, limit, min_similarity)`, DTO
  `EmbeddingResult`/`RetrievalResult`, `KnowledgeBaseError`.
  `application/services/knowledge.py` (`KnowledgeRetriever`: pregunta =
  asunto + cuerpo, 2000 caracteres; 5 resultados, ≥ 0,45; fallos de
  Ollama/PostgreSQL → `failed` sin lanzar). `OllamaEmbeddings` (httpx;
  mismo `/api/embed` con `truncate`, normalizado, dimensiones; conexión →
  `LLMUnavailableError`, 404 → configuración con el `ollama pull`;
  `LLMCall` `ai_service.knowledge_search`). `PgVectorStore` (SQLAlchemy
  2.1 async + psycopg 3.3: `set_config('app.tenant_id')` +
  `hnsw.iterative_scan` en la transacción, `WHERE tenant_id` en
  `document_chunks` y `documents`, `ready`, mismo `embedding_model`,
  distancia `<=>` con umbral, reordenado). Tests: Ollama con
  `httpx.MockTransport` y pgvector contra PostgreSQL real
  (`AI_SERVICE_TEST_DATABASE_URL`; fixture con `tests/fixtures/knowledge_schema.sql`,
  el rol y el **mismo SQL de `contracts/database/`**): aislamiento entre
  empresas, RLS como segunda barrera, sin escritura ni columnas extra,
  `ready` + modelo, umbral/límite, error de BD. CI: servicio
  `pgvector/pgvector:pg16` en el job `ai-service`. 190 tests
- [x] 2.3 **Nodo `retrieve` y citas.** Contrato (`email.processed.v1`,
  opcionales): `sources` ([{type document|order|ticket|customer,
  reference}]), `tool_calls` ([{tool, arguments, result, status
  success|error|rejected, error, duration_ms}]) y `duration_ms`; ejemplo
  `--with-rag` y dos inválidos. Python: `Source`/`SourceType` en `Draft`,
  `Evidence` (referencias consultadas + problemas) en `DecisionPolicy`
  (**cita a algo no consultado → human_review**; problemas de consulta →
  motivos), `ToolCallRecord`, `DraftContext`, `AgentOutcome.tool_calls` /
  `duration_ms` (`tools_used` = las que fueron bien). `LLMPort.draft(email,
  classification, context)`. Prompt **`email_draft@2`** (documentación en
  `<documentacion>` con el formato de `KnowledgeContext::toPrompt()`,
  `neutralize_docs`, `sources` en el esquema). Grafo: router → `retrieve`
  (búsqueda registrada como herramienta `search_company_documents` con los
  fragmentos; fallo → `knowledge_unavailable` + motivo, sin bloquear) →
  draft → validate. Config RAG (`AI_SERVICE_DATABASE_URL` del lector,
  `OLLAMA_URL`, `EMBEDDING_*`, `RAG_LIMIT`, `RAG_MIN_SIMILARITY`); sin URL,
  sin RAG. IA simulada: confía solo si hay documentación (usa su texto y la
  cita). **Laravel** (`RemoteResultHandler::recordAgentRun`): con borrador o
  herramientas crea `ai_agent_runs` (completed, end_turn, pasos, tokens,
  coste, duración, `final_output` con `source: ai-service`), enlaza las
  peticiones (menos la clasificación) y guarda `ai_tool_calls` colgadas de
  la búsqueda; la respuesta lleva `agent_run_id` y `sources`. Así
  **`ReplyContentGuard`** usa lo consultado: 14,95 € de la documentación →
  enviada sola; 9,99 € inventado → revisión. 198 tests de Python; Laravel
  1186 + los 25 del entorno
- [x] 3.1 **Servidor MCP** (`mcp_server/`, SDK oficial **`mcp` 2.3**: en
  2.x `FastMCP` pasó a `mcp.server.mcpserver.MCPServer`, los modelos usan
  snake_case — `input_schema`, `is_error`, `structured_content` — y los
  fallos previstos se lanzan con `ToolError`; cualquier otra excepción
  llega como "Error executing tool"). `backends.py` (`DemoData`: pedidos
  del **mismo `database/seeders/demo/orders.json` de Laravel**, CRM
  derivado, tickets en `mcp_server/data/tickets.json`; todo por
  `tenant_id` + `customer_email`; pedido ajeno = inexistente;
  `normalize_order_number` como Laravel; sin dirección completa).
  `server.py`: herramientas `get_customer`, `get_order`,
  `get_customer_orders`, `get_customer_tickets` (`read_only_hint`) y
  `create_support_ticket` (`destructive_hint`, no persiste, no se ofrece
  hasta la Fase 4); `build_app` = `streamable_http_app(stateless_http=True)`
  + `/health` + middleware ASGI `BearerAuth` (`hmac.compare_digest`; sin
  token no arranca). `config.py` (`McpSettings`, prefijo `AI_SERVICE_MCP_`:
  token, puerto 8100, ficheros, `demo_tenants`). Arranque en
  **`entrypoints/mcp.py`** (`ai-service-mcp`): import-linter rompía el
  contrato de capas si `mcp_server` importaba los logs de
  `infrastructure`. Tests: backends (normalización, pedido propio, ajeno/
  inexistente/otra empresa = mismo error, listados y tickets acotados) y
  servidor (lista con anotaciones, llamada con contexto, pedido ajeno sin
  datos, HTTP con y sin token, no arranca sin token). 216 tests
- [x] 3.2 **`ToolPort` y cliente MCP.** `ToolContext(tenant_id, customer)`
  en `application/dto.py` (lo construye el backend, nunca el modelo);
  `ToolPort` (`available`, `call(tool, arguments, context) ->
  ToolCallRecord`, nunca lanza). `infrastructure/tools/mcp_client.py`
  (`McpToolClient`): `Client(streamable_http_client(url, http_client=
  httpx2.AsyncClient(headers={Authorization: Bearer})))` por llamada
  (servidor sin estado); solo `READ_ONLY_TOOLS`; argumentos con
  `tenant_id`/`customer_email` → `rejected`; inyecta el contexto; timeout
  (`asyncio.timeout`, 10 s); `is_error` → `error` con el mensaje; red/token/
  protocolo → `error` "servicio no disponible"; resultado JSON recortado a
  8000 caracteres; registra los argumentos sin el contexto. Tests contra el
  servidor real por HTTP: pedido propio, ajeno sin datos, mismo cliente en
  otra empresa sin nada, contexto en argumentos rechazado, escritura
  rechazada, token malo y servidor caído como error. 222 tests
- [x] 3.3 **Nodo `tools` y prompt con datos.** `application/services/
  tool_planner.py`: plan **determinista** por intención
  (`plan_tool_calls`: pedido mencionado → `get_order`, máx. 3; sin número →
  `get_customer_orders`; factura/cobro → también sus pedidos; soporte/queja
  → `get_customer_tickets`; cambio de cuenta → `get_customer`; solo las que
  ofrece el servidor) y `references_in` (pedidos, tickets y cuenta que una
  respuesta puede citar). Grafo: retrieve → **tools** → draft;
  `ToolContext` desde el email (empresa y remitente), llamadas en paralelo,
  tope `AI_SERVICE_MAX_TOOL_CALLS` (5); consulta con error o sin encontrar
  → `issues` → revisión humana; `Evidence` incluye lo devuelto por las
  herramientas. `DraftContext.tool_results`. Prompt **`email_draft@3`**:
  bloque `<datos>` (`<consulta nombre estado>` + JSON; `<datos>`,
  `<consulta>` y `<email>` neutralizados), estados/fechas/importes solo de
  ahí, fuentes `order`/`ticket`/`customer`. IA simulada usa los pedidos
  (estado, transportista, seguimiento; cita el pedido). Config
  `AI_SERVICE_MCP_URL/TOKEN/TIMEOUT_SECONDS` (`build_tools`: URL sin token
  → error de configuración). El contrato y Laravel no cambian: los
  `tool_calls` ya iban a `ai_tool_calls` y `ReplyContentGuard` encuentra
  en ellos pedidos e importes (2 tests Pest nuevos: pedido consultado →
  enviada sola; pedido que nadie devolvió → persona). Tests: planificador,
  nodo (contexto, cita de pedido consultado/no consultado, error,
  tope, sin herramientas, injection sin herramientas), bloque `<datos>`,
  adaptador de Claude con datos, IA simulada, contenedor y **grafo + IA
  simulada + servidor MCP real** (pedido propio enviado solo con
  seguimiento; ajeno a persona sin sus datos). 249 tests
- [x] 3.4 **Compose, CI, README y prueba de punta a punta.** Servicio
  **`ai-mcp`** (perfil `ai-service`, misma imagen, `ai-service-mcp`, sin
  puertos publicados, token `AI_SERVICE_MCP_TOKEN` —`local-mcp-token` en
  local—, `orders.json` montado de solo lectura, healthcheck sobre
  `:8100/health`, endurecido como `ai-service`). `ai-service`:
  `AI_SERVICE_DATABASE_URL` con `ai_service_reader` +
  `AI_SERVICE_DB_PASSWORD`, `OLLAMA_URL`/`OLLAMA_EMBEDDING_MODEL`,
  `AI_SERVICE_MCP_URL=http://ai-mcp:8100`; espera a PostgreSQL y `ai-mcp`
  sanos. Promtail parsea también el JSON de `ai-mcp`. CI: prueba de la
  imagen como servidor MCP (`/health`, 401 sin token). Cliente MCP: quita
  el prefijo "Error executing tool X:" del SDK. **Prueba en Docker** (imagen
  construida, `ai-mcp` + worker con IA simulada + Laravel con
  `AI_ENGINE=python` + GreenMail): pedido propio #45821 → `get_order` →
  enviada sola con transportista y seguimiento; pedido ajeno #76540 →
  revisión humana sin sus datos; Laura sin número → `get_customer_orders`
  → enviada sola con #78123. RAG: sin Ollama en la sesión, probado con
  PostgreSQL real y embeddings simulados. README del servicio (compose,
  prueba, hoja de ruta, resumen de entrevista) y README raíz

**Pendiente para dar las Fases 2 y 3 por cerradas:** que el usuario
revise la rama `fase-ai-service`, el CI en verde y lo pruebe en su equipo:
`docker compose up -d --build ai-service ai-mcp ai-results`, `migrate`
(rol `ai_service_reader`), `ollama pull bge-m3` si no lo tenía y
`AI_SERVICE_MCP_TOKEN` en el `.env`. Siguiente: Fase 4 (checkpointing,
`interrupt`, límites y herramientas de escritura con aprobación).

### AI-Service, Fase 4 (checkpointing, aprobación humana y límites): COMPLETADA Y PROBADA DE PUNTA A PUNTA CON LA IA SIMULADA (2026-10-17), pendiente de que la pruebe el usuario

Misma rama `fase-ai-service`, misma forma de trabajo (un commit por tarea,
tests en verde, CI comprobado tras cada push, documentación al día).

**Objetivo:** que el agente pueda **proponer acciones que modifican datos**
(crear un ticket de soporte) sin ejecutarlas hasta que una persona las
apruebe en el panel, con el grafo **en pausa** (checkpoint de LangGraph
en PostgreSQL) y reanudado con la decisión; que una persona pueda
**reprocesar con una indicación**; y **límites** del agente (tokens,
pasos) que mandan el caso a una persona en vez de fallar.

**Decisiones tomadas:**
- **`interrupt` + checkpointing solo para las acciones con aprobación.**
  Cambio sobre lo anotado en la Fase 1: **reprocesar con una indicación NO
  reanuda un checkpoint**; Laravel publica otro `email.received.v1` con
  `review_instruction` y el grafo empieza de nuevo. Motivos: entre la
  revisión y el reproceso pueden pasar horas o días y el estado del pedido
  o la documentación cambian (reanudar respondería con datos viejos), y
  así no quedan miles de ejecuciones en pausa (con datos personales) por
  cada email que va a revisión. La clasificación se paga otra vez
  (~0,01 $), asumible.
- Las acciones las **planifica el backend de forma determinista** (como
  las consultas de la Fase 3): p. ej. soporte técnico sin ticket abierto →
  proponer `create_support_ticket` con asunto, descripción y prioridad
  sacados del email y la clasificación. El modelo nunca elige la acción ni
  sus argumentos; empresa y cliente los inyecta el cliente MCP al
  ejecutarla, y solo por el camino "aprobada".
- Checkpoints con **`langgraph-checkpoint-postgres`** (`AsyncPostgresSaver`)
  en un **esquema propio `ai_service`** con un **rol propio**
  (`ai_service_checkpoints`, solo ese esquema; el rol de solo lectura del
  RAG no cambia). `durability="exit"` (se guarda al pausar o terminar, no
  en cada nodo) y el hilo se borra al terminar; los que esperan aprobación
  se purgan tras N días (`expired` si alguien los reanuda después).
- Ejecución de la acción **idempotente** (clave = `action_id`): una
  reanudación repetida (Redis entrega al menos una vez) no crea dos
  tickets.
- Límites: **presupuesto de tokens por email** (los precios están en
  Laravel: Python limita tokens, no dólares), nº máximo de pasos del grafo
  y de reanudaciones; superarlos → revisión humana con el motivo.

**Tareas:**
- [x] 4.1 **Contrato.** `common`: `thread_id` (`[A-Za-z0-9._:-]{8,128}`) y
  `pending_action` (`action_id` UUID, `tool`, `arguments`, `summary` para
  la persona; sin extras). `email.processed.v1`: opcionales `thread_id` y
  `pending_action`; con acción pendiente, `thread_id` obligatorio y
  `decision` ≠ `auto_send` (`allOf` con el `if/then` anterior).
  `email.received.v1`: opcional `review_instruction` (máx. 2000).
  `email.processing_failed.v1`: `error_code` `expired` (valor nuevo de
  enum: los dos lados cambian a la vez). Evento nuevo
  **`email.review_resumed.v1`** (Laravel → ai-service: `thread_id`,
  `action_id`, `approved`, `reviewer_id` opcional). Ejemplos válidos
  (acción pendiente, indicación, reanudación aprobada y rechazada,
  `expired`) e inválidos (acción sin `thread_id`, acción con `auto_send`,
  campo extra en la acción, indicación demasiado larga, reanudación sin
  `thread_id`, `action_id` no UUID, `approved` no booleano, `thread_id`
  con espacios). Python: `PendingActionPayload`, `EmailReviewResumed`
  (`approved` con `StrictBool`: "yes" no vale), validación de coherencia
  en `EmailProcessed`. Laravel: `EventContract` y sus tests recogen el
  esquema y los ejemplos nuevos solos. `contracts/README.md`: flujo de
  aprobación. 43 tests de contrato (Python) y 89 de `tests/Feature/AI/Remote`
- [x] 4.2 **Checkpoints en PostgreSQL.** `langgraph-checkpoint-postgres`
  3.1.2 (la 3.2 tenía 3 días; regla de versiones con al menos dos
  semanas) + `psycopg-pool`. `contracts/database/ai_service_checkpoints.sql`:
  esquema `ai_service` propiedad del rol `ai_service_checkpoints`, `REVOKE
  ALL ... FROM PUBLIC`. Migración `add_ai_service_checkpoints` (crea el rol
  como la del lector, contraseña `ai.remote.checkpoints_db_password` =
  `AI_SERVICE_CHECKPOINTS_DB_PASSWORD`; `down` borra el esquema).
  **Bug encontrado con los tests en paralelo:** `ALTER ROLE ... SET
  search_path` desde varias migraciones a la vez → "tuple concurrently
  updated" (el rol es del clúster) → quitado; ai-service fija
  `search_path` al conectar. Python: `infrastructure/checkpoints/postgres.py`
  (`PostgresCheckpoints.open()` con `AsyncConnectionPool` —autocommit,
  `prepare_threshold=0`, `dict_row`, `search_path`— y `setup()`;
  `delete(thread_id)`, `purge(horas)` por el `ts` del último checkpoint de
  cada hilo, `ping()`). Config `AI_SERVICE_CHECKPOINT_DATABASE_URL` y
  `AI_SERVICE_CHECKPOINT_TTL_HOURS` (336). Worker: lo abre si está
  configurado, `/ready` con `checkpoints`, purga en el mantenimiento del
  consumidor. Compose: URL del rol en `ai-service`. Tests: Laravel
  `AiServiceCheckpointsTest` (8: esquema del rol, crea y usa sus tablas,
  sin acceso a `emails`/`users`/`tenants`/`documents`/`ai_requests`, no
  crea fuera de su esquema) y Python contra PostgreSQL real (10: tablas en
  su esquema y ninguna en `public`, **un grafo con `interrupt` en pausa se
  reanuda tras reiniciar el worker**, borrar, purga solo de lo antiguo, el
  rol no toca tablas de Laravel, `ping`, worker y mantenimiento). 272
  tests de Python; Laravel 1209 + los 25 del entorno
- [x] 4.3 **Límites del agente.** `AI_SERVICE_MAX_TOKENS_PER_EMAIL`
  (60.000: entrada + salida de todas las llamadas del email, también las
  fallidas; las búsquedas de embeddings locales —`FREE_PURPOSES`— no
  cuentan): antes de redactar, si se alcanzó, no se llama al modelo y va a
  revisión humana con el motivo ("alcanzó su límite de tokens (X de Y)").
  `AI_SERVICE_MAX_GRAPH_STEPS` (15) como `recursion_limit`;
  `GraphRecursionError` → `AgentLimitError` (código `internal`, no
  reintentable). Log `agent_limit_reached` con el límite. **Sin límite de
  reanudaciones**: cada pausa espera una sola acción y una reanudación
  repetida encuentra el hilo terminado (`expired`, tarea 4.5). Tests: sin
  redactar al superar el presupuesto, embeddings fuera del presupuesto,
  límite de pasos no reintentable, el contenedor pasa los límites. 276
  tests
- [x] 4.4 **Acción con aprobación (`interrupt`).** `PendingAction`
  (`action_id` = clave de idempotencia, `tool`, `arguments`, `summary`) y
  `AgentOutcome.pending_action`/`thread_id`; `calls` = solo las llamadas de
  esa ejecución. Errores `ResumeExpiredError` (código `expired`) y
  `ThreadMismatchError`. Puertos: `ActionPort` (`available_actions`,
  `execute`) y `EmailAgentPort.run(email, *, thread_id)` + `resume(thread_id,
  *, tenant_id, email_id, action_id, approved)`. `action_planner.plan_action`
  (determinista: soporte técnico, tickets consultados sin ninguno abierto →
  `create_support_ticket` con asunto, resumen + pedidos, prioridad alta si
  urgencia alta). Grafo: tools → **propose_action** → (acción) →
  **await_approval** (`interrupt`) → draft; dos nodos porque al reanudar se
  repite el nodo del `interrupt` (otro `action_id` si planificara ahí);
  aprobada → `execute` (MCP, idempotente) y fallo → motivo; rechazada →
  `ToolCallRecord` `rejected` "Una persona la rechazó."; `durability="exit"`
  y hilo borrado al terminar; `resume()` comprueba hilo (si no `expired`),
  empresa, email y acción (si no `ThreadMismatchError`, la pausa sigue).
  Solo con checkpointer **y** acciones. `references_in` acepta el ticket
  creado. MCP: `create_support_ticket` real (en memoria, `idempotency_key`,
  validación de asunto/descripción/prioridad, empresa sin helpdesk → error);
  cliente: `WRITE_TOOLS` solo por `execute()` con `idempotency_key`
  reservado. Evento: `thread_id` y `pending_action` en `email.processed.v1`
  (`thread_id_for` = `t{empresa}-e{email}-{event_id}`), `processed_event`/
  `failed_event` aceptan también la reanudación como causa. IA simulada:
  reglas de soporte técnico ("no enciende"…) y cita el ticket creado.
  Contenedor: el mismo cliente MCP como `tools` y `actions`; el worker pasa
  el `saver`. Tests: planificador (5), grafo con `InMemorySaver` (12: pausa
  sin borrador, aprobada una vez con la misma acción y cita al ticket, solo
  llamadas nuevas, rechazada, fallo → persona, doble reanudación →
  `expired`, otra empresa/email/acción → rechazo sin tocar la pausa, hilo
  desconocido, ticket ya abierto, sin checkpoints, sin estado al terminar),
  caso de uso (evento con acción que cumple el contrato, hilo por petición),
  backend MCP (idempotente, inválidos), cliente MCP real (`execute` con
  clave, escritura nunca por `call`, reservados) y **grafo + IA simulada +
  MCP real + PostgreSQL real con reinicio entre pausa y aprobación**. 303
  tests
- [x] 4.5 **Reanudar.** `ResumeReview` (`use_cases/resume_review.py`):
  `agent.resume(...)` → `email.processed.v1` (`causation_id` = la
  reanudación); `AgentError` no reintentable (`expired`…) →
  `processing_failed`; temporal → se relanza; `ThreadMismatchError` se
  propaga. `EventConsumer` genérico (`event_model` y caso de uso
  inyectados); `ThreadMismatchError` → cola de errores + `log.critical`
  (`resume_rejected`), resultado `rejected`. Worker: **un agente y dos
  consumidores** (`email.received.v1`, que también purga checkpoints, y
  `email.review_resumed.v1`), `/ready` con el latido de los dos. Stream
  `RESUMED`. Alerta **`ReanudacionIARechazada`** (crítica, con prueba en
  `alerts.test.yml`, primera aparición incluida). Comprobado: si el modelo
  falla al redactar tras aprobar, la pausa sigue y el reintento repite la
  acción con el mismo `action_id`. Tests: caso de uso (4), consumidor con
  Redis real (una vez aunque se entregue dos veces; otra empresa → DLQ) y
  **worker real con Redis + PostgreSQL + MCP + IA simulada** (pausa → `/ready`
  con `checkpoints` → aprobada → ticket citado, `auto_send`, solo la llamada
  de redacción facturada). 311 tests
- [x] 4.6 **Indicación de la persona.** `Email.review_instruction`
  (mapeada y recortada desde el evento). Prompt **`email_draft@4`**: bloque
  `<indicacion_del_equipo>` antes del email (seguirla sin saltarse reglas;
  el email sigue sin ser instrucción). `neutralize()` convierte también
  `<indicacion_del_equipo>` en `[indicacion_del_equipo]`: un email, un
  documento o un dato no pueden fingir una indicación, y la indicación no
  puede cerrar su bloque. `DecisionPolicy`: con indicación → revisión
  humana ("Redactada con una indicación del equipo: la revisa una
  persona."). Tests: bloque solo con indicación y antes del email, etiqueta
  falsa en el email neutralizada, regla de decisión, llega al agente desde
  el evento de ejemplo. 316 tests
- [x] 4.7 **Laravel: acción pendiente y reproceso con indicación.**
  Migración `add_ai_pending_action_to_emails`: `emails.ai_pending_action`
  (jsonb: action_id, tool, arguments, summary, thread_id, proposed_at y, al
  decidir, approved/decided_by/decided_at) y `ai_review_instruction`
  (text); `Email::undecidedAction()`. `RemoteResultHandler`: cada resultado
  borra indicación y acción anterior; con `pending_action` la guarda y pasa
  a human_review **sin propuesta**; tras reanudar (sin llamada de
  clasificación) conserva `classification_ai_request_id`.
  **`ReviewResumedPublisher`** (`email.review_resumed.v1`, validado contra el
  contrato, after commit, `ai_event_id`/`ai_requested_at`; `decide()` guarda
  la decisión y reinicia los intentos). `EmailEventPublisher` envía
  `review_instruction`; `traceId()` pública. `HumanReviewService::
  decideAction()` (human_review/failed → processing, `ReviewDecision`
  nuevas **`action_approved`/`action_rejected`** —cuentan como resueltas en
  `ReviewStats`—, 409 si no hay acción, ya se decidió o `AI_ENGINE=laravel`)
  y `reprocess($email, $user, ?$instruction)` (guarda la indicación, motivo
  de la decisión = la indicación, descarta la acción); responder y cerrar
  también descartan la acción. API: `POST /emails/{id}/action/approve|reject`
  (`DecideActionRequest`), `reprocess` con `instruction` (`ReprocessEmailRequest`:
  máx. 2000; con el motor de Laravel → **422**). Auditoría
  `email.action_approved|rejected` (solo `tool`) y `email.reprocessed` con
  `with_instruction` (nunca el texto). Detalle: `pending_action` (tool,
  summary, arguments, proposed_at) y `can_instruct`; bandeja:
  `has_pending_action`. `ai:reconcile-remote`: con decisión tomada republica
  **la reanudación**, no el email. Panel: bloque "La IA propone: …" con sus
  datos y **Aprobar acción** / **Rechazar acción** en `ReviewPanel`,
  reprocesar con un diálogo (indicación solo si `can_instruct`), etiqueta
  "Acción por aprobar" en la bandeja, `create_support_ticket` en
  `toolLabels`, auditoría en español. **Arreglado de paso:** los ejemplos
  del contrato y los fakes usaban el propósito `ai_service.classify`, pero
  ai-service envía `ai_service.classification` (y Laravel enlaza la
  clasificación por ese sufijo): corregidos. Tests: `RemoteApprovalTest`
  (10: pausa guardada sin propuesta y visible en detalle y bandeja,
  aprobar/rechazar publica la reanudación válida con auditoría sin datos del
  cliente, resultado tras reanudar con la clasificación original y el
  ticket como fuente, 409 en segunda decisión/sin acción/motor de Laravel,
  indicación que viaja una vez, 422 con el motor de Laravel, el
  reconciliador republica la decisión, `expired` → persona, otra empresa →
  404) y Vitest (2 nuevos en `ReviewPanel.test.ts`, 87 en total). Laravel
  1219 + los 25 del entorno
- [x] Fix (CI en rojo en la 4.7): dos líneas de tests de ai-service pasaron
  del máximo de ruff al renombrar el propósito. **Lección:** pasar `ruff
  format` + `ruff check` siempre que se toque `ai-service/`, también si solo
  son tests o un renombrado
- [x] Fix detectado en la prueba de punta a punta: tras reanudar, ai-service
  manda **todas** las herramientas (las de antes de la pausa y la acción) y
  Laravel creaba otra ejecución con todas → `ai_tool_calls` duplicadas
  (auditoría y métricas de Grafana contadas dos veces) y las de la pausa
  sin paso (`ai_request_id` null: no salían en la traza). Ahora un resultado
  tras reanudar **continúa la ejecución de la pausa**: suma pasos, tokens,
  coste y duración, añade solo las herramientas nuevas (las de la pausa van
  primero, en orden) y las de la pausa pasan a colgar del primer paso.
  Test en `RemoteApprovalTest`
- [x] 4.8 **Compose, CI, README y prueba de punta a punta.** Compose y CI ya
  cubrían la fase (URL de checkpoints del rol `ai_service_checkpoints` en
  `ai-service` desde la 4.2; los tests de integración de Python usan el
  PostgreSQL del CI; las contraseñas de los roles salen del mismo `.env`).
  **Prueba en la sesión** (BD de desarrollo migrada, Redis, GreenMail,
  servidor MCP y worker con IA simulada y checkpoints en PostgreSQL, Laravel
  con `AI_ENGINE=python` y su API real con `php artisan serve` y token de
  Sanctum; empresa en `auto` con `technical_support` permitido): Marcos,
  "el robot aspirador del pedido 76540 no enciende" → pausa con
  `create_support_ticket` (1 checkpoint) → **reinicio del worker** →
  aprobada por la API → TCK-2001 una vez → respuesta con pedido y ticket
  **enviada sola**, sin checkpoints; John → rechazada → `rejected`, sin
  ticket, a revisión; reprocesado con indicación → grafo nuevo, nueva pausa
  y, tras aprobar, revisión "Redactada con una indicación del equipo".
  Encontrado: herramientas duplicadas al reanudar (fix anterior). README del
  servicio (resumen de entrevista, grafo con `propose_action`/
  `await_approval` regenerado, nodos, compose con los dos roles, prueba de
  punta a punta, hoja de ruta) y README raíz. **Lecciones del entorno:**
  GreenMail pierde las cuentas al reiniciar el contenedor (crearlas con su
  API `POST /api/user` y volver a poner el buzón en `active`); el login por
  token devuelve `token` en la raíz, no en `data`; en tests de PHP un `[]`
  vacío se codifica como array JSON, y el contrato pide objeto
  (`arguments`)

**Resumen de la Fase 4:** 8 tareas + 2 fixes (CI y duplicados al
reanudar), 316 tests de Python (checkpoints con PostgreSQL real, grafo con
`interrupt`, worker real reanudando tras reinicio), 10 tests Pest nuevos
(`RemoteApprovalTest`) + 8 de checkpoints, 87 Vitest, alerta
`ReanudacionIARechazada` probada con `promtool`. **Pendiente para
cerrarla:** que el usuario revise la rama `fase-ai-service`, el CI en verde
y lo pruebe en su equipo: `.env` con `AI_SERVICE_CHECKPOINTS_DB_PASSWORD`,
`migrate` (rol y esquema `ai_service`, columnas `ai_pending_action` y
`ai_review_instruction`), `docker compose up -d --build ai-service ai-mcp
ai-results` y un correo de soporte técnico de un cliente sin ticket
abierto (p. ej. marcos@cliente.test). Siguiente: Fase 5 (supervisor con
subagentes y modo *shadow*) cuando lo apruebe.

### AI-Service, Fase 5 (supervisor con especialistas y modo sombra): COMPLETADA Y PROBADA DE PUNTA A PUNTA CON LA IA SIMULADA (2026-10-18), pendiente de que la pruebe el usuario

Misma rama `fase-ai-service`, misma forma de trabajo (un commit por tarea,
tests en verde, CI comprobado tras cada push, documentación al día).

**Objetivo:** pasar del grafo único a un **supervisor con especialistas**
(pedidos, facturación, soporte) y un **redactor agéntico** que puede pedir
consultas de solo lectura por su cuenta; y un **modo sombra** para
comparar el motor de Python con el de Laravel con emails reales sin que
el de Python afecte a nada.

**Decisiones tomadas:**
- **Supervisor determinista, sin llamada extra al modelo.** Decide con la
  clasificación (ya es una llamada con structured outputs): especialista
  principal por intención (`orders`: order_status, return_request,
  cancellation; `billing`: invoice_request, billing_issue; `support`:
  technical_support, complaint; `general`: el resto, también
  product_question) y
  secundarios si el email menciona pedidos (`orders`) o facturas
  (`billing`). Se descartó un supervisor LLM: una llamada más por email
  (presupuesto ajustado), menos predecible y un email malicioso podría
  intentar dirigirse a un especialista con más herramientas.
- **Especialistas = subgrafos de LangGraph** lanzados en paralelo con
  `Send`: cada uno consulta su dominio con un plan determinista (el de la
  Fase 3, repartido por dominio), propone sus acciones (soporte: abrir un
  ticket) y aporta sus reglas de redacción. Sin llamadas al modelo: el
  coste por email no sube.
- **La aprobación de acciones sigue en el grafo principal** (después de
  juntar lo de los especialistas): un `interrupt` dentro de una rama en
  paralelo complicaría reanudar.
- **Redactor agéntico** (cumple lo anunciado en `tool_planner.py`): el
  modelo redacta con lo consultado y puede pedir **consultas extra de
  solo lectura** de los especialistas del email (bucle de tool calling de
  LangGraph: redactar ↔ consultar), con límites de rondas y de llamadas.
  Lo normal es que no pida nada (el plan ya trajo los datos): una sola
  llamada, como ahora. Empresa y cliente siguen saliendo del email;
  herramientas descritas en el código (nunca con `tenant_id`/
  `customer_email`). Cada consulta lleva `origin` (`plan`, `model`,
  `approval`) para la auditoría.
- **Modo sombra:** con `AI_ENGINE=laravel`, una muestra configurable de
  emails se publica también a ai-service con `mode: shadow`. ai-service la
  procesa **sin efectos** (sin acciones ni pausa) y Laravel guarda el
  resultado en una tabla propia, **nunca lo aplica al email**. Se compara
  cuando los dos motores han terminado (intención, decisión recomendada,
  confianza, coste, tiempo). Comparación y costes, solo para el operador
  (Grafana y comandos), no en el panel. El coste de la sombra cuenta para
  el presupuesto diario de la empresa (`AiSpendGuard`).

**Tareas:**
- [x] 5.1 **Contrato.** `common`: `mode` (`live`/`shadow`), `specialist`
  (orders, billing, support, general) y `tool_call.origin` opcional (`plan`,
  `model`, `approval`). `email.received.v1`: `mode` opcional (en sombra,
  `review_instruction` debe ser null). `email.processed.v1`: `mode` y
  `specialists` (sin repetir, máx. 4; en sombra sin `pending_action` ni
  `thread_id`). `email.processing_failed.v1`: `mode`. Ejemplos válidos
  (sombra en los tres eventos, especialistas con origen de las
  herramientas) e inválidos (sombra con indicación, modo desconocido,
  sombra con acción pendiente, especialista desconocido o repetido, origen
  desconocido). Python: `Mode`, `Specialist`, `ToolOrigin`, validadores
  (`origin` por defecto `plan`). **Laravel: `RemoteResultHandler` descarta
  cualquier resultado en sombra sin tocar el email** (resultado `shadow`
  en la métrica) aunque la sombra aún no se publique: un resultado en
  sombra nunca debe aplicarse. 53 tests de contrato (Python), 118 de
  `tests/Feature/AI/Remote`
- [x] 5.2 **Supervisor y especialistas.** `domain/specialist.py`
  (`Specialist`, `primary_specialist`, `route`: principal por intención y
  secundarios `orders`/`billing` solo por números extraídos, nunca por el
  texto). `tool_planner`: `plan_for(especialista, clasificación, disponibles,
  primary)` y `READ_TOOLS` (cada herramienta de lectura es de **un solo**
  especialista: en paralelo sin repetir consultas; como secundario solo lo
  mencionado, no el historial). Grafo: retrieve → **supervisor** →
  `Send` por especialista → **`specialist`** (subgrafo compilado con
  `input_schema`/`output_schema`: devuelve solo consultas, problemas,
  propuestas y visitados; si devolviera el email, varias ramas lo
  escribirían a la vez) con `consult` y `propose` (solo soporte, con
  checkpoints) → **`gather`** (acción propuesta) → await_approval/draft.
  Aprobación en el grafo principal. `ToolCallRecord.origin` (`plan` por
  defecto, `approval` en la acción aprobada o rechazada);
  `AgentOutcome.specialists` y `DraftContext.specialists` → evento
  (`specialists`, `tool_calls[].origin`). **Facturación con datos propios:**
  servidor MCP con `get_invoice` y `get_customer_invoices` (demostración:
  una factura `FAC-<pedido>` por pedido facturado —no los cancelados ni
  pendientes de pago—, `refunded` si se devolvió; factura ajena = no
  existe, mismo mensaje); cliente MCP con las dos de lectura;
  `references_in` reconoce facturas; fuente `invoice` en el dominio, en el
  prompt y en el contrato (`source.type`: valor nuevo de enum, los dos
  lados en el mismo cambio). Prompt **`email_draft@5`**: reglas por
  especialista en el system (fijo, cacheable) y en el mensaje "Especialistas
  que han consultado datos: soporte (principal), pedidos" + facturas
  mencionadas. IA simulada: extrae `FAC-n` aparte de los pedidos (un número
  de factura no es un pedido) y usa y cita las facturas. Tests: rutas del
  supervisor (11 intenciones, secundarios, sin repetir, el texto no cuenta),
  planes por especialista, backend de facturas (propia, ajena, cancelada,
  otra empresa), grafo (soporte + pedidos en paralelo con el contexto del
  email, facturación citando la factura, problemas de todos los
  especialistas, solo soporte propone, injection sin supervisor), origen
  en aprobada/rechazada y en el evento, y **grafo + IA simulada + MCP real**
  (factura; soporte + pedido de Marcos). 355 tests de Python
- [x] 5.3 **Redactor agéntico.** DTO `ToolSpec`, `ToolRequest`,
  `ToolAnswer`, `DraftConversation` (turnos opacos del adaptador) y
  `DraftResult` con `draft` opcional + `tool_requests` + `conversation`.
  `LLMPort.draft(..., tools, conversation, answers)`. `tool_catalog.py`:
  herramientas para el modelo **descritas en el código** (sin
  `tenant_id`/`customer_email`, todo `required`, `additionalProperties:
  false`) y `tools_for_model` = las de los especialistas del email + la
  búsqueda si hay RAG, en orden estable. Grafo: **`draft` ⇄ `lookup`**
  (arista condicional): lookup ejecuta solo lo ofrecido, con la empresa y
  el cliente del email, hasta `max_model_lookups` (3) por email (el resto
  `rejected` "redacta con lo que tienes"), `origin="model"`, búsqueda con
  la pregunta del modelo en la documentación de la empresa
  (`KnowledgeRetriever.search(tenant, query)`; `references_in` reconoce sus
  fuentes como consultadas); fallos → motivos → persona;
  `max_model_rounds` (2): si sigue pidiendo, sin borrador → persona. El
  primer mensaje del modelo no cambia en las rondas siguientes (el contexto
  inicial no incluye lo que pidió el modelo; va en los `tool_result`).
  Adaptador de Claude: `bind_tools` (dicts en formato Anthropic con
  `strict: true`, sin `tool_choice`) + `output_config.format` con
  `anthropic.transform_schema` (langchain lo fusiona con `effort`),
  `_check_stop_reason` común, turno del modelo reenviado tal cual,
  `ToolMessage` con `status="error"` → `is_error`; sin herramientas sigue
  el camino de `with_structured_output`. IA simulada: pide `get_order` del
  más reciente si tiene la lista sin detalle, o busca en la documentación
  en soporte técnico sin documentación; continúa con las respuestas (el
  detalle gana al resumen). Prompt **`email_draft@6`** (cuándo usar las
  herramientas). Config `AI_SERVICE_MAX_MODEL_TOOL_ROUNDS` (2) y
  `AI_SERVICE_MAX_MODEL_TOOL_CALLS` (3). Tests: grafo (pide el detalle y
  redacta con él —contexto del email, solo herramientas del dominio, mismas
  en cada ronda, respuesta con su `call_id`—, herramienta de otro dominio
  rechazada sin llamarla, tope de consultas, tope de rondas, 0 rondas = sin
  herramientas, búsqueda en la documentación de su empresa citada),
  adaptador con la API simulada (herramienta estricta, sin `tool_choice`,
  formato y effort; segunda petición solo-añade: mismo primer mensaje y
  herramientas, razonamiento firmado intacto, `tool_result` con su id y
  `is_error`), IA simulada y **grafo + IA simulada + MCP real** ("¿cuándo
  llega mi pedido?" → lista por el plan → detalle pedido por el modelo →
  seguimiento en la respuesta). 366 tests de Python
- [x] 5.4 **Laravel: especialistas y origen en la traza.** Migración
  `add_origin_to_ai_tool_calls` (`origin` nullable: plan/model/approval;
  null = motor de Laravel o resultados anteriores), `AiToolCall` fillable,
  `RemoteResultHandler` guarda `origin` y `final_output.specialists`;
  `AgentRunResource` expone `specialists` y `origin` de cada herramienta.
  Panel: `AgentTrace` con "Especialistas: Soporte (principal) · Pedidos" y
  etiqueta "Pedida por la IA" / "Aprobada por una persona" (el plan no se
  marca); `toolLabels` de las herramientas de ai-service (`get_customer`,
  `get_customer_tickets`, `get_invoice`, `get_customer_invoices`);
  `specialistLabels`, `toolOriginLabels`, `sourceTypeLabels`. **Arreglado
  de paso:** `ReplyCard` mostraba "Documento" para cualquier fuente que no
  fuera un pedido (tickets, cuenta y ahora facturas). Tests: Pest (el
  ejemplo de especialistas guarda los orígenes y la API del detalle los
  devuelve con los especialistas) y Vitest (especialistas, origen marcado
  solo para el modelo, tipos de fuente; 89 en total)
- [x] 5.5 **Modo sombra en ai-service.** `EmailAgentPort.run(...,
  shadow)`; en el grafo `shadow` en el estado y `can_propose` falso (no hay
  acción ni pausa aunque haya checkpoints; el hilo no queda guardado).
  `ProcessEmail` lo activa con `mode: shadow`; `mappers.mode_of(causa)`
  (una reanudación siempre es `live`) en `email.processed.v1` y
  `email.processing_failed.v1`. Métrica `aiservice_decisions_total` con
  etiqueta `mode`; dashboard de ai-service con `mode!="shadow"` en sus tres
  consultas de decisiones (también casa con series antiguas sin la
  etiqueta). Tests: grafo (soporte sin ticket en sombra: sin acción, sin
  hilo, redacta), caso de uso (procesado y fallo en sombra cumplen el
  contrato; los normales salen `live`), consumidor con Redis real (sale en
  sombra y la métrica la separa). 371 tests de Python
- [x] 5.6 **Laravel: publicar en sombra y guardar el resultado.** Tabla
  `ai_shadow_runs` (tenant restrict, **email cascade** —borrador y
  clasificación son datos del cliente—, `event_id` único, status
  pending/completed/failed, recomendación: categoría, confianzas, decisión,
  borrador, motivos, especialistas, resumen de herramientas con origen,
  modelo, tokens, `cost_usd`, duración, error) y modelo `AiShadowRun`
  (`BelongsToTenant`, nada asignable en masa). Config `ai.shadow.enabled`
  (`AI_SHADOW_ENABLED`, false) y `sample_rate` (`AI_SHADOW_SAMPLE_RATE`,
  0,1). `EmailEventPublisher::build($email, $mode)` (en sombra sin
  `review_instruction`; `mode` siempre en el evento). **`ShadowPublisher`**:
  solo con el motor de Laravel, muestra determinista (`crc32` del id), una
  copia por email, fila pendiente + publicación after commit, **nunca
  rompe el procesamiento real** (cualquier fallo → aviso en el log) y no
  toca `ai_event_id`/`ai_requested_at`. `EmailProcessor` la llama tras los
  filtros baratos y `AiSpendGuard`, antes de clasificar. **`ShadowResultHandler`**
  (desde `RemoteResultHandler` si `mode: shadow`): busca la copia por
  `causation_id` (sin él → `missing`), exige su empresa y su email
  (si no, `TenantMismatchException` → cola de errores), entrega repetida →
  `stale`, guarda la recomendación o el fallo con el coste de
  `CostCalculator` (precios de Laravel) sin tocar el email ni crear
  `ai_requests`. `AiSpendGuard::spentToday()` suma también el coste de las
  copias (dinero real). `.env.docker.example` con las dos variables. Tests
  `ShadowModeTest` (8: publica la copia —contrato válido, modo sombra—
  mientras el motor de Laravel procesa igual y sin tocar los campos del
  motor remoto; muestra determinista y una sola copia; nada si está
  desactivada o con el motor de Python; guarda el resultado con coste sin
  tocar el email ni `ai_requests` y una entrega repetida no lo cambia;
  copia fallida; otra empresa → cola de errores; cuenta para el
  presupuesto; se borra con el email). Laravel 1239 + los 25 del entorno
- [x] 5.7 **Comparación, métricas, Grafana e informe.** Migración
  `add_comparison_to_ai_shadow_runs` (`compared_at`, `laravel_intent`,
  `laravel_decision`, `python_decision`, `intent_match`, `decision_match`,
  `laravel_cost_usd`, `laravel_duration_ms`). **`ShadowComparator`** (en el
  tenant): solo si la copia terminó y el email ya está decidido; compara
  **recomendación con recomendación** (no el modo de la empresa): Laravel =
  ignored → `ignore`, failed → `failed`, si no la primera propuesta de la IA
  (`created_by` null) sin `needs_human` → `auto_send`, con él o sin
  propuesta → `human_review`; Python = fallida → `failed`, spam/auto_reply
  con confianza ≥ `ignore_min_confidence` → `ignore` (la regla que Laravel
  aplica al resultado), escalate → `human_review`. Coste de Laravel = suma
  de sus `ai_requests` del email; tiempo = de processing a la primera
  decisión. Métricas `ai_shadow_comparisons_total` {tenant, intent_match,
  decision_match}, `ai_shadow_decisions_total` {tenant, laravel, python} y
  `ai_shadow_cost_usd_total` {tenant, engine}. Comando
  **`ai:shadow-compare`** (cada 5 min, `withoutOverlapping`; copias
  pendientes de más de 24 h → failed `no_response`) y
  **`ai:shadow-report {--days=7} {--tenant=} {--limit=10}`** (copias
  comparadas, % misma intención y decisión, coste medio y tiempo medio de
  cada motor, fallos, matriz de decisiones y últimos desacuerdos con el
  motivo de ai-service; solo operador). Grafana: fila **"Modo sombra"** en
  el dashboard de ai-service (copias comparadas, misma intención, misma
  decisión, coste de la sombra y barras "Laravel → ai-service" de un solo
  color); las 8 consultas con `mode`/sombra comprobadas contra un
  Prometheus real. Tests (4 más en `ShadowModeTest`, 12 en total):
  comparación cuando los dos han terminado (coincidencia, escalar = revisión
  humana con distinta intención, spam ignorado por los dos, email aún en
  marcha sin comparar, métricas), copia sin respuesta → fallo de
  ai-service, informe y programación
- [x] 5.8 **Compose, CI, README y prueba de punta a punta.** Compose y CI
  ya cubrían la fase (la sombra usa el perfil `ai-service` y `ai-results`;
  las variables `AI_SHADOW_*` en `.env.docker.example` desde la 5.6):
  comentarios aclarando que el perfil también hace falta con el modo sombra
  (`AI_ENGINE=laravel`). **Prueba en la sesión** (BD de desarrollo con las
  migraciones de la fase, Redis, GreenMail, servidor MCP y worker con IA
  simulada y checkpoints; empresa en `auto`). Motor de Python: Laura "¿cuándo
  llega mi pedido?" → pedidos, `get_customer_orders` (plan) + `get_order`
  78123 **pedida por el modelo** → enviada sola citando el pedido; factura
  FAC-44102 → facturación + pedidos en paralelo, cita pedido y factura →
  revisión (la empresa no permite `invoice_request`); Marcos "no enciende
  pedido 76540" → soporte + pedidos → pausa con `create_support_ticket`.
  Modo sombra (`AI_ENGINE=laravel`, `AI_SHADOW_ENABLED=true`,
  `AI_SHADOW_SAMPLE_RATE=1`, IA simulada en Laravel): 4 correos (pedido
  propio, Canarias, spam, queja con abogado) procesados por Laravel como
  siempre y cada uno copiado en sombra; `ai:consume-results` → 4 `shadow`
  sin tocar ningún email; `ai:shadow-compare` → 4 comparadas, 100 % misma
  intención y decisión (escalate = human_review, spam = ignore);
  `ai:shadow-report` con coste medio 0,0197 $ (Laravel) frente a 0,0066 $
  (ai-service) y 0,5 s frente a 0,2 s (precio ficticio de `simulated`).
  **Corregido en la documentación:** este archivo decía que
  `product_question` iba a `support`; el código (y sus tests) la mandan a
  `general`. README del servicio (sección de la fase como hecha, resumen de
  entrevista con supervisor/redactor agéntico/sombra, decisiones nuevas,
  prueba de punta a punta, hoja de ruta) y README raíz

**Resumen de la Fase 5:** 8 tareas, 371 tests de Python (supervisor,
especialistas en paralelo, redactor agéntico con la API de Claude simulada,
modo sombra, MCP con facturas), 12 tests Pest de modo sombra + los de
orígenes y especialistas, 89 Vitest, fila "Modo sombra" en Grafana con
consultas probadas contra Prometheus. **Pendiente para cerrarla:** que el
usuario revise la rama `fase-ai-service`, el CI en verde y lo pruebe en su
equipo: `migrate` (`ai_tool_calls.origin`, `ai_shadow_runs`), reconstruir
`ai-service`/`ai-mcp` y, para la sombra, `AI_ENGINE=laravel` +
`AI_SHADOW_ENABLED=true` con el perfil `ai-service` levantado y después
`php artisan ai:shadow-report`. **Todos los pasos de las Fases 1–5 en un
solo sitio** (2026-10-18, pedido por el usuario): sección "Probarlo en tu
equipo (Fases 1–5)" de `ai-service/README.md` (variables del `.env`,
migración + seeder de permisos, `ollama pull`, construir los tres
servicios, `horizon:terminate`, reiniciar la observabilidad, `ai:automation`,
correos de prueba con lo que debe pasar, modo sombra y problemas típicos),
enlazada desde el README raíz; sustituye al bloque desfasado de la Fase 1.
Siguiente: Fase 6 (Kubernetes) cuando lo apruebe.

### AI-Service, Fase 6 (Kubernetes): COMPLETADA Y PROBADA EN UN CLÚSTER REAL (k3s en la sesión, kind en el CI) (2026-10-18), pendiente de que la pruebe el usuario

Misma rama `fase-ai-service`, misma forma de trabajo (un commit por tarea,
tests en verde, CI comprobado tras cada push, documentación al día).

**Objetivo:** desplegar el microservicio (worker `ai-service` + servidor
MCP `ai-mcp`) en Kubernetes con manifiestos de calidad de producción
(seguridad, sondas, despliegues sin cortes, escalado) y **probarlo en un
clúster real**, no solo validar el YAML.

**Entorno de la sesión en la nube:** kind no arranca (runc no puede crear
los contenedores anidados del nodo) y los pods de Kubernetes fallan porque
la VM **no permite `oom_score_adj` negativo** (el kubelet pone -998 a los
sandbox). Solución **solo de la sesión**, fuera del repositorio: k3s v1.33
con `--docker` y dockerd con un runtime por defecto que envuelve `runc` y
sube a 0 los `oomScoreAdj` negativos del `config.json`
(`/tmp/claude-0/bin/runc-clamp`). Imágenes de Docker Hub desde
`mirror.gcr.io` renombradas en local (`imagePullPolicy: IfNotPresent`). En
el equipo del usuario y en el CI, kind funciona sin nada de esto.

**Decisiones tomadas:**
- **Solo el microservicio en Kubernetes**; Laravel sigue en Docker Compose
  (el despliegue de toda la plataforma es la Fase 16). En producción
  comparten Redis y PostgreSQL.
- **Kustomize** (dentro de `kubectl`) y no Helm: YAML normal + overlays,
  sin plantillas. Helm compensaría para distribuirlo a terceros.
- Namespace `aimail-ai` con **Pod Security `restricted`** (también las
  dependencias del overlay local): sin root, solo lectura, sin capacidades,
  seccomp, sin token de la API (`automountServiceAccountToken: false`).
- Datos de demostración del MCP = el mismo `database/seeders/demo/orders.json`
  de Laravel y el SQL de `contracts/database/`: se renderiza con
  `--load-restrictor LoadRestrictionsNone` (kustomize rechaza también los
  enlaces simbólicos a ficheros de fuera).
- Secret **`ai-service-secrets` con nombre fijo** (en producción se crea
  fuera del repositorio; lo usan el despliegue y la prueba de humo).
- `ai-mcp` con una réplica (tickets de demostración en memoria).

**Tareas:**
- [x] 6.1 **Base, overlay local y prueba de humo.**
  `ai-service/deploy/k8s/base`: Namespace (PSS restricted), ServiceAccounts
  sin token, Deployment `ai-service` (`ai-service-worker`, envFrom
  ConfigMap + Secret, startup/liveness `/health`, readiness `/ready`,
  RollingUpdate `maxUnavailable: 0`, gracia 60 s, requests 100m/256Mi y
  límite 512Mi, `/tmp` en emptyDir, anotaciones de Prometheus) + Service
  8000; Deployment `ai-mcp` (solo su token y los datos de demostración por
  ConfigMap) + Service 8100; ConfigMap `ai-service-config` generado (hash →
  reinicio al cambiar). `overlays/local`: Redis (requirepass) y PostgreSQL
  16 de pruebas sin root ni volúmenes (el rol `ai_service_checkpoints` es
  dueño de la BD y el script de `contracts/database` crea su esquema), IA
  simulada, secretos de desarrollo. `smoke-test/`: Job con la imagen del
  servicio que publica un `email.received.v1` (Laura, pedido sin número) y
  espera su `email.processed.v1`: plan `get_customer_orders`, `get_order`
  pedido por la IA y `auto_send`. **Probado en k3s real:** 4 pods en
  `restricted` sin avisos, `/ready` con redis/consumer/checkpoints, tablas
  de checkpoints en `ai_service`, prueba de humo OK (primer intento falló
  por el script: la categoría está en la raíz del evento, no en
  `classification`). CI: paso **kubeconform** (Kubernetes 1.33, estricto)
  sobre el overlay local y la prueba de humo. `deploy/` fuera de la imagen
  (`.dockerignore`). README del servicio: sección "Kubernetes (Fase 6)"
- [x] 6.2 **NetworkPolicies.** Base: `default-deny` (entrada y salida de
  todo el namespace), `allow-dns` (CoreDNS de kube-system), `ai-service`
  (entra solo Prometheus por 8000 desde cualquier namespace; sale a
  `ai-mcp`:8100 y, **por puerto**, a 6379/5432/11434/443: en producción
  Redis, PostgreSQL y Ollama están fuera del namespace con IP desconocida, y
  Anthropic por HTTPS) y `ai-mcp` (solo recibe de `ai-service`; sin salida
  salvo DNS). Overlay local: Redis acepta a `ai-service` y a la prueba de
  humo; PostgreSQL, solo a `ai-service`. Prueba de humo con su política de
  salida a 6379. **Encontrado al probar en k3s:** el Job fallaba con
  "connection refused" aunque las políticas eran correctas: el controlador
  de red (kube-router) tarda un momento en dar de alta la IP de un pod
  recién creado y el script conectaba al arrancar → reintenta la conexión
  inicial 30 s. **Pruebas negativas en el clúster:** desde `ai-mcp` y desde
  un pod sin etiquetas, Redis, PostgreSQL, `ai-mcp`, `ai-service` e internet
  (1.1.1.1:443) → rechazados; desde `ai-service`, Redis/PostgreSQL/`ai-mcp`
  → abiertos; prueba de humo OK con todo aplicado
- [x] 6.3 **Overlay de producción.** `overlays/production`: 2 réplicas del
  worker con `topologySpreadConstraints` por nodo (ScheduleAnyway),
  **PodDisruptionBudget** `minAvailable: 1`, imagen
  `registry.example.com/aimail/ai-service:1.0.0` (placeholder; se cambia
  con `kustomize edit set image`), Ollama de la plataforma en el ConfigMap,
  y el Secret `ai-service-secrets` **fuera del repositorio**:
  `secrets.example.env` con las claves (Redis, Anthropic, token MCP, roles
  de lectura y de checkpoints) y `secrets.env` en `.gitignore`. Escalado
  por CPU descartado (el worker espera a la IA); KEDA por mensajes
  pendientes del stream, anotado para cuando haya volumen. **Encontrado al
  desplegarlo:** cada pod nuevo es un consumidor nuevo del grupo de Redis y
  los de pods muertos se quedaban para siempre → `RedisStreamConsumer.
  prune_consumers()` (en el mantenimiento del consumidor, cada minuto):
  borra los que no tienen pendientes y llevan más de 1 h sin leer
  (`ConsumerOptions.prune_consumers_idle_ms`), nunca a sí mismo; los
  pendientes de uno caído los reclama antes `XAUTOCLAIM`. Tests con Redis
  real (muerto sin pendientes → borrado; con pendientes y el propio → se
  quedan; vivos nunca). **Lección:** en Redis 7.0 un `XREADGROUP` sin
  mensajes no registra al consumidor (en el test, `XGROUP CREATECONSUMER`).
  **Probado en k3s:** 2 réplicas listas y registradas en el grupo, PDB con
  1 baja permitida, `/ready` en verde; vuelta al overlay local y prueba de
  humo OK con la imagen nueva. CI: kubeconform también sobre producción.
  373 tests de Python
- [x] 6.4 **Despliegue en kind en el CI.** Job nuevo **`kubernetes`**:
  construye la imagen (`aimail/ai-service:dev`), crea el clúster con
  `helm/kind-action@v1.12.0` (kind v0.29.0, `kindest/node:v1.33.1`; las
  versiones comprobadas con `git ls-remote`), `kind load docker-image`,
  aplica el overlay local (Redis y PostgreSQL desde Docker Hub,
  NetworkPolicies con kindnet), espera los 4 despliegues y pasa la prueba de
  humo; si falla, vuelca pods, jobs, eventos, `describe` y logs del worker,
  del MCP y del Job
- [x] 6.5 **README y cierre.** CI de la 6.4 en verde a la primera: en kind
  los 4 despliegues arrancan, se crean las NetworkPolicies (kindnet) y la
  prueba de humo dice OK (`get_customer_orders` del plan + `get_order` de la
  IA → `auto_send`; comprobado en el log del job). README del servicio:
  sección "Kubernetes (Fase 6)" como hecha, **"Kubernetes en tu equipo
  (kind)"** (Windows + Docker Desktop: `winget install Kubernetes.kind
  Kubernetes.kubectl`, crear el clúster, construir y cargar la imagen,
  aplicar el overlay local, prueba de humo, curiosear, repetir y borrar; en
  PowerShell, sin `\` de continuación), resumen de entrevista con
  Kubernetes, decisiones (Kustomize frente a Helm, PSS restricted, escalar
  a mano frente a HPA/KEDA) y hoja de ruta. README raíz: las 6 fases hechas

**Resumen de la Fase 6:** 5 tareas en 5 commits, manifiestos en
`ai-service/deploy/k8s` (base, overlays local y producción, prueba de
humo), probados en k3s real en la sesión (con las pruebas negativas de las
NetworkPolicies) y en **kind en el CI en cada push** (job `kubernetes`) +
kubeconform. 1 arreglo de código encontrado al desplegar (consumidores
muertos en el grupo de Redis), 373 tests de Python. **Pendiente para
cerrarla:** que el usuario revise la rama `fase-ai-service`, el CI en verde
y, si quiere, lo pruebe en su equipo con "Kubernetes en tu equipo (kind)"
del README del servicio. **Con esto termina el plan de 6 fases del
microservicio.** Siguientes pasos posibles (a decidir con el usuario):
fusionar la rama, probar con Claude real (`AI_SERVICE_LLM_PROVIDER=
anthropic`), retirar el motor de IA de Laravel cuando ai-service lo iguale,
o seguir con las fases 14–17 de la plataforma.

### Retirada del motor de IA de Laravel: COMPLETADA (2026-10-18), rama `fase-retirada-motor-laravel`

Pedido por el usuario: **toda la lógica de la IA en el microservicio**.
Hoy conviven dos motores que hacen lo mismo (`AI_ENGINE=laravel|python`,
Laravel por defecto): dos copias de prompts, clasificación, agente,
herramientas y RAG, doble mantenimiento y riesgo de que respondan distinto.
Objetivo: `ai-service` es el **único motor de IA**; en Laravel no queda
ninguna llamada a un modelo ni a embeddings (ni `ANTHROPIC_API_KEY`: la
clave solo vive en ai-service). Misma forma de trabajo: un commit por
tarea, tests en verde, CI comprobado tras cada push, documentación al día.

**Frontera (propuesta): qué es "lógica de IA" y qué no.**
- **A ai-service:** clasificación, agente, prompts, herramientas (MCP),
  búsqueda semántica, embeddings, **extracción + troceado + indexado** de
  documentos, IA simulada, comandos de depuración de la IA.
- **Se queda en Laravel (no es IA, son datos y reglas de la plataforma):**
  - **Configuración de cada empresa** (modo de automatización, intenciones,
    herramientas activadas, presupuesto): Laravel la guarda y la **envía en
    el evento**; ai-service la aplica.
  - **Las reglas de envío** `AutoSendPolicy` y `ReplyContentGuard`
    (deterministas, sin modelo): quien envía comprueba lo que envía; si
    ai-service tuviera un fallo, no puede enviar nada que Laravel no
    apruebe (defensa en profundidad, decisión de la Fase 1 del servicio).
    `AiSpendGuard` también: es control de gasto en dólares y los precios
    están en Laravel.
  - **Registro y costes:** `ai_requests`, `ai_tool_calls`, `ai_agent_runs`
    desde los eventos y `CostCalculator` (una sola tabla de precios).
  - **Documentos como ficheros:** subida, fichero original, permisos,
    estado y panel (`documents`).
  - El catálogo de intenciones (`EmailIntent`) como espejo del contrato
    (ya validado por test).

**Etapas y tareas** (rama propuesta: **`fase-retirada-motor-laravel`**, creada
desde `fase-ai-service`, que aún no está fusionada):

- **R1. Paridad funcional en ai-service** (el motor de Laravel sigue por
  defecto):
  - R1.1 **Herramientas por empresa.** Catálogo único de herramientas en
    `contracts/tools/catalog.json` (nombre, etiqueta, descripción,
    lectura/escritura, especialista, activada por defecto) que usan el
    panel y ai-service (test: catálogo = herramientas del servidor MCP).
    `email.received.v1` con `enabled_tools` (opcional; sin él, solo las
    activadas por defecto). Laravel lo calcula con `tenant_tools`;
    ai-service solo planifica, ofrece al modelo y ejecuta esas.
  - R1.2 **API interna síncrona de ai-service** (FastAPI, token de
    servicio, solo red interna; NetworkPolicy y compose):
    `POST /internal/v1/knowledge/search` (la "Probar la búsqueda" del panel
    y `kb:search`) y `GET /internal/v1/tools?tenant_id=` (catálogo +
    disponibilidad por empresa, que hoy calcula `OrderProvider::
    isConfigured()`). Cliente `AiServiceClient` en Laravel con timeout;
    caído → 503 como ahora con Ollama.
  - R1.3 **Depuración sin el motor de Laravel:** `ai:process {email}
    --now` con el motor remoto publica y **espera el resultado** (máx. N s)
    mostrando la traza ya guardada (`ai_agent_runs` → pasos →
    herramientas); sustituye a `ai:classify` y `ai:reply`. Comando de
    Python `ai-service-tool` (ejecutar una herramienta MCP con empresa y
    cliente) en lugar de `ai:tool`.
  - R1.4 **Modo degradado:** si ai-service no responde, un email no debe
    acabar en `failed` sin más: tras `max_publish_attempts` el
    reconciliador lo pasa a **`human_review`** ("La IA no está disponible")
    para que una persona pueda contestar.
- **R2. Indexado de documentos en ai-service:**
  - R2.1 Contrato: `document.uploaded.v1` (Laravel → ai-service: empresa,
    documento, título, tipo, tamaño, sha256 y **URL firmada temporal**
    para descargar el original), `document.indexed.v1` (nº de fragmentos,
    modelo, uso de embeddings → `ai_requests` con propósito
    `document_indexing`) y `document.indexing_failed.v1` (mensaje para el
    usuario: "PDF sin texto"…); `document.deleted.v1` para borrar sus
    fragmentos (borrado y retención).
  - R2.2 Los fragmentos pasan a ser **de ai-service**: tabla propia en su
    esquema `ai_service` (rol propio con escritura solo ahí; el de los
    checkpoints o uno nuevo), filtro por empresa en cada consulta y tests de
    aislamiento. Así se cumple "Python no escribe tablas de Laravel" y el
    rol de solo lectura con RLS sobre tablas de Laravel deja de hacer falta.
  - R2.3 Python: descarga (máx. 20 MB, solo la URL firmada de Laravel),
    extracción (PDF por página, Markdown por encabezado, HTML, TXT; mismos
    errores), troceado (mismos parámetros que `TextChunker`), embeddings
    bge-m3 y guardado; búsqueda del grafo y de la API interna sobre su
    tabla. Tests portados de Laravel (`Pdf::make` → generador en Python).
  - R2.4 Laravel: ruta interna firmada de descarga, publicador (subir y
    reindexar publican el evento) y consumidor de `document.indexed.v1` /
    `indexing_failed.v1` (estado del documento como ahora en el panel).
  - R2.5 Migración: `kb:reindex --all` publica todos los documentos; cuando
    estén indexados en ai-service, la búsqueda deja de usar
    `document_chunks`.
- **R3. Puerta de calidad con Claude real (modo sombra).** Antes de
  cambiar nada por defecto: probar ai-service con Claude
  (`AI_SERVICE_LLM_PROVIDER=anthropic`) en 1–2 emails y después modo sombra
  con `AI_SHADOW_SAMPLE_RATE=1` sobre **30–50 emails** que cubran las 14
  intenciones (los de la BD de desarrollo + nuevos por SMTP). Coste
  estimado **3–5 $** (los dos motores). Umbrales fijados antes de mirar:
  **≥ 90 % misma intención y misma decisión**, ningún `auto_send` de
  ai-service donde Laravel pide persona por seguridad (injection, amenaza
  legal, acciones), coste por email ≤ el de Laravel; revisar a mano cada
  desacuerdo y ajustar prompts si hace falta. Indexado y búsqueda con
  Ollama real (no hay Ollama en la sesión de la nube): en el equipo del
  usuario.
- **R4. Cambio de motor:** `AI_ENGINE=python` por defecto; `ai-service`,
  `ai-mcp` y `ai-results` dejan de ser un perfil opcional del compose;
  `ANTHROPIC_API_KEY` sale del `.env` de Laravel; modo sombra apagado.
  **Periodo de respaldo** (a decidir; p. ej. dos semanas de uso) con
  `AI_ENGINE=laravel` aún posible.
- **R5. Borrado del motor de Laravel** (por partes, cada una con la suite
  en verde):
  - `app/AI`: `AIService`, `LLMProvider` y DTOs `LLM*`/`AIResult`,
    proveedores Anthropic y simulado, `Prompts/`, `EmailClassifier`,
    `ClassifyEmailJob`, `EmailClassificationPrompt`, `Agent/`
    (`AgentService`, `AgentDecision`, `EmailAgentPrompt`,
    `EmailAgentContext`, `AgentReply`, `AgentResult`), `Embeddings/`,
    excepciones `LLM*`, `AIEngine` y todo el modo sombra (publicador,
    comparador, consumidor, tabla `ai_shadow_runs`, comandos, fila de
    Grafana). Se quedan `EmailIntent`, `EmailClassification` (lo usa
    `recordClassification`) y los enums de las tablas de auditoría
    (`AgentRunStatus`, `AgentStopReason`, `ToolCallStatus` →
    `app/AI/Enums`).
  - `app/Tools` (salvo la configuración por empresa, que pasa a leer el
    catálogo del contrato) y `app/ERP` entero (`orders.json` se mueve a los
    datos del servidor MCP; compose y Kubernetes apuntan allí).
  - `app/Documents`: `Extraction/`, `Chunking/`, `Search/`,
    `KnowledgeBaseService`, `KnowledgeContext` y el procesado de
    `ProcessDocumentJob`; tabla `document_chunks` (tras R2.5).
  - `EmailProcessor` sin el camino local; comandos `ai:classify`,
    `ai:reply`, `ai:tool`, `ai:shadow-*`; `config/ai.php` limpio (se quedan
    precios, automatización y límites) y `config/erp.php` fuera;
    `composer remove anthropic-ai/sdk smalot/pdfparser` (`opis/json-schema`
    se queda: valida los contratos); Horizon sin `redis-long` para la IA.
  - Tests: fuera los ~20 archivos del motor local; los E2E
    (`AutomationEndToEndTest`, `HumanReviewEndToEndTest`) y los que usan
    `FakeLLMProvider` (procesador, métricas, gasto, detalle) pasan al flujo
    remoto con resultados válidos según el contrato. **Job E2E nuevo en el
    CI:** compose con Laravel + ai-service (IA simulada) + `ai-mcp` +
    GreenMail, de un correo SMTP a la respuesta en el buzón del cliente.
    `ArchitectureTest`: prohibido cualquier SDK de modelos o cliente de
    embeddings en Laravel.
  - `ai_prompt_versions` se conserva (historial de peticiones antiguas),
    sin escrituras nuevas.
- **R6. Documentación:** README (secciones 6–10 describen el motor de
  Laravel), README del servicio, CLAUDE.md. Consecuencias anotadas: las
  integraciones reales de la Fase 14 (CRM, ERP, helpdesk) van al servidor
  MCP, y la evaluación de la Fase 15 se hace en Python.

**Riesgos:** ai-service pasa a ser imprescindible (sin él no hay IA:
mitigado con el modo degradado de R1.4, réplicas y alertas que ya existen);
más piezas en marcha en local; reindexar todos los documentos una vez.

**Decisiones (aprobadas por el usuario el 2026-10-18: "haz todo lo
recomendado"):** las reglas de envío y el control de gasto se quedan en
Laravel; el fichero a indexar llega por **URL firmada temporal**; puerta de
calidad con Claude real antes de cambiar de motor; rama
`fase-retirada-motor-laravel` desde `fase-ai-service`. **Cambio en la forma de
trabajar pedido por el usuario:** cuando todo esté hecho y el CI en verde,
**push a `main`** con todo (fast-forward; `main` es ancestro de la rama).

**Estado de las tareas:**
- [x] **R3. Puerta de calidad con Claude real** (hecha primero: la clave
  que dio el usuario caducaba en un día y hacían falta los dos motores).
  Prueba previa por `/debug/process`: ai-service con Claude real funciona,
  incluido el redactor agéntico (el modelo pide `get_order`, dos rondas,
  structured outputs + herramientas sin ningún 400). Después modo sombra con
  el conjunto de 32 correos de `ai-service/evals/gate_dataset.py` (14
  intenciones), mismo modelo en los dos motores, búsqueda con un servidor
  de embeddings de bolsa de palabras **solo de la sesión** (bge-m3 no se
  puede descargar aquí: registro de Ollama y Hugging Face bloqueados) igual
  para los dos. **Se agotó el saldo de la cuenta tras 13 correos** (pedidos
  ×5, devoluciones ×3, cancelaciones ×2, facturas ×2, cobro duplicado): el
  resto falló en los dos motores con "credit balance is too low".
  Resultados de los 13 (`ai-service/evals/retirement-gate-2026-10-18.json`):
  **misma intención 13/13 (100 %)**, **misma decisión 11/13 (84,6 %)**, por
  debajo del umbral del 90 %: en los 2 desacuerdos **ai-service es más
  prudente** (pide una persona donde Laravel enviaría) por su umbral de
  confianza 0,85 frente al 0,75 de Laravel (#27 confianza 0,80; #36 0,75 +
  acciones pendientes); **ningún envío automático de ai-service donde
  Laravel pide una persona**; coste medio **0,054 $ frente a 0,067 $**
  (−19 %). Se mantiene el umbral 0,85 (más revisión humana, más seguro) y se
  sigue con la retirada por decisión del usuario. **Pendiente:** completar
  la puerta con saldo (los 19 correos sin comparar: preguntas de producto,
  soporte, quejas, spam, inyección…) con
  `python ai-service/evals/gate_dataset.py` y `ai:shadow-report` antes de
  usarlo en producción. Gasto total de la prueba ≈ 4,5 $ (incluye una
  primera pasada inválida: en el PostgreSQL de la sesión, pgvector 0.6, la
  búsqueda de Laravel fallaba; se repitió en un PostgreSQL con pgvector 0.8).
  **Lecciones:** `docker kill` filtrando por la columna del comando no
  encuentra los contenedores (sale recortada: filtrar por imagen); un
  `mail:sync` parado deja el bloqueo de `ShouldBeUnique` en Redis (claves con
  `\`: borrarlas con `EVAL` y `KEYS`, `read` sin `-r` se come las barras)

- [x] **R1.1 Herramientas por empresa** + **R1.2 API interna** (un commit:
  comparten `TenantToolService`). Catálogo único
  `contracts/tools/catalog.json` (+ esquema; 8 herramientas: búsqueda,
  ficha, pedido, pedidos, factura, facturas, tickets —lectura— y
  `create_support_ticket` —escritura—; por defecto solo la búsqueda).
  `email.received.v1` con `enabled_tools` (opcional, nullable, sin
  repetidos); ai-service: `Email.enabled_tools` (`None` = sin restricción,
  solo tests/depuración; del evento siempre un conjunto: ausente → por
  defecto), `allows_tool`/`allowed_tools` aplicados en el plan de cada
  especialista, la búsqueda (`retrieve`), las herramientas del modelo y las
  acciones. Laravel: `App\AI\ToolCatalog`, `TenantToolService::
  enabledNames()` (catálogo + `tenant_tools`; `set()` acepta herramientas
  del catálogo aunque Laravel no las implemente) → `EmailEventPublisher`.
  API interna de ai-service (`entrypoints/http/internal.py`, token
  `AI_SERVICE_INTERNAL_TOKEN`, sin él no se monta): `POST
  /internal/v1/knowledge/search` y `GET /internal/v1/tools?tenant_id=`
  (disponibilidad desde `GET /capabilities` del servidor MCP; `null` si no
  responde). Laravel: `AiServiceClient` (`AI_SERVICE_URL`, token, timeout;
  la llamada de embeddings se guarda en `ai_requests` con
  `RemoteCallRecorder`, extraído de `RemoteResultHandler`), búsqueda del
  panel y lista de herramientas (`catalogStatusFor`) desde ai-service con el
  motor remoto; panel: disponibilidad desconocida se puede activar.
  NetworkPolicy: entra Laravel por el 8000. Tests: catálogo (esquema,
  nombres, por defecto, servidor MCP), grafo (herramienta desactivada no se
  planifica ni se ofrece, sin búsqueda no hay RAG, sin la acción no se
  propone), mapeador, API interna (token, 404 sin token, empresa, 503,
  disponibilidad, MCP caído), Pest `AiServiceApiTest` y en publicación,
  Vitest. **Entorno de la sesión:** los tests de Laravel corren ahora contra
  un PostgreSQL con **pgvector 0.8** (`mirror.gcr.io/pgvector/pgvector:pg16`
  en el puerto 5433): desaparecen los 25 fallos de entorno; quedan 8 que
  solo fallan en paralelo (helpers entre archivos, nombre de la BD)

- [x] **R1.3 Depuración sin el motor de Laravel.** `php artisan ai:trace
  {email}` (lo que hizo la IA, la última ejecución guardada: pasos,
  herramientas, propuesta, decisión y coste; sale de `ai:reply --last`, que se
  borra en R5). `ai:process {email} --now` con el motor remoto publica y
  **espera** el resultado (`--wait`, 120 s por defecto; lo aplica
  `ai:consume-results`, otro proceso) y si no llega lo dice. Python:
  **`ai-service-tool`** (`--list`; `<herramienta> --tenant --customer --args`)
  por el mismo `McpToolClient` que la IA (sustituye a `ai:tool`). Tests: Pest
  (`ai:trace`, espera sin respuesta) y Python contra el servidor MCP real
- [x] **R1.4 Modo degradado:** el reconciliador, agotados los intentos de
  publicación, pasa el email a **revisión humana** ("El servicio de IA no
  respondió tras N intentos: lo atiende una persona.") en vez de `failed`:
  sin IA una persona sigue pudiendo contestar

- [x] **R2. Indexado de documentos en ai-service** (R2.1–R2.5 en un
  commit: contrato, almacén, Python y Laravel no funcionan por separado).
  Contrato: `document.uploaded.v1` (Laravel → ai-service: título, tipo,
  tamaño, sha256 y `download_path` = **ruta relativa firmada** que ai-service
  une a su `AI_SERVICE_LARAVEL_URL`: el evento nunca elige el host),
  `document.deleted.v1`, `document.indexed.v1` (nº de fragmentos, modelo,
  `usage.calls` de embeddings) y `document.indexing_failed.v1` (`error_code`
  unsupported/empty/no_text/unreadable/too_large/download/embeddings/
  internal + mensaje para el panel); `common.document_id`; `_Envelope` común
  en Python. **Fragmentos de ai-service** en `ai_service.knowledge_chunks`
  (su esquema, el rol de los checkpoints; `PgVectorStore.setup()` crea la
  tabla, el índice por empresa+documento y el HNSW al arrancar;
  `replace_document` en transacción, `delete_document`, búsqueda por empresa
  y modelo; `AI_SERVICE_DATABASE_URL` pasa a ser ese rol). Python: extracción
  con **pypdf 6.19** (PDF por página, Markdown por encabezado, HTML, texto;
  mismos errores y mensajes que Laravel), `TextChunker` portado (mismos
  2000/200), `OllamaEmbeddings.embed_documents` por lotes (una llamada por
  lote, el error lleva las ya hechas), `LaravelDocumentSource` (solo rutas
  `/internal/documents/…`, sin redirecciones, tamaño máximo mientras
  descarga; 401/403/404 definitivos, red/5xx temporales), caso de uso
  `IndexDocument`/`DeleteDocument` y `DocumentConsumer` (uno a uno, duplicados
  por `event_id`, contrato roto → DLQ, temporal → reintento y, agotado,
  `indexing_failed` con su código). Laravel: migración
  `add_remote_indexing_to_documents` (`index_event_id`,
  `index_requested_at`, `index_attempts`), ruta
  **`/internal/documents/{id}/content`** (`signed:relative`, 30 min,
  `application/octet-stream`, fuera del comodín del panel),
  `DocumentIndexPublisher` (subir/reindexar → processing + evento after
  commit; borrar → `document.deleted.v1`), `DocumentResultHandler` en
  `ai:consume-results` (listo/error, embeddings a `ai_requests`, empresa
  distinta → DLQ, documento ya borrado → vuelve a pedir el borrado, resultado
  de un documento que ya no está "Indexando" → `stale`), reconciliador de
  documentos atascados (republica con enlace nuevo; agotados → error
  "Reindexar") y **`php artisan kb:reindex {--tenant=} {--all}`**;
  `kb:search` por la API interna. NetworkPolicy: salida al 80 (Laravel).
  **Prueba en la sesión:** `kb:reindex --tenant=1` → ai-service descargó los
  4 documentos por la ruta firmada de `php artisan serve` y los indexó con
  **los mismos fragmentos que Laravel (5, 5, 5 y 7)**; `ai:consume-results` →
  4 `applied` y los documentos `ready`; `kb:search` respondió por la API
  interna. **Encontrado con los tests:** el caso de uso no convertía un fallo
  de descarga en reintento y, al agotarlo, el código salía `embeddings`
  (ahora el error temporal lleva su propio código). 438 tests de Python

- [x] Fix (CI en rojo en R1.1, job `kubernetes`): la prueba de humo
  publicaba un `email.received.v1` sin `enabled_tools` y, con el cambio de
  R1.1, solo valía la búsqueda: no se consultaban pedidos. Ahora la prueba
  activa `get_customer_orders` y `get_order` como haría Laravel.
  **Lección:** al cambiar el contrato, buscar también los productores de
  eventos fuera de los tests (la prueba de humo de Kubernetes)

- [x] **R5.1 Modo sombra fuera de Laravel** (sin dos motores no hay nada
  que comparar): `ShadowPublisher`, `ShadowComparator`,
  `ShadowResultHandler`, `AiShadowRun` (migración que borra
  `ai_shadow_runs`), `ai:shadow-compare`/`ai:shadow-report`, sus métricas,
  la fila de Grafana y la configuración `AI_SHADOW_*`. **Se queda** el campo
  `mode` del contrato y el modo sombra de ai-service (procesar sin efectos):
  quitarlo rompería la v1 y servirá para comparar prompts o modelos en la
  Fase 15. Laravel descarta cualquier resultado en sombra sin tocar el email
  (resultado `shadow` en la métrica)

- [x] **R4 + R5.2 Cambio de motor y borrado del motor de Laravel** (en un
  commit: sin el motor de Laravel no hay interruptor que cambiar).
  `AI_ENGINE` desaparece (y `AIEngine`): **ai-service es el único motor**.
  Borrado de Laravel: `AIService`, `LLMProvider` y los DTOs `LLM*` (se queda
  `LLMUsage` para `CostCalculator`), proveedores Anthropic y simulado,
  `Prompts/`, `EmailClassifier`/`ClassifyEmailJob`/prompt, `Agent/` (se
  quedan sus enums de las tablas), `Embeddings/`, excepciones `LLM*`,
  `app/Tools` salvo `TenantToolService` y `ToolCallStatus`, `app/ERP` y
  `config/erp.php`, extracción/troceado/búsqueda/`KnowledgeBaseService`/
  `ProcessDocumentJob`, comandos `ai:classify`, `ai:reply`, `ai:tool`.
  `EmailProcessor`: filtros baratos → publicar (sin camino local);
  `DocumentService` siempre publica (`mimeFor` vive ahí);
  `TenantToolService` = catálogo compartido (`statusFor` con la
  disponibilidad de ai-service, `null` si no responde; `ai:tools` lo
  muestra); `ReplyContentGuard` usa los nombres del catálogo;
  `AIServiceProvider` solo streams, contrato y catálogo; `config/ai.php`
  limpio (precios, documentos, automatización, límites, `remote` con
  `model`/`min_confidence`/`llm_provider` solo para mostrar y para
  `security:check`); `ProcessEmailJob` pasa a la conexión `redis` (solo
  publica: timeout 120) y fuera `redis-long` y `supervisor-documents`;
  `AiSpendGuard` cuenta también los emails ya publicados
  (`ai_requested_at`), no solo los clasificados (con el motor remoto, una
  ráfaga se publicaba entera antes de que llegara ninguna clasificación);
  métricas de clasificación por `*classification` (con `needs_human` del
  evento); reprocesar con indicación y aprobar acciones ya no dependen del
  motor (`can_instruct` siempre true). **Pedidos de demostración** movidos a
  `ai-service/src/ai_service/mcp_server/data/orders.json` (dentro de la
  imagen: fuera el volumen del compose, el ConfigMap de Kubernetes y el
  montaje del CI). **Compose:** `ai-service`, `ai-mcp` y `ai-results`
  siempre activos (sin perfil). `ANTHROPIC_API_KEY` solo la usa el
  contenedor de ai-service. **Tests:** `TestCase` usa `InMemoryEventStream`
  por defecto; helpers en `tests/Pest.php` para simular a ai-service
  (`processedEvent`, `failedEvent`, `applyAiResult`, `processWithAi`,
  `classificationOf`); reescritos procesador, job, comandos, métricas,
  gasto, documentos, detalle, herramientas, configuración y los E2E con
  GreenMail (el resultado de ai-service se simula); `insertEmailRow` a
  `tests/Pest.php` (fallaba al lanzar un archivo suelto). **R5.3 en el
  mismo commit:** `composer remove anthropic-ai/sdk smalot/pdfparser`, tabla
  `document_chunks` y modelo `DocumentChunk` fuera (migración
  `drop_document_chunks_and_reader`, que también quita la RLS y los permisos
  del rol `ai_service_reader`; la migración antigua que los creaba queda
  vacía y `contracts/database/knowledge_base_reader.sql` se borra),
  `ArchitectureTest`: prohibido cualquier SDK de modelos o de PDF en `app/`
  y llamadas a APIs de modelos o a `/api/embed`. **R6:** README (secciones
  6–10 reescritas: ai-service como motor, RAG, herramientas, agente,
  automatización; servicios, comandos, estructura, tests, problemas
  frecuentes), README de ai-service (estado, "Probarlo en tu equipo",
  compose, decisiones, hoja de ruta) y este archivo. Laravel 1035 tests en
  verde (salvo `DatabaseConnectionTest`, que solo falla en paralelo por el
  nombre de la BD), Python 438. **Pendiente:** evaluar con Claude real los
  19 correos de la puerta que no se llegaron a comparar (sin modo sombra:
  intención esperada frente a la clasificada); job E2E de compose en el CI
  (Laravel + ai-service con IA simulada + GreenMail) aplazado: los E2E de
  Pest y de Python ya cubren cada lado con el contrato compartido

**Cambio de rama pedido por el usuario:** con todo hecho y el CI en verde,
push de la rama a **`main`** (fast-forward; `main` es ancestro).

**Decisiones abiertas que había (ya resueltas arriba):**
1. Frontera: las reglas de envío y el control de gasto se quedan en
   Laravel (recomendado).
2. Cómo le llega a ai-service el fichero a indexar: **URL firmada temporal
   de Laravel** (recomendado: nada nuevo que desplegar) frente a
   almacenamiento compartido (volumen o S3/MinIO; más infraestructura).
3. Puerta de calidad con Claude real (3–5 $) antes de cambiar de motor
   (recomendado) o cambiar solo con la IA simulada (no recomendado: nunca
   se ha medido ai-service con Claude).
4. Rama nueva `fase-retirada-motor-laravel` desde `fase-ai-service`
   (recomendado) o seguir en `fase-ai-service`.

### Fase 14 (integraciones): EN CURSO (desde 2026-10-19), rama `fase-14-integraciones`

Misma forma de trabajo: un commit por tarea, tests en verde antes de cada
commit, este archivo actualizado en cada commit, **sin push** (lo hace el
usuario). **Sin IA real** (el usuario no tiene saldo por ahora): todo se
prueba con la IA simulada (`AI_SERVICE_LLM_PROVIDER=simulated`), que llama
a las mismas herramientas que Claude. La evaluación con Claude (Fase 15 y
los 19 correos de la puerta de la retirada) queda para cuando haya saldo.

**Objetivo:** que el servidor MCP deje de responder solo con datos de
demostración y se conecte a los sistemas de cada empresa, **también de las
que no usan WooCommerce/PrestaShop/Shopify**. Sin integración la plataforma
ya funciona (documentación + revisión humana de lo que dependa de datos);
cada integración permite automatizar más.

**Plan acordado con el usuario (en este orden: primero lo que sirve a
todos):**
1. Interfaces de proveedor en el servidor MCP (la demo, una implementación más)
2. **API estándar de la plataforma**: especificación (`contracts/integrations/`)
   + proveedor del servidor MCP
3. Importación de pedidos por **CSV** en Laravel, que **implementa la API
   estándar** para servirlos
4. La API estándar apuntando al sistema de la empresa: configuración por
   empresa, credenciales cifradas, anti-SSRF
5. Conectores de tiendas (WooCommerce, PrestaShop, Shopify): traductores al
   mismo formato
6. Herramientas para negocios sin pedidos (citas, contratos, tickets)

**Decisión (aprobada por el usuario, 2026-10-19, "opción B"):** los pedidos
importados por CSV **se guardan en Laravel** (dueño de los datos de negocio:
permisos, auditoría, retención y `data:forget-address` los cubren sin trabajo
extra; Python sigue sin escribir tablas de Laravel) y Laravel los sirve
**implementando la API estándar**: el servidor MCP solo necesita un proveedor
genérico para el CSV y para el ERP propio de una empresa. Descartado guardarlos
en ai-service (el servidor MCP necesitaría PostgreSQL y el borrado de datos
tendría que llegar a otro servicio). Por eso la API estándar (antes tarea 3)
va antes que el CSV.

Descartado: acceso directo a la BD de la empresa (sección 13) y conector
genérico configurable (URL y campos a mano) salvo demanda real. Valorado
para empresas con equipo técnico: que conecten su propio servidor MCP.

**Tareas:**
- [x] 1. **Interfaces de proveedor.** `mcp_server/` en tres capas:
  `server.py` (herramientas MCP, ahora `async`) → **`tools.py`
  (`BusinessTools`)**: reglas comunes a todos los proveedores (normalizar
  números, **volver a comprobar que cada dato es del cliente que escribe**,
  mismo mensaje para ajeno e inexistente, límites 1–10, validación de
  tickets, lo que ve la IA: sin email ni dirección; ficha del cliente
  derivada de sus pedidos; `availability()` según lo conectado) →
  **`integrations/contracts.py`**: `IntegrationResolver.for_tenant(empresa)`
  → `TenantIntegrations(orders, invoices, tickets)` (`None` = la empresa no
  tiene ese sistema → herramienta no disponible) con interfaces asíncronas
  `OrderSource` (`find_order(número, cliente)`, `customer_orders`),
  `InvoiceSource`, `TicketSource` (`create_ticket` idempotente por clave) e
  `IntegrationError` (sistema caído o credenciales: mensaje apto para la IA;
  **nunca se disfraza de "no encontrado"**, la IA no afirmaría algo falso).
  Formato común en `integrations/models.py` (`Order`, `OrderItem`,
  `Shipment`, `Invoice`, `Ticket` con `priority`, `NewTicket`,
  `CreatedTicket`). `integrations/demo.py` (`DemoIntegrations`) = los
  datos de demostración de siempre (facturas `FAC-<pedido>` derivadas,
  tickets creados en memoria). Fuera `backends.py`/`DemoData`. El servidor
  traduce `ToolInputError` e `IntegrationError` a `ToolError` (mensaje
  legible). Tests: `tests/unit/mcp_server/test_tools.py` (los de antes,
  asíncronos, + **un proveedor que devuelve datos de otro cliente no llega a
  la IA**, un sistema caído no es "no encontrado", empresa sin nada,
  disponibilidad por empresa) y en `test_mcp_server.py` el error legible por
  MCP. 444 tests de Python en verde con Redis y PostgreSQL reales. **Prueba
  en el stack** (IA simulada): pedido propio 45821 → `get_order` → enviado
  solo; pedido ajeno 76540 → error → revisión humana; `ai:tools 1` muestra la
  disponibilidad desde la capa nueva (`6448646`)
- [x] 2. **API estándar.** Contrato en **`contracts/integrations/`**:
  `README.md` (especificación para el informático de la empresa: endpoints,
  reglas, qué hace la plataforma con cada respuesta, versionado),
  `standard-api.v1.openapi.yaml` (OpenAPI 3.1 que referencia los esquemas),
  JSON Schema 2020-12 `schemas/{order,invoice,order-list,invoice-list}.v1`
  (lector tolerante arriba; estados fijos —pedidos `pending_payment` …
  `returned`, facturas `paid`/`refunded`/`pending`—, moneda ISO 4217, país
  ISO alfa-2, nunca dirección completa, `tracking_url` solo http/https,
  listas de máx. 100) y ejemplos `examples/{valid,invalid}/<esquema>--<caso>`.
  Endpoints (solo lectura, relativos a la URL base de la empresa):
  `GET /v1/orders/{number}?customer_email=` (404 = no existe o es de otro),
  `GET /v1/orders?customer_email=&limit=` (`{"data": [...]}`, nunca 404) y
  lo mismo para `/v1/invoices`; `Authorization: Bearer`. Python:
  `mcp_server/integrations/standard_api.py`: `StandardApiClient` (`get_one`
  → None en 404, `get_list`; sin redirecciones, 5 s, máx. 1 MB cortado
  mientras se descarga, número codificado en la ruta, token nunca en
  mensajes ni logs), `StandardApiOrders`/`StandardApiInvoices`, modelos
  Pydantic **estrictos** (`strict=True`: "89.90" no es un importe ni un
  número una fecha) y `standard_api_integrations(client, orders, invoices)`
  (una empresa puede implementar solo una parte). Errores → `IntegrationError`
  (401/403 "rechazó las credenciales", 429/5xx/red "no responde", JSON o
  esquema no válidos, redirección o 404 de una lista "respuesta no válida").
  La URL base de momento sale de la configuración de la plataforma; el
  anti-SSRF llega con la configuración por empresa (tarea 4). Tests:
  `tests/contract/test_standard_api_contract.py` (esquemas válidos, cada
  esquema con su modelo, **los ejemplos válidos pasan y los inválidos fallan
  en el esquema y en el modelo**, OpenAPI con los 4 endpoints y los
  esquemas compartidos) y `tests/integration/test_standard_api.py` (petición
  con token, cliente y número codificado, límite acotado, 404, facturas,
  12 fallos que nunca son "no encontrado" —redirección a 169.254.169.254 no
  seguida, importe como texto, fecha como número…—, 404 de lista, respuesta
  enorme, token fuera de los logs, implementación parcial, y con
  `BusinessTools` una API que no filtra por cliente no enseña datos ajenos).
  484 tests de Python en verde con Redis y PostgreSQL reales (`f1d1d27`)
- [x] 3. **Pedidos por CSV en Laravel, servidos con la API estándar.**
  Tabla **`customer_orders`** (tenant restrict, único tenant+número, índice
  tenant+email+fecha; mismos campos que `order.v1`, email en minúsculas,
  `items` jsonb) y modelo `CustomerOrder` (`BelongsToTenant`, nada asignable
  en masa, `toStandardApi()`). **`App\ERP\Csv\OrderCsvImporter`**: separador
  `;`/`,`/tab detectado, UTF-8 (con BOM) o Windows-1252, cabeceras en inglés
  o español normalizadas (`Str::ascii`: "Nº pedido" → `no_pedido`), estados
  en español, importes "89,90"/"1.234,56 €"/"1,234.56", fechas ISO o
  `dd/mm/aaaa`/`j/n/Y` sin desbordes (31/02 no vale), productos "2x Silla;
  1x Mesa", http(s) en el seguimiento, país y moneda ISO; **todo o nada** con
  errores por fila (máx. 50 devueltos, todos contados; repetidos en el
  fichero), máx. 20.000 filas y 5 MB (`config/integrations.php`); **upsert
  por número** (cuenta nuevos y actualizados). Permisos **`orders.view`**
  (todos) y **`orders.manage`** (owner, admin). API del panel: `GET
  /orders?q=` (con `meta.total_orders`/`last_imported_at`), `GET
  /orders/template` (`resources/templates/pedidos-plantilla.csv`, **un test
  la importa tal cual**), `POST /orders/import` (throttle 10/min; 422 con
  `rows` y `total_row_errors`), `DELETE /orders`. Auditoría
  `orders.imported` (fichero y recuentos, sin datos de clientes) y
  `orders.deleted`, categoría `orders`. `data:forget-address` y
  `RetentionService::forgetAddress` borran también sus pedidos (`orders` en
  la auditoría). **Rutas internas** (fuera de web/api, middleware
  `RequireDataToken`: `AI_SERVICE_DATA_TOKEN`, 404 sin él):
  `GET /internal/integrations/{tenant}` (`orders: {type: csv}` si tiene
  pedidos importados; empresa desactivada → nada) y la API estándar en
  `/internal/standard-api/{tenant}/v1/orders[/{number}]` (solo del
  `customer_email`; ajeno = 404 igual que inexistente; 400 sin cliente o con
  `limit` fuera de 1–100). **Servidor MCP:**
  `integrations/laravel.py` (`LaravelIntegrations`: consulta la
  configuración con caché por empresa —60 s—, `csv` → `StandardApiOrders`
  sobre Laravel, tipo desconocido ignorado, nada configurado → respaldo con
  los datos de demostración; Laravel caído → última configuración conocida o
  `IntegrationError`, nunca "sin datos"); `McpSettings.laravel_url/
  laravel_token/integrations_cache_seconds` y `build_resolver()`;
  `/capabilities` responde 503 si no sabe qué tiene la empresa. Compose:
  `ai-mcp` con `AI_SERVICE_MCP_LARAVEL_URL=http://nginx` y el token de
  datos; NetworkPolicy de `ai-mcp` con salida a 80/443. Panel: página
  **Pedidos** (`/pedidos`, menú con icono de carrito): importar, plantilla,
  errores por fila, búsqueda, paginación, borrar todos; `ApiError.body`
  (cuerpo completo del error). **Arreglado de paso:** el menú lateral
  arrancaba abierto también en un móvil (220 px de 390): ahora plegado por
  debajo de 768 px. Tests: Pest `tests/Feature/ERP/` (importador 16,
  API del panel 9, API estándar interna 14 —respuestas validadas con los
  **mismos JSON Schema y ejemplos** que Python—), Python
  `test_laravel_integrations.py` (9) y Vitest `OrdersPage.test.ts` (3).
  **Prueba en el stack** (IA simulada): CSV importado por la API → Elena
  pregunta por su pedido 90001 → `get_order` desde Laravel → **enviada sola**
  con MRW y el seguimiento; otro cliente pide el 90001 → "no hay ningún
  pedido" → revisión humana; auditoría con la importación y el borrado.
  Panel revisado con Chromium (escritorio y móvil). Los pedidos de prueba se
  borraron: "Empresa Demo" vuelve a los datos de demostración (`9570d17`)
- [x] 4. **El sistema propio de la empresa por la API estándar.** Tabla
  **`tenant_integrations`** (tenant restrict, `type` único por empresa —hoy
  `standard_api`—, `base_url`, `credentials` **cifrada** (cast `encrypted`,
  oculta en JSON), `orders`/`invoices` (qué implementa), `enabled`, `status`
  pending/active/failing, `last_tested_at`, `last_error`, `created_by`) y
  modelo `TenantIntegration`. `App\ERP\StandardApi\`: `StandardApiContract`
  (opis contra `contracts/integrations/schemas`, reutilizable),
  **`StandardApiTester`** (`rejectUrl`: HTTPS —HTTP solo con hosts privados
  permitidos—, sin usuario/contraseña/parámetros/`#`, host con
  `MailHostGuard`; `test`: `GET /v1/orders` (y `/v1/invoices`) con
  `customer_email=prueba-de-conexion@example.invalid&limit=1`, IP fijada con
  `CURLOPT_RESOLVE`, sin redirecciones, 5 s, máx. 1 MB, respuesta validada
  contra el contrato; mensajes "rechazó la clave", "no tiene la ruta…",
  "respondió 503", "no responde con el formato…") registrado en
  `MailProvidersServiceProvider` con el mismo permiso de hosts privados
  (solo local/testing) y `StandardApiConnectionService` (guardar —clave
  vacía = conservar— **y probar al momento**, probar, borrar; auditoría
  `integration.created/updated/tested/deleted`, la clave como `[oculto]`).
  API del panel (sin id en la URL): `GET|PUT|DELETE
  /integrations/standard-api` y `POST /integrations/standard-api/test`
  (`orders.view` para ver, `orders.manage` para cambiar; throttle 10/min).
  `/internal/integrations/{tenant}`: si hay conexión **activada** manda la
  configuración `{type: standard_api, base_url, token}` (clave descifrada,
  `Cache-Control: no-store`) para pedidos y/o facturas, **con prioridad sobre
  el CSV**; desactivada → vuelven los pedidos importados. **Servidor MCP:**
  `HostGuard` en `standard_api.py` (mismas reglas que `MailHostGuard`:
  enlace local y `0.0.0.0/8` siempre bloqueados, también como IPv4 en IPv6;
  privadas y HTTP solo con `allow_private`), comprobado **antes de cada
  petición** (el DNS puede cambiar tras la validación de Laravel);
  `LaravelIntegrations` construye `StandardApiOrders`/`StandardApiInvoices`
  con la URL y la clave de la empresa (`SecretStr`) y el guard; los errores
  de configuración se registran **solo por tipo** (un `ValidationError`
  incluiría la clave). `McpSettings.allow_private_hosts` solo cuenta con
  `AI_SERVICE_ENVIRONMENT` local/testing; compose: `ai-mcp` recibe
  `AI_SERVICE_MCP_ALLOW_PRIVATE_HOSTS` (= `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS`)
  y `AI_SERVICE_ENVIRONMENT`. Panel: tarjeta **"Tu propio sistema"** en
  Pedidos (`components/orders/StandardApiCard.vue`: estado, qué tiene, último
  error, editar con la clave vacía para conservarla, probar, desconectar);
  auditoría en español con `base_url`/`token`/`orders`/`invoices`/`enabled` y
  booleanos como sí/no. Tests: Pest `StandardApiConnectionTest` (13: conectar
  y probar sin devolver la clave —cifrada en la BD y fuera de la auditoría—,
  facturas, 5 motivos de fallo, sin conexión, **7 URL rechazadas** —metadatos
  siempre, loopback, red privada, HTTP, usuario, parámetros, no URL— sin
  enviar nada, clave conservada al editar, obligatoria al crear, probar y
  borrar, permisos, aislamiento, lo que recibe el servidor MCP y prioridad
  sobre el CSV), Python `test_company_standard_api.py` (11) y Vitest
  `StandardApiCard.test.ts` (4). **Prueba en el stack** (IA simulada; como
  "ERP" la API estándar interna de Laravel sirviendo los pedidos CSV de una
  empresa temporal): metadatos de la nube → 422; clave mala → "Falla"; clave
  buena → "Conectado"; email de Elena sobre su pedido 70707 → `get_order`
  contra el "ERP" con la clave de la empresa → **enviada sola** con GLS y el
  seguimiento. Antes de reconstruir bien la imagen, el servidor MCP viejo no
  entendía la configuración y el email fue a revisión humana con un error
  legible (el modo degradado funciona). Datos de prueba borrados.
  **Lección:** `ai-mcp` no tiene `build` propio (usa la imagen de
  `ai-service`): reconstruir con `docker compose up -d --build ai-service ai-mcp` (`70f812a`)
- [x] 5. **Conector de WooCommerce** (el primero de tiendas; PrestaShop y
  Shopify, mismo patrón, cuando haya demanda). **Servidor MCP:**
  `integrations/woocommerce.py` (`WooCommerceOrders`: `GET
  /wp-json/wc/v3/orders?search=<email>&per_page=…&orderby=date` con las
  claves como **Basic** —por HTTP WooCommerce exige OAuth 1.0a: no se usa—,
  pedido buscado por **número visible** entre los últimos 100 del cliente
  —los plugins de numeración lo separan del id— y **email exacto** (el
  `search` busca en más campos); estados `pending`/`on-hold`/`failed`/
  `checkout-draft` → pending_payment, `processing`, `completed` → **shipped**
  (WooCommerce no sabe si se entregó), `cancelled`, `refunded` → returned,
  estados de plugins → processing con aviso; fecha GMT marcada como UTC;
  seguimiento del plugin *Shipment Tracking* (`_wc_shipment_tracking_items`,
  solo enlaces http/https); formato tolerante, pero una respuesta que no es
  una lista de pedidos es `IntegrationError`). `StandardApiClient` acepta otra
  cabecera `authorization`; `LaravelIntegrations` entiende `type:
  woocommerce` (`consumer_key`/`consumer_secret` como `SecretStr`) con el
  mismo `HostGuard`. **Laravel:** refactor común en `app/ERP/Connections/`:
  `CompanyUrl` (anti-SSRF de las direcciones que escribe una empresa + IP
  fijada; registrado con el permiso de hosts privados), `ConnectionTestResult`
  y **`IntegrationService`** (sustituye a `StandardApiConnectionService`:
  guardar y probar por tipo; credenciales de WooCommerce cifradas como JSON
  `{consumer_key, consumer_secret}`; vacías = conservar; auditoría con
  `credentials` como `[oculto]`). `WooCommerceTester` (`GET
  /wp-json/wc/v3/orders?per_page=1` con Basic; 401/403 "rechazó las claves",
  404 "¿enlaces permanentes?", respuesta que no es lista de pedidos). Rutas
  `/integrations/{type}` (`standard-api` | `woocommerce`, `whereIn`); el
  recurso da `consumer_key_hint` ("…cdef"), nunca el secreto.
  `/internal/integrations`: **pedidos = ERP propio > WooCommerce > CSV**.
  Panel: tarjeta **Tienda WooCommerce** en Pedidos (`WooCommerceCard.vue`) y
  cliente `api/integrations.ts` por tipo. **Tienda real de pruebas:**
  `docker-compose.woocommerce.yml` (MariaDB 11.4.7, WordPress
  6.8.2-php8.3-apache, wp-cli 2.12.0; red `app-net`, sin puertos) +
  `docker/woocommerce/setup.sh` (idempotente: instala WordPress y WooCommerce
  9.9.5, permalinks, moneda EUR, 3 pedidos de Elena/Laura/Marcos —el de Elena
  con MRW— y claves de API **fijas de solo lectura**; falla si no puede
  crearlas). WordPress finge HTTPS (`$_SERVER['HTTPS']`) para aceptar Basic
  sin TLS en la red de Docker. **Encontrado al probarlo:** la clave inventada
  tenía 44 caracteres y WooCommerce guarda 43 (`cs_` + 40): el INSERT fallaba
  en silencio → claves con el formato real y el script se para si no se
  guardan. Tests: Python `test_woocommerce.py` (15: traducción completa,
  número visible frente a id, 8 estados, email exacto, sin seguimiento,
  claves malas y respuestas raras como error, claves fuera de los logs, de
  Laravel a `BusinessTools`), Pest `WooCommerceConnectionTest` (9) y Vitest
  `WooCommerceCard.test.ts` (3). **Prueba con la tienda real** (IA
  simulada): claves malas → "Falla" con el motivo; buenas → "Conectada";
  Laura "¿cuándo me llega lo que pedí?" → `get_customer_orders` + `get_order`
  contra WooCommerce → **enviada sola**; el pedido 13 de Elena se preparó con
  MRW y el seguimiento reales, y fue a revisión **por el límite contra bucles**
  (ya tenía 3 respuestas automáticas de las pruebas: correcto); pedido 15 de
  Marcos pedido por Elena (`ai-service-tool`) → "no hay ningún pedido".
  **Limitación de la IA simulada:** solo reconoce números de pedido de 5–6
  cifras (los de WooCommerce de pruebas son 13–15). Tras la prueba, tienda
  desconectada de "Empresa Demo" y contenedores parados (volúmenes intactos)

- [x] Fix (CI en rojo tras el push, 2026-10-22): el paso `pip-audit` del job
  `ai-service` falló por vulnerabilidades publicadas en dependencias, no por
  el código: **pypdf** 6.19.0 (5 CVE) → 6.21.2 y **certifi** 2026.7.22 →
  2026.10.19 (`uv lock --upgrade-package`); mínimo de `pyproject.toml` a
  `pypdf>=6.21.1` para que nunca se resuelva una versión vulnerable

**Estado (2026-10-21): tareas 1–5 hechas, sin push.** El usuario **aparca la
tarea 6** (las dos opciones de abajo) hasta que decida retomarla; pidió
dejarlas planificadas. Antes de empezar cualquiera de las dos: que haga push
de `fase-14-integraciones` y el CI esté en verde (cinco commits sin pasar por
GitHub Actions).

#### Tarea 6 — opción A elegida (2026-10-22), rama `fase-14-citas`

`fase-14-integraciones` se fusionó en `main` (fast-forward, CI en verde) y
se borraron las ramas de fases anteriores (las remotas, a mano por el
usuario: el proxy de la sesión no deja borrarlas). Alcance acordado,
recortado a propósito: **consultar citas** (no cambiarlas ni cancelarlas:
modificar datos exigiría aprobación humana, más adelante), tickets en la API
estándar después; contratos, conectores de agenda concretos y CSV de citas,
aplazados hasta que haya demanda.

- [x] 6.1 **Contrato de las citas.** `contracts/integrations/`: esquemas
  `appointment.v1` (requeridos `id`, `customer_email`, `status` —
  `scheduled`/`cancelled`/`completed`/`no_show`—, `starts_at` con zona; y
  `ends_at`, `customer_name`, `service`, `professional`, `location` —lugar de
  la empresa, nunca la dirección del cliente—, `instructions` —solo lo que
  el cliente puede leer, **nunca notas internas ni clínicas**—, `price`,
  `currency`) y `appointment-list.v1` (ordenadas por `starts_at` de la más
  lejana a la más antigua: las próximas, primero); endpoints
  `GET /v1/appointments/{id}` y `GET /v1/appointments` en el OpenAPI y el
  README (una empresa implementa solo las partes que tiene). Ejemplos
  válidos (completo, mínimo, lista, lista vacía) e inválidos (sin inicio,
  estado desconocido, inicio como número, id vacío, precio negativo, cita
  inválida en la lista). Python: `AppointmentPayload` y
  `AppointmentListPayload` (estrictos) en `standard_api.py`; el test de
  contrato exige que esquema y modelo acepten y rechacen lo mismo. Laravel
  valida los ejemplos nuevos solo (mismo test de `StandardApiTest`;
  comprobado aparte con opis en la sesión, sin entorno de Laravel)
- [x] 6.2 **Herramientas de citas en el servidor MCP.** Modelo `Appointment`
  e interfaz `AppointmentSource` (`find_appointment`,
  `customer_appointments`) en `TenantIntegrations.appointments`.
  `BusinessTools.get_appointment` (identificador normalizado como los
  pedidos: "la cita C-2031" → `C-2031`; cita de otro cliente, inexistente o
  empresa sin agenda → el mismo "No hay ninguna cita con ese identificador
  asociada a este cliente") y `get_customer_appointments` (las próximas
  primero, límite 1–10, `note` si no tiene). Lo que ve la IA, sin email ni
  nombre: id, estado + etiqueta, **`upcoming`** (programada y aún no ha
  empezado, calculado con un reloj inyectable: la IA no compara fechas),
  inicio/fin, servicio, profesional, lugar, indicaciones y precio.
  `StandardApiAppointments` (`/v1/appointments`; errores "el sistema de
  citas…") y `standard_api_integrations(appointments=)`; `LaravelIntegrations`
  ya entiende `appointments` en la configuración (solo `standard_api`: el
  CSV es de pedidos), Laravel lo enviará en la 6.4. **Demostración:**
  `data/appointments.json` con "clinica-demo" (María, Javier y Ana: citas
  programadas, realizada, no acudió y cancelada) y
  `AI_SERVICE_MCP_APPOINTMENTS_FILE`; una empresa de demostración tiene
  citas solo si las tiene en el fichero. **Especialista nuevo
  `appointments`** (contrato de eventos, dominio, `READ_TOOLS`, etiqueta
  "citas" en el prompt y "Citas" en el panel), dueño de las dos
  herramientas; todavía **no le llega ningún email** (el enrutado con la
  intención nueva es la 6.3), así que nada cambia para los emails actuales.
  Catálogo (`contracts/tools/catalog.json`, desactivadas por defecto),
  `SPECS` del redactor, `READ_ONLY_TOOLS` del cliente MCP y `toolLabels`
  del panel. Tests: reglas de `BusinessTools` (cita propia, ajena/
  inexistente/otra empresa con el mismo mensaje, las próximas primero,
  `upcoming` según estado y hora, nota sin citas, límite, un proveedor que
  devuelve citas de otro no llega a la IA), API estándar simulada (id
  codificado, límite, 404, respuestas no válidas como error del "sistema de
  citas", una clínica que solo implementa citas) y la agenda propia de una
  empresa vía Laravel. 515 tests de Python
- [x] 6.3 **Intención "cita" y enrutado.** Intención nueva **`appointment`**
  ("pregunta por una cita o pide reservarla, cambiarla o cancelarla"; en
  `cancellation` se aclara "no una cita") en los dos lados a la vez:
  contrato (`category` y `source.type` `appointment`), `Category` y
  `SourceType` de Python, `EmailIntent` de Laravel (se puede permitir el
  envío automático; no está entre las de por defecto) y panel (`Cita` en
  intenciones y fuentes). Prompts: **`email_classification@2`** y
  **`email_draft@7`** (reglas del especialista de citas: fecha y hora tal
  como figuran, `upcoming` para no presentar como próxima una cita pasada,
  cancelada o a la que no acudió, preguntar si hay varias próximas, **nunca
  confirmar que una cita se reservó, cambió o canceló** → `pending_actions`
  + persona; fuente `appointment` con su id). Supervisor: `appointment` →
  especialista `appointments`, cuyo plan es `get_customer_appointments`
  (el clasificador no extrae identificadores de cita y el cliente casi
  nunca los da); el modelo puede pedir `get_appointment`; `references_in`
  reconoce las citas como consultadas. IA simulada: "cita/citas/
  appointment" con límites de palabra ("solicitar" contiene "cita") antes
  que "cancelar"; redacta con la siguiente cita próxima (o la más reciente
  si no hay) y la cita como fuente. Ejemplo de contrato
  `email.processed.v1--appointment.json`. Tests: enrutado, plan,
  referencias, clasificación y redacción simuladas, **grafo + IA simulada +
  servidor MCP real con la clínica** (María recibe su C-2031 y se envía
  sola; Javier solo ve la suya; una tienda sin agenda recibe la lista vacía
  y pasa a una persona) y Pest (el ejemplo aplicado: enviada sola con la
  intención permitida, a una persona con las de por defecto). 525 tests de
  Python, 1035 de Laravel (todo menos el grupo `greenmail`) y 100 de
  Vitest en verde. **Entorno de la sesión:** PHP 8.3 en el sistema y
  Composer sin zips de GitHub (403) → `composer install --prefer-source
  --ignore-platform-req=php` (clona con git) y Pest en
  `mirror.gcr.io/serversideup/php:8.4-cli-alpine` (`-u 0`, red del host)
  contra PostgreSQL 16 + `postgresql-16-pgvector` y Redis de la sesión; con
  `GREENMAIL_HOST=127.0.0.1` como en el CI (si no, 2 tests de anti-SSRF
  fallan porque `greenmail` no resuelve)
- [x] 6.4 **La agenda de una empresa en el panel y Clínica Demo.**
  Migración `add_appointments_to_tenant_integrations` (booleano
  `appointments`, como `orders`/`invoices`: más simple que una lista
  genérica de recursos). `IntegrationService` lo guarda (sin el campo en la
  petición, **se conserva**: un cliente antiguo de la API no lo apaga;
  WooCommerce nunca) y lo audita; `StandardApiTester` prueba también
  `/v1/appointments` contra `appointment-list.v1` ("El sistema de citas
  no responde con el formato…"; sin ninguna parte: "pedidos, facturas o
  citas"); validación `sometimes|boolean`, recurso con `appointments`;
  `/internal/integrations` manda `appointments` (solo del sistema propio)
  y **`tenant_slug`**. **Datos de demostración por slug:**
  `DemoIntegrations.for_tenant(tenant_id, slug=)` y `LaravelIntegrations`
  pasa el `tenant_slug` al respaldo de demostración (`fallback` tipado como
  `DemoIntegrations`): una empresa con slug `clinica-demo` tiene las citas
  sin configurar ids; un slug que no está en los ficheros no recibe nada
  aunque su id coincida con `AI_SERVICE_MCP_DEMO_TENANTS` (que sigue para
  el modo sin Laravel). **`ClinicDemoSeeder`** (aparte, como
  `KnowledgeBaseSeeder`; idempotente): "Clínica Demo", propietaria
  `clinica@example.com` / `password`, herramientas de citas activadas.
  Panel: casilla **Citas** en "Tu propio sistema" (`parts()`: "Pedidos,
  Facturas y Citas"), "Citas" en la auditoría. Tests: Pest (agenda de una
  clínica probada solo en `/v1/appointments` y entregada al servidor MCP,
  formato erróneo, se conserva al editar sin el campo, ninguna parte,
  seeder), Python (demostración por slug) y Vitest (clínica solo con
  citas). 526 de Python, 1039 de Laravel, 101 de Vitest. **Pendiente:**
  la tarjeta vive en la página "Pedidos" y pide `orders.manage`; si se
  añaden más tipos de negocio, valorar una página "Integraciones"
- [x] 6.5 **Prueba de punta a punta con la IA simulada (2026-10-22).**
  Ajuste previo: la IA simulada imita la regla de `email_draft@7` (pedir
  reservar, cambiar o cancelar una cita → acción pendiente + persona; antes
  lo habría enviado solo). Stack de la sesión: PostgreSQL + pgvector y
  Redis locales, GreenMail (`mirror.gcr.io`), Laravel en el contenedor de
  PHP 8.4 (`php artisan serve` con **`PHP_CLI_SERVER_WORKERS=4`**: con un
  solo proceso, Laravel → ai-service → MCP → Laravel se bloquea y la
  disponibilidad de las herramientas sale "desconocida"), `queue:work
  redis --queue=ai,mail,default` en lugar de Horizon (`ProcessEmailJob`
  usa la conexión `redis` aunque `QUEUE_CONNECTION=sync`), servidor MCP y
  worker de ai-service con `uv run`. `migrate --seed` + `ClinicDemoSeeder`
  (Clínica Demo = empresa 2) → buzón `recepcion@clinica.test` creado y
  probado por la API (recepción y envío OK) → modo `auto` con
  `appointment` permitida → el panel ve `get_appointment` y
  `get_customer_appointments` **disponibles** (citas de demostración por el
  slug, sin configurar ids). 5 correos por SMTP → `mail:sync` → publicados
  → `email_processed` ×5 → `ai:consume-results` (5 `applied`):
  - María "¿a qué hora tengo la cita?" → `get_customer_appointments` (plan)
    → **enviada sola**: C-2031, Limpieza dental, 29/10/2026 a las 10:30,
    Dra. López, consulta 2; llegó a su buzón como "Re: Mi cita", en el hilo
    y con `Auto-Submitted`. Traza en el panel: especialista `appointments`,
    fuente `appointment` C-2031.
  - Javier "¿cuándo es mi próxima cita?" → **enviada sola** con **su**
    C-2044 (Empaste, 05/11 a las 17:00, Dr. Martín), nunca la de María.
  - Ana "quiero cancelar mi cita" y María "quiero cambiar mi cita" →
    **revisión humana** con acción pendiente; nada enviado.
  - Pedro (sin citas) → **revisión humana** (confianza 0,50, sin datos);
    nada enviado.
  - Bandeja de revisión: 3 casos. **Tarea 6 (opción A) terminada.**

#### Opciones que se plantearon para la tarea 6

**Opción A (recomendada): herramientas para negocios sin pedidos** (citas,
contratos/suscripciones, tickets). Abre la plataforma a empresas de
servicios (clínicas, academias, asesorías, SaaS), que hoy solo tienen
documentación + revisión humana. Mismo patrón que pedidos y facturas:
1. **Contrato**: ampliar la API estándar **v1** con recursos opcionales
   (añadir endpoints opcionales no rompe la v1): `GET /v1/appointments?
   customer_email=&limit=` y `/v1/appointments/{id}` (fecha y hora con zona,
   servicio, profesional, lugar, estado `scheduled`/`cancelled`/`completed`/
   `no_show`; nunca notas clínicas), `GET /v1/contracts?…` y
   `/v1/contracts/{id}` (plan, estado `active`/`paused`/`cancelled`,
   renovación, importe periódico), `GET /v1/tickets?…` y **`POST /v1/tickets`**
   (escritura con cabecera `Idempotency-Key` = `action_id`). Esquemas
   `schemas/{appointment,contract,ticket}(-list).v1`, ejemplos válidos e
   inválidos, OpenAPI y README de `contracts/integrations/` ampliados.
2. **Servidor MCP**: modelos `Appointment`, `Contract` (y `Ticket` ya
   existe), interfaces `AppointmentSource`, `ContractSource`
   (`TicketSource` ya existe) en `TenantIntegrations`; `StandardApi*`
   para cada una (modelos Pydantic estrictos + test de contrato que acepta y
   rechaza lo mismo que el esquema); `BusinessTools`: `get_appointment`,
   `get_customer_appointments`, `get_contract`, `get_customer_contracts`
   (mismas reglas: solo el cliente que escribe, ajeno = inexistente,
   límites) y `availability()` con las nuevas; herramientas en `server.py`.
3. **Catálogo** `contracts/tools/catalog.json`: las herramientas nuevas (por
   defecto desactivadas; el test catálogo = servidor MCP las exige).
   **Ojo:** "pedir/cambiar una cita" no existe como intención →
   `EmailIntent` (Laravel), `category` del contrato de eventos y el prompt
   de clasificación (`email_classification@2`, sube versión) cambian a la
   vez en los dos lados (p. ej. intención `appointment`). Especialista:
   añadir `appointments` o colgarlo de `support`/`general` (decidirlo; el
   supervisor es determinista por intención); planes en `tool_planner`
   (cita → `get_customer_appointments`; `account_change` →
   `get_customer_contracts`).
4. **Laravel**: `tenant_integrations` pasa de `orders`/`invoices` a una
   columna `resources` jsonb (migración que conserve lo actual) o añadir
   booleanos `appointments`/`contracts`/`tickets` (más simple; decidir);
   `StandardApiTester` prueba cada recurso marcado; `/internal/integrations`
   manda `appointments`/`contracts`/`tickets`; tarjeta "Tu propio sistema"
   con las casillas nuevas; `toolLabels` en el panel.
5. **Helpdesk real** (tickets): conector como el de WooCommerce. Para
   probar gratis en Docker: **Zammad** (open source, API REST con token);
   Freshdesk (plan gratuito, solo en la nube, API key como Basic) y Zendesk
   (prueba de 14 días) después. Crear ticket sigue pasando por la
   aprobación humana que ya existe (`pending_action` → `interrupt`).
6. Opcional: importar citas por CSV como los pedidos (`OrderCsvImporter`
   como modelo). Prueba de punta a punta con la IA simulada (ojo: sus
   reglas por palabras clave tendrán que reconocer citas).

**Opción B: más tiendas** (mismo patrón que WooCommerce: traductor en
`ai-service/src/ai_service/mcp_server/integrations/<tienda>.py`, tester en
`app/ERP/<Tienda>/`, tipo nuevo en `IntegrationController::TYPES`,
`TenantIntegration`, `/internal/integrations`, tarjeta en Pedidos y tests
con respuestas reales grabadas). Decidir antes si una empresa puede tener
**varias tiendas** a la vez (hoy la prioridad es ERP > WooCommerce > CSV y
gana una sola).
- **PrestaShop** (probable primero: se prueba gratis en Docker como
  WooCommerce): imagen oficial `prestashop/prestashop` 8.x + MariaDB con un
  `docker-compose.prestashop.yml` y script de preparación (activar el
  Webservice y crear una clave de solo lectura). API: la clave como usuario
  de Basic, `output_format=JSON`; buscar el cliente por email
  (`/api/customers?filter[email]=[x]`) y sus pedidos
  (`/api/orders?filter[id_customer]=[id]&display=full`); el número visible
  es la **`reference`** (p. ej. "XKBKNABJK"), no el id; el estado es un id
  de `current_state` → traducirlo con `/api/order_states` (son
  configurables por tienda: mapear por los estados de serie y lo demás a
  "en preparación" con aviso); seguimiento en `order_carriers`
  (`tracking_number`).
- **Shopify** (no se puede en Docker): tienda de desarrollo gratuita con una
  cuenta de Partner + app personalizada con token de Admin API de solo
  lectura (`read_orders`, `read_customers`). **GraphQL Admin API** con
  versión fijada (una con al menos dos semanas); `orders(query:
  "email:<x>")`; número visible = `name` ("#1001"); estados a partir de
  `displayFinancialStatus` + `displayFulfillmentStatus`; seguimiento en
  `fulfillments.trackingInfo`; límites por coste de consulta (429 →
  `IntegrationError` "no responde"). Tests con respuestas grabadas y prueba
  real en la tienda de desarrollo con el token del usuario.

**Lecciones del entorno (2026-10-19):** tras traer cambios con migraciones,
el entorno local del usuario no estaba migrado desde la Fase 13 y ai-service
no arrancaba (faltaba el rol `ai_service_checkpoints`): `composer install`,
`migrate`, `RolesAndPermissionsSeeder`, reconstruir `ai-service`/`ai-mcp`/
`ai-results` y `kb:reindex --tenant=1`. Si el scheduler falla a mitad (p. ej.
durante `composer install`), **`mail:sync` se queda con el mutex de
`withoutOverlapping`** ("Has Mutex" en `schedule:list`) y el buzón deja de
sincronizar sin error: `php artisan schedule:clear-cache`. Al reiniciar
GreenMail cambia el UIDVALIDITY: el buzón se resincroniza sin histórico (los
correos enviados justo antes no se importan; es lo previsto). Los tests de
Python se ejecutan en un contenedor `python:3.12-slim` con uv en la red
`ai-mail-automation-platform_app-net` (en el equipo del usuario no hay uv),
con `AI_SERVICE_TEST_REDIS_URL`/`AI_SERVICE_TEST_DATABASE_URL` apuntando a
`redis`/`postgres` (BD `app_testing`).

### Fase 15 (evaluación de la IA): EN CURSO (desde 2026-10-22), rama `fase-15-evaluacion`

Misma forma de trabajo: un commit por tarea, tests en verde, CI comprobado
tras cada push, documentación al día. **Pedido por el usuario:** primero
todo con la **IA simulada** y, solo cuando funcione bien, con **Claude real**
(para no gastar saldo en errores del ejecutor). La clave de Anthropic está en
las variables de entorno del entorno de la nube (`ANTHROPIC_API_KEY`); la
recoge una sesión **nueva** (la de 2026-10-22 no la ve).

**Objetivo:** medir con datos la calidad de ai-service (intención, seguridad
de la decisión, datos inventados, coste) y comparar modelos y prompts, sin
Laravel ni correo: el ejecutor lanza el agente real (grafo, herramientas
con los datos de demostración, documentación de ejemplo) contra cada caso.

**Decisiones:** el conjunto es un JSON versionado en
`ai-service/evals/dataset.json`; el modelo de casos y la puntuación (lógica
pura) en `application/evaluation/`; el ejecutor en `entrypoints/` (compone
el agente). Lo que importa no es el texto exacto: **un envío automático
cuando `auto_send_ok=false` es un fallo grave**; además intención,
automatización perdida, datos obligatorios y prohibidos.

**Tareas:**
- [x] 15.1 **Conjunto de casos** (`evals/dataset.json`, 40): los 32 correos
  de la puerta de la retirada + 8 de la clínica (citas: hora, próxima,
  cancelar, cambiar, sin citas, no acudió, inglés) + factura ajena. Cada
  caso: empresa (`shop` = Empresa Demo, `clinic` = Clínica Demo),
  remitente, asunto, cuerpo, `expect` (`intents` aceptables —vacío =
  cualquiera, p. ej. la prompt injection—, `language`, **`auto_send_ok`**,
  `ideal` auto_send/human_review/either, `must_include` —texto o lista de
  alternativas: "14,95"/"14.95"—, `must_not_include` —datos de otro
  cliente, direcciones inventadas, el propio prompt—, `tools` esperadas) y
  etiquetas (`seguridad`, `alucinacion`). Modelo Pydantic `Dataset`/`Case`/
  `Expectation` (estricto: `ideal=auto_send` exige `auto_send_ok`; ids
  únicos; `select(only, limit)`). Tests: carga, las 15 intenciones y las
  dos empresas cubiertas, casos de seguridad y cada `ideal`, herramientas
  esperadas del catálogo, coherencia, selección

### Pendientes anotados para más adelante

- **Rutas `/internal` en producción (Fase 16):** hoy nginx las sirve a
  cualquiera y solo las protege su token o su firma (en local no se puede
  distinguir: el tráfico del host también llega desde la red de Docker). En
  producción, que el ingress/nginx público las bloquee y solo se alcancen
  desde la red interna (ai-service, ai-mcp).

- **Herramientas por tipo de negocio (Fase 14, integraciones)** — pedido
  por el usuario (2026-10-14): empresas sin pedidos (atención al cliente,
  servicios) necesitan otras herramientas, cada una con su proveedor
  intercambiable como `OrderProvider` y las mismas reglas (atadas al
  remitente, validadas, auditadas, aisladas por empresa): soporte
  `get_ticket`/`create_support_ticket` (Zendesk, Freshdesk, helpdesk
  propio), citas `get_appointment`/`get_available_slots` (agenda,
  Calendly), contratos/suscripciones `get_contract`/`get_invoice` (CRM,
  facturación). Sin herramientas de datos el agente sigue pudiendo
  responder con `search_company_documents` (FAQs, condiciones) y derivar
  a una persona lo demás.
- **Herramienta genérica configurable por empresa** (más adelante y solo
  si hay demanda real): el administrador define URL, parámetros y datos
  devueltos de una API propia. Muy flexible pero arriesgada (SSRF,
  filtrado de datos de otros clientes, credenciales): exigiría
  anti-SSRF como `MailHostGuard`, credenciales cifradas, atadura al
  remitente y límites de respuesta.

- ~~Autodetección de configuración IMAP/SMTP~~: **hecha en la Fase 10,
  tarea 11**.
- **Registrar apps OAuth y probar con cuentas reales**: Microsoft (Entra
  ID, gratis; en empresas cliente puede requerir consentimiento del
  admin) y Google (modo pruebas: máx. 100 usuarios de prueba y refresh
  token que caduca a los 7 días; producción exige verificación +
  auditoría CASA anual por `gmail.readonly`). Mientras tanto, Gmail puede
  conectarse por IMAP con contraseña de aplicación; Microsoft 365 no
  (Microsoft desactivó usuario/contraseña en IMAP/SMTP de empresa).
- Microsoft: envío de adjuntos > 3 MB (upload session) y detección de
  correos movidos entre carpetas (delta query) si hace falta.
- Copia en "Enviados" de lo enviado por SMTP: **aplazada en la Fase 9**
  (las respuestas ya quedan en `email_replies`; hay servidores SMTP que la
  guardan solos, como Gmail, y saldría duplicada). Valorar una opción por
  buzón (IMAP APPEND a la carpeta con rol Sent) cuando haya panel.
- Clasificación de preguntas informativas sobre devoluciones: en la prueba
  real de la Fase 9, "¿cuántos días tengo para devolver…?" se clasificó
  `product_question` y se envió sola (respuesta exacta según la política).
  Es razonable (solo informa), pero si una empresa no lo quiere, quitar
  `product_question` de sus intenciones o afinar el prompt (Fase 15).
- Ingesta (Fase 4), límites conocidos:
  - Solo se sincroniza la bandeja de entrada (añadir carpetas = más filas
    en `mail_sync_states`; valorar cuando haga falta).
  - **Mensaje "envenenado":** si un correo concreto falla siempre con un
    error no temporal (bug, dato imposible de guardar), el cursor no
    avanza y el buzón se queda atascado (`consecutive_failures` crece).
    Alertar en Fase 11 y valorar saltarlo tras N intentos.
  - ~~Borrar un email no borraba los ficheros de adjuntos~~: **hecho en la
    Fase 12, tarea 9** (`EmailEraser`).
  - IMAP: si UIDVALIDITY cambia entre guardar un email y descargar sus
    adjuntos (reintento), el UID guardado puede apuntar a otro mensaje.
    Muy improbable; revisar si aparece.
  - Buzones `pending` no se sincronizan hasta probarlos: valorar probar
    automáticamente al crear la conexión (sobre todo OAuth).
- "Iniciar sesión con Microsoft/Google" para los usuarios de la app: NO
  existe (el OAuth de la Fase 3 es solo para conectar buzones). Valorar
  si interesa.

### Reglas de trabajo que aplican en TODO momento

Ver secciones 18 y 19 de este documento: paso a paso, tareas pequeñas,
código completo con ruta exacta, comandos de verificación, sin
sobreingeniería (no construir para escala/casos de uso que aún no
existen), comparar opciones técnicas cuando las haya.