#!/usr/bin/env bash
#
# OPS-01 — nightly production database backup.
#
# Why a shell script and not an artisan command: Hostinger disables PHP's exec(),
# so Laravel cannot shell out to mysqldump (this is the same reason
# `php artisan storage:link` fails on this host). Cron calls this directly.
#
# Safety properties:
#   * credentials are read from .env and passed via a 0600 temp defaults-file, so
#     the password never appears in `ps` output or in the crontab;
#   * the dump is VERIFIED (non-empty + "Dump completed" marker) before anything
#     is pruned, so a failed backup can never delete your good ones;
#   * exits non-zero on any failure so cron/MAILTO surfaces it.
#
# Usage:  bash scripts/backup-db.sh
# Cron:   30 2 * * * /bin/bash ~/public_html/osms/scripts/backup-db.sh >> ~/backups/backup.log 2>&1

set -euo pipefail

RETENTION_DAYS="${RETENTION_DAYS:-14}"
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$APP_ROOT/.env}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }

[ -f "$ENV_FILE" ] || die "No .env at $ENV_FILE"

# Read a key from .env, stripping surrounding quotes. Handles values containing
# '#' and '@' (the production password has both), because we never eval the line.
env_get() {
    local key="$1" line val
    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | head -n 1 | tr -d "\r" || true)"
    [ -n "$line" ] || return 1
    val="${line#*=}"
    val="${val%\"}"; val="${val#\"}"
    val="${val%\'}"; val="${val#\'}"
    printf '%s' "$val"
}

DB_DATABASE="$(env_get DB_DATABASE)" || die "DB_DATABASE missing from .env"
DB_USERNAME="$(env_get DB_USERNAME)" || die "DB_USERNAME missing from .env"
DB_PASSWORD="$(env_get DB_PASSWORD || true)"
DB_HOST="$(env_get DB_HOST || true)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT || true)"; DB_PORT="${DB_PORT:-3306}"

mkdir -p "$BACKUP_DIR"

# Credentials via a locked-down temp file: keeps the password out of `ps`.
# RAW is registered with the trap too, so a failed/partial dump never lingers on
# disk pretending to be a backup.
CNF="$(mktemp)"
chmod 600 "$CNF"
RAW=""
cleanup() {
    rm -f "$CNF"
    # Only remove RAW if it still exists uncompressed, i.e. we failed before gzip.
    [ -n "$RAW" ] && [ -f "$RAW" ] && rm -f "$RAW"
    return 0
}
trap cleanup EXIT

cat > "$CNF" <<EOF
[client]
user=${DB_USERNAME}
password=${DB_PASSWORD}
host=${DB_HOST}
port=${DB_PORT}
EOF

STAMP="$(date +%F_%H%M)"
RAW="$BACKUP_DIR/osms_${STAMP}.sql"   # registered with the cleanup trap above

log "Backing up '${DB_DATABASE}' -> ${RAW}.gz"

# --single-transaction gives a consistent InnoDB snapshot without locking the
# tables, so the shop can keep trading while this runs.
mysqldump --defaults-extra-file="$CNF" \
    --single-transaction --quick \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" > "$RAW" \
    || die "mysqldump failed (backup aborted, existing backups untouched)"

# ---- Verify BEFORE pruning: a truncated dump must never pass as a good backup.
[ -s "$RAW" ] || die "Dump is empty (backup aborted)"
tail -n 5 "$RAW" | grep -q "Dump completed" \
    || die "Dump has no completion marker — likely truncated (backup aborted)"

# Sanity-check that real tables made it in.
grep -qE 'CREATE TABLE `(orders|customers|users)`' "$RAW" \
    || die "Dump is missing core tables (backup aborted)"

gzip -f "$RAW"
SIZE="$(du -h "${RAW}.gz" | cut -f1)"
log "OK — ${RAW}.gz (${SIZE})"

# ---- Prune only now that we know today's backup is good.
DELETED="$(find "$BACKUP_DIR" -name 'osms_*.sql.gz' -type f -mtime "+${RETENTION_DAYS}" -print -delete | wc -l | tr -d ' ')"
log "Pruned ${DELETED} backup(s) older than ${RETENTION_DAYS} days. Retained: $(find "$BACKUP_DIR" -name 'osms_*.sql.gz' -type f | wc -l | tr -d ' ')"
