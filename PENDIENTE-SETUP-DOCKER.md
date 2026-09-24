# Pendiente: terminar configuración de Docker (Horizon + pgvector)

## 1. Instalar Docker (Ubuntu 24.04)

```bash
sudo apt remove docker docker-engine docker.io containerd runc
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# para no necesitar sudo en cada comando docker:
sudo usermod -aG docker $USER
# cerrar sesión y volver a entrar, o: newgrp docker

# comprobar:
docker --version
docker compose version
```

## 2. Clonar/actualizar el proyecto

```bash
git clone <URL-del-repo>
# o si ya lo tenías clonado en esta máquina de casa:
cd "AI Mail Automation Platform"
git pull
```

## 3. Variables de entorno

```bash
cp .env.docker.example .env
# rellenar/ajustar DB_DATABASE, DB_USERNAME, DB_PASSWORD, GRAFANA_USER, etc.
```

## 4. Levantar app + postgres + redis, e instalar Horizon

```bash
docker compose up -d app postgres redis
docker compose exec app composer require laravel/horizon
docker compose exec app php artisan horizon:install
docker compose exec app php artisan migrate
```

## 5. Recrear el volumen de Postgres (necesario por el cambio a pgvector)

⚠️ Esto borra los datos actuales de esa base de datos si ya existían.

```bash
docker compose down
docker volume rm nombre-proyecto_postgres-data
docker compose up -d --build
```

(el nombre exacto del volumen puede variar; para verlo antes de borrar: `docker volume ls`)

## 6. Comprobar que todo arranca bien

```bash
docker compose ps
docker compose logs -f horizon
```

Dentro de `psql`, comprobar que la extensión vector quedó instalada:
```bash
docker compose exec postgres psql -U app -d app -c "\dx"
```
Debería aparecer `vector` en el listado.

## 7. Pendiente opcional (no bloqueante)

- Proteger la ruta `/horizon` con una Gate en `app/Providers/HorizonServiceProvider.php`
  (por defecto solo es accesible en entorno `local`).
- Ajustar `config/horizon.php` (número de procesos, colas, balanceo) según carga esperada.

## 8. Usar cada entorno (local / staging / producción)

Tres `.env` distintos, mismo `docker-compose.yml` base:

**Local (por defecto, sin nada especial):**
```bash
docker compose up -d --build
```

**Staging:**
```bash
cp .env.staging.example .env.staging
# rellenar con valores/secretos reales de staging
docker compose --env-file .env.staging -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

**Producción:**
```bash
cp .env.production.example .env.production
# rellenar con valores/secretos reales de producción (contraseñas fuertes, dominio real)
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

`docker-compose.prod.yml` es el override que quita el bind-mount del código fuente
(usa el código ya horneado en la imagen) y pone `restart: always`. Ni `.env.staging`
ni `.env.production` (los reales, con secretos) se suben a git — están en el
`.gitignore`, solo sus `.example` sí.

## 9. Cambiar la password de Redis en producción

La plantilla `.env.production.example` trae `REDIS_PASSWORD=usa-un-secreto-real-aqui`
como recordatorio de que hay que cambiarlo, no como valor válido.

```bash
# generar una contraseña aleatoria fuerte:
openssl rand -base64 32
```
Copia el resultado y ponlo como valor de `REDIS_PASSWORD` en tu `.env.production`
real (no en el `.example`). Si el stack ya estaba levantado con la contraseña
antigua, reinicia Redis y la app para que cojan el nuevo valor:
```bash
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml up -d --force-recreate redis app horizon scheduler
```

## 10. Generar APP_KEY en un dispositivo nuevo

Cada máquina/entorno que no tenga ya un `.env` con `APP_KEY` rellena necesita
generar una:
```bash
docker compose exec app php artisan key:generate
```
Esto escribe la clave directamente en el `.env` que esté usando el contenedor
`app` en ese momento (local, staging o producción, según con qué `--env-file`
hayas levantado el stack).

⚠️ Si el entorno YA tenía datos cifrados con una `APP_KEY` anterior (sesiones,
campos `encrypted`, etc.), regenerarla invalida esas sesiones y esos datos
dejan de poder descifrarse. En un entorno nuevo sin datos previos, no hay
ningún problema en generarla sin más.

## 11. Configurar Laravel Sanctum (API tokens + SPA)

```bash
# Instala Sanctum y el scaffolding de API (routes/api.php, migración de tokens)
docker compose exec app php artisan install:api
docker compose exec app php artisan migrate

# Publicar config/cors.php (Laravel 11+ no lo trae por defecto)
docker compose exec app php artisan config:publish cors
```

Después, editar a mano:

- **`app/Models/User.php`** — añadir `use Laravel\Sanctum\HasApiTokens;` y el trait `HasApiTokens` en la clase.
- **`bootstrap/app.php`** — anteponer el middleware de Sanctum al grupo `api`:
  ```php
  use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

  ->withMiddleware(function (Middleware $middleware) {
      $middleware->api(prepend: [
          EnsureFrontendRequestsAreStateful::class,
      ]);
  })
  ```
- **`config/cors.php`** — `paths` con `api/*` y `sanctum/csrf-cookie`, `allowed_origins` con tu `APP_URL` (y el puerto de Vite en desarrollo si aplica), `supports_credentials => true`.
- **`routes/api.php`** — agrupar todo bajo `Route::prefix('v1')->group(...)` desde ya, para no tener que reestructurar cuando exista `v2`.

**Probar:**
```bash
docker compose exec app php artisan tinker
```
```php
$user = App\Models\User::first();
$token = $user->createToken('test-token')->plainTextToken;
```
```bash
curl -H "Authorization: Bearer TOKEN_AQUI" http://localhost:8080/api/v1/user
```

Pendiente para Fase 2 (no ahora): middleware de tenant que aísle tokens/sesiones por empresa.

## 12. Configurar Laravel Queue (Redis)

```bash
docker compose exec app php artisan migrate   # confirma la tabla failed_jobs
```

Editar a mano **`config/queue.php`**, bloque `redis`:
```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 300, // antes 90: las llamadas a IA pueden tardar más
    'block_for' => null,
    'after_commit' => false,
],
```

Colas por nombre a usar al despachar jobs (sin archivo de config nuevo, solo
convención): `emails-ingestion`, `ai-processing`, `emails-sending`. Luego se
priorizan en `config/horizon.php`.

**Probar:**
```bash
docker compose exec app php artisan make:job TestQueueJob
# dentro de handle(): Log::info('TestQueueJob ejecutado correctamente');
docker compose exec app php artisan tinker
```
```php
App\Jobs\TestQueueJob::dispatch();
```
```bash
docker compose logs -f horizon
```

## 13. Configurar Laravel Horizon (Fase 1: un supervisor genérico + Gate)

Editar **`config/horizon.php`**, sección `environments` — un solo supervisor
sobre la cola `default`, sin colas específicas todavía (esas llegan en
Fase 4/5, cuando existan jobs reales que las necesiten):
```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries' => 3,
        ],
    ],

    'local' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'tries' => 3,
        ],
    ],
],
```

Proteger el dashboard en **`app/Providers/HorizonServiceProvider.php`** (esto
sí es de seguridad, no depende de que existan colas):
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return in_array($user->email, ['tu-email@dominio.com']);
    });
}
```

**Aplicar y comprobar:**
```bash
docker compose exec app php artisan config:clear
docker compose restart horizon
docker compose logs -f horizon
```
Entrar a `http://localhost:8080/horizon` y verificar que `supervisor-1` está activo.

**Pendiente para Fase 4/5:** separar supervisors por tipo de cola
(`emails-ingestion`, `ai-processing`, `emails-sending`) una vez existan jobs
reales y datos de cuánto tarda cada uno.




## 14. Configurar logging (rotación diaria)

Añadir al `.env` de cada entorno (ya aplicado en `.env.staging.example` y
`.env.production.example`; añádelo también a tu `.env` local real):
```env
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=debug        # staging: warning · producción: error
LOG_DAILY_DAYS=14      # producción: 30
```

`LOG_STACK=daily` cambia el canal `stack` de `single` (un archivo que crece
sin límite) a `daily` (un archivo por día, ej. `laravel-2026-09-24.log`),
que además es justo el patrón que ya lee Promtail en
`docker/promtail/promtail-config.yaml` (`storage/logs/*.log`). No hace falta
tocar `config/logging.php`.

**Comprobar:**
```bash
docker compose exec app php artisan tinker
```
```php
\Illuminate\Support\Facades\Log::info('Prueba de logging diario');
```
```bash
docker compose exec app ls storage/logs/
```
Debería aparecer un archivo con la fecha de hoy.

## 15. Crear estructura base de API /api/v1

- `routes/api.php` hace `require` de `routes/api/v1.php` bajo `prefix('v1')`
- Nuevo `routes/api/v1.php`: rutas versionadas (health check, /user con auth:sanctum, y el resto se añade fase a fase)
- Nuevo `app/Http/Controllers/Api/V1/HealthController.php`: GET /api/v1/health
- `bootstrap/app.php`: withExceptions devuelve JSON en vez de HTML para rutas api/* (validación 422, auth 401, 404, 500 solo si APP_DEBUG=false)
- `AppServiceProvider::boot()`: RateLimiter::for('api', 60 req/min por usuario o IP)

**Probar:**
```bash
docker compose exec app php artisan route:list --path=api
curl http://localhost:8080/api/v1/health
```