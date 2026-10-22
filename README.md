# AI Mail Automation Platform

Plataforma SaaS **multi-tenant** de automatización de emails para empresas.
Cada empresa conecta sus cuentas de correo y la plataforma recibe los emails,
los clasifica con IA, consulta sus sistemas internos (CRM, ERP, pedidos,
facturación, tickets, documentación) mediante *tool calling*, genera una
respuesta y decide si **enviarla sola**, dejarla como **borrador** para un
trabajador o **escalarla** a una persona. Todo queda auditado y medido en
Grafana.

No es un chatbot: es una capa de IA integrada con los sistemas reales de
cada empresa.

```
Email → IMAP → Laravel → Cola (Redis) → IA: clasificación → RAG / Tools
      → respuesta + nivel de confianza → envío automático o revisión humana
      → auditoría y métricas (Grafana)
```

## Estado actual

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Base: Docker, PostgreSQL + pgvector, Redis, Horizon, Sanctum, API `/api/v1`, logging, Pest, CI | ✅ Hecha |
| 2 | Empresas (tenants), usuarios, login/logout, roles y permisos, aislamiento entre empresas | ✅ Hecha |
| 3 | Conexiones de correo: IMAP/SMTP, Microsoft 365 (Graph + OAuth), Gmail (API + OAuth), credenciales cifradas, prueba de conexión | ✅ Hecha |
| 4 | Ingesta de emails: sincronización periódica de los buzones, emails + destinatarios + adjuntos guardados sin duplicados, reintentos | ✅ Hecha |
| 5 | IA con Claude (Anthropic) — *hoy en ai-service*: clasificación de cada email (intención, confianza, resumen, datos), registro de cada petición con tokens, coste y latencia | ✅ Hecha (probada con Claude real: ~0,012 $ por email) |
| 6 | Base de conocimiento (RAG) — *indexado y búsqueda hoy en ai-service*: documentos de la empresa (PDF, TXT, Markdown, HTML), troceado, embeddings **locales** (Ollama + bge-m3) y búsqueda por significado con pgvector, citando la fuente | ✅ Hecha |
| 7 | Tools — *hoy en el servidor MCP de ai-service*: herramientas controladas que la IA puede pedir (documentación, pedidos del cliente), validadas, con permisos y auditadas en `ai_tool_calls` | ✅ Hecha |
| 8 | Agente — *hoy en ai-service*: Claude usa las herramientas en cadena, prepara una propuesta de respuesta citando sus fuentes y el backend decide si necesita revisión humana | ✅ Hecha (probada con Claude real: ~0,05 $ por respuesta) |
| 9 | Automatización: cada email se procesa solo de principio a fin (clasificar → agente → reglas → **enviar en el hilo** o revisión humana), con estados, historial y protección contra bucles | ✅ Hecha (probada con Claude real y GreenMail) |
| 10 | Panel de administración (Vue + Naive UI): resumen, emails con lo que hizo la IA, buzones (con autodetección), documentación, ajustes de la IA, empresa y usuarios | ✅ Hecha (probada en navegador) |
| 11 | Observabilidad: métricas de Prometheus, 6 dashboards de Grafana, logs en Loki y alertas probadas; IA simulada para probar sin coste | ✅ Hecha (probada en el stack con la IA simulada) |
| 12 | Seguridad: auditoría inmutable, cuenta propia, freno del gasto de IA, revisión del texto antes de enviar, cabeceras y CSP, secretos fuera de los logs, retención y derecho de supresión, infraestructura solo en local, tests de arquitectura | ✅ Hecha (probada con ataques contra el stack) |
| 13 | Revisión humana (*human in the loop*): bandeja de casos pendientes, aprobar, corregir o descartar la respuesta de la IA, responder, cerrar, reprocesar, asignarse casos, historial de decisiones con métricas y alerta | ✅ Hecha (probada en el navegador con la IA simulada y GreenMail) |
| AI | **Microservicio de IA en Python** ([`ai-service/`](ai-service/README.md)): LangGraph, Redis Streams, MCP, arquitectura hexagonal; Laravel publica cada email como evento y aplica el resultado | ✅ Las 6 fases hechas: 1 camino mínimo, 2 RAG, 3 MCP + tools, 4 aprobación humana (`interrupt` y checkpoints en PostgreSQL), 5 supervisor con especialistas, redactor agéntico y modo sombra, y 6 Kubernetes (Kustomize, Pod Security restricted, NetworkPolicies; desplegado en kind en el CI en cada push). Probadas de punta a punta con la IA simulada y con Claude real. **Es el único motor de IA**: el de Laravel se retiró (octubre de 2026) tras una puerta de calidad con Claude real en modo sombra |
| 14–17 | Integraciones, evaluación, producción, SaaS | Pendiente |

