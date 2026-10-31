#!/bin/sh
# Despliegue (Fase 16). En el servidor, desde la raíz del repositorio:
#
#   git pull
#   scripts/deploy.sh            versión = fecha + commit (p. ej. 20261031-1402-a1b2c3d)
#   scripts/deploy.sh 1.4.0      con un nombre de versión propio
#   SKIP_BUILD=1 scripts/deploy.sh <versión>   con imágenes ya construidas
#   SKIP_OLLAMA_PULL=1 ...       sin descargar el modelo de embeddings
#
# Pasos (si uno falla, se para y la versión anterior sigue funcionando):
#   1. Construye las imágenes de esta versión (Laravel, nginx con el panel,
#      ai-service). Las de versiones anteriores se quedan: el rollback no
#      tiene que volver a construir nada.
#   2. security:check --production con la imagen nueva: no se despliega una
#      configuración insegura.
#   3. Copia de seguridad (verificada) antes de migrar.
#   4. Migraciones y permisos nuevos.
#   5. Arranca la versión nueva y espera a que esté sana.
#   6. scripts/smoke-test.sh. Si falla, vuelve sola a la versión anterior.
#
# Las migraciones tienen que funcionar con la versión anterior del código
# (añadir columnas o tablas, no renombrar ni borrar en el mismo despliegue):
# así el rollback de las imágenes no necesita deshacer la base de datos. Si
# alguna vez no es posible, la copia del paso 3 permite volver atrás
# (scripts/restore.sh).
set -eu
cd "$(dirname "$0")/.."
. scripts/lib.sh

PREVIOUS="$(current_version)"
VERSION="${1:-$(date -u +%Y%m%d-%H%M)-$(git rev-parse --short HEAD 2>/dev/null || echo local)}"

case "$VERSION" in
    *[!A-Za-z0-9._-]*) fail "Versión no válida: $VERSION (letras, números, . _ -)" ;;
esac

if git status --porcelain 2>/dev/null | grep -qv '^??'; then
    echo "AVISO: hay cambios sin commitear: se despliegan tal cual están en esta carpeta."
fi

export APP_VERSION="$VERSION"
mkdir -p "$DEPLOY_DIR"

say "Desplegando $VERSION (ahora: ${PREVIOUS:-nada})"
dc config -q

if [ -n "${SKIP_BUILD:-}" ]; then
    say "1. Imágenes ya construidas (SKIP_BUILD)"
    for image in app web ai-service; do
        docker image inspect "${IMAGE_PREFIX:-aimail}/$image:$VERSION" >/dev/null 2>&1 || fail "Falta la imagen ${IMAGE_PREFIX:-aimail}/$image:$VERSION"
    done
else
    say "1. Construyendo las imágenes"
    dc build
fi

say "2. Comprobando la configuración de seguridad"
dc run --rm --no-deps app php artisan security:check --production

say "3. Arrancando la base de datos y Redis"
dc up -d --wait postgres redis

BACKUP=""
if [ -n "$PREVIOUS" ]; then
    say "   Copia de seguridad antes de migrar"
    dc run --rm backup once
    BACKUP="$(dc run --rm --no-deps backup list | tail -1)"
fi

say "4. Migraciones y permisos"
dc run --rm app php artisan migrate --force
dc run --rm app php artisan db:seed --class=RolesAndPermissionsSeeder --force

start_and_check() {
    say "5. Arrancando $VERSION"
    dc up -d --remove-orphans --wait --wait-timeout "${DEPLOY_WAIT_SECONDS:-240}" || return 1

    if [ -z "${SKIP_OLLAMA_PULL:-}" ]; then
        say "   Modelo de embeddings (solo se descarga la primera vez)"
        dc exec -T ollama ollama pull "$(sed -n 's/^OLLAMA_EMBEDDING_MODEL=//p' "$ENV_FILE_PROD" | tail -1 | grep . || echo bge-m3)" >/dev/null || return 1
    fi

    say "6. Comprobación"
    scripts/smoke-test.sh
}

if ! start_and_check; then
    if [ -n "$PREVIOUS" ]; then
        say "La versión $VERSION no arranca bien: volviendo a $PREVIOUS"
        APP_VERSION="$PREVIOUS" dc up -d --remove-orphans --wait --wait-timeout "${DEPLOY_WAIT_SECONDS:-240}" || true
        echo "$(date -u +%FT%TZ) $VERSION FALLIDA (vuelta a $PREVIOUS)" >> "$DEPLOY_DIR/history"
        APP_VERSION="$PREVIOUS" scripts/smoke-test.sh || true
        fail "Despliegue de $VERSION cancelado; sigue $PREVIOUS. Si las migraciones de $VERSION rompen $PREVIOUS: scripts/restore.sh $BACKUP"
    fi
    fail "La versión $VERSION no arranca bien (primer despliegue: no hay versión anterior). Logs: docker compose -f $COMPOSE_FILE_PROD --env-file $ENV_FILE_PROD logs"
fi

if [ -n "$PREVIOUS" ] && [ "$PREVIOUS" != "$VERSION" ]; then
    echo "$PREVIOUS" > "$DEPLOY_DIR/previous"
fi
echo "$VERSION" > "$DEPLOY_DIR/current"
echo "$(date -u +%FT%TZ) $VERSION desplegada (antes: ${PREVIOUS:-nada}; copia previa: ${BACKUP:-ninguna})" >> "$DEPLOY_DIR/history"

# Imágenes de versiones antiguas: se guardan las de las 3 últimas desplegadas.
KEEP="$(grep ' desplegada ' "$DEPLOY_DIR/history" | awk '{print $2}' | tail -3; cat "$DEPLOY_DIR/current" "$DEPLOY_DIR/previous" 2>/dev/null || true)"
for image in $(docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -E "^${IMAGE_PREFIX:-aimail}/(app|web|ai-service):" || true); do
    tag="${image##*:}"
    echo "$KEEP" | grep -qx "$tag" || docker image rm "$image" >/dev/null 2>&1 || true
done

say "Desplegada $VERSION. Volver a la anterior: scripts/rollback.sh"
