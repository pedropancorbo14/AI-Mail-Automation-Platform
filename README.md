# AI Mail Automation Platform

Plataforma SaaS multi-tenant que recibe emails de empresas, los clasifica
mediante IA, consulta sistemas internos (CRM/ERP/pedidos/facturación/tickets/
documentación) mediante tool calling, genera una respuesta y decide si
enviarla automáticamente, dejarla como borrador para un trabajador, o
escalar el caso a una persona. Todo queda auditado, con métricas visibles en
Grafana.

> Estado actual: **Fase 1 completada** (base del proyecto). Las fases 2 en
> adelante (tenants, conexiones de email, AI Engine, RAG, tools, agente,
> automatización, panel de administración...) están descritas en la hoja de
> ruta interna del proyecto y se irán implementando de forma incremental.

## Stack

- **Backend:** PHP 8.4, Laravel 13, PostgreSQL 16 + pgvector, Redis, Laravel
  Horizon, Laravel Sanctum
- **Infraestructura:** Docker, Docker Compose, Nginx
- **Observabilidad:** Prometheus, Grafana, Loki, Promtail
- **Testing:** Pest
- **CI:** GitHub Actions

## Requisitos

- Docker y Docker Compose (`docker compose version`)
- Composer (solo si vas a ejecutar comandos fuera del contenedor)

## Puesta en marcha (entorno local)

```bash
git clone <URL-del-repo>
cd "AI Mail Automation Platform"

cp .env.docker.example .env
cp .env.example .env        # si aún no tienes tu .env de Laravel local

docker compose up -d --build

docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

La app queda disponible en `http://localhost:8080`.

| Servicio | URL |
|---|---|
| App (Nginx) | http://localhost:8080 |
| Horizon (colas) | http://localhost:8080/horizon |
| Grafana | http://localhost:3000 |
| Prometheus | http://localhost:9090 |

## Entornos

El proyecto soporta `local`, `staging` y `producción` mediante `.env`
distintos y un override de Docker Compose para los dos últimos:

```bash
# staging o producción
cp .env.production.example .env.production   # o .env.staging.example
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Detalle completo de cada entorno en `PENDIENTE-SETUP-DOCKER.md`.

## Tests

```bash
docker compose exec app php artisan test
```

Los tests corren contra una base de datos Postgres separada (`app_testing`,
con pgvector habilitado), no contra SQLite — necesario por las columnas
`vector` que se usarán a partir de la Fase 6 (RAG).

## CI

Cada `push`/Pull Request a `main` ejecuta automáticamente los tests contra
Postgres+pgvector y Redis reales (ver `.github/workflows/tests.yml`). El
estado se ve en la pestaña **Actions** del repositorio.

## Colas / Horizon

Los jobs se procesan con Redis + Laravel Horizon (`docker compose logs -f
horizon`, dashboard en `/horizon`, protegido por Gate en
`HorizonServiceProvider`).

## API

Versionada bajo `/api/v1` (ver `routes/api/v1.php`). Autenticación con
Laravel Sanctum: tokens para integraciones externas, cookies para el panel
de administración (SPA).

```bash
curl http://localhost:8080/api/v1/health
```

## Documentación adicional

- `PENDIENTE-SETUP-DOCKER.md` — checklist detallado de la Fase 1: Docker,
  PostgreSQL, Redis, entornos, Sanctum, Queue, Horizon, logging, API base,
  Pest y CI, con los comandos exactos de cada paso.
- `README-docker.md` — referencia rápida de los servicios del stack Docker.