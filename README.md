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
| 4 | Ingesta de emails (guardarlos, deduplicar, sincronizar con jobs) | ⏳ Siguiente |
| 5–17 | AI Engine (Claude/Anthropic), RAG, tools, agente, automatización, panel de administración, Grafana, seguridad, human-in-the-loop, integraciones… | Pendiente |

Ahora mismo existe la **base multi-tenant**, la **API de usuarios** y las
**conexiones de correo**: la plataforma ya sabe leer, detectar y enviar el
correo de las cuentas de cada empresa, pero todavía no lo procesa de forma
automática (eso empieza en la Fase 4). La hoja de ruta completa y las
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
```

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
| API | http://localhost:8080/api/v1 | ver [API](#6-api) |
| Horizon (colas) | http://localhost:8080/horizon | libre en entorno `local` |
| Grafana | http://localhost:3000 | `admin` / `admin` |
| Prometheus | http://localhost:9090 | — |
| GreenMail (correo de pruebas) | IMAP `127.0.0.1:3143` · SMTP `127.0.0.1:3025` (sin TLS) | `soporte@demo.test` / `secret` |

**Usuario de desarrollo** (lo crea el seeder): `test@example.com` /
`password`, *owner* de la empresa "Empresa Demo".

### Comandos útiles

```bash
docker compose exec app php artisan migrate:fresh --seed   # BD de desarrollo desde cero
docker compose exec app php artisan tinker                 # consola interactiva
docker compose exec app php artisan route:list --path=api  # rutas de la API
docker compose logs -f horizon                             # logs de las colas
docker compose exec app tail -f storage/logs/laravel-$(date +%F).log
```

---

## 2. Arquitectura

Monolito modular en Laravel (sin microservicios): una sola imagen de PHP
que se usa para la app web, los workers de colas y el scheduler.

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
| `postgres` | PostgreSQL 16 con la extensión `pgvector` (para el RAG de fases futuras). Crea también la BD `app_testing` para los tests. |
| `redis` | Colas, caché y sesiones. Con contraseña y persistencia AOF. |
| `postgres-exporter`, `redis-exporter` | Exponen métricas de BD y Redis para Prometheus. |
| `prometheus` | Recoge métricas. |
| `loki` + `promtail` | Almacén y recolector de logs (Laravel + contenedores). |
| `grafana` | Paneles; datasources de Prometheus y Loki ya configurados. |
| `greenmail` | Servidor de correo de pruebas (IMAP 3143, SMTP 3025, API 8080). Lo usan los tests de IMAP/SMTP y sirve para probar a mano (p. ej. con Thunderbird). Todo se queda en memoria. |

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
  sí lo hacen solos): se decidirá en la Fase 9.

### Añadir un proveedor nuevo

Implementa `MailProvider` (y `OAuthClient` si usa OAuth), regístralo en
[`MailProvidersServiceProvider`](app/Providers/MailProvidersServiceProvider.php)
con `$factory->extend(MailProviderType::X, fn (MailConnection $c) => ...)` y
añade el caso a `MailProviderType`. El resto de la aplicación no cambia.

---

## 5. Estructura del proyecto

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
│   ├── MailProviderFactory   Conexión → proveedor
│   └── MailConnectionService Prueba de conexión y su estado
├── Models/                   Tenant, User, Role, Permission, MailConnection
├── Policies/                 UserPolicy, TenantPolicy, MailConnectionPolicy
├── Providers/                AppServiceProvider (rate limits, Gates), Horizon, MailProviders
├── Tenancy/                  TenantService, BelongsToTenant, TenantScope
└── Jobs/                     Jobs de cola
bootstrap/app.php             Middleware y manejo de errores de la API
config/                       Configuración (sanctum, horizon, mail_connections, services…)
database/
├── migrations/               Esquema de la BD
├── seeders/                  Roles/permisos + datos de desarrollo
└── factories/                Datos falsos para tests
docker/                       Config de nginx, php, postgres, redis, prometheus, loki, grafana
routes/
├── api.php                   Prefijo /api/v1
├── api/v1.php                Rutas de la API v1
└── console.php               Tareas programadas
tests/
├── Feature/Auth/             Login, logout, tokens
├── Feature/Tenancy/          Tenants, roles, permisos, aislamiento
├── Feature/Users/            Gestión de usuarios y empresa
├── Feature/Mail/             Conexiones: Imap/, Smtp/ (GreenMail real), Microsoft/, Google/ (APIs simuladas)
├── Feature/*.php             API base (health, errores, rate limit, BD de test)
├── Support/GreenMail.php     Cuentas y buzones de prueba en GreenMail
└── Fakes/FakeMailProvider    Proveedor en memoria para probar código que usa MailProvider
```

En próximas fases se añadirán más módulos en `app/` por dominio (`AI/`,
`Tools/`, `Documents/`, `CRM/`, `ERP/`, `Observability/`…), siempre con la
lógica de negocio en servicios y los controladores finos.

---

## 6. API

Versionada bajo **`/api/v1`**. Siempre responde JSON; envía
`Accept: application/json`.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/health` | público | Estado de la API |
| POST | `/auth/login` | público | Devuelve un token Bearer (máx. 5 intentos/min por email+IP) |
| GET | `/auth/me` | autenticado | Usuario actual, su empresa, rol y permisos |
| POST | `/auth/logout` | autenticado | Revoca el token usado (los demás siguen activos) |
| GET | `/tenant` | `tenant.view` | Datos de tu empresa |
| PATCH | `/tenant` | `tenant.update` | Renombrar tu empresa |
| GET | `/users` | `users.view` | Usuarios de tu empresa (paginado) |
| POST | `/users` | `users.create` | Crear usuario en tu empresa |
| GET | `/users/{id}` | `users.view` | Ver un usuario de tu empresa |
| PATCH | `/users/{id}` | `users.update` | Editar (nombre, email, password, rol) |
| DELETE | `/users/{id}` | `users.delete` | Eliminar (y revocar sus tokens) |
| GET | `/mail-connections` | `mail_connections.view` | Conexiones de correo de tu empresa (sin secretos) |
| POST | `/mail-connections` | `mail_connections.manage` | Conectar una cuenta **IMAP/SMTP** |
| GET | `/mail-connections/{id}` | `mail_connections.view` | Ver una conexión |
| PATCH | `/mail-connections/{id}` | `mail_connections.manage` | Editar (la contraseña no enviada se conserva; `enabled` true/false) |
| DELETE | `/mail-connections/{id}` | `mail_connections.manage` | Eliminar |
| POST | `/mail-connections/{id}/test` | `mail_connections.manage` | Probar recepción y envío (máx. 10/min) |
| POST | `/mail-connections/oauth/{microsoft\|google}/start` | `mail_connections.manage` | Iniciar la conexión OAuth: devuelve `authorization_url` |
| GET | `/mail-connections/oauth/{microsoft\|google}/callback` | público | Vuelta desde Microsoft/Google (la llama el navegador) |

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
de forma automática. Ya está configurada también la autenticación por cookie
para el futuro panel de administración (SPA).

**Errores** (mismo formato siempre, sin detalles internos):

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | Sin token o token inválido/caducado | `{"message":"No autenticado."}` |
| 403 | Sin permiso, o empresa desactivada | `{"message":"No autorizado."}` / `"La empresa está desactivada."` |
| 404 | No existe **o es de otra empresa** | `{"message":"Recurso no encontrado."}` |
| 422 | Datos inválidos | `{"message":"...","errors":{"campo":["..."]}}` |
| 429 | Demasiadas peticiones (60/min en general, 5/min en login, 10/min al probar conexiones) | — |
| 500 | Error inesperado | `{"message":"Error interno del servidor."}` |

---

## 7. Tests

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
- Helpers: `inTenant($tenant, fn)` (en `tests/Pest.php`) para crear/leer
  modelos de una empresa fuera de una petición, y `FakeMailProvider` para
  probar código que usa `MailProvider` sin servidor.

## 8. CI

Cada push y cada Pull Request a `main` ejecuta los tests en GitHub Actions
contra PostgreSQL + pgvector, Redis y GreenMail reales
([`.github/workflows/tests.yml`](.github/workflows/tests.yml)). El resultado
se ve en la pestaña **Actions** del repositorio.

## 9. Entornos

Ahora mismo solo existe el entorno **local**. Staging y producción (con su
`.env` y un override de Docker Compose sin el código montado desde disco) se
prepararán en la fase de producción.

## 10. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `horizon` en bucle "Restarting" | Faltan `vendor/` o `APP_KEY`: pasos 3–4 del arranque. |
| `No application encryption key has been specified` | Se generó la clave pero los contenedores no se recrearon: `docker compose up -d`. |
| Un puerto ya está en uso (8080, 3000, 9090, 3100) | Cámbialo en `.env`: `APP_PORT`, `GRAFANA_PORT`, `PROMETHEUS_PORT`, `LOKI_PORT`. |
| Falta la extensión `vector` o la BD `app_testing` | El script de inicio de Postgres solo se ejecuta con un volumen nuevo: `docker compose down -v` (⚠️ borra los datos locales) y volver a levantar. |
| `429 Too Many Requests` al probar el login | Límite de 5 intentos/min por email+IP; espera un minuto. |
| Los tests del grupo `greenmail` fallan con "No se pudo conectar" | GreenMail no está levantado: `docker compose up -d greenmail`. |
| "El servidor X apunta a una dirección interna no permitida" en local | Protección anti-SSRF. Para usar GreenMail u otro servidor local: `MAIL_CONNECTIONS_ALLOW_PRIVATE_HOSTS=true` en `.env` (**nunca en producción**). |
| "La conexión con microsoft/google no está configurada en este servidor" | Faltan `MICROSOFT_CLIENT_ID/SECRET` o `GOOGLE_CLIENT_ID/SECRET` (ver [Conexiones de correo](#4-conexiones-de-correo)). |

## Documentación adicional

- [`CLAUDE.md`](CLAUDE.md): especificación completa del proyecto (objetivo,
  17 fases, arquitectura futura), estado real de avance y registro de las
  decisiones técnicas tomadas en cada fase.
- `PENDIENTE-SETUP-DOCKER.md` y `README-docker.md`: notas históricas de
  la Fase 1. Algunas partes están desactualizadas; la referencia vigente es
  este README.
