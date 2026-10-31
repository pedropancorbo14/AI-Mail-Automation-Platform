#!/bin/sh
# Alertmanager (Fase 16): manda los avisos de las alertas de Prometheus
# (docker/prometheus/alerts.yml) por email, por Telegram o por los dos.
#
# Su configuración no admite variables de entorno, así que este script la
# escribe al arrancar a partir de las ALERT_* del .env.production. Las
# contraseñas no se escriben en la configuración: van en ficheros de secreto
# (/run/secrets) que Alertmanager lee él mismo.
#
#   Email (cualquier SMTP; gratis, p. ej. Gmail con una contraseña de aplicación):
#     ALERT_EMAIL_TO, ALERT_SMTP_HOST (servidor:puerto), ALERT_SMTP_FROM,
#     ALERT_SMTP_USER, ALERT_SMTP_PASSWORD, ALERT_SMTP_TLS (true por defecto)
#   Telegram (gratis: un bot de @BotFather y tu chat):
#     ALERT_TELEGRAM_BOT_TOKEN, ALERT_TELEGRAM_CHAT_ID
#
# Sin ninguno, Alertmanager arranca igual pero no avisa a nadie (las alertas
# se siguen viendo en Prometheus y Grafana) y lo dice en el log.
#
#   start.sh             escribe la configuración y arranca Alertmanager
#   start.sh --render    solo la escribe en la salida (pruebas: amtool check-config)
set -eu

CONFIG="${ALERTMANAGER_CONFIG:-/tmp/alertmanager.yml}"
SECRETS="${ALERTMANAGER_SECRETS_DIR:-/run/secrets}"

# Valor entre comillas dobles de YAML (sin saltos de línea; " y \ escapados).
q() {
    printf '"%s"' "$(printf '%s' "$1" | tr -d '\r\n' | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

has_secret() {
    [ -s "$SECRETS/$1" ]
}

email=false
telegram=false
[ -n "${ALERT_EMAIL_TO:-}" ] && email=true
[ -n "${ALERT_TELEGRAM_CHAT_ID:-}" ] && telegram=true

render() {
    cat <<YAML
# Generado por docker/alertmanager/start.sh: no lo edites, cambia las ALERT_*.
global:
  resolve_timeout: 5m
YAML

    if $email; then
        : "${ALERT_SMTP_HOST:?Falta ALERT_SMTP_HOST (servidor:puerto) para enviar las alertas por email}"
        : "${ALERT_SMTP_FROM:?Falta ALERT_SMTP_FROM}"
        echo "  smtp_smarthost: $(q "$ALERT_SMTP_HOST")"
        echo "  smtp_from: $(q "$ALERT_SMTP_FROM")"
        echo "  smtp_require_tls: ${ALERT_SMTP_TLS:-true}"
        if [ -n "${ALERT_SMTP_USER:-}" ]; then
            echo "  smtp_auth_username: $(q "$ALERT_SMTP_USER")"
            has_secret alert_smtp_password && echo "  smtp_auth_password_file: $SECRETS/alert_smtp_password"
        fi
    fi

    cat <<'YAML'

# Agrupa por alerta y empresa: diez buzones caídos a la vez son un aviso.
route:
  receiver: avisos
  group_by: [alertname, tenant]
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  routes:
    - matchers: ['severity="critical"']
      receiver: avisos
      repeat_interval: 1h
    - matchers: ['severity="info"']
      receiver: avisos
      repeat_interval: 24h

# Si la app no responde, no hace falta avisar además de todo lo que depende de ella.
inhibit_rules:
  - source_matchers: ['alertname="MetricasNoDisponibles"']
    target_matchers: ['severity=~"warning|info"']

receivers:
  - name: avisos
YAML

    if $email; then
        cat <<YAML
    email_configs:
      - to: $(q "$ALERT_EMAIL_TO")
        send_resolved: true
YAML
    fi

    if $telegram; then
        has_secret alert_telegram_bot_token || { echo "Falta ALERT_TELEGRAM_BOT_TOKEN para avisar por Telegram" >&2; exit 1; }
        cat <<YAML
    telegram_configs:
      - bot_token_file: $SECRETS/alert_telegram_bot_token
        chat_id: $(printf '%s' "$ALERT_TELEGRAM_CHAT_ID" | tr -cd '0-9-')
        parse_mode: ""
        send_resolved: true
YAML
    fi
}

if [ "${1:-}" = "--render" ]; then
    render
    exit 0
fi

render > "$CONFIG"

if ! $email && ! $telegram; then
    echo "AVISO: sin ALERT_EMAIL_TO ni ALERT_TELEGRAM_CHAT_ID las alertas no se envían a nadie (solo se ven en Prometheus y Grafana)." >&2
fi

exec /bin/alertmanager \
    --config.file="$CONFIG" \
    --storage.path=/alertmanager \
    --web.external-url="${ALERTMANAGER_URL:-http://localhost:9093}"
