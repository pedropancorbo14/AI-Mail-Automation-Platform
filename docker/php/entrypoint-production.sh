#!/bin/sh
# Arranque de los contenedores de Laravel en producción (app, horizon,
# scheduler, ai-results). Las variables de entorno solo existen al arrancar
# (el .env no va dentro de la imagen), así que la caché de configuración,
# rutas, eventos y vistas se genera aquí y no al construir la imagen.
set -e

if [ -z "$APP_KEY" ]; then
    echo "Falta APP_KEY: genera una con 'php artisan key:generate --show' y ponla en .env.production." >&2
    exit 1
fi

php artisan optimize --no-interaction >/dev/null

exec "$@"
