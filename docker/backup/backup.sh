#!/bin/sh
# Copias de seguridad (Fase 16). Corre en el contenedor "backup" del
# docker-compose.prod.yml (imagen de PostgreSQL: pg_dump de la misma versión
# que el servidor).
#
#   backup.sh loop      espera hasta BACKUP_TIME cada día y hace una copia
#   backup.sh once      una copia ahora (scripts/deploy.sh la hace antes de migrar)
#   backup.sh list      copias disponibles
#   backup.sh restore <copia>   restaura esa copia (BORRA los datos actuales;
#                       úsalo con scripts/restore.sh, que para la app antes)
#
# Cada copia:
#   1. Base de datos: pg_dump en formato "custom" (comprimido, se restaura
#      tabla a tabla con pg_restore).
#   2. Ficheros: adjuntos de los emails y documentos subidos (storage/app);
#      sin logs ni cachés.
#   3. PRUEBA DE RESTAURACIÓN: restaura la copia en una base de datos
#      temporal y comprueba que tiene las mismas tablas y migraciones que la
#      original. Una copia que no se restaura bien no cuenta como copia.
#   4. Borra las de más de BACKUP_KEEP_DAYS días y anota la hora del último
#      éxito (Laravel la publica como métrica y Prometheus avisa si se
#      retrasa: alerta CopiaDeSeguridadAtrasada).
#
# Las copias se quedan en el volumen "backups" del mismo servidor: sácalas a
# otro sitio (scripts/backup-pull.sh desde tu equipo) o, si el servidor se
# pierde, se pierden con él.
set -eu

: "${PGHOST:=postgres}" "${PGUSER:?}" "${PGPASSWORD:?}" "${PGDATABASE:?}"
export PGHOST PGUSER PGPASSWORD PGDATABASE
BACKUP_DIR="${BACKUP_DIR:-/backups}"
STORAGE_DIR="${STORAGE_DIR:-/storage}"
STATUS_FILE="${STATUS_FILE:-/status/last_success}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
BACKUP_TIME="${BACKUP_TIME:-03:15}"
VERIFY_DB="backup_verify"

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) [backup] $*"; }

tables() {
    psql -d "$1" -tAc "select count(*) from information_schema.tables where table_schema in ('public', 'ai_service')"
}

migrations() {
    psql -d "$1" -tAc "select count(*) from migrations"
}

backup_once() {
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/storage"
    dump="$BACKUP_DIR/db/$PGDATABASE-$stamp.dump"
    files="$BACKUP_DIR/storage/storage-$stamp.tar.gz"

    log "copia de la base de datos $PGDATABASE"
    pg_dump --format=custom --no-owner --file="$dump.partial"
    mv "$dump.partial" "$dump"

    if [ -d "$STORAGE_DIR/app" ]; then
        log "copia de los ficheros (storage/app)"
        tar -czf "$files.partial" -C "$STORAGE_DIR" app
        mv "$files.partial" "$files"
    fi

    log "prueba de restauración en $VERIFY_DB"
    psql -d postgres -qc "drop database if exists $VERIFY_DB" -c "create database $VERIFY_DB"
    # --no-owner: en la prueba todo queda del usuario de la app (basta para
    # comprobar la copia). scripts/restore.sh restaura con los dueños reales.
    if ! pg_restore --dbname="$VERIFY_DB" --exit-on-error --no-owner "$dump"; then
        psql -d postgres -qc "drop database if exists $VERIFY_DB"
        rm -f "$dump" "$files"
        log "ERROR: la copia no se pudo restaurar: borrada"
        return 1
    fi

    expected="$(tables "$PGDATABASE")/$(migrations "$PGDATABASE")"
    restored="$(tables "$VERIFY_DB")/$(migrations "$VERIFY_DB")"
    psql -d postgres -qc "drop database if exists $VERIFY_DB"

    if [ "$expected" != "$restored" ]; then
        rm -f "$dump" "$files"
        log "ERROR: la copia restaurada no coincide (tablas/migraciones: $restored, esperado $expected): borrada"
        return 1
    fi

    if [ -f "$files" ] && ! tar -tzf "$files" >/dev/null; then
        rm -f "$dump" "$files"
        log "ERROR: el archivo de ficheros está dañado: borrado"
        return 1
    fi

    (cd "$BACKUP_DIR" && sha256sum "db/$(basename "$dump")" $( [ -f "$files" ] && echo "storage/$(basename "$files")" ) > "checksums-$stamp.sha256")

    find "$BACKUP_DIR" -type f -mtime +"$KEEP_DAYS" \( -name '*.dump' -o -name '*.tar.gz' -o -name '*.sha256' \) -print -delete \
        | sed 's/^/borrada (antigua): /'

    mkdir -p "$(dirname "$STATUS_FILE")"
    date -u +%s > "$STATUS_FILE.tmp" && mv "$STATUS_FILE.tmp" "$STATUS_FILE"

    log "OK: $(basename "$dump") ($(du -h "$dump" | cut -f1)), tablas/migraciones $restored"
}

list_backups() {
    ls -1 "$BACKUP_DIR/db" 2>/dev/null | sed -n "s/^$PGDATABASE-\(.*\)\.dump$/\1/p" | sort
}

restore_backup() {
    stamp="${1:?Indica la copia: backup.sh list}"
    dump="$BACKUP_DIR/db/$PGDATABASE-$stamp.dump"
    files="$BACKUP_DIR/storage/storage-$stamp.tar.gz"
    sums="$BACKUP_DIR/checksums-$stamp.sha256"

    [ -f "$dump" ] || { log "no existe la copia $stamp (backup.sh list)"; return 1; }

    if [ -f "$sums" ]; then
        (cd "$BACKUP_DIR" && sha256sum -c --quiet "$sums") || { log "ERROR: la copia $stamp está dañada (sha256)"; return 1; }
    fi

    log "restaurando la base de datos $PGDATABASE desde $stamp (se borran los datos actuales)"
    psql -d postgres -qc "select pg_terminate_backend(pid) from pg_stat_activity where datname = '$PGDATABASE' and pid <> pg_backend_pid()" >/dev/null
    psql -d postgres -qc "drop database if exists \"$PGDATABASE\"" -c "create database \"$PGDATABASE\""
    pg_restore --dbname="$PGDATABASE" --exit-on-error "$dump"

    if [ -f "$files" ]; then
        log "restaurando los ficheros (storage/app)"
        rm -rf "${STORAGE_DIR:?}/app"
        tar -xzf "$files" -C "$STORAGE_DIR"
    fi

    log "OK: restaurada la copia $stamp ($(migrations "$PGDATABASE") migraciones)"
}

seconds_until() {
    now="$(date -u +%s)"
    target="$(date -u -d "today $1" +%s)"
    [ "$target" -le "$now" ] && target="$(date -u -d "tomorrow $1" +%s)"
    echo $((target - now))
}

case "${1:-loop}" in
    once)
        backup_once
        ;;
    list)
        list_backups
        ;;
    restore)
        restore_backup "${2:-}"
        ;;
    loop)
        log "copias diarias a las $BACKUP_TIME UTC, se guardan $KEEP_DAYS días"
        while true; do
            sleep "$(seconds_until "$BACKUP_TIME")"
            backup_once || log "la copia de hoy ha fallado; se reintenta mañana (y avisa la alerta)"
        done
        ;;
    *)
        echo "Uso: backup.sh [loop|once|list|restore <copia>]" >&2
        exit 2
        ;;
esac
