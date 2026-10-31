#!/bin/sh
# Restaura una copia de seguridad (Fase 16): base de datos + ficheros.
# BORRA los datos actuales. Antes para todo lo que escribe y, al terminar,
# lo vuelve a arrancar con la versión desplegada.
#
#   scripts/restore.sh                ver las copias disponibles
#   scripts/restore.sh <copia>        restaurar (pide confirmación)
#   scripts/restore.sh <copia> --yes  sin confirmación (scripts, CI)
#
# Después de restaurar, los emails que estaban en marcha en la IA vuelven a
# su estado de la copia: ai:reconcile-remote los republica solo.
set -eu
cd "$(dirname "$0")/.."
. scripts/lib.sh

[ -n "$(current_version)" ] || fail "No hay ninguna versión desplegada (.deploy/current): despliega primero."

if [ $# -eq 0 ]; then
    say "Copias disponibles (la más reciente, la última)"
    dc run --rm --no-deps backup list
    exit 0
fi

STAMP="$1"
if [ "${2:-}" != "--yes" ]; then
    printf 'Se BORRARÁN los datos actuales y se restaurará la copia %s. Escribe "restaurar" para seguir: ' "$STAMP"
    read -r answer
    [ "$answer" = "restaurar" ] || fail "Cancelado."
fi

say "Copia de seguridad de lo que hay ahora (por si acaso)"
dc run --rm backup once

say "Parando la aplicación"
# shellcheck disable=SC2086
dc stop $WRITERS

say "Restaurando $STAMP"
if ! dc run --rm backup restore "$STAMP"; then
    dc up -d
    fail "No se pudo restaurar $STAMP: la aplicación sigue con los datos anteriores."
fi

say "Arrancando la aplicación"
dc up -d
say "Restaurada la copia $STAMP."
