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

### Fase 3 (conexiones de correo): EN CURSO (iniciada 2026-09-30)

Misma forma de trabajo que la Fase 2: un commit por tarea, tests antes de
cada commit, este archivo actualizado en cada commit, sin push.

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
  `#[SensitiveParameter]` (fuera de las trazas)
- [ ] 4. Modelo `MailConnection`
- [ ] 5. `MailProviderFactory`
- [ ] 6. API de conexiones (CRUD + policy + permisos) — añadida
- [ ] 7–12. IMAP: conexión, autenticación, carpetas, mensajes, adjuntos, nuevos emails
- [ ] 13–16. SMTP: conexión, envío, sistema de prueba de conexión, tests IMAP/SMTP
- [ ] 17–20. Microsoft Graph: cliente, OAuth, lectura, envío
- [ ] 21–24. Google Gmail API: cliente, OAuth, lectura, envío

### Reglas de trabajo que aplican en TODO momento

Ver secciones 18 y 19 de este documento: paso a paso, tareas pequeñas,
código completo con ruta exacta, comandos de verificación, sin
sobreingeniería (no construir para escala/casos de uso que aún no
existen), comparar opciones técnicas cuando las haya.