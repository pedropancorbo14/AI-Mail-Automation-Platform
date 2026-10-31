#!/bin/sh
# Crea .env.production a partir de .env.production.example (Fase 16) con
# todos los secretos aleatorios y una APP_KEY nueva.
#
#   scripts/init-env.sh <dominio> [fichero]
#
# La clave de Anthropic se toma de ANTHROPIC_API_KEY si está en el entorno;
# si no, queda vacía (y el compose no arranca hasta que la pongas).
# Nunca sobrescribe un fichero que ya existe: perderías la APP_KEY y, con
# ella, las credenciales cifradas de los buzones.
set -eu

DOMAIN="${1:?Uso: scripts/init-env.sh <dominio> [fichero]}"
TARGET="${2:-.env.production}"
cd "$(dirname "$0")/.."

if [ -e "$TARGET" ]; then
    echo "$TARGET ya existe: no se toca." >&2
    exit 1
fi

case "$DOMAIN" in
    *[!A-Za-z0-9.-]*|"") echo "Dominio no válido: $DOMAIN" >&2; exit 1 ;;
esac

command -v openssl >/dev/null || { echo "Hace falta openssl." >&2; exit 1; }

APP_KEY="base64:$(openssl rand -base64 32)"

# Un secreto distinto por cada __SECRET__ (48 caracteres hexadecimales).
umask 077
awk -v domain="$DOMAIN" -v key="$APP_KEY" -v anthropic="${ANTHROPIC_API_KEY:-}" '
    {
        gsub(/__DOMAIN__/, domain)
        gsub(/__APP_KEY__/, key)
        gsub(/__ANTHROPIC_API_KEY__/, anthropic)
        while (index($0, "__SECRET__") > 0) {
            cmd = "openssl rand -hex 24"
            cmd | getline secret
            close(cmd)
            sub(/__SECRET__/, secret)
        }
        print
    }
' .env.production.example > "$TARGET"

echo "Creado $TARGET (permisos 600). Guarda una copia en un gestor de contraseñas."
[ -n "${ANTHROPIC_API_KEY:-}" ] || echo "Falta ANTHROPIC_API_KEY: añádela antes de desplegar."
