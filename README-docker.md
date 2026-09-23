# Stack Docker: Laravel + PostgreSQL + Redis + Workers + Observabilidad

## Servicios incluidos

| Servicio | Descripción |
|---|---|
| `app` | PHP-FPM 8.3 con tu código Laravel |
| `nginx` | Sirve la app en `http://localhost:8080` |
| `queue-worker` | 2 réplicas de `php artisan queue:work redis` |
| `scheduler` | Ejecuta `php artisan schedule:run` cada 60s |
| `postgres` | PostgreSQL 16 |
| `redis` | Redis 7 (colas, caché, sesiones) |
| `postgres-exporter` / `redis-exporter` | Exponen métricas para Prometheus |
| `prometheus` | Scrapea métricas, disponible en `http://localhost:9090` |
| `loki` | Almacena logs |
| `promtail` | Envía logs de los contenedores y de `storage/logs` a Loki |
| `grafana` | Dashboards en `http://localhost:3000` (datasources ya provisionados) |

## Cómo arrancarlo

1. Copia las variables de entorno:
```bash
   cp .env.docker.example .env
```
   (o añade esas claves a tu `.env` de Laravel si ya tienes uno)

2. Genera la `APP_KEY` si no la tienes:
```bash
   docker compose run --rm app php artisan key:generate
```

3. Levanta todo:
```bash
   docker compose up -d --build
```

4. Ejecuta las migraciones:
```bash
   docker compose exec app php artisan migrate --force
```

5. Escalar workers si hace falta:
```bash
   docker compose up -d --scale queue-worker=5
```

## Notas importantes

- **Endpoint `/metrics`**: el `prometheus.yml` ya está configurado para scrapear `nginx:80/metrics`, pero Laravel no expone esa ruta por defecto. Necesitas un paquete que la genere (por ejemplo `spatie/laravel-prometheus`, `promphp/prometheus_client_php` con una ruta propia en `routes/web.php`, o similar). Si no la añades, ese job de Prometheus aparecerá como "down", pero el resto del stack (Postgres, Redis, logs) funciona igual.
- **Persistencia**: los volúmenes `postgres-data`, `redis-data`, `prometheus-data`, `grafana-data` y `loki-data` sobreviven a `docker compose down`. Usa `docker compose down -v` solo si quieres borrarlo todo.
- **Producción**: este `docker-compose.yml` está pensado como base. Para producción real conviene separar imágenes por entorno, usar secretos en vez de variables en texto plano, y poner TLS delante de Nginx (o un balanceador que lo haga).
- **Dashboards de Grafana**: el datasource ya está provisionado; puedes añadir archivos JSON de dashboards en `docker/grafana/provisioning/dashboards/` y se cargarán automáticamente al reiniciar Grafana.