#!/bin/sh
# Vuelve a una versión anterior (Fase 16): arranca sus imágenes, que se
# guardan en el servidor (las 3 últimas desplegadas), sin construir nada.
#
#   scripts/rollback.sh             a la versión anterior (.deploy/previous)
#   scripts/rollback.sh <versión>   a una concreta (ver .deploy/history)
#
# La base de datos NO se toca: las migraciones son compatibles hacia atrás
# (ver scripts/deploy.sh). Si una no lo fuera, restaura la copia que se hizo
# antes de ese despliegue (está en .deploy/history): scripts/restore.sh <copia>.
set -eu
cd "$(dirname "$0")/.."
. scripts/lib.sh

CURRENT="$(current_version)"
TARGET="${1:-$(cat "$DEPLOY_DIR/previous" 2>/dev/null || true)}"

[ -n "$TARGET" ] || fail "No hay versión anterior a la que volver (.deploy/previous)."
[ "$TARGET" != "$CURRENT" ] || fail "La versión $TARGET ya es la desplegada."

for image in app web ai-service; do
    docker image inspect "${IMAGE_PREFIX:-aimail}/$image:$TARGET" >/dev/null 2>&1 \
        || fail "No está la imagen ${IMAGE_PREFIX:-aimail}/$image:$TARGET en este servidor (solo se guardan las 3 últimas): despliégala con scripts/deploy.sh."
done

say "Volviendo de ${CURRENT:-nada} a $TARGET"
export APP_VERSION="$TARGET"
dc up -d --remove-orphans --wait --wait-timeout "${DEPLOY_WAIT_SECONDS:-240}"

if ! scripts/smoke-test.sh; then
    fail "La versión $TARGET tampoco pasa la comprobación: revisa los logs (dc logs)."
fi

echo "$TARGET" > "$DEPLOY_DIR/current"
if [ -n "$CURRENT" ]; then
    echo "$CURRENT" > "$DEPLOY_DIR/previous"
fi
echo "$(date -u +%FT%TZ) $TARGET rollback (antes: ${CURRENT:-nada})" >> "$DEPLOY_DIR/history"

say "Ahora está $TARGET. Para volver a $CURRENT: scripts/rollback.sh $CURRENT"
