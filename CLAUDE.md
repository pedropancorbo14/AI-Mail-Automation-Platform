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

## ESTADO ACTUAL (actualizado 2026-10-17)

**Decisión ya tomada, distinta al plan original:** empezar la Fase 5 (AI
Engine) con un único proveedor de IA — **Claude/Anthropic** — en vez de
OpenAI primero, por presupuesto ajustado ahora mismo. OpenAI/Gemini quedan
como interfaces intercambiables para el futuro, no implementadas todavía.

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

### Fase 13 (human in the loop): EN CURSO (2026-10-17)

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

### Pendientes anotados para más adelante

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