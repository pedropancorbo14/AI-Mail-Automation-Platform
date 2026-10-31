#!/bin/sh
# Comprobación rápida de que la plataforma desplegada funciona (Fase 16).
# La usan scripts/deploy.sh y scripts/rollback.sh tras arrancar (si falla,
# vuelven a la versión anterior) y el job "production" del CI.
#
#   scripts/smoke-test.sh
#
# Comprueba desde fuera (como un navegador, por HTTPS) y por dentro (que
# ai-service esté listo y Prometheus lea las métricas).
set -eu
cd "$(dirname "$0")/.."
. scripts/lib.sh

DOMAIN="$(sed -n 's/^APP_DOMAIN=//p' "$ENV_FILE_PROD" | tail -1)"
[ -n "$DOMAIN" ] || fail "Falta APP_DOMAIN en $ENV_FILE_PROD"
BASE="https://$DOMAIN"

# localhost (staging): el certificado es de la autoridad local de Caddy.
CURL="curl -sS --max-time 15"
[ "$DOMAIN" = "localhost" ] && CURL="$CURL --insecure"

FAILED=0
# Cada comprobación se reintenta unos segundos (p. ej. Prometheus raspa cada 15 s).
check() {
    n=0
    while ! eval "$2" >/dev/null 2>&1; do
        n=$((n + 1))
        if [ $n -ge "${SMOKE_RETRIES:-10}" ]; then
            echo "  FALLA $1"
            FAILED=$((FAILED + 1))
            return 0
        fi
        sleep 3
    done
    echo "  OK    $1"
}

status() { $CURL -o /dev/null -w '%{http_code}' "$@"; }

# HTTPS puede tardar unos segundos en tener certificado tras el primer arranque.
i=0
until [ "$(status "$BASE/up" || true)" = "200" ] || [ $i -ge 30 ]; do
    i=$((i + 1)); sleep 2
done

say "Comprobando $BASE"
check "la aplicación responde (/up)" '[ "$(status "$BASE/up")" = "200" ]'
check "API: /api/v1/health" '$CURL "$BASE/api/v1/health" | grep -q "\"status\""'
check "panel compilado (assets de /build)" '$CURL "$BASE/" | grep -q "/build/assets/"'
check "HSTS (Laravel ve la petición como HTTPS: confía en Caddy)" '$CURL -I "$BASE/up" | grep -qi "^strict-transport-security:"'
check "cookies solo por HTTPS" '$CURL -I "$BASE/" | grep -i "^set-cookie:" | grep -qi "secure"'
check "sin versión de nginx/PHP en las cabeceras" '! $CURL -I "$BASE/" | grep -qiE "^(x-powered-by:|server: nginx/)"'
check "/internal no sale a internet" '[ "$(status "$BASE/internal/integrations/1")" = "404" ]'
check "/metrics no sale a internet" '[ "$(status "$BASE/metrics")" = "404" ]'
check "http:// redirige a https://" '[ "$(curl -sS --max-time 10 -o /dev/null -w "%{redirect_url}" "http://$DOMAIN/")" = "$BASE/" ]'
check "ai-service listo (/ready)" 'dc exec -T ai-service python -c "import urllib.request,sys; sys.exit(0 if b\"\\\"ok\\\"\" in urllib.request.urlopen(\"http://127.0.0.1:8000/ready\", timeout=5).read() else 1)"'
check "Prometheus lee las métricas de la app (target laravel up)" 'curl -s --max-time 5 "http://127.0.0.1:${PROMETHEUS_PORT:-9090}/api/v1/query?query=up%7Bjob%3D%22laravel%22%7D" | grep -q "\"1\"\]"'

if [ "$FAILED" -gt 0 ]; then
    fail "$FAILED comprobaciones fallan."
fi

say "Todo correcto."
