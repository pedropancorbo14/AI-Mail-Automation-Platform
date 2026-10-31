#!/bin/sh
# Staging (Fase 16): levanta el stack de PRODUCCIÓN completo en esta máquina
# (https://localhost, con el certificado local de Caddy) y prueba todo lo que
# haría falta en un servidor real, de principio a fin:
#
#   1. Primer despliegue desde cero (scripts/deploy.sh): imágenes, migraciones,
#      security:check --production y comprobación (scripts/smoke-test.sh).
#   2. Alta de una empresa (tenant:create) y login del panel por HTTPS.
#   3. Copia de seguridad + restauración real (scripts/restore.sh): lo creado
#      después de la copia desaparece.
#   4. Un aviso de Alertmanager llega por email (a un GreenMail de pruebas).
#   5. Segundo despliegue, rollback, y un despliegue roto que vuelve solo a la
#      versión anterior.
#
# Al terminar lo borra todo (contenedores y volúmenes de staging). Lo ejecuta
# el CI (job "production") en cada push; en tu equipo necesita Docker, sh,
# curl y python3, y los puertos 80 y 443 libres:
#
#   scripts/staging-test.sh
#
# Usa su propio proyecto de compose (aimail-staging), su .env.staging y su
# .deploy-staging: no toca nada de un despliegue real.
set -eu
cd "$(dirname "$0")/.."

export COMPOSE_PROJECT_NAME=aimail-staging
export ENV_FILE_PROD=.env.staging
export DEPLOY_DIR=.deploy-staging
export SKIP_OLLAMA_PULL=1
V1="${STAGING_VERSION:-staging-1}"
V2="${V1%-*}-2"
V3="${V1%-*}-3"

say() { printf '\n######## %s\n' "$*"; }
fail() { printf '\nSTAGING FALLA: %s\n' "$*" >&2; exit 1; }

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        echo "---- Estado y logs (la prueba ha fallado) ----"
        docker ps -a --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" --format '{{.Names}}  {{.Status}}' || true
        for c in $(docker ps -a --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" --format '{{.Names}}'); do
            echo "=== $c"; docker logs --tail 60 "$c" 2>&1 || true
        done
    fi
    [ -n "${STAGING_KEEP:-}" ] && { echo "STAGING_KEEP: se deja todo levantado."; return; }
    docker rm -f "$COMPOSE_PROJECT_NAME-greenmail" >/dev/null 2>&1 || true
    APP_VERSION="$V1" docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE_PROD" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$ENV_FILE_PROD" "$DEPLOY_DIR"
}

[ -e "$ENV_FILE_PROD" ] && fail "$ENV_FILE_PROD ya existe (¿otra prueba a medias?): bórralo."
trap cleanup EXIT

say "Variables de staging (secretos aleatorios; clave de IA falsa: no se llama a la IA)"
ANTHROPIC_API_KEY=sk-ant-staging-not-a-real-key scripts/init-env.sh localhost "$ENV_FILE_PROD"
cat >> "$ENV_FILE_PROD" <<EOF
ALERT_EMAIL_TO=ops@alertas.test
ALERT_SMTP_HOST=greenmail-alerts:3025
ALERT_SMTP_FROM=Alertas AI Mail <alertas@alertas.test>
ALERT_SMTP_TLS=false
EOF

. scripts/lib.sh

say "1. Primer despliegue ($V1)"
scripts/deploy.sh "$V1"

say "2. Empresa y login del panel por HTTPS"
PASSWORD="$(dc run --rm -T app php artisan tenant:create "Acme Staging" ana@acme.example --generate-password | sed -n 's/.*no se volverá a mostrar): //p' | tr -d '\r')"
[ -n "$PASSWORD" ] || fail "tenant:create no devolvió la contraseña"
python3 scripts/ci/panel_login.py https://localhost ana@acme.example "$PASSWORD"

say "3. Copia de seguridad y restauración"
dc run --rm -T backup once
STAMP="$(dc run --rm -T --no-deps backup list | tail -1 | tr -d '\r')"
dc run --rm -T app php artisan tenant:create "Creada después de la copia" luis@despues.example --generate-password >/dev/null
scripts/restore.sh "$STAMP" --yes
TENANTS="$(dc exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select name from tenants order by id"' | tr -d '\r' | paste -sd, -)"
[ "$TENANTS" = "Acme Staging" ] || fail "tras restaurar hay estas empresas: $TENANTS (esperada solo Acme Staging)"
echo "  OK    restaurada la copia $STAMP: lo creado después ya no está"
scripts/smoke-test.sh
python3 scripts/ci/panel_login.py https://localhost ana@acme.example "$PASSWORD"

say "4. Aviso de Alertmanager por email"
docker run -d --name "$COMPOSE_PROJECT_NAME-greenmail" --network "${COMPOSE_PROJECT_NAME}_observability-net" --network-alias greenmail-alerts \
    -p "127.0.0.1:${STAGING_IMAP_PORT:-13143}:3143" \
    -e GREENMAIL_OPTS="-Dgreenmail.setup.test.smtp -Dgreenmail.setup.test.imap -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=ops:secret@alertas.test -Dgreenmail.users.login=email" \
    greenmail/standalone:2.1.5 >/dev/null
dc exec -T alertmanager amtool alert add PruebaDeStaging severity=warning --annotation='summary="Prueba de aviso de staging"' --alertmanager.url=http://127.0.0.1:9093
python3 scripts/ci/wait_for_mail.py "${STAGING_IMAP_PORT:-13143}" ops@alertas.test PruebaDeStaging

say "5. Segundo despliegue ($V2), rollback y despliegue roto ($V3)"
if [ -n "${SKIP_BUILD:-}" ]; then
    for image in app web ai-service; do docker tag "${IMAGE_PREFIX:-aimail}/$image:$V1" "${IMAGE_PREFIX:-aimail}/$image:$V2"; done
fi
scripts/deploy.sh "$V2"
[ "$(cat "$DEPLOY_DIR/current")" = "$V2" ] || fail "no quedó desplegada $V2"

scripts/rollback.sh
[ "$(cat "$DEPLOY_DIR/current")" = "$V1" ] || fail "el rollback no volvió a $V1"

# Versión rota: la app sin public/index.php (nginx no arranca sano).
printf 'FROM %s/app:%s\nUSER root\nRUN rm /var/www/html/public/index.php\nUSER www-data\n' "${IMAGE_PREFIX:-aimail}" "$V1" \
    | docker build -q -t "${IMAGE_PREFIX:-aimail}/app:$V3" - >/dev/null
for image in web ai-service; do docker tag "${IMAGE_PREFIX:-aimail}/$image:$V1" "${IMAGE_PREFIX:-aimail}/$image:$V3"; done
if SKIP_BUILD=1 DEPLOY_WAIT_SECONDS=60 scripts/deploy.sh "$V3"; then
    fail "el despliegue roto ($V3) se dio por bueno"
fi
[ "$(cat "$DEPLOY_DIR/current")" = "$V1" ] || fail "tras el despliegue roto no se volvió a $V1"
APP_VERSION="$V1" scripts/smoke-test.sh

say "STAGING OK: despliegue, HTTPS, login, copias, restauración, avisos y rollback"