Ahora mismo existe la **base multi-tenant**, la **API de usuarios**, las
**conexiones de correo** y la **ingesta**: cada minuto la plataforma trae
los correos nuevos de los buzones conectados, los guarda (con destinatarios
y adjuntos) y los **clasifica con Claude**: intención, confianza, idioma,
resumen, si necesita una persona y datos como el número de pedido; toda
la IA la hace el microservicio [`ai-service`](ai-service/README.md). Cada
empresa puede además subir su **documentación** (políticas, FAQs,
manuales), que se indexa en local y se puede consultar por significado,
y la IA tiene **herramientas controladas** para consultar esa
documentación y los pedidos del cliente que escribe. Con ellas, el
**agente** prepara una **propuesta de respuesta** para cada email, citando
de dónde saca cada dato, y el backend decide si una persona debe revisarla.
Si la empresa lo permite y la respuesta es segura, **se envía sola** en el
hilo del cliente; si no, va a la **bandeja de revisión**, donde una persona
la aprueba (tal cual o corregida), la descarta y escribe la suya, o cierra
el caso (ver [Revisión humana](#14-revisión-humana-human-in-the-loop)). Todo se ve y se configura desde el **panel web**
(`http://localhost:8080`), y el funcionamiento de la plataforma (volumen,
automatización, IA, costes, errores) se sigue en **Grafana**
(`http://localhost:3000`) con alertas. Lo que hace cada persona queda en
un **registro de auditoría** que nadie puede modificar, la IA tiene un
**presupuesto diario** por empresa, el texto de cada respuesta se
**revisa antes de enviarse sola** y los emails se **borran solos** pasado
el plazo de retención (ver [Seguridad](#13-seguridad)). La hoja de ruta completa y las
decisiones de arquitectura están en [`CLAUDE.md`](CLAUDE.md).

---

## 1. Arranque en local (desde cero)

**Requisitos:** Docker y Docker Compose v2 (`docker compose version`). No
hace falta PHP ni Composer en tu máquina: todo corre dentro de los
contenedores.

```bash
git clone <URL-del-repo>
cd AI-Mail-Automation-Platform

# 1. Variables de entorno (valores de desarrollo listos para usar)
cp .env.docker.example .env

# 2. Construir y levantar todos los servicios
docker compose up -d --build

# 3. Instalar dependencias PHP dentro del contenedor
#    (el código se monta desde tu disco y tapa el vendor/ de la imagen)
docker compose exec app composer install

# 4. Generar la clave de la aplicación y recrear los contenedores para
#    que la reciban (docker-compose.yml la pasa como variable de entorno)
docker compose exec app php artisan key:generate
docker compose up -d

# 5. Crear las tablas y los datos iniciales
docker compose exec app php artisan migrate --seed

# 6. Descargar el modelo de embeddings para el RAG (una vez, ~1,2 GB)
docker compose exec ollama ollama pull bge-m3

# 7. (Opcional) Documentación de ejemplo de "Empresa Demo" para probar el RAG
docker compose exec app php artisan db:seed --class=KnowledgeBaseSeeder

# 8. (Opcional) "Clínica Demo": un negocio con citas (clinica@example.com / password)
docker compose exec app php artisan db:seed --class=ClinicDemoSeeder
```

El **panel** (`http://localhost:8080`) lo sirve en desarrollo el servicio
`vite` (se levanta con los demás e instala solo sus dependencias de Node la
primera vez: tarda un par de minutos). Sin Vite, compílalo una vez:
`docker compose run --rm vite npm run build`.

> Entre los pasos 2 y 4 es **normal** ver el contenedor `horizon`
> reiniciándose: no puede arrancar hasta que existen `vendor/` y `APP_KEY`.

**Comprobar que funciona:**

```bash
docker compose ps                                   # todos los servicios "Up"
curl http://localhost:8080/api/v1/health            # {"status":"ok",...}
docker compose exec app php artisan test            # todos los tests en verde
```

| Servicio | URL | Acceso |
|---|---|---|
| **Panel** | http://localhost:8080 | `test@example.com` / `password` |
| API | http://localhost:8080/api/v1 | ver [API](#16-api) |
| Horizon (colas) | http://localhost:8080/horizon | libre en entorno `local` |
| **Grafana** (dashboards y alertas) | http://localhost:3000 | `admin` / `admin` (`GRAFANA_USER`/`GRAFANA_PASSWORD`; cámbiala fuera de tu equipo) |
| Prometheus | http://localhost:9090 | — |
| PostgreSQL (HeidiSQL, DBeaver…) | `127.0.0.1:5433`, BD `app` | `app` / `secret` |
| GreenMail (correo de pruebas) | IMAP `127.0.0.1:3143` · SMTP `127.0.0.1:3025` (sin TLS) | `soporte@demo.test` / `secret` |

**Ver la base de datos con un cliente gráfico** (HeidiSQL, DBeaver,
pgAdmin…): tipo de red **PostgreSQL (TCP/IP)**, host `127.0.0.1`, puerto
`5433`, usuario `app`, contraseña `secret`, base de datos `app` (la de los
tests es `app_testing` y se vacía en cada test). El puerto solo escucha en
tu máquina, no en la red; se cambia con `DB_FORWARD_PORT` en `.env`. Desde
consola: `docker compose exec postgres psql -U app -d app`.

**Usuario de desarrollo** (lo crea el seeder): `test@example.com` /
`password`, *owner* de la empresa "Empresa Demo".

### Comandos útiles

```bash
docker compose exec app php artisan migrate:fresh --seed   # BD de desarrollo desde cero
docker compose exec app php artisan tinker                 # consola interactiva
docker compose exec app php artisan route:list --path=api  # rutas de la API
docker compose logs -f horizon                             # logs de las colas
docker compose exec app php artisan mail:sync              # sincronizar ya los buzones (si no, cada minuto)
docker compose exec app php artisan kb:search "¿cómo devuelvo un producto?" --tenant=1   # buscar en la documentación
docker compose exec app php artisan ai:tools 1             # herramientas activadas para la empresa 1
docker compose exec app php artisan ai:trace 1             # lo que hizo la IA con el email 1: pasos, herramientas, propuesta y coste
docker compose exec ai-service ai-service-tool --list      # herramientas del servidor MCP (y ejecutarlas a mano)
docker compose exec app php artisan ai:automation 1        # modo de automatización de la empresa 1 (off / draft / auto)
docker compose exec app php artisan ai:process 1 --now     # procesar el email 1 (espera a ai-service) y ver su historial de estados
docker compose exec app php artisan ai:budget 1            # presupuesto diario de IA de la empresa 1 y gasto de hoy (--daily=25 para cambiarlo)
docker compose exec app php artisan data:prune             # aplicar la retención ya (si no, cada noche a las 03:30)
docker compose exec app php artisan data:forget-address 1 ana@cliente.test   # derecho de supresión (cuenta; --force para borrar)
docker compose exec app php artisan security:check --production              # ¿la configuración es apta para producción?
docker compose exec app tail -f storage/logs/laravel-$(date +%F).log
docker compose logs -f vite                                # servidor de desarrollo del panel
docker compose exec vite npm test                          # tests del panel (Vitest)
curl -H "Authorization: Bearer local-metrics-token" http://localhost:8080/metrics   # métricas para Prometheus
docker compose exec prometheus promtool test rules /etc/prometheus/alerts.test.yml  # probar las alertas
```

---

## 2. Arquitectura

Monolito modular en Laravel (una sola imagen de PHP para la app web, los
workers de colas y el scheduler) más un **microservicio de IA en Python**,
[`ai-service/`](ai-service/README.md), que hace toda la IA y se comunica
con Laravel por eventos en Redis Streams. Laravel se queda con la ingesta,
las empresas, el panel, los estados, las reglas finales y el envío.

```
  Cliente (curl, panel, integraciones)
        │  http://localhost:8080
        ▼
     nginx ──FastCGI──► app (PHP-FPM, Laravel)
                          │   encola jobs
                          ▼
   ┌──────────────┐    ┌──────────────────────────┐    ┌──────────────┐
   │  PostgreSQL  │◄───│ app · horizon · scheduler │───►│    Redis     │
   │  + pgvector  │    │ (misma imagen de PHP)     │    │ colas, caché │
   └──────────────┘    └──────────────────────────┘    │  y sesiones  │
                                                        └──────────────┘
   horizon   = workers que procesan las colas de Redis
   scheduler = ejecuta las tareas programadas cada minuto

   greenmail = servidor de correo de PRUEBAS (IMAP + SMTP), solo en
               desarrollo y CI: no envía nada fuera
   ai-service + ai-mcp = la IA (Python): lee email.received.v1 y
               document.uploaded.v1 de Redis Streams y publica los resultados;
               ai-results (Laravel) los aplica
   ollama    = modelo de embeddings LOCAL (bge-m3) para el RAG: los
               documentos de las empresas no salen del servidor

  Observabilidad:
   logs de Laravel y contenedores ──► promtail ──► loki ──────┐
   postgres-exporter, redis-exporter ──► prometheus ──────────┴──► grafana
```

| Servicio | Qué hace |
|---|---|
| `app` | PHP-FPM 8.4 con Laravel 13. Atiende las peticiones HTTP. |
| `nginx` | Servidor web; pasa las peticiones PHP a `app`. |
| `horizon` | Procesa los jobs en cola (Redis) con Laravel Horizon. |
| `scheduler` | Ejecuta `php artisan schedule:run` cada minuto (tareas programadas, ver `routes/console.php`). |
| `postgres` | PostgreSQL 16 con la extensión `pgvector` (vectores del RAG, índice HNSW). Crea también la BD `app_testing` para los tests. |
| `redis` | Colas, caché y sesiones. Con contraseña y persistencia AOF. |
| `postgres-exporter`, `redis-exporter` | Exponen métricas de BD y Redis para Prometheus. |
| `prometheus` | Recoge métricas. |
| `loki` + `promtail` | Almacén y recolector de logs (Laravel + contenedores). |
| `grafana` | Paneles; datasources de Prometheus y Loki ya configurados. |
| `greenmail` | Servidor de correo de pruebas (IMAP 3143, SMTP 3025, API 8080). Lo usan los tests de IMAP/SMTP y sirve para probar a mano (p. ej. con Thunderbird). Todo se queda en memoria. |
| `ollama` | Modelos de IA locales: calcula los embeddings del RAG con `bge-m3`. Solo en la red interna (sin puerto publicado); el modelo se guarda en el volumen `ollama-data`. |
| `ai-service` | Microservicio de IA en Python ([`ai-service/`](ai-service/README.md)), el único motor de IA: consume `email.received.v1` y `document.uploaded.v1` de Redis Streams y publica los resultados; API interna para el panel. `/health`, `/ready` y `/metrics` en `127.0.0.1:8001`. |
| `ai-mcp` | Servidor MCP del microservicio (CRM, ERP, facturas y tickets de demostración). Solo red interna, con token (`AI_SERVICE_MCP_TOKEN`). |
| `ai-results` | Misma imagen que la app: `php artisan ai:consume-results` aplica los resultados de ai-service (estado, propuesta, `ai_requests`, envío, documentos indexados). |

Configuración de cada servicio en [`docker/`](docker/) y en
[`docker-compose.yml`](docker-compose.yml).

---

## 3. Multi-tenant: cómo se aíslan las empresas

**Tenant = empresa.** Todas las empresas comparten la misma base de datos;
cada tabla con datos de una empresa lleva una columna `tenant_id`. La regla
principal del proyecto: **los datos de una empresa nunca pueden ser vistos ni
modificados por otra.**

### Recorrido de una petición autenticada

```
Petición con "Authorization: Bearer <token>"
  │
  ├─ auth:sanctum ...... identifica al usuario a partir del token (401 si no)
  ├─ tenant ............ ResolveTenant: toma el tenant DEL USUARIO (nunca de la
  │                      petición), lo guarda en TenantService, 403 si la
  │                      empresa está desactivada, añade tenant_id a los logs
  ├─ route binding ..... carga los modelos de la URL (/users/{user})
  ├─ FormRequest ....... autoriza (policy) y DESPUÉS valida los datos
  ├─ Policy ............ ¿es de mi empresa? (si no → 404) ¿tengo el permiso? (si no → 403)
  └─ Controller ........ fino: delega y devuelve un API Resource (JSON)
```

### Piezas (en [`app/Tenancy/`](app/Tenancy/))

| Pieza | Para qué sirve |
|---|---|
| `TenantService` | Guarda "la empresa en la que estamos" durante la petición o el job. Métodos `current()`, `id()`, `check()`, `runAs($tenant, fn)` (para jobs y comandos). Se reinicia en cada petición y en cada job, así que un tenant no se "arrastra" al siguiente. |
| Middleware `tenant` ([`ResolveTenant`](app/Http/Middleware/ResolveTenant.php)) | Fija el tenant a partir del usuario autenticado. Se ejecuta antes del *route model binding*. |
| Trait `BelongsToTenant` | Para los modelos con `tenant_id`: filtra **automáticamente** todas las consultas por el tenant actual y rellena `tenant_id` al crear. **Falla cerrado**: si no hay tenant en contexto lanza `MissingTenantException` en vez de devolver datos de todas las empresas. |
| Policies ([`app/Policies/`](app/Policies/)) | Comprueban empresa y permisos en cada acción. |

> `User` es la excepción: no usa `BelongsToTenant` porque Sanctum carga el
> usuario desde el token *antes* de saber su empresa. Los usuarios se
> protegen con `UserPolicy` y consultando siempre a través de
> `$tenant->users()`.

### Reglas al añadir código nuevo

1. Toda tabla con datos de una empresa lleva `tenant_id` (FK a `tenants`) y
   su modelo usa `use BelongsToTenant;`.
2. El `tenant_id` **nunca** se acepta del cliente (body, query, cabeceras):
   sale de `TenantService`.
3. Las rutas autenticadas van dentro del grupo `['auth:sanctum', 'tenant']`
   de [`routes/api/v1.php`](routes/api/v1.php).
4. Cada recurso tiene su Policy: primero se comprueba la empresa y un recurso
   de otra empresa se responde con **404** (no 403), para no revelar que
   existe. Después se comprueba el permiso.
5. Los jobs que trabajen con datos de una empresa se ejecutan dentro de
   `TenantService::runAs($tenant, ...)`.
6. Consultas globales deliberadas (mantenimiento, administración de la
   plataforma): `Modelo::withoutGlobalScope(TenantScope::class)`, y con un
   comentario que explique por qué.
7. Cada funcionalidad nueva añade tests de aislamiento en la línea de
   [`TenantIsolationTest`](tests/Feature/Tenancy/TenantIsolationTest.php).

### Roles y permisos

Tablas `roles`, `permissions` y `permission_role`. Son un **catálogo global**
(igual para todas las empresas) y cada usuario tiene un único rol.

| Rol | Permisos |
|---|---|
| `owner` | Todo: usuarios (incluidos otros owners), editar la empresa y gestionar conexiones de correo |
| `admin` | Gestionar usuarios (salvo owners) y conexiones de correo; ver la empresa |
| `agent` | Solo lectura: ver usuarios, empresa y conexiones de correo |

- El catálogo se define en código: `Role::CATALOG`, `Role::PERMISSIONS` y
  `Permission::CATALOG`. [`RolesAndPermissionsSeeder`](database/seeders/RolesAndPermissionsSeeder.php)
  lo sincroniza con la BD y se puede ejecutar tantas veces como se quiera.
- **Añadir un permiso:** constante + entrada en `Permission::CATALOG`,
  asignarlo en `Role::PERMISSIONS` y ejecutar
  `php artisan db:seed --class=RolesAndPermissionsSeeder`.
- Cada permiso es una Gate de Laravel: `$user->can('users.create')`,
  middleware `can:users.create`, o `$user->hasPermission(...)`.
- Reglas extra: solo un owner puede crear, editar o borrar owners, y nadie
  puede borrarse a sí mismo ni cambiarse el rol (así siempre queda un owner).

---

## 4. Conexiones de correo

Cada empresa conecta una o varias **cuentas de correo** (`mail_connections`).
La plataforma no depende de un proveedor concreto: todo el código trabaja
contra una única interfaz, [`MailProvider`](app/Mail/Contracts/MailProvider.php),
y cada tipo de cuenta tiene su implementación.

| Tipo (`provider`) | Implementación | Cómo se conecta | Para quién |
|---|---|---|---|
| `imap` | [`ImapSmtpProvider`](app/Mail/Providers/Imap/ImapSmtpProvider.php): IMAP para leer ([ImapEngine](https://github.com/DirectoryTree/ImapEngine)) y SMTP para enviar (Symfony Mailer) | Servidor, puerto, cifrado, usuario y contraseña | Cualquier proveedor de correo estándar |
| `microsoft` | [`MicrosoftGraphProvider`](app/Mail/Providers/Microsoft/MicrosoftGraphProvider.php) (Microsoft Graph) | OAuth: el usuario inicia sesión en Microsoft y autoriza | Microsoft 365 / Outlook.com |
| `google` | [`GmailProvider`](app/Mail/Providers/Google/GmailProvider.php) (Gmail API) | OAuth: el usuario inicia sesión en Google y autoriza | Gmail / Google Workspace |

### Cómo encaja

```
 MailConnection (BD)                  MailProviderFactory
 +---------------------------+ make() +-------------------------------+
 | tenant_id, email, status  | -----> | imap      -> ImapSmtpProvider |
 | provider                  |        | microsoft -> MicrosoftGraph…  | --> MailProvider
 | credentials (cifradas)    |        | google    -> GmailProvider    |
 +---------------------------+        +-------------------------------+
```

| Operación de `MailProvider` | Qué hace |
|---|---|
| `testConnection()` | Prueba recepción y envío **por separado**; nunca lanza excepción |
| `folders()` | Carpetas (en Gmail, etiquetas) con su rol normalizado: `inbox`, `sent`, `drafts`, `trash`, `junk`, `archive` |
| `messages($carpeta, $limite)` / `message($carpeta, $id)` | Leer correos (**sin marcarlos como leídos**) como [`MailMessage`](app/Mail/Data/MailMessage.php) |
| `downloadAttachment(...)` | Contenido de un adjunto (máx. `MAIL_CONNECTIONS_MAX_ATTACHMENT_MB`, 25 MB) |
| `newMessages($carpeta, $cursor)` | Correos llegados desde un cursor. Con cursor `null` solo fija el punto de partida (no trae el histórico). Por lotes (`hasMore`). Base de la ingesta de la Fase 4 |
| `send($mensaje)` | Envía desde la propia cuenta (no se puede elegir otro remitente); con `inReplyTo` responde **en el mismo hilo** |

Los proveedores traducen cualquier error a `MailProviderException` y sus
subclases (`MailAuthenticationException`, `MailConnectionException`,
`MailNotFoundException`), con mensajes aptos para el usuario y **sin
credenciales**.

### Seguridad

- **Credenciales cifradas** con la `APP_KEY` (AES-256 + MAC) en una sola
  columna, como objetos tipados ([`app/Mail/Credentials/`](app/Mail/Credentials/)).
  Nunca salen por la API (solo host/puerto/usuario) ni en logs o `dd()`
  (se muestran como `********`).
- **Anti-SSRF**: como el cliente elige el servidor IMAP/SMTP, un atacante
  podría apuntar a la red interna (Redis, Postgres, `169.254.169.254`…).
  [`MailHostGuard`](app/Mail/Security/MailHostGuard.php) rechaza hosts que
  resuelven a IPs privadas, loopback o reservadas, al validar **y** justo
  antes de conectar. Solo en desarrollo se desactiva con
  `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` (para GreenMail).
- **OAuth** con `state` de un solo uso (10 min) + **PKCE**. La empresa y el
  usuario salen del `state`, nunca de la petición, y se revalidan al volver.
- **Tokens OAuth** que se renuevan solos al caducar, con un lock por
  conexión (Microsoft rota el refresh token). Si el proveedor revoca el
  acceso, la conexión pasa a `failing` con "vuelve a conectarla".
- La prueba de conexión tiene su propio límite (10/min): abre conexiones
  hacia fuera y no debe poder usarse como escáner de puertos.

### Conectar una cuenta IMAP/SMTP

```bash
curl -X POST http://localhost:8080/api/v1/mail-connections \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{
    "name": "Soporte", "email": "soporte@demo.test", "provider": "imap",
    "imap": {"host": "greenmail", "port": 3143, "encryption": "none", "username": "soporte@demo.test", "password": "secret"},
    "smtp": {"host": "greenmail", "port": 3025, "encryption": "none", "username": "soporte@demo.test", "password": "secret"}
  }'

# Probarla: guarda el resultado en la conexión (status active/failing, last_error)
curl -X POST http://localhost:8080/api/v1/mail-connections/<ID>/test \
  -H "Accept: application/json" -H "Authorization: Bearer <TOKEN>"
```

`encryption`: `ssl` (993/465), `starttls` (143/587, **obligatorio**: nunca
se envía en claro si el servidor no lo ofrece) o `none` (solo servidores
locales). El ejemplo usa la cuenta demo de GreenMail.

### Conectar Microsoft 365 o Gmail (OAuth)

1. Registra la aplicación (una sola vez, por instalación):
   - **Microsoft**: Azure Portal → *Microsoft Entra ID → App registrations*.
     Redirect URI (Web): `APP_URL/api/v1/mail-connections/oauth/microsoft/callback`.
     Permisos delegados de Graph: `Mail.ReadWrite`, `Mail.Send`, `User.Read`,
     `offline_access`. Variables: `MICROSOFT_CLIENT_ID`,
     `MICROSOFT_CLIENT_SECRET` (y `MICROSOFT_TENANT`, por defecto `common`).
   - **Google**: Google Cloud Console → *APIs & Services*: activa la Gmail
     API y crea un *OAuth client ID* de tipo "Web application". Redirect URI:
     `APP_URL/api/v1/mail-connections/oauth/google/callback`. Scopes:
     `gmail.readonly` y `gmail.send`. Variables: `GOOGLE_CLIENT_ID`,
     `GOOGLE_CLIENT_SECRET`.
2. `POST /api/v1/mail-connections/oauth/{microsoft|google}/start` (opcional
   `{"name": "..."}`) devuelve `authorization_url`.
3. Abre esa URL en el navegador, inicia sesión y autoriza. El proveedor
   vuelve al callback, que crea la conexión (o reconecta la existente si
   la cuenta ya estaba). Con `MAIL_CONNECTIONS_OAUTH_REDIRECT_TO` vuelve
   después al panel con `?status=connected&connection_id=...`; sin ella, el
   callback responde JSON.

### Limitaciones conocidas

- Microsoft y Google están probados con sus APIs **simuladas** (con el
  formato real de sus respuestas), **no con cuentas reales**: hace falta
  registrar las apps (paso 1) para probarlos de verdad.
- Microsoft: los adjuntos de más de 3 MB no se pueden enviar todavía
  (requieren "upload session"). Un correo **movido** a una carpeta desde
  otra no se detecta como nuevo (conserva su fecha de recepción).
- Google: `gmail.readonly` es un scope restringido; para usarlo con cuentas
  de otras organizaciones hay que pasar la verificación de la app en Google.
- No se guarda copia de lo enviado por SMTP en "Enviados" (Gmail/Microsoft
  sí lo hacen solos). Las respuestas quedan guardadas en la plataforma
  (`email_replies`); la copia se valorará por buzón (algunos servidores
  SMTP ya la guardan y saldría duplicada).

### Añadir un proveedor nuevo

Implementa `MailProvider` (y `OAuthClient` si usa OAuth), regístralo en
[`MailProvidersServiceProvider`](app/Providers/MailProvidersServiceProvider.php)
con `$factory->extend(MailProviderType::X, fn (MailConnection $c) => ...)` y
añade el caso a `MailProviderType`. El resto de la aplicación no cambia.

---

## 5. Ingesta de emails

Cada buzón conectado se sincroniza **solo, cada minuto**: la plataforma trae
los correos nuevos, los guarda una única vez con todo su contenido y deja
constancia de cualquier error.

### Cómo funciona

```
scheduler (cada minuto)
  └─ php artisan mail:sync ── un FetchEmailsJob por buzón "active" ──► cola "mail" (Horizon)
        └─ MailSyncService (dentro de la empresa del buzón)
              ├─ provider->newMessages(carpeta, cursor guardado)
              ├─ por cada correo: EmailIngestionService
              │     ├─ ingest():   email + destinatarios + metadatos de adjuntos (sin duplicados)
              │     └─ complete(): adjuntos → disco privado · ingested_at · evento EmailIngested
              └─ solo con todo el lote guardado: se avanza el cursor (mail_sync_states)
```

- **Qué se sincroniza:** los buzones en estado `active` de empresas activas,
  y por ahora solo su **bandeja de entrada**. Un buzón `pending` (sin
  probar) o `failing` (credenciales rechazadas) no se toca hasta que se
  prueba de nuevo con `POST /mail-connections/{id}/test`.
- **Primera sincronización:** fija el punto de partida y **no importa el
  correo antiguo**; a partir de ahí se guarda todo lo que llega.
- **El buzón del cliente no se modifica:** no se marcan correos como leídos
  ni se mueven (Gmail se conecta con permisos de solo lectura).
- **Correos grandes o muchos a la vez:** se traen por lotes (hasta
  `MAIL_SYNC_MAX_BATCHES` lotes por pasada; el resto en la siguiente).

### Qué se guarda

| Tabla | Contenido |
|---|---|
| `emails` | Buzón, carpeta e id en el proveedor, `message_id` / `in_reply_to` / `references` (hilos), remitente, asunto, cuerpo de texto y HTML, fecha (`sent_at`), `status` y `ingested_at` |
| `email_recipients` | Destinatarios `to`, `cc`, `reply_to` (en orden) |
| `email_attachments` | Nombre, tipo, tamaño y **estado** de cada adjunto: `stored` (fichero en disco, con su SHA-256), `skipped` (supera `MAIL_CONNECTIONS_MAX_ATTACHMENT_MB`), `failed` (el proveedor no lo entregó) o `pending` |
| `mail_sync_states` | Por buzón y carpeta: cursor, último intento, último éxito, último error y fallos seguidos |

- Los **adjuntos** van a un disco **privado** (`MAIL_ATTACHMENTS_DISK`, por
  defecto `storage/app/private`), en `tenants/{empresa}/emails/{email}/{adjunto}`.
  El nombre del fichero que puso el remitente solo se muestra, nunca se usa
  como ruta.
- El **HTML** se guarda tal cual llega: hay que **sanearlo antes de
  mostrarlo** (lo hará el panel).
- **"Procesado":** `ingested_at` indica que el email y sus adjuntos están
  guardados; en ese momento se lanza el evento `EmailIngested`, que será la
  entrada del procesamiento con IA. `status` (`received` → `processing` →
  `ai_review` → `human_review` → `approved` → `sent` / `failed`) lo moverán
  las fases de IA y revisión humana.
- Un buzón con correos guardados **no se puede borrar** (409): se
  desactiva (`PATCH` con `enabled: false`).

### Duplicados

Cada correo se identifica dentro de su buzón por su cabecera
**Message-ID** (o, si no la tiene, por una huella de remitente, fecha,
asunto y cuerpo). Aunque el mismo correo llegue dos veces, se reintente una
sincronización o el servidor IMAP renumere sus mensajes, se guarda **una
sola vez** (clave única en la BD, también si dos workers lo intentan a la
vez). El mismo correo en dos buzones distintos de la empresa sí se guarda en
cada uno.

### Errores y reintentos

| Qué pasa | Qué hace la plataforma |
|---|---|
| Error temporal (red, timeout, servidor caído) | El cursor **no avanza**; el job se reintenta 2 veces más (a los 30 s y 2 min) y, si sigue fallando, en la siguiente pasada del scheduler. Nada se pierde ni se duplica. |
| El servidor rechaza la contraseña / token revocado | El buzón pasa a `failing` con el motivo en `last_error` y **no se reintenta** (repetir logins fallidos puede bloquear la cuenta). Se reanuda al corregirlo y probarlo. |
| Un adjunto que el proveedor no puede entregar | Se marca `failed` con el motivo y se sigue con el resto del correo. |
| El proveedor invalida el cursor (p. ej. cambio de UIDVALIDITY en IMAP) | Nuevo punto de partida, se anota `resynced_at` y un aviso en el log (puede faltar correo intermedio). |

Cada intento queda registrado en `mail_sync_states` (`last_error`,
`consecutive_failures`) y en el log (`Buzón sincronizado`, `Error al
sincronizar el buzón`, con `tenant_id`), consultable en Grafana/Loki.

### Probarlo en local

```bash
docker compose exec app php artisan mail:sync        # 1ª vez: fija el punto de partida
# Envía un correo al buzón demo (GreenMail, SMTP 127.0.0.1:3025, sin TLS)
# a soporte@demo.test, p. ej. desde Thunderbird o con swaks.
# En menos de un minuto aparece en la tabla emails:
docker compose exec app php artisan tinker --execute 'echo DB::table("emails")->pluck("subject");'
```

La conexión demo debe estar `active` (se prueba con
`POST /api/v1/mail-connections/1/test`). El progreso de los jobs se ve en
Horizon (`http://localhost:8080/horizon`, cola `mail`).

---

## 6. Inteligencia artificial: el microservicio ai-service

Toda la IA de la plataforma (clasificación, agente, herramientas, búsqueda
e indexado de documentos) vive en **[`ai-service/`](ai-service/README.md)**,
un microservicio en Python (LangGraph, MCP, Redis Streams, arquitectura
hexagonal). **Laravel no llama a ningún modelo** ni calcula embeddings (un
test de arquitectura lo prohíbe): publica cada email como evento, aplica el
resultado con **sus propias reglas de envío** y registra cada llamada a la
IA con su coste. Hasta la retirada del motor de Laravel (octubre de 2026)
convivían dos motores; ahora solo existe ai-service.

```
Laravel                                   Redis Streams             ai-service (Python)
ingesta → filtros baratos ─► email.received.v1 ───────────────►  clasificación → RAG → especialistas
                                                                  (herramientas MCP) → redactor → decisión
ai:consume-results ◄──────── email.processed.v1 ◄──────────────   (recomendación + llamadas + herramientas)
  → ai_requests (coste con los precios de Laravel), ai_agent_runs, ai_tool_calls
  → AutoSendPolicy + ReplyContentGuard (la última palabra es de Laravel) → envío o persona
```

- **Contratos:** cada evento tiene un JSON Schema en
  [`contracts/events/`](contracts/README.md), validado en los dos lados.
- **Quién decide:** ai-service **recomienda** (`auto_send`,
  `human_review`, `escalate`); Laravel lo pasa por el modo de la empresa,
  las intenciones permitidas, los límites anti-bucles y la revisión del
  texto. Si ai-service tuviera un fallo, no podría enviar nada que Laravel
  no apruebe.
- **Costes:** ai-service manda los tokens de cada llamada y Laravel calcula
  el coste con los precios de [`config/ai.php`](config/ai.php) (una sola
  tabla de precios); así el panel, Grafana y el freno del gasto ven todo.
- **Si ai-service no responde:** `ai:reconcile-remote` (cada 5 minutos)
  vuelve a publicar los emails que llevan 15 minutos en `processing`; tras 3
  intentos el email pasa a **revisión humana** ("El servicio de IA no
  respondió…"): sin IA una persona puede contestar igualmente.

### Activarla

Los servicios `ai-service` (worker + API interna), `ai-mcp` (herramientas)
y `ai-results` (consumidor de resultados en Laravel) arrancan con el resto
del stack. Lo único que hace falta es la clave de Anthropic, que **solo**
recibe el contenedor `ai-service`:

1. Crea una API key en [platform.claude.com](https://platform.claude.com)
   y añade saldo a la cuenta.
2. Pégala en `.env`: `ANTHROPIC_API_KEY=sk-ant-...` y reinicia el
   servicio: `docker compose up -d ai-service`.
3. Prueba con un email ya guardado (publica, espera el resultado y enseña
   el historial): `docker compose exec app php artisan ai:process <id> --now --reprocess`
   y la traza de la IA: `php artisan ai:trace <id>`.

Para probar **sin gastar**: `AI_SERVICE_LLM_PROVIDER=simulated` (reglas por
palabras clave, solo en local; ver [Observabilidad](#12-observabilidad-métricas-grafana-y-alertas)).

| Variable | Para qué |
|---|---|
| `ANTHROPIC_API_KEY` | Clave de la API (solo la usa `ai-service`). |
| `ANTHROPIC_MODEL` | Modelo (por defecto `claude-opus-5-5`, $4 / $20 por millón de tokens). Más barato: `claude-sonnet-5-5` ($2 / $10) o `claude-haiku-4-5` ($1 / $5). |
| `AI_SERVICE_LLM_PROVIDER` | `anthropic` o `simulated` (IA de pruebas, prohibida fuera de `local`). |
| `AI_PROCESS_ON_INGEST` | Procesar cada email al guardarlo (`true`). |
| `AI_CLASSIFICATION_MAX_BODY_CHARS` | Caracteres del cuerpo que se mandan a ai-service (20 000); si se recorta, va marcado en el evento. |
| `AI_SERVICE_INTERNAL_TOKEN` | Token de la API interna (búsqueda del panel y herramientas disponibles). |
| `AI_SERVICE_CHECKPOINTS_DB_PASSWORD` | Rol de PostgreSQL de ai-service: solo su esquema `ai_service`. |

Qué hace ai-service por dentro (prompts versionados, structured outputs,
protección contra prompt injection, supervisor con especialistas, redactor
agéntico, aprobación de acciones con `interrupt`, Kubernetes) está en
[ai-service/README.md](ai-service/README.md). La lista de pasos para
probarlo en tu equipo, en
[Probarlo en tu equipo](ai-service/README.md#probarlo-en-tu-equipo-fases-15).

### Qué guarda Laravel de cada email

- **Clasificación** en `emails`: `intent` (pedido, devolución, cancelación,
  factura, cobro, producto, soporte técnico, queja, ventas, cambio de
  cuenta, opinión, respuesta automática, spam, otros), `intent_confidence`,
  `language`, `summary`, `needs_human` + motivo, `sentiment`, `urgency`,
  `injection_suspected` y `ai_entities` (pedidos, facturas, nombre).
- **Cada llamada a un modelo** en `ai_requests` (también las fallidas, que
  se facturan): propósito (`ai_service.classification`, `ai_service.draft`,
  `ai_service.knowledge_search`…), modelo, tokens, `cost_usd`, latencia,
  versión del prompt. Gasto del mes:
  `SELECT SUM(cost_usd) FROM ai_requests WHERE created_at >= date_trunc('month', now());`
- **Lo que hizo la IA** en `ai_agent_runs` y `ai_tool_calls` (ver
  [sección 9](#9-agente-propuesta-de-respuesta)).

---

## 7. Base de conocimiento (RAG)

Cada empresa sube su documentación (política de devoluciones, envíos,
garantía, FAQs, manuales…) y la IA encuentra **por significado** el
fragmento que responde a una pregunta, aunque no use las mismas palabras e
incluso en otro idioma, y lo cita en la respuesta.

```
Subir documento (panel, API o kb:import) ─► Laravel: disco privado + documents (processing)
   └─ document.uploaded.v1 (con un enlace firmado de 30 min para descargar el original)
        └─ ai-service: descarga → texto (PDF por páginas, Markdown por secciones, HTML, TXT)
             → trozos de ~2000 caracteres → embeddings bge-m3 (Ollama, local)
             → ai_service.knowledge_chunks (pgvector, HNSW)
   ◄─ document.indexed.v1 / document.indexing_failed.v1 → documents: ready (n fragmentos) o failed con el motivo
```

- **Privacidad:** los embeddings se calculan con un modelo **local**
  (contenedor `ollama`, solo red interna): los documentos no salen del
  servidor para indexarlos.
- **Aislamiento:** cada fragmento lleva su empresa y toda búsqueda filtra
  por ella; ai-service solo descarga el fichero por la ruta firmada que le
  da Laravel (no puede pedir otro documento ni otra URL).
- **Fuente citada:** cada fragmento guarda la página (PDF) o la sección
  (Markdown) de la que sale.
- **Solo lo relevante:** por debajo de una similitud mínima no se
  devuelve nada.
- **Formatos:** PDF con texto (sin OCR), TXT, Markdown y HTML; máximo
  20 MB (`RAG_MAX_UPLOAD_MB`); el mismo fichero no se sube dos veces en una
  empresa. Borrar un documento borra también sus fragmentos en ai-service
  (`document.deleted.v1`).

```bash
docker compose exec ollama ollama pull bge-m3                              # una vez
docker compose exec app php artisan db:seed --class=KnowledgeBaseSeeder     # documentos de ejemplo
docker compose exec app php artisan kb:search "La lámpara me llegó rota, ¿qué hago?" --tenant=1
docker compose exec app php artisan kb:reindex --all                        # volver a indexarlo todo
```

En el panel, "Documentación → Probar la búsqueda" enseña lo que
encontraría la IA (la búsqueda es la de ai-service, por su API interna).

---

## 8. Herramientas de la IA (Tools)

La IA **nunca accede a los datos por su cuenta**: pide una herramienta por
su nombre y el backend decide si la ejecuta. Las herramientas las sirve el
**servidor MCP** de ai-service (`ai-mcp`); el catálogo es único y lo
comparten Laravel y ai-service:
[`contracts/tools/catalog.json`](contracts/tools/catalog.json).

| Herramienta | Qué hace |
|---|---|
| `search_company_documents` | Busca en la documentación de la empresa (activada por defecto) |
| `get_customer` | Ficha del cliente que escribe |
| `get_order` / `get_customer_orders` | Un pedido del cliente o sus últimos pedidos |
| `get_invoice` / `get_customer_invoices` | Una factura del cliente o sus facturas |
| `get_customer_tickets` | Tickets de soporte del cliente |
| `get_appointment` / `get_customer_appointments` | Una cita del cliente o sus citas, las próximas primero (negocios con agenda: clínicas, talleres…). Los emails con la intención `appointment` ("¿a qué hora es mi cita?") van al especialista de citas, que las consulta; la IA solo informa: reservar, cambiar o cancelar lo hace una persona |
| `create_support_ticket` | **Escribe**: solo tras aprobarlo una persona en el panel |

- **Por empresa:** cada empresa activa las que le sirven (una clínica no
  tiene pedidos); Laravel manda la lista en cada evento (`enabled_tools`) y
  ai-service no planifica, ofrece ni ejecuta ninguna otra.
- **Los datos de un cliente solo se le enseñan a ese cliente:** empresa y
  remitente los pone el backend, nunca el modelo; un pedido de otra persona
  se responde como si no existiera.
- **De dónde salen los datos (Fase 14):** el servidor MCP pregunta a
  Laravel qué tiene conectado cada empresa. Hoy, **pedidos importados por
  CSV** (ver abajo); si una empresa no tiene nada, los datos de demostración
  de "Empresa Demo" (`ai-service/src/ai_service/mcp_server/data/`). Una
  empresa sin ningún sistema sigue funcionando: la IA responde con su
  documentación y pasa a una persona lo que dependa de un pedido.

### Pedidos importados por CSV (Fase 14)

Para empresas sin tienda online conectada (un Excel, un programa propio):
**Pedidos → Importar CSV** en el panel (permiso `orders.manage`; los agentes
los ven con `orders.view`), con plantilla descargable.

- **Formato pensado para un Excel en español:** separador `;` o `,`, UTF-8
  o Windows-1252, cabeceras en español o en inglés ("Nº pedido", "Email",
  "Estado"…), estados en español ("Enviado"), decimales con coma, fechas
  `dd/mm/aaaa` y productos como `2x Silla roble; 1x Mesa`.
- **Todo o nada:** con una fila mal no se importa nada y se dice qué falla
  en cada fila. Volver a subir el fichero **actualiza por número de pedido**.
- **Cómo llegan a la IA:** Laravel guarda los pedidos (tabla
  `customer_orders`, por empresa) y los sirve con la **API estándar**
  ([`contracts/integrations/`](contracts/integrations/README.md)) en una ruta
  interna protegida con `AI_SERVICE_DATA_TOKEN`; el servidor MCP la consulta
  con el mismo proveedor que usará el ERP propio de una empresa.
- Importar y borrar quedan en la auditoría (sin datos de clientes), y
  `data:forget-address` borra también los pedidos de esa persona.

### Conectar el sistema propio de la empresa (Fase 14)

Si la empresa tiene un ERP o programa a medida, su informático publica la
**API estándar** ([`contracts/integrations/`](contracts/integrations/README.md):
consultas de solo lectura) y en **Pedidos → Tu propio sistema** se
guarda la dirección y la clave. Si está activada, **manda sobre los pedidos
importados**; ofrece solo las partes que tiene: pedidos, facturas y/o
**citas** (una clínica o un taller conecta solo su agenda).

**Probar las citas en local:** `php artisan db:seed --class=ClinicDemoSeeder`
crea "Clínica Demo" (`clinica@example.com` / `password`) con las
herramientas de citas activadas. El servidor MCP le da las citas de
demostración por su slug (`clinica-demo`), sin configurar nada: un email de
`maria@cliente.test` preguntando "¿a qué hora es mi cita?" se contesta con su
cita C-2031. Para recibir correo, añade un buzón en **Buzones**.
Probado de punta a punta con la IA simulada: las preguntas por una cita se
contestan solas con la cita de quien escribe (si la empresa permite la
intención `appointment` en modo automático); pedir cambiarla o cancelarla,
o no tener citas, va a revisión humana.

- **Se prueba al guardar** (y con "Probar conexión"): pide la lista de un
  cliente que no existe y comprueba que responde con el formato del
  contrato; el motivo de un fallo se ve en el panel ("rechazó la clave", "no
  tiene la ruta /v1/orders"…).
- **Clave cifrada** (`tenant_integrations.credentials`, APP_KEY), nunca en
  respuestas, auditoría ni logs. Solo la recibe el servidor MCP por la ruta
  interna con el token de datos (`Cache-Control: no-store`).
- **Anti-SSRF en los dos lados:** HTTPS obligatorio, sin usuario, parámetros
  ni `#` en la URL, y el host no puede apuntar a la red interna
  (`MailHostGuard` en Laravel, con la conexión fijada a la IP comprobada;
  `HostGuard` en el servidor MCP, antes de cada petición, por si el DNS
  cambia). Las direcciones de enlace local (metadatos de la nube) se rechazan
  siempre; HTTP y hosts privados solo en local
  (`MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS`, ignorado fuera de local/testing).
- Si el sistema de la empresa falla, la IA recibe un error legible y el email
  va a revisión humana: nunca responde como si el pedido no existiera.

### Conectar una tienda WooCommerce (Fase 14)

**Pedidos → Tienda WooCommerce**: dirección de la tienda y unas claves de API
de **solo lectura** (WooCommerce → Ajustes → Avanzado → API REST). La IA
consulta los pedidos de quien escribe (estado, productos, importes y, con el
plugin oficial *Shipment Tracking*, transportista y seguimiento). Solo
pedidos: WooCommerce no trae facturas de serie.

- **De dónde salen los pedidos de una empresa:** su sistema propio (API
  estándar) si lo tiene, si no su tienda WooCommerce, si no lo importado por
  CSV.
- Claves cifradas (JSON `{consumer_key, consumer_secret}`), autenticación
  Basic por HTTPS, mismas protecciones anti-SSRF que la API estándar; en el
  panel solo se ve el final de la consumer key.
- **Cómo se traduce:** estados `pending`/`on-hold`/`failed` → pendiente de
  pago, `processing` → en preparación, `completed` → enviado (WooCommerce no
  sabe si se entregó), `cancelled`, `refunded` → devuelto; un pedido se busca
  por su **número visible** entre los últimos 100 del cliente (los plugins de
  numeración lo separan del id interno), siempre con su email exacto.

**Probar WooCommerce en local** (WordPress + WooCommerce reales en Docker,
sin una tienda de verdad; fichero aparte, no arranca con el stack):

```bash
docker compose -f docker-compose.yml -f docker-compose.woocommerce.yml up -d woocommerce
docker compose -f docker-compose.yml -f docker-compose.woocommerce.yml run --rm woocommerce-cli
```

El script ([`docker/woocommerce/setup.sh`](docker/woocommerce/setup.sh),
idempotente) instala WordPress y WooCommerce, crea pedidos de Elena, Laura y
Marcos (el de Elena con seguimiento de MRW) e imprime unas claves de API
fijas. En el panel: dirección `http://woocommerce` (HTTP y host privado solo
valen en local) y esas claves. La tienda de pruebas finge HTTPS
(`$_SERVER['HTTPS']`) porque WooCommerce solo acepta las claves como Basic
por HTTPS.

```bash
docker compose exec app php artisan ai:tools 1                      # estado (y si tienen datos, según ai-service)
docker compose exec app php artisan ai:tools 1 --enable=get_order --enable=get_customer_orders
docker compose exec ai-service ai-service-tool --list
docker compose exec ai-service ai-service-tool get_order --tenant 1 --customer cliente@cliente.test --args '{"order_number":"45821"}'
```

---

## 9. Agente: propuesta de respuesta

ai-service decide qué consultar (supervisor con especialistas de pedidos,
facturación y soporte; el redactor puede pedir consultas extra), redacta
una **propuesta** que cita sus fuentes y recomienda qué hacer con ella. En
Laravel la propuesta queda en `email_replies` (borrador) y la ejecución
entera, auditada:

| Tabla | Qué guarda |
|---|---|
| `ai_agent_runs` | Una fila por ejecución: pasos, herramientas, tokens, coste, duración, especialistas y salida final |
| `ai_requests` | Una fila por llamada a un modelo (`agent_run_id`), con su coste |
| `ai_tool_calls` | Cada herramienta: argumentos, resultado, estado y origen (plan, pedida por la IA, aprobada por una persona) |
| `email_replies` | La propuesta: texto, confianza, `needs_human` + motivos, fuentes, estado |

ai-service pide una persona si la confianza es baja (menos de 0,85), hay
sospecha de prompt injection, una amenaza legal, una cita a algo que no
consultó, una consulta fallida, una acción pendiente o el email era
demasiado largo. Si propone una **acción que modifica datos** (abrir un
ticket), el grafo queda en pausa hasta que una persona la apruebe o la
rechace en el panel. Una persona también puede **reprocesar con una
indicación** ("ofrece cambiarla por otra").

```bash
docker compose exec app php artisan ai:trace 12     # lo que hizo la IA: pasos, herramientas, propuesta y coste
```

Resultados con Claude real (puerta de calidad antes de retirar el motor de
Laravel, 13 emails en modo sombra): misma intención que el antiguo motor
de Laravel en el 100 %, misma decisión en el 85 % (en los desacuerdos,
ai-service es más prudente) y un 19 % más barato (0,054 $ frente a 0,067 $
por email). Detalle en
[`ai-service/evals/`](ai-service/evals/retirement-gate-2026-10-18.json).

---

## 10. Automatización: cada email de principio a fin

Es el MVP completo: cuando llega un email, la plataforma lo procesa sola y
termina **enviando la respuesta en el hilo del cliente** o dejándolo
preparado para una persona. Código en [`app/Automation/`](app/Automation/).

### Cómo funciona

```
email nuevo (mail:sync, cada minuto) ── EmailIngested ──► ProcessEmailJob (cola "ai")
  received ─► processing
     ├─ ¿correo automático? (rebote, lista, boletín, fuera de oficina...) ─► ignored   (sin gastar IA)
     ├─ ¿empresa en modo "off"? ─────────────────────────────────────────► human_review
     ├─ ¿presupuesto diario agotado o remitente que inunda? ─────────────► human_review (sin IA)
     └─ email.received.v1 ─► ai-service ─► email.processed.v1 ─► ai:consume-results
            ├─ spam / respuesta automática con confianza ≥ 0,8 ─► ignored
            ├─ sin propuesta (escala o pide persona) ─► human_review
            └─ propuesta ─► ai_review ─► reglas: AutoSendPolicy + ReplyContentGuard
                 ├─ todo correcto ─► approved ─► SendReplyJob (cola "mail") ─► sent
                 └─ cualquier motivo ─► human_review (con todos los motivos)
```

Cada cambio de estado se guarda en **`email_status_changes`** con su motivo
y quién lo decidió (null = la plataforma). Un email no puede saltarse
pasos: las transiciones válidas están en
[`EmailStatus`](app/Mail/Enums/EmailStatus.php) y el cambio es atómico.

### Modos por empresa

| Modo | Qué hace |
|---|---|
| `off` | Sin IA: todos los emails van a revisión humana. |
| `draft` (**por defecto**) | La IA clasifica y prepara la respuesta; **una persona la revisa siempre**. |
| `auto` | Además, se envían solas las respuestas seguras de las **intenciones permitidas** (por defecto `order_status` y `product_question`). |

```bash
docker compose exec app php artisan ai:automation 1                                   # ver
docker compose exec app php artisan ai:automation 1 --mode=auto                       # activar el envío automático
docker compose exec app php artisan ai:automation 1 --intents=order_status,invoice_request
docker compose exec app php artisan ai:automation 1 --mode=draft                      # volver a borradores
```

Nunca se envían solas (aunque la empresa lo pida) las quejas, los
problemas de cobro, el spam, las respuestas automáticas y lo que no se ha
entendido (`other`).

### Cuándo se envía sola una respuesta

Solo si **no** se cumple ninguna de estas condiciones (si se cumple
alguna, va a una persona con todos los motivos):

- ai-service no recomienda el envío automático ([sección 9](#9-agente-propuesta-de-respuesta));
- la empresa no está en modo `auto` o la intención no está permitida;
- es un correo automático o hay sospecha de prompt injection;
- el texto no pasa la revisión (enlaces, direcciones, pedidos o importes
  que no salen de lo consultado, detalles internos, promesas de que lo
  revisará una persona…; ver [Seguridad](#13-seguridad));
- la respuesta iría a un **buzón de la propia empresa** o a una dirección
  `noreply`;
- esa dirección ya recibió **3 respuestas automáticas en 24 horas**
  (`AI_MAX_AUTO_REPLIES_PER_ADDRESS`): corta bucles entre sistemas;
- **freno de emergencia**: `AI_AUTO_SEND=false` desactiva el envío
  automático en toda la plataforma.

### Cómo se envía

- Por el **mismo buzón** en el que llegó, al "Responder a" del email (o a
  su remitente), con "Re: " y las cabeceras `In-Reply-To`/`References`:
  el cliente la ve en la misma conversación.
- Las respuestas automáticas llevan **`Auto-Submitted: auto-replied`**
  (RFC 3834), para que otros sistemas automáticos no contesten. (Microsoft
  Graph no permite esa cabecera.)
- **Nunca se envía dos veces:** antes de enviar, la respuesta se "reclama"
  con un cambio de estado atómico. Solo se reintenta si el servidor no
  respondió. Si se desconoce si salió, queda en `failed` para que lo revise
  una persona, y un email con un envío ya intentado no se reprocesa nunca.

### Probarlo

```bash
docker compose exec app php artisan ai:automation 1 --mode=auto
# Envía un correo a soporte@demo.test (GreenMail, SMTP 127.0.0.1:3025):
#   "¿Hacéis envíos a Canarias?" → en < 1 min se responde solo.
docker compose exec app php artisan ai:process 12 --now        # procesar uno, esperar a ai-service y ver su historial
docker compose exec app php artisan ai:process 12 --now --reprocess   # volver a procesar uno ya decidido
docker compose exec app php artisan ai:process --pending       # encolar los que siguen sin procesar
docker compose exec app php artisan ai:process --failed        # reintentar los que fallaron
```

---

## 11. Panel de administración

SPA de **Vue 3 + TypeScript + Naive UI** dentro de este mismo repositorio
([`resources/js/panel/`](resources/js/panel/)), compilada con Vite y servida
por Laravel en `/`. Cada empresa ve y gestiona **solo lo suyo**.

| Sección | Qué hace | Permiso |
|---|---|---|
| **Resumen** | Emails del periodo (7/30/90 días), respondidos solos y % de automatización, los que esperan a una persona, con error, ignorados, respuesta media de la IA, intenciones más frecuentes, estados y "necesitan atención" | `emails.view` |
| **Revisión** | Bandeja de lo que espera a una persona (con su número en el menú) e historial de decisiones ([Revisión humana](#14-revisión-humana-human-in-the-loop)) | `emails.view` / `emails.review` |
| **Emails** | Listado con filtros (estado, intención, buzón, texto, fechas; guardados en la URL) | `emails.view` |
| Detalle del email | Mensaje (texto o formato original aislado), adjuntos, clasificación, **respuesta propuesta** con los motivos de revisión, **historial de estados** y **lo que hizo la IA** (cada paso y cada herramienta con lo que consultó y lo que vio) | `emails.view` |
| **Buzones** | Añadir IMAP (con **autodetección**), conectar Microsoft 365 / Gmail (OAuth), probar, editar, desactivar, eliminar | `mail_connections.view` / `.manage` |
| **Documentación** | Subir, reindexar y eliminar documentos (se refresca mientras indexa) y **probar la búsqueda**: lo que encontraría la IA, con su fuente | `documents.view` / `.manage` |
| **Pedidos** | Importar pedidos por CSV (plantilla, errores por fila, todo o nada), buscar y borrarlos ([Pedidos por CSV](#pedidos-importados-por-csv-fase-14)) | `orders.view` / `.manage` |
| **Ajustes de la IA** | Modo (`off` / `draft` / `auto`, con confirmación), intenciones que se responden solas, herramientas del agente | `tenant.view` / `automation.manage` |
| **Empresa y usuarios** | Nombre de la empresa, usuarios y roles | `tenant.view`, `users.*` |

Lo que el rol no permite no se muestra; aun así, **la API comprueba
siempre los permisos** (la interfaz solo es comodidad).

**Costes y tokens de la IA no aparecen en el panel ni en su API**: son
información del operador de la plataforma (tu margen, tus proveedores), no
de las empresas cliente. Se consultan en **Grafana** (dashboard Costes) y,
por consola, en `ai:trace`. Un test recorre las respuestas
de la API del panel y falla si aparece cualquier campo de coste o tokens.

### Seguridad del panel

- **Sesión por cookie** de Sanctum (`POST /api/v1/auth/session`), httpOnly
  y con CSRF; nunca se guarda un token en el navegador (un XSS no podría
  llevárselo). Tras el login solo se vuelve a rutas internas del panel.
- El **HTML de los emails** (que escribe cualquiera) se limpia en el
  servidor con HTMLPurifier y se muestra en un `iframe` aislado
  (`sandbox` sin scripts ni acceso al panel, CSP que bloquea conexiones e
  **imágenes remotas**: píxeles de rastreo).
- Los **adjuntos** se descargan siempre como fichero, nunca se abren en el
  navegador.
- La **autodetección** de buzones consulta servidores de un dominio que
  escribe el usuario: solo HTTPS, IPs comprobadas por la protección
  anti-SSRF y fijadas en la conexión (sin *DNS rebinding*), sin
  redirecciones, con tiempo y tamaño máximos.

### Autodetección de buzones

Al escribir el email (o con "Detectar configuración") se proponen
servidor, puerto y cifrado de IMAP y SMTP, como hace Thunderbird:

1. Base de datos pública de Thunderbird (ISPDB).
2. Autoconfig del propio dominio.
3. Registros DNS SRV.
4. Nombres habituales (`imap.`, `smtp.`, `mail.`): es una suposición.

Si el dominio usa **Microsoft 365 o Google** (por sus MX) avisa de que es
mejor "Conectar Microsoft 365 / Gmail". El buzón se **prueba al guardarlo**.

### Desarrollo

```bash
docker compose logs -f vite                      # el servidor de Vite recompila al guardar
docker compose exec vite npm run typecheck       # tipos (vue-tsc)
docker compose exec vite npm test                # tests (Vitest + @vue/test-utils)
docker compose run --rm vite npm run build       # compilar para producción (public/build)
```

- Rutas en [`router.ts`](resources/js/panel/router.ts) (con `meta.permission`)
  y entradas del menú en [`menu.ts`](resources/js/panel/menu.ts).
- Cliente de la API en [`api/http.ts`](resources/js/panel/api/http.ts)
  (cookie CSRF, errores `ApiError` con `fieldError()`, sesión caducada → login).
- Textos de estados, intenciones y herramientas en español en
  [`utils/labels.ts`](resources/js/panel/utils/labels.ts).

---

## 12. Observabilidad: métricas, Grafana y alertas

Cómo está funcionando la plataforma, en Grafana (**http://localhost:3000**,
`admin`/`admin`, carpeta **AI Mail Automation**; al entrar se abre el
General).

```
App (PHP-FPM + Horizon) ──► métricas en Redis ──► GET /metrics ──► Prometheus ──► Grafana
          │                                         (token)          (alertas)
          └──► log JSON ──► Promtail ──► Loki ─────────────────────────────────────►┘
```

### Actualizar un entorno que ya existía (antes de la Fase 11)

```bash
# 1. Añadir a .env (están en .env.docker.example):
#      METRICS_TOKEN=local-metrics-token
#      LOG_STACK=daily,json
# 2. Dependencias nuevas, contenedores (nginx entra en la red de Prometheus;
#    imágenes con versión fija) y workers con el código nuevo:
docker compose exec app composer install
docker compose up -d
docker compose exec horizon php artisan horizon:terminate
# 3. Comprobar: target "laravel" UP en http://localhost:9090/targets
```

### Dashboards

| Dashboard | Qué responde |
|---|---|
| **General** | ¿Cuántos emails llegan, cuántos se responden solos (%), cuántos van a una persona, cuánto tiempo se ahorra y cuánto cuesta la IA? ¿Qué espera ahora mismo a una persona o tiene error? Intenciones y volumen por empresa. |
| **Emails** | Entrada (personas vs automáticos), decisiones, tiempo hasta la decisión (mediana y p95), estado actual, respuestas enviadas solas / por personas / fallidas y cada buzón (fallos seguidos, último éxito). |
| **IA** | Peticiones por uso (clasificación, agente, búsqueda, indexado), % de errores, latencia p95, tokens, intenciones, distribución de la confianza, herramientas y cómo terminan las ejecuciones del agente. |
| **Costes** | Gasto del periodo, de 24 h y de 30 días, por día, por uso, por modelo y por empresa; coste medio por email. Cuadra con `ai_requests.cost_usd`. |
| **Errores** | Alertas disparadas, errores ahora y su evolución, trabajos fallidos y reintentos, y los **logs** de error y aviso (Loki). |
| **Rendimiento** | HTTP (peticiones, duración, rutas más lentas), colas, duración de los trabajos, latencia de la IA, PostgreSQL y Redis. |

Todos se filtran por **Empresa**. El **tiempo ahorrado** es una estimación:
respuestas enviadas solas × "Minutos por respuesta manual" (variable del
General, 5 por defecto). Los dashboards están en
[`docker/grafana/dashboards/`](docker/grafana/dashboards/): se pueden editar
en Grafana, pero al reiniciar manda el fichero (para guardar un cambio:
*Export → JSON* y sustituir el fichero).

### Métricas

La app expone sus métricas en `GET /metrics` (formato de Prometheus),
**solo con el token** de `METRICS_TOKEN` (`Authorization: Bearer …`; sin él,
404: las métricas revelan volumen y costes de cada empresa). Prometheus las
lee cada 15 s a través de nginx y guarda **400 días**.

- **Acumuladas** (prefijo `aimail_`, guardadas en Redis para que PHP-FPM y
  los workers de Horizon sumen en los mismos contadores): emails guardados,
  cambios de estado, tiempo hasta la decisión, respuestas enviadas/fallidas,
  sincronizaciones; peticiones a la IA con tokens, coste y latencia,
  clasificaciones (intención, confianza), herramientas, ejecuciones del
  agente; peticiones HTTP (por **plantilla** de ruta, nunca la URL) y
  trabajos de cola.
- **Estado actual** (se calcula en cada lectura): emails por estado, buzones
  y su sincronización, documentos, tamaño de las colas.
- Salen de los **mismos registros de auditoría** (`emails`, historial de
  estados, `ai_requests`, `ai_tool_calls`, `ai_agent_runs`…) mediante
  observers: el código de negocio no sabe nada de Prometheus. Todas están
  declaradas en un único catálogo
  ([`app/Observability/Metrics.php`](app/Observability/Metrics.php)) y
  registrar una métrica **nunca rompe** el procesamiento (si Redis falla,
  aviso en el log y se sigue).

> `increase()` de Prometheus no cuenta la primera aparición de una serie:
> con datos de prueba recién creados, los totales pueden salir algo por
> debajo de la BD. La cifra exacta está siempre en el panel y en la BD.

### Logs

Además del log de texto (`storage/logs/laravel-AAAA-MM-DD.log`, para
`tail -f`), Laravel escribe el mismo log **en JSON** (canal `json`,
`LOG_STACK=daily,json`) y Promtail lo envía a Loki con el nivel como
etiqueta. Cada petición lleva un **`request_id`** (también en la cabecera
`X-Request-Id`) que aparece en todos sus logs y en los de los trabajos que
encola. En Grafana → *Explore* → Loki:

```
{job="laravel", level="ERROR"} | json
{job="laravel"} | json | extra_tenant_id="1"
{job="laravel"} | json | extra_request_id="<id>"
```

### Alertas

Reglas de Prometheus en
[`docker/prometheus/alerts.yml`](docker/prometheus/alerts.yml), con
**pruebas** en `alerts.test.yml` (`promtool test rules`, también en el CI).
Se ven en http://localhost:9090/alerts y en el dashboard **Errores**.

| Alerta | Cuándo |
|---|---|
| `MetricasNoDisponibles` (crítica) | Prometheus no puede leer `/metrics` 2 min (app caída o token distinto). |
| `EstadoNoDisponible` | No se puede calcular el estado actual (BD o colas) 5 min. |
| `TrabajosDeColaFallando` | Un trabajo agota sus reintentos. |
| `ColaAtascada` | Más de 100 trabajos esperando 10 min. |
| `ErroresHttp5xx` | Más del 5 % de peticiones con error 5xx 10 min. |
| `BuzonAtascado` | Un buzón activo lleva ≥ 5 sincronizaciones fallidas seguidas (servidor caído o un correo que siempre falla). |
| `BuzonSinSincronizar` | Un buzón activo lleva más de 15 min sin sincronizar. |
| `EmailsConError` | ≥ 3 emails de una empresa pasan a error en 15 min. |
| `RevisionHumanaAcumulada` (info) | Una empresa tiene más de 50 emails esperando a una persona durante 1 h. |
| `CasoSinAtender` | El caso más antiguo de una empresa lleva más de 4 h esperando a una persona (5 min). |
| `IAConErrores` | Más del 20 % de las peticiones a la IA fallan (con al menos 5 en 10 min). |
| `GastoIAPorHoraAlto` | La IA gasta más de 5 $ en una hora. |

Todavía **no se envían avisos** (email, Slack…): eso es Alertmanager, en la
fase de producción. Los umbrales se cambian en `alerts.yml` (y sus pruebas).

### Probarlo sin gastar en IA

Con `AI_SERVICE_LLM_PROVIDER=simulated` en `.env` (y `docker compose up -d
ai-service` para que lo lea) la IA responde con reglas por palabras clave,
sin API ni coste: clasifica, consulta pedidos y la plataforma decide y
envía como con Claude. Su modelo se llama `simulated` y tiene un **precio
ficticio** (el de Opus 5.5) para que se vean costes en Grafana. Envía unos
cuantos correos por SMTP a `soporte@demo.test` (GreenMail, `127.0.0.1:3025`,
cada uno con su `Message-ID`, o se tomarán por duplicados) y en un par de
minutos los verás en los dashboards.

**Resultado de la prueba en el stack (IA simulada + GreenMail, modo
`auto`):** 32 correos en dos tandas → pedidos del propio cliente
respondidos solos (también en inglés), pedido de otro cliente, quejas,
facturas, prompt injection y preguntas sin documentación (sin Ollama) a una
persona, spam y correos automáticos ignorados sin IA; el límite
anti-bucles frenó la cuarta respuesta automática a la misma dirección. Con
GreenMail parado, `BuzonAtascado` se disparó sola.

## 13. Seguridad

Lo que ya protegían las fases anteriores (roles y permisos, aislamiento
entre empresas con tests, credenciales cifradas, anti-SSRF, HTML de los
emails saneado y aislado, detección de *prompt injection*, límites del
agente) más lo que añade la Fase 12. El código nuevo está en
[`app/Security/`](app/Security/).

| Riesgo | Protección |
|---|---|
| No saber quién cambió algo | **Registro de auditoría** inmutable (página "Auditoría" del panel) |
| Una sesión o un token robados | Cambiar la contraseña cierra **todas** las demás sesiones y tokens; "cerrar las demás sesiones" sin cambiarla |
| Contraseñas débiles o filtradas | Mínimo 10 caracteres y, en producción, rechazo de las que aparecen en filtraciones (Have I Been Pwned, k-anonimato) |
| Alguien inunda un buzón y dispara el gasto de IA | **Presupuesto diario por empresa** y **máximo de emails por remitente**; superados, el email va a una persona sin llamar a la IA |
| La IA envía algo que no debía | **Revisión del texto** antes de enviar sola: promesas falsas, detalles internos, enlaces o direcciones ajenos, pedidos o importes inventados |
| XSS, clickjacking, versiones expuestas | **Cabeceras de seguridad y CSP** en el panel y la API; nginx y PHP sin versión |
| Secretos en los logs | Contraseñas, tokens y claves ocultos (`[oculto]`) en todos los canales; trazas sin argumentos |
| Guardar datos personales para siempre | **Retención** (borrado automático cada noche) y **derecho de supresión** |
| Servicios internos accesibles desde la red | Todos los puertos solo en `127.0.0.1`; Grafana sin acceso anónimo |
| SSRF hacia los metadatos de la nube | Bloqueados **siempre**, también en local |
| Una regresión en una fase futura | **Tests de arquitectura** y auditoría de dependencias en el CI |

### Registro de auditoría

Cada acción de una persona queda en la tabla `audit_logs`: quién (y una
copia de su email, por si después se borra el usuario), sobre qué, qué
cambió (antes → después), desde qué IP y navegador, y el `request_id` de
la petición (con él se encuentran sus logs en Loki). Se registran inicios
y cierres de sesión, **intentos fallidos** (en la empresa de la cuenta
atacada), usuarios, empresa, buzones (incluida la conexión por OAuth),
ajustes de la IA, documentación, emails borrados y la retención.

- **Nunca guarda secretos:** las contraseñas salen como `[oculto]` (se
  sabe que cambiaron, nunca cuál era).
- **Inmutable:** la aplicación no puede modificar ni borrar un registro, y
  un *trigger* de PostgreSQL rechaza cualquier `UPDATE`, también con SQL
  directo. Solo la retención borra lo antiguo (730 días).
- **Quién lo ve:** propietario y administrador (`audit.view`), solo de su
  empresa. Los intentos de login con un email que no existe no tienen
  empresa: solo los ve el operador en la BD.

### Cuentas

"Mi cuenta" (menú del usuario, arriba a la derecha) permite cambiar la
contraseña y cerrar la sesión en los demás dispositivos. Ambas cosas
piden la contraseña actual (una sesión robada no basta) y tienen un límite
de 6 intentos por minuto. Cada sesión del panel guarda una huella de la
contraseña: al cambiarla, las demás sesiones dejan de valer en su
siguiente petición y los tokens de API se revocan. Si un administrador
cambia la contraseña de otra persona, también se revocan sus tokens.

### Freno del gasto de IA

Antes de clasificar un email se comprueba:

- **Presupuesto diario de la empresa** (10 $ por defecto,
  `AI_TENANT_DAILY_BUDGET_USD`): suma del coste de sus peticiones de IA
  desde las 00:00. Lo decide el operador, no la empresa:
  `php artisan ai:budget <empresa> --daily=25` (o `default`).
- **Emails de un mismo remitente que pasaron por la IA en 24 h** (20,
  `AI_MAX_EMAILS_PER_SENDER`), sin distinguir mayúsculas.

Si se supera alguno, el email va a revisión humana **sin llamar a la IA**
y con el motivo en su historial; la alerta `IAFrenadaPorLimites` avisa al
operador. Es un freno, no un tope exacto: un email que ya está en marcha
termina (y cada uno tiene su propio límite de gasto).

### Revisión del texto antes de enviar sola

Además de las reglas de la Fase 9 (intención, confianza, destinatario), el
texto que redactó la IA se compara con lo que realmente tenía delante (el
email y lo que devolvieron sus herramientas). La respuesta va a una persona
si:

- promete que **una persona revisará el caso** (sería falso si sale sola);
- contiene **detalles internos** (nombres de herramientas, etiquetas del prompt);
- incluye **enlaces** que no son de la empresa ni de sus datos. Los que
  venían en el email del cliente no cuentan: así un atacante no consigue
  que la empresa reenvíe su enlace de phishing;
- menciona **direcciones de email** ajenas al cliente y a la empresa;
- cita **números de pedido o importes** que no aparecen en ningún sitio
  (alucinaciones);
- está vacía o es demasiado larga.

Son comprobaciones sin coste (no llaman a la IA). Probadas con las
respuestas reales de Claude de las fases anteriores: paran las 5 que
prometían una revisión humana y dejan pasar las 5 que sí se enviaron.

### Retención y derecho de supresión

- **Cada noche (03:30)** `data:prune` borra los emails más antiguos que la
  retención de su empresa (365 días por defecto, `RETENTION_EMAILS_DAYS`;
  cada empresa elige entre 30 y 3650 en "Empresa y usuarios") y la
  auditoría de más de 730 días (`RETENTION_AUDIT_DAYS`).
- Borrar un email elimina también sus destinatarios, adjuntos (también
  **los ficheros del disco**), respuestas e historial. De los registros de
  IA se borra el contenido (respuesta, argumentos y resultados de
  herramientas) y se conservan tokens y costes. Nunca se borra un email
  que se está procesando o enviando.
- **Derecho de supresión:** `php artisan data:forget-address <empresa>
  <email> --force` borra todo lo de una persona (como remitente o
  destinataria). Sin `--force`, solo dice cuántos emails hay. En el panel,
  propietarios y administradores pueden borrar un email desde su detalle.
- En la auditoría de estos borrados no queda ningún dato del cliente: del
  email, solo el dominio del remitente; de la dirección olvidada, su hash.

### Infraestructura

- **Todos los puertos se publican solo en `127.0.0.1`.** Para abrir la app
  a la red local: `APP_BIND=0.0.0.0`. Prometheus y Loki no tienen usuario
  ni contraseña: no deben salir de la máquina.
- **Grafana** sin registro ni acceso anónimo, sin avatares externos ni
  llamadas a grafana.com. Su contraseña (`GRAFANA_PASSWORD`) solo se aplica
  la primera vez que arranca; para cambiarla después:
  `docker compose exec grafana grafana cli admin reset-admin-password <nueva>`.
- **Detrás de un proxy con HTTPS** (producción): `TRUSTED_PROXIES` con sus
  IPs, para que la auditoría y los límites vean la IP real del cliente.
  Sin eso, una cabecera `X-Forwarded-For` falsa se ignora.
- **Antes de desplegar:** `php artisan security:check --production`
  revisa debug, HTTPS, cookies, contraseñas por defecto, token de
  métricas, IA simulada, anti-SSRF, proxies y caducidad de tokens, y sale
  con error si algo falla.
- `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` (necesario en local para
  GreenMail) **solo funciona en `local` y en los tests**, y ni siquiera
  así permite los metadatos de la nube (`169.254.169.254`).

### Prueba de ataques contra el stack

Con un script de `curl` contra nginx (como un navegador o un cliente de la
API), todo dio el resultado esperado:

| Ataque | Resultado |
|---|---|
| Un usuario de otra empresa lee, pide el HTML o borra un email | 404 (igual que si no existiera); su búsqueda no encuentra nada ajeno |
| Un agente consulta la auditoría | 403 |
| 6 intentos de contraseña en un minuto | 429 en el sexto; los fallidos, en la auditoría de la empresa |
| Buzón apuntando a `169.254.169.254`, `::ffff:169.254.169.254`, `fe80::1` o `0.0.0.0` | 422 "dirección interna" (**antes, en local, se aceptaba**: corregido) |
| `/metrics` sin token o con uno falso | 404 |
| Un intruso con la contraseña filtrada entra por el panel y por la API; la víctima cambia la contraseña | La sesión y el token del intruso dejan de valer; la de la víctima sigue |
| Contraseña de 8 caracteres | 422 |
| Cabeceras del panel | CSP, `X-Frame-Options: DENY`, sin `X-Powered-By` ni versión de nginx |
| `X-Forwarded-For` falso | Ignorado: la auditoría registra la IP real |

### Actualizar un entorno anterior a la Fase 12

```bash
docker compose exec app composer install               # dependencia nueva de desarrollo (symfony/yaml)
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder   # permisos audit.view y emails.delete
docker compose up -d                                   # puertos solo en local y Grafana endurecido
docker compose restart nginx prometheus                # nginx sin versión; nueva alerta
docker compose exec horizon php artisan horizon:terminate
```

## 14. Revisión humana (*human in the loop*)

La IA no tiene la última palabra: lo que no puede enviar sola (modo
borrador, confianza baja, quejas, cobros, sospecha de *prompt injection*,
envíos fallidos...) lo resuelve una persona desde el panel. Código en
[`app/Automation/Review/`](app/Automation/Review/).

### La bandeja

**Revisión** (`/revision`, primera entrada del menú, con el **número de
casos pendientes**): los emails en `human_review` o `failed`, **primero los
urgentes y, dentro de cada urgencia, los que más llevan esperando**. Cada
caso muestra cuánto lleva esperando, el **motivo** (por qué no salió solo),
la intención, si la IA dejó una propuesta y a quién está asignado.
Pestañas *Todos / Asignados a mí / Sin asignar* y filtro de intención (en
la URL). "Asignarme" evita que dos personas trabajen el mismo caso sin
saberlo (no lo bloquea: un responsable puede actuar igual).

### Qué puede hacer una persona

En el detalle del email aparece la tarjeta **Revisión** (permiso
`emails.review`, que tienen los tres roles: es el trabajo diario de un
agente):

| Acción | Qué pasa |
|---|---|
| **Aprobar y enviar** | La respuesta de la IA sale tal cual, en el hilo del cliente, por el buzón en el que llegó. |
| **Enviar con mis cambios** | Al editar el texto, se guarda el **original de la IA** (se puede ver "qué corrigió") y sale el corregido. |
| **Descartar propuesta** | La propuesta queda descartada (con motivo opcional); el caso sigue esperando a una persona. |
| **Enviar respuesta** (sin propuesta) | La persona escribe la suya. |
| **Cerrar sin responder** | El email pasa a "Sin respuesta" (`ignored`), con motivo opcional. |
| **Reprocesar con la IA** | La IA prepara una propuesta nueva (la anterior se descarta). **Nunca** si ya se intentó enviar una respuesta: podría llegar dos veces. Se puede añadir una **indicación** para la IA; esa respuesta vuelve siempre a revisión. |
| **Aprobar / Rechazar acción** | La IA propuso algo que modifica datos (p. ej. abrir un ticket de soporte) y espera en pausa. Aprobada, la hace (una sola vez) y prepara la respuesta con su resultado; rechazada, responde sin hacerla. Ver [ai-service](ai-service/README.md#acciones-con-aprobación-humana-fase-4). |

- Lo que envía una persona sale **sin `Auto-Submitted`** (no es una respuesta
  automática) y el historial dice quién lo aprobó.
- **Avisos antes de enviar:** las comprobaciones de la [revisión del
  texto](#revisión-del-texto-antes-de-enviar-sola) (enlaces o direcciones
  ajenos, pedidos o importes que no salen de ningún dato, detalles internos)
  se muestran sobre la propuesta y se repiten al editar. No impiden enviar:
  decide la persona. Si el texto promete que "una persona revisará el caso",
  se le recuerda que alguien tiene que hacer ese seguimiento.
- **Dos personas a la vez:** cada decisión es condicional; si otra persona (o
  la plataforma) se adelantó, la API responde **409** con el motivo y no
  cambia nada.
- Un email con un **envío fallido** también se resuelve aquí, con un aviso:
  la respuesta anterior puede haber llegado.

### Historial y métricas

- Cada decisión queda en **`email_reviews`** (quién, qué, si corrigió el
  texto, motivo, cuánto esperó el caso), en el historial de estados del
  email y en la **auditoría** (sin el texto ni los motivos: pueden llevar
  datos del cliente y la auditoría dura más que el email).
- **Historial de la revisión** (`/revision/historial`): casos pendientes,
  resueltos, espera mediana y media, **% de propuestas de la IA aprobadas
  sin cambios** (corregidas / descartadas: la mejor medida de la calidad
  real de la IA), qué decidió cada persona y la lista de todas las
  decisiones.
- **Grafana** (dashboard Emails, fila "Revisión humana"): pendientes, caso
  más antiguo, decisiones, aprobadas sin cambios, corregidas, espera mediana
  y p95. Métricas `aimail_email_reviews_total`,
  `aimail_email_review_wait_seconds` y
  `aimail_email_review_oldest_waiting_seconds`; alerta **`CasoSinAtender`**
  si el caso más antiguo de una empresa pasa de 4 h esperando.

### Probarlo sin gastar en IA

```bash
# .env: AI_SERVICE_LLM_PROVIDER=simulated (IA de pruebas por palabras clave) y docker compose up -d ai-service
docker compose exec horizon php artisan horizon:terminate
docker compose exec app php artisan ai:automation 1 --mode=draft     # todo pasa por una persona
# Envía correos a soporte@demo.test (GreenMail, SMTP 127.0.0.1:3025) y en < 1 min
# aparecen en http://localhost:8080/revision. Las respuestas llegan al buzón del
# remitente en GreenMail (IMAP 127.0.0.1:3143, contraseña "secret").
```

### Resultados de la prueba en el navegador (IA simulada + GreenMail)

5 correos de clientes (pedido, envíos a Canarias, doble cobro, lámpara rota
en inglés, factura) → los 5 en la bandeja en segundos, con su motivo. Como
agente: **corregir** la propuesta del pedido con un número inventado
(#99999) → aviso "menciona pedidos que no aparecen…"; corregido y enviado →
llegó al buzón del cliente en GreenMail, en el hilo y sin `Auto-Submitted`,
y el detalle enlaza el texto original de la IA. **Descartar** la propuesta
de la factura con motivo y **escribir** la respuesta → llegó. **Cerrar** el
doble cobro ("atendido por teléfono"). Como propietario: **reprocesar** la
lámpara → propuesta nueva y la anterior en "Respuestas anteriores". El
número del menú bajó de 5 a 2, el historial mostró las decisiones por
persona y la auditoría las 6 acciones. Encontrado y corregido: la IA
simulada propone "A member of our team will review your message shortly" y
no se avisaba al aprobarla (ahora es un recordatorio).

### Actualizar un entorno anterior a la Fase 13

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder   # permiso emails.review
docker compose restart prometheus grafana                # alerta CasoSinAtender y fila nueva del dashboard Emails
docker compose exec horizon php artisan horizon:terminate
```

## 15. Estructura del proyecto

```
app/
├── Http/
│   ├── Controllers/Api/V1/   Controladores de la API (finos)
│   ├── Middleware/           ResolveTenant (middleware "tenant")
│   ├── Requests/             Validación + autorización de cada petición
│   └── Resources/            Formato JSON de las respuestas
├── Mail/                     Conexiones de correo (Fase 3)
│   ├── Contracts/            MailProvider (la interfaz común)
│   ├── Data/                 DTOs: MailMessage, MailFolder, OutgoingMessage…
│   ├── Credentials/          Credenciales tipadas + cifrado (cast)
│   ├── Providers/            Imap/ (IMAP+SMTP), Microsoft/ (Graph), Google/ (Gmail)
│   ├── OAuth/                Flujo OAuth, renovación de tokens, cliente HTTP común
│   ├── Security/             MailHostGuard (anti-SSRF)
│   ├── Support/              MIME común (parser y builder), HTML → texto
│   ├── Ingestion/            Ingesta (Fase 4): MailSyncService, EmailIngestionService, FetchEmailsJob
│   ├── Autodetect/           Autodetección IMAP/SMTP (ISPDB, autoconfig, SRV, MX) con anti-SSRF
│   ├── Enums/                Proveedor, estados de conexión/email/adjunto, tipo de destinatario
│   ├── MailProviderFactory   Conexión → proveedor
│   └── MailConnectionService Prueba de conexión y su estado
├── AI/                       Integración con ai-service (la IA vive en el microservicio)
│   ├── Remote/               Eventos: EmailEventPublisher, RemoteResultHandler, DocumentIndexPublisher,
│   │                         DocumentResultHandler, ReviewResumedPublisher, RedisEventStream, EventContract,
│   │                         AiServiceClient (API interna), RemoteCallRecorder (ai_requests)
│   ├── Classification/       EmailIntent y EmailClassification (lo que se guarda en emails)
│   ├── ToolCatalog           Catálogo de herramientas compartido (contracts/tools/catalog.json)
│   └── CostCalculator        Coste en USD por modelo (precios en config/ai.php)
├── Documents/                Base de conocimiento: DocumentService (subir, reindexar, borrar; indexa ai-service)
├── Tools/                    TenantToolService: herramientas activadas por empresa
├── Automation/               Automatización (Fase 9): EmailProcessor + ProcessEmailJob, AutoSendPolicy,
│                             ReplySender + SendReplyJob (envío en el hilo), AutomationMode;
│                             Review/ (Fase 13): HumanReviewService (decisiones de las personas), ReviewStats
├── Observability/            Métricas (Fase 11): Metrics (catálogo y almacén Redis), StateMetrics (estado actual),
│                             Observers/ (métricas desde los registros de auditoría), QueueMetricsSubscriber,
│                             MetricLabels; DashboardStats (cifras del resumen del panel)
├── Security/                 Seguridad (Fase 12): Audit/AuditLogger, Redactor + Logging/RedactSecrets (secretos fuera
│                             de los logs), AiSpendGuard (freno del gasto), ReplyContentGuard (revisión del texto),
│                             Retention/ (EmailEraser, RetentionService)
├── Console/Commands/         mail:sync (cada minuto), ai:process, ai:trace, ai:consume-results, ai:reconcile-remote,
│                             kb:import, kb:search, kb:reindex, ai:tools, ai:automation, ai:budget,
│                             data:prune (cada noche), data:forget-address, security:check
├── Models/                   Tenant, User, Role, Permission, MailConnection, Email,
│                             EmailRecipient, EmailAttachment, MailSyncState,
│                             AiRequest, AiPromptVersion (historial), Document, AiToolCall,
│                             TenantTool, AiAgentRun, EmailReply, EmailStatusChange, AuditLog, EmailReview
├── Policies/                 UserPolicy, TenantPolicy, MailConnectionPolicy, DocumentPolicy, EmailPolicy
├── Providers/                AppServiceProvider (rate limits, Gates), Horizon, MailProviders, AI
├── Tenancy/                  TenantService, BelongsToTenant, TenantScope
└── Jobs/                     Jobs de cola
bootstrap/app.php             Middleware y manejo de errores de la API
config/                       Configuración (ai, sanctum, horizon, mail_connections, services…)
database/
├── migrations/               Esquema de la BD
├── seeders/                  Roles/permisos, datos de desarrollo y documentación de ejemplo (knowledge/)
└── factories/                Datos falsos para tests
docker/                       Config de nginx, php, postgres, redis, prometheus (+ alerts.yml y sus pruebas),
                              loki, promtail, grafana (provisioning + dashboards/*.json)
resources/js/panel/           Panel (Fase 10): Vue + TS + Naive UI — pages/, components/, layouts/, api/, stores/, utils/
resources/views/panel.blade.php  Página que carga el panel (Laravel la sirve en cualquier ruta que no sea de la API)
routes/
├── api.php                   Prefijo /api/v1
├── api/v1.php                Rutas de la API v1
└── console.php               Tareas programadas (mail:sync cada minuto, limpieza de tokens, retención cada noche)
tests/
├── Feature/Auth/             Login, logout, tokens
├── Feature/Tenancy/          Tenants, roles, permisos, aislamiento
├── Feature/Users/            Gestión de usuarios y empresa
├── Feature/Mail/             Conexiones: Imap/, Smtp/ (GreenMail real), Microsoft/, Google/ (APIs simuladas)
├── Feature/Emails/           Tablas y modelos de emails, API del panel (listado, detalle, revisión, bandeja, historial);
│                             Ingestion/ (servicio, sync, job, comando, E2E con GreenMail)
├── Feature/AI/               Costes, clasificación guardada; Remote/ (contrato, publicación, resultados, documentos,
│                             aprobación de acciones, API interna, Redis real, rol de ai-service)
├── Feature/Documents/        Documentos: subida, API, aislamiento, comandos
├── Feature/Tools/            Herramientas por empresa y comando ai:tools
├── Feature/Automation/       Modos, política de envío, envío en el hilo, procesador, job, comandos y E2E con GreenMail real;
│                             Review/ (revisión humana: modelo, servicio y E2E con GreenMail y la IA simulada)
├── Feature/Observability/    Métricas (emails, IA, HTTP, colas, estado), /metrics, logs JSON, dashboard del panel
├── Feature/Security/         Auditoría, gasto de IA, revisión del texto, cabeceras, logs, retención, infraestructura,
│                             arquitectura (aislamiento, rutas protegidas, dependencias encapsuladas, higiene)
├── Feature/*.php             API base (health, errores, rate limit, BD de test)
├── Support/                  GreenMail (buzones de prueba) y Pdf (PDFs generados al vuelo)
└── Fakes/                    FakeMailProvider, InMemoryEventStream (streams de Redis en memoria)
```

En próximas fases se añadirán más módulos en `app/` por dominio (`CRM/`…), siempre con la
lógica de negocio en servicios y los controladores finos.

---

## 16. API

Versionada bajo **`/api/v1`**. Siempre responde JSON; envía
`Accept: application/json`.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/health` | público | Estado de la API |
| POST | `/auth/login` | público | Devuelve un token Bearer (máx. 5 intentos/min por email+IP) |
| GET | `/auth/me` | autenticado | Usuario actual, su empresa, rol y permisos |
| POST | `/auth/logout` | autenticado | Revoca el token usado (los demás siguen activos) |
| GET | `/tenant` | `tenant.view` | Datos de tu empresa |
| PATCH | `/tenant` | `tenant.update` | Renombrar tu empresa (`name`) y elegir cuántos días se conservan sus emails (`email_retention_days`, 30–3650; `null` = los de la plataforma) |
| PUT | `/auth/password` | autenticado | Cambiar la propia contraseña (`current_password`, `password`, `password_confirmation`); cierra las demás sesiones (máx. 6/min) |
| POST | `/auth/logout-other-devices` | autenticado | Cerrar la sesión en los demás dispositivos (`password`; máx. 6/min) |
| GET | `/users` | `users.view` | Usuarios de tu empresa (paginado) |
| POST | `/users` | `users.create` | Crear usuario en tu empresa |
| GET | `/users/{id}` | `users.view` | Ver un usuario de tu empresa |
| PATCH | `/users/{id}` | `users.update` | Editar (nombre, email, password, rol) |
| DELETE | `/users/{id}` | `users.delete` | Eliminar (y revocar sus tokens) |
| GET | `/mail-connections` | `mail_connections.view` | Conexiones de correo de tu empresa (sin secretos) |
| POST | `/mail-connections` | `mail_connections.manage` | Conectar una cuenta **IMAP/SMTP** |
| GET | `/mail-connections/{id}` | `mail_connections.view` | Ver una conexión |
| PATCH | `/mail-connections/{id}` | `mail_connections.manage` | Editar (la contraseña no enviada se conserva; `enabled` true/false) |
| DELETE | `/mail-connections/{id}` | `mail_connections.manage` | Eliminar (409 si ya tiene correos guardados: desactívala) |
| POST | `/mail-connections/{id}/test` | `mail_connections.manage` | Probar recepción y envío (máx. 10/min) |
| POST | `/mail-connections/oauth/{microsoft\|google}/start` | `mail_connections.manage` | Iniciar la conexión OAuth: devuelve `authorization_url` |
| GET | `/mail-connections/oauth/{microsoft\|google}/callback` | público | Vuelta desde Microsoft/Google (la llama el navegador) |
| GET | `/documents` | `documents.view` | Documentación de tu empresa (paginado, con estado de indexado) |
| POST | `/documents` | `documents.manage` | Subir un documento (multipart: `file` + `title` opcional); se indexa en cola |
| GET | `/documents/{id}` | `documents.view` | Ver un documento |
| POST | `/documents/{id}/reindex` | `documents.manage` | Volver a indexarlo |
| DELETE | `/documents/{id}` | `documents.manage` | Eliminar documento, trozos y fichero |
| POST | `/knowledge-base/search` | `documents.view` | Buscar por significado (`query`, `limit` opcional); devuelve trozos con su fuente (máx. 30/min) |
| POST | `/auth/session` | público (solo el panel) | Login del panel con cookie de sesión (sin token) |
| POST | `/mail-connections/autodetect` | `mail_connections.manage` | Proponer la configuración IMAP/SMTP de un email (máx. 10/min) |
| GET | `/dashboard?days=7\|30\|90` | `emails.view` | Resumen del periodo para el panel |
| GET | `/emails` | `emails.view` | Emails (filtros `status`, `intent`, `mailbox`, `q`, `from`, `to`; paginado) |
| GET | `/emails/{id}` | `emails.view` | Detalle: clasificación, propuesta, historial y traza de la IA |
| GET | `/emails/{id}/html` | `emails.view` | HTML del email **ya saneado** |
| GET | `/emails/{id}/attachments/{adjunto}` | `emails.view` | Descargar un adjunto (siempre como fichero) |
| GET | `/automation` | `tenant.view` | Modo de automatización, intenciones y herramientas de tu empresa |
| PATCH | `/automation` | `automation.manage` | Cambiar el modo (`mode`) y las intenciones (`auto_send_intents`) |
| PATCH | `/automation/tools/{tool}` | `automation.manage` | Activar o desactivar una herramienta (`enabled`) |
| DELETE | `/emails/{id}` | `emails.delete` | Borrar un email con sus adjuntos, respuestas e historial (409 si se está procesando o enviando) |
| GET | `/audit-logs` | `audit.view` | Registro de auditoría (filtros `category`, `user`, `from`, `to`; paginado) |
| GET | `/review-queue` | `emails.view` | Bandeja de revisión: lo que espera a una persona, urgentes y más antiguos primero (filtros `assigned` = `mine`/`unassigned`, `intent`, `mailbox`; `meta.counts`) |
| POST | `/emails/{id}/replies/{reply}/approve` | `emails.review` | Aprobar y enviar la propuesta de la IA (`body` opcional: el texto corregido) |
| POST | `/emails/{id}/replies/{reply}/reject` | `emails.review` | Descartar la propuesta (`reason` opcional) |
| POST | `/emails/{id}/replies` | `emails.review` | Enviar una respuesta escrita por la persona (`body`) |
| POST | `/emails/{id}/close` | `emails.review` | Cerrar sin responder (`reason` opcional) |
| POST | `/emails/{id}/reprocess` | `emails.review` | Volver a procesarlo con la IA (409 si ya se intentó un envío; `instruction` opcional, máx. 2000 caracteres) |
| POST | `/emails/{id}/action/approve` | `emails.review` | Aprobar la acción que propuso ai-service (409 si no hay ninguna pendiente o ya se decidió) |
| POST | `/emails/{id}/action/reject` | `emails.review` | Rechazar la acción que propuso ai-service |
| PUT | `/emails/{id}/assignee` | `emails.review` | Asignar el caso (`user_id`, o `null` para liberarlo) |
| POST | `/emails/{id}/review-checks` | `emails.review` | Avisos de las comprobaciones automáticas sobre un texto (`body`, `reply_id` opcional) |
| GET | `/reviews` | `emails.view` | Historial de decisiones (filtros `decision`, `user`, `from`, `to`; paginado) |
| GET | `/reviews/summary?days=7\|30\|90` | `emails.view` | Resumen: pendientes, esperas, propuestas de la IA aprobadas sin cambios, por persona |
| GET | `/orders?q=` | `orders.view` | Pedidos importados por CSV (búsqueda por número, email o nombre) |
| GET | `/orders/template` | `orders.manage` | Plantilla CSV para rellenar en Excel |
| POST | `/orders/import` | `orders.manage` | Importa un CSV (`file`): todo o nada; 422 con `rows` (errores por fila) |
| DELETE | `/orders` | `orders.manage` | Borra todos los pedidos importados de la empresa |
| GET | `/integrations/{standard-api\|woocommerce}` | `orders.view` | Conexión con el sistema propio o la tienda (sin credenciales) o `null` |
| PUT | `/integrations/standard-api` | `orders.manage` | Crea o cambia la conexión (`base_url`, `token` —vacía = conservar—, `orders`, `invoices`, `enabled`) y la prueba |
| PUT | `/integrations/woocommerce` | `orders.manage` | Igual para la tienda (`base_url`, `consumer_key` + `consumer_secret` —vacías = conservar—, `enabled`) |
| POST | `/integrations/{tipo}/test` | `orders.manage` | Vuelve a probar la conexión |
| DELETE | `/integrations/{tipo}` | `orders.manage` | Desconecta el sistema |

```bash
# Login
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"password","device_name":"mi-portatil"}'

# Usar el token devuelto
curl http://localhost:8080/api/v1/auth/me \
  -H "Accept: application/json" -H "Authorization: Bearer <TOKEN>"
```

**Autenticación:** tokens de Laravel Sanctum, válidos durante 7 días
(`SANCTUM_TOKEN_EXPIRATION`, en minutos). Los caducados se borran a diario
de forma automática. El panel no usa tokens: entra con cookie de sesión
(`/auth/session`).

**Errores** (mismo formato siempre, sin detalles internos):

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | Sin token o token inválido/caducado | `{"message":"No autenticado."}` |
| 403 | Sin permiso, o empresa desactivada | `{"message":"No autorizado."}` / `"La empresa está desactivada."` |
| 404 | No existe **o es de otra empresa** | `{"message":"Recurso no encontrado."}` |
| 409 | El recurso cambió entre medias (p. ej. otra persona ya aprobó la respuesta) o está en uso | `{"message":"<motivo>"}` |
| 422 | Datos inválidos | `{"message":"...","errors":{"campo":["..."]}}` |
| 429 | Demasiadas peticiones (60/min en general, 5/min en login, 10/min al probar conexiones) | — |
| 500 | Error inesperado | `{"message":"Error interno del servidor."}` |

---

## 17. Tests

```bash
docker compose exec app php artisan test                       # todos
docker compose exec app php artisan test --filter=TenantIsolation
docker compose exec app php artisan test --group=greenmail   # solo IMAP/SMTP contra GreenMail
```

- **Pest**, contra una base de datos PostgreSQL **separada** (`app_testing`),
  no SQLite, porque en fases futuras se usarán columnas `vector` de pgvector.
  `DatabaseConnectionTest` falla si alguna vez los tests apuntan a otra BD.
- Cada test empieza con la BD vacía (`RefreshDatabase`) y con el catálogo de
  roles y permisos cargado (`$seed` en [`tests/TestCase.php`](tests/TestCase.php)).
- ⚠️ En [`phpunit.xml`](phpunit.xml) las variables críticas (`DB_DATABASE`,
  `APP_ENV`, `QUEUE_CONNECTION`…) van como `<server>` y no como `<env>`:
  el contenedor ya define esas variables y, con `<env>`, los tests usarían la
  BD de desarrollo `app` y la **borrarían**. No lo cambies.
- **IMAP/SMTP** se prueban contra **GreenMail real** (grupo `greenmail`):
  cada test crea sus propias cuentas con `Tests\Support\GreenMail`.
- **Microsoft/Google** se prueban con `Http::fake()` y
  `Http::preventStrayRequests()`: si algún código intenta llamar de verdad a
  una API externa, el test falla.
- Helpers: `inTenant($tenant, fn)` e `incomingMessage([...])` (en
  `tests/Pest.php`) para crear/leer modelos de una empresa fuera de una
  petición y construir correos recibidos, y `FakeMailProvider` para probar
  código que usa `MailProvider` sin servidor (lotes, resync y fallos
  simulados).
- **IA:** Laravel no llama a ningún modelo; los tests simulan a
  ai-service. Los eventos van a `InMemoryEventStream` (en `TestCase`, salvo
  los tests de Redis real) y los helpers de `tests/Pest.php` hacen el
  recorrido completo: `processRemote($email)` lo publica,
  `processedEvent($email, [...])` / `failedEvent()` construyen un resultado
  que cumple el contrato (a partir de los ejemplos compartidos de
  `contracts/events/examples/`), `applyAiResult($evento)` lo aplica como
  `ai:consume-results` y `processWithAi($email, [...])` hace las tres
  cosas. La API interna de ai-service se simula con `Http::fake`. La IA en
  sí se prueba en `ai-service/` (pytest).
- Las funciones de ayuda de los archivos de test son **globales** en Pest:
  no repitas un nombre ya usado en otro archivo.
- **Métricas:** con `METRICS_STORAGE=memory` (cada test empieza de cero) y
  el helper `metricSample('nombre', ['etiqueta' => 'valor'])` de
  `tests/Pest.php`, que lee `/metrics` como Prometheus. `phpunit.xml`
  fija `AI_SERVICE_URL`/`AI_SERVICE_INTERNAL_TOKEN` de prueba para que un
  `.env` local no cambie los tests.
- **Seguridad** (`tests/Feature/Security/`): además de cada protección,
  **tests de arquitectura** que fallan si un cambio futuro se salta una
  regla. Todo modelo con `tenant_id` usa `BelongsToTenant` y no deja
  asignarlo en masa. Toda ruta de la API exige sesión y empresa, salvo las
  públicas a propósito. Laravel no usa ningún SDK de modelos ni de
  embeddings (la IA es de ai-service) y las librerías de IMAP/SMTP solo se
  usan en su módulo. En `app/` no hay `dd`/`dump`, ejecución de comandos,
  `eval`/`unserialize` ni `env()` fuera de `config/`. Se comprueban con el
  tokenizer de PHP sobre `app/`; el `arch()` de Pest analiza también
  `vendor/` y se queda sin memoria.
- **Revisión humana:** `tests/Feature/Automation/Review/` (servicio y modelo)
  y `tests/Feature/Emails/` (API de la revisión, bandeja e historial).
  `HumanReviewEndToEndTest` recorre el caso completo contra GreenMail con la
  **IA simulada** (`ai.provider=simulated`): el cliente escribe, la IA
  propone, una persona corrige y aprueba por la API, y la respuesta llega a
  su buzón.
- **Panel:** Vitest + `@vue/test-utils` en `resources/js/panel/**/*.test.ts`
  (`docker compose exec vite npm test`), con `fetch` simulado: rutas y
  permisos, cliente HTTP, filtros en la URL, el iframe aislado del email,
  formularios y páginas por rol.

## 18. CI

Cada push y cada Pull Request a `main` ejecuta los tests en GitHub Actions
contra PostgreSQL + pgvector, Redis y GreenMail reales, y además el job
`frontend` comprueba los tipos del panel, sus tests y que compila, y el job
`observability` valida la configuración de Prometheus, prueba las alertas
(`promtool test rules`) y revisa los dashboards de Grafana. **`composer
audit`** y **`npm audit`** hacen fallar el CI si alguna dependencia tiene
una vulnerabilidad conocida
([`.github/workflows/tests.yml`](.github/workflows/tests.yml)). El resultado
se ve en la pestaña **Actions** del repositorio.

## 19. Entornos

Ahora mismo solo existe el entorno **local**. Staging y producción (con su
`.env` y un override de Docker Compose sin el código montado desde disco) se
prepararán en la fase de producción.

## 20. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| El panel sale en blanco | Ni Vite ni build: `docker compose up -d vite` (mira `docker compose logs vite`) o `docker compose run --rm vite npm run build`. |
| No puedo iniciar sesión en el panel ("solo para el panel") | Entra por la URL de `APP_URL` (`http://localhost:8080`): Sanctum solo abre sesión a los dominios de `SANCTUM_STATEFUL_DOMAINS`. |
| "La sesión ha caducado. Recarga la página." (419) | Token CSRF caducado: recarga la página. |
| `horizon` en bucle "Restarting" | Faltan `vendor/` o `APP_KEY`: pasos 3–4 del arranque. |
| `No application encryption key has been specified` | Se generó la clave pero los contenedores no se recrearon: `docker compose up -d`. |
| Un puerto ya está en uso (8080, 3000, 9090, 3100, 5433) | Cámbialo en `.env`: `APP_PORT`, `GRAFANA_PORT`, `PROMETHEUS_PORT`, `LOKI_PORT`, `DB_FORWARD_PORT`. Si pasa justo al recrear contenedores (`docker compose up -d`), Docker Desktop aún no había liberado el puerto: repite el comando. |
| Desde otro equipo de la red no se abre el panel | Desde la Fase 12 todos los puertos escuchan solo en `127.0.0.1`. Para la app: `APP_BIND=0.0.0.0` en `.env` y `docker compose up -d nginx` (Grafana, Prometheus y Loki se quedan en local a propósito). |
| Un email va a revisión humana con "presupuesto diario de IA" o "ha enviado más de N emails en 24 h" | Freno del gasto de IA. Mira `ai:budget <empresa>`; súbelo con `--daily=`, o espera al día siguiente / a que pasen 24 h. Luego `ai:process <id> --now --reprocess`. |
| Una respuesta segura no se envía sola: "La respuesta incluye enlaces que no son de la empresa…" (o pedidos, importes, direcciones) | Revisión del texto antes de enviar: algo de la respuesta no sale ni del email ni de los datos que consultó la IA. Lo revisa una persona; es intencionado. |
| Después de cambiar la contraseña, el panel pide entrar en otro navegador | Es lo previsto: cambiarla cierra las demás sesiones y revoca los tokens de API. |
| Mi buzón local (GreenMail) da "dirección interna no permitida" | `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` solo vale con `APP_ENV=local`; y `169.254.x.x` no se permite nunca. |
| Falta la extensión `vector` o la BD `app_testing` | El script de inicio de Postgres solo se ejecuta con un volumen nuevo: `docker compose down -v` (⚠️ borra los datos locales) y volver a levantar. |
| `429 Too Many Requests` al probar el login | Límite de 5 intentos/min por email+IP; espera un minuto. |
| Los tests del grupo `greenmail` fallan con "No se pudo conectar" | GreenMail no está levantado: `docker compose up -d greenmail`. |
| "El servidor X apunta a una dirección interna no permitida" en local | Protección anti-SSRF. Para usar GreenMail u otro servidor local: `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` en `.env` (**nunca en producción**). |
| Llegan correos al buzón pero no aparecen en `emails` | El buzón no está `active` (pruébalo con `POST /mail-connections/{id}/test`), la 1ª sincronización solo fija el punto de partida (lo anterior no se importa), o Horizon no procesa la cola `mail`: `docker compose exec horizon php artisan horizon:supervisors` debe mostrar `supervisor-mail`. Mira `last_error` en `mail_sync_states`. |
| Tras cambiar código o `config/horizon.php` los jobs siguen igual | Horizon mantiene el código cargado: `docker compose exec horizon php artisan horizon:terminate` (el contenedor lo vuelve a arrancar). |
| Los emails se guardan pero se quedan en `received` | `AI_PROCESS_ON_INGEST=false`, o Horizon no procesa la cola `ai`: `horizon:supervisors` debe mostrar `supervisor-ai` (si no, `horizon:terminate`). Para encolarlos: `ai:process --pending`. |
| Todos los emails van a `human_review` | Es lo normal en modo `draft` (por defecto). Mira el motivo en `email_status_changes`: "La IA no está configurada" (falta `ANTHROPIC_API_KEY`; tras ponerla, `horizon:terminate` y `ai:process <id> --now --reprocess`), modo `off`, intención no permitida, confianza baja... |
| No se envía nada aunque la empresa esté en `auto` | `AI_AUTO_SEND=false`, la intención no está en `ai:automation <empresa>`, o el motivo está en `email_status_changes`. Si el email está `approved`, Horizon no procesa la cola `mail` (`supervisor-mail`). |
| Un email en `failed` con "No se pudo enviar la respuesta" | El servidor rechazó el envío o falló la conexión tras los reintentos (`send_error` en `email_replies`). No se reprocesa solo para no enviarlo dos veces: aparece en la **bandeja de revisión**, donde una persona comprueba si llegó y responde o lo cierra. |
| "Este email ya no está esperando a una persona" (409) al aprobar | Otra persona (o la plataforma) decidió antes: recarga la página para ver su estado. |
| No veo la tarjeta "Revisión" en un email | Solo aparece si el email espera a una persona (`human_review` o `failed`) y tu rol tiene `emails.review` (en un entorno anterior a la Fase 13: `db:seed --class=RolesAndPermissionsSeeder`). |
| En Grafana, "Aprobadas sin cambios" o los totales salen por debajo de la realidad | Limitación de Prometheus: `increase()` no cuenta la primera aparición de una serie. Con uso continuo no se nota; la cifra exacta está en **Revisión → Historial** del panel. |
| Un documento se queda en `failed`: "El modelo bge-m3 no está descargado en Ollama" | Falta descargarlo: `docker compose exec ollama ollama pull bge-m3` y después reindexar (`kb:reindex --all` o el botón del panel). |
| Un documento se queda en "Indexando" | ai-service o `ai-results` no están en marcha (`docker compose ps`), o ai-service no llega a Laravel por `AI_SERVICE_LARAVEL_URL`. `ai:reconcile-remote` lo republica cada 5 min y, tras 3 intentos, lo deja con error. |
| "El PDF no contiene texto extraíble" | Es un PDF escaneado (solo imágenes). El OCR no está soportado todavía: súbelo en texto o PDF con texto. |
| El agente no consulta pedidos (solo documentación) | Las herramientas de pedidos no están activadas para la empresa: `ai:tools 1 --enable=get_order --enable=get_customer_orders`. |
| Los emails se quedan en `processing` | ai-service o `ai-results` no están en marcha (`docker compose ps`; logs: `docker compose logs ai-service`). Sin clave de Anthropic el worker no arranca. Tras 15 min se republican y, tras 3 intentos, pasan a revisión humana. |
| Emails en `failed` con "La IA no está bien configurada" | La clave de Anthropic es incorrecta, se revocó o no hay saldo: revisa `ANTHROPIC_API_KEY`, `docker compose up -d ai-service` y `ai:process --failed`. |
| Grafana no arranca: "Datasource provisioning error: data source not found" | Un Grafana antiguo tenía los datasources con otro `uid`. El provisioning ya los borra y recrea (`deleteDatasources`); si aun así falla: `docker compose rm -sf grafana && docker volume rm <proyecto>_grafana-data` (⚠️ borra lo creado a mano en Grafana). |
| El target `laravel` sale DOWN en Prometheus (http://localhost:9090/targets) | `METRICS_TOKEN` de `.env` distinto del de `docker/prometheus/prometheus.yml`, o nginx no está en la red `observability-net` (`docker compose up -d nginx`). |
| Los dashboards salen vacíos | Aún no hay datos en el periodo (cambia el rango arriba a la derecha) o el target `laravel` está DOWN. "Gasto por día" muestra los días ya terminados: para hoy, "Últimas 24 h". |
| Cambié `AI_SERVICE_LLM_PROVIDER` (u otra variable) y los emails siguen igual | Los contenedores leen el `.env` al crearse: `docker compose up -d ai-service` (y `horizon:terminate` para las variables de Laravel). |
| "La conexión con microsoft/google no está configurada en este servidor" | Faltan `MICROSOFT_CLIENT_ID/SECRET` o `GOOGLE_CLIENT_ID/SECRET` (ver [Conexiones de correo](#4-conexiones-de-correo)). |

## Documentación adicional

- [`CLAUDE.md`](CLAUDE.md): especificación completa del proyecto (objetivo,
  17 fases, arquitectura futura), estado real de avance y registro de las
  decisiones técnicas tomadas en cada fase.
- `PENDIENTE-SETUP-DOCKER.md` y `README-docker.md`: notas históricas de
  la Fase 1. Algunas partes están desactualizadas; la referencia vigente es
  este README.
