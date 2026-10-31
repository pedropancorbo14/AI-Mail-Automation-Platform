# Funciones comunes de los scripts de producción (Fase 16). Se carga con
# ". scripts/lib.sh" desde la raíz del repositorio.

COMPOSE_FILE_PROD="${COMPOSE_FILE_PROD:-docker-compose.prod.yml}"
ENV_FILE_PROD="${ENV_FILE_PROD:-.env.production}"
DEPLOY_DIR="${DEPLOY_DIR:-.deploy}"
# El compose lo usa también como env_file de los contenedores de Laravel.
export ENV_FILE_PROD

say() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ -f "$ENV_FILE_PROD" ] || fail "No existe $ENV_FILE_PROD: créalo con scripts/init-env.sh <dominio>."

# Versión desplegada ahora (la escribe scripts/deploy.sh).
current_version() {
    cat "$DEPLOY_DIR/current" 2>/dev/null || true
}

# docker compose de producción con la versión indicada (o la actual).
dc() {
    APP_VERSION="${APP_VERSION:-$(current_version)}" \
        docker compose -f "$COMPOSE_FILE_PROD" --env-file "$ENV_FILE_PROD" "$@"
}

# Servicios que escriben en la base de datos (se paran para restaurar).
WRITERS="app horizon scheduler ai-results ai-service ai-mcp nginx"
