#!/bin/sh
# Copia las copias de seguridad del servidor a tu equipo (Fase 16). Las que
# se quedan en el servidor se pierden si se pierde el servidor: tráetelas
# con regularidad (p. ej. una vez por semana, o programado en tu equipo).
#
#   scripts/backup-pull.sh usuario@servidor [carpeta]   (por defecto ./backups)
#
# Solo usa ssh y docker en el servidor; en tu equipo, ssh y tar (en Windows,
# desde Git Bash o WSL). Las copias llevan TODOS los datos de los clientes:
# guárdalas cifradas (p. ej. en un disco cifrado con BitLocker o VeraCrypt).
set -eu

SERVER="${1:?Uso: scripts/backup-pull.sh usuario@servidor [carpeta]}"
DEST="${2:-backups}"
VOLUME="${BACKUP_VOLUME:-aimail_backups}"

mkdir -p "$DEST"
ssh "$SERVER" "docker run --rm -v $VOLUME:/backups:ro pgvector/pgvector:pg16 tar -C /backups -cf - ." | tar -xf - -C "$DEST"

echo "Copias en $DEST:"
ls -1 "$DEST/db" | tail -5
(cd "$DEST" && for f in checksums-*.sha256; do sha256sum -c --quiet "$f" || echo "DAÑADA: $f"; done)
