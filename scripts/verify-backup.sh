#!/usr/bin/env bash
#
# OPS-01 — restore drill. "A backup you have never restored is not a backup."
#
# Restores a backup into a SCRATCH database and compares row counts against
# production, proving the dump is actually usable. Production is never written to.
#
# One-time setup: create an empty scratch DB in hPanel (e.g. u174003801_osms_drill)
# and grant the same DB user access to it.
#
# Usage:
#   bash scripts/verify-backup.sh <scratch_db> [backup_file|latest]
#
# Example:
#   bash scripts/verify-backup.sh u174003801_osms_drill
#   bash scripts/verify-backup.sh u174003801_osms_drill ~/backups/osms_2026-07-21_0230.sql.gz

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$APP_ROOT/.env}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"

TARGET_DB="${1:-}"
BACKUP_FILE="${2:-latest}"

log()  { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die()  { printf '[%s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }

[ -n "$TARGET_DB" ] || die "Usage: bash scripts/verify-backup.sh <scratch_db> [backup_file|latest]"
[ -f "$ENV_FILE" ]  || die "No .env at $ENV_FILE"

env_get() {
    local key="$1" line val
    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | head -n 1 | tr -d "\r" || true)"
    [ -n "$line" ] || return 1
    val="${line#*=}"
    val="${val%\"}"; val="${val#\"}"
    val="${val%\'}"; val="${val#\'}"
    printf '%s' "$val"
}

PROD_DB="$(env_get DB_DATABASE)" || die "DB_DATABASE missing from .env"
DB_USERNAME="$(env_get DB_USERNAME)" || die "DB_USERNAME missing from .env"
DB_PASSWORD="$(env_get DB_PASSWORD || true)"
DB_HOST="$(env_get DB_HOST || true)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT || true)"; DB_PORT="${DB_PORT:-3306}"

# ---- HARD SAFETY GUARD: never, ever restore over production.
if [ "$TARGET_DB" = "$PROD_DB" ]; then
    die "REFUSING: target '$TARGET_DB' is the PRODUCTION database. Use a scratch DB."
fi

# Resolve which backup to test.
if [ "$BACKUP_FILE" = "latest" ]; then
    BACKUP_FILE="$(find "$BACKUP_DIR" -name 'osms_*.sql.gz' -type f | sort | tail -n 1)"
    [ -n "$BACKUP_FILE" ] || die "No backups found in $BACKUP_DIR"
fi
[ -f "$BACKUP_FILE" ] || die "Backup file not found: $BACKUP_FILE"

# Scratch-DB credentials. Some hosts (Hostinger) create one dedicated user per
# database, so the production user often CANNOT reach the scratch DB. Set
# DRILL_USER to use a separate account; the password is prompted for (never passed
# on the command line, so it stays out of shell history and `ps`).
DRILL_USER="${DRILL_USER:-$DB_USERNAME}"
DRILL_PASS="${DRILL_PASS:-}"
if [ "$DRILL_USER" != "$DB_USERNAME" ] && [ -z "$DRILL_PASS" ]; then
    printf 'Password for scratch-DB user %s: ' "$DRILL_USER" >&2
    read -rs DRILL_PASS; printf '\n' >&2
fi
[ -n "$DRILL_PASS" ] || DRILL_PASS="$DB_PASSWORD"

# MySQL option files treat '#' as a start-of-comment, so values MUST be quoted
# (the production password contains '#'). Backslash escapes apply inside quotes.
cnf_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

# Two connections: PROD (read-only) and DRILL (the scratch DB we overwrite).
CNF="$(mktemp)"; chmod 600 "$CNF"
CNF_DRILL="$(mktemp)"; chmod 600 "$CNF_DRILL"
trap 'rm -f "$CNF" "$CNF_DRILL"' EXIT

cat > "$CNF" <<EOF
[client]
user="$(cnf_escape "$DB_USERNAME")"
password="$(cnf_escape "$DB_PASSWORD")"
host="$(cnf_escape "$DB_HOST")"
port=${DB_PORT}
EOF

cat > "$CNF_DRILL" <<EOF
[client]
user="$(cnf_escape "$DRILL_USER")"
password="$(cnf_escape "$DRILL_PASS")"
host="$(cnf_escape "$DB_HOST")"
port=${DB_PORT}
EOF

log "Backup under test : $BACKUP_FILE"
log "Production DB     : $PROD_DB  (read-only, as $DB_USERNAME)"
log "Scratch DB        : $TARGET_DB  (will be OVERWRITTEN, as $DRILL_USER)"

# Fail early with a clear message rather than a confusing error mid-restore.
mysql --defaults-extra-file="$CNF_DRILL" -e "SELECT 1" "$TARGET_DB" >/dev/null 2>&1 \
    || die "Cannot connect to scratch DB '$TARGET_DB' as '$DRILL_USER'. If your host gives each database its own user, re-run with: DRILL_USER=<that_user> bash scripts/verify-backup.sh $TARGET_DB"

# Wipe the scratch DB so the drill is honest (no leftovers from a previous run).
log "Clearing scratch DB..."
mysqldump --defaults-extra-file="$CNF_DRILL" --add-drop-table --no-data "$TARGET_DB" 2>/dev/null \
    | grep -E '^DROP TABLE' \
    | mysql --defaults-extra-file="$CNF_DRILL" "$TARGET_DB" 2>/dev/null || true

log "Restoring backup into scratch DB..."
gunzip < "$BACKUP_FILE" | mysql --defaults-extra-file="$CNF_DRILL" "$TARGET_DB" \
    || die "RESTORE FAILED — this backup is NOT usable."

# ---- Compare row counts: production vs restored copy.
TABLES="tenants users customers orders order_items payments eye_records inventory tax_invoices"
printf '\n%-16s %12s %12s   %s\n' "TABLE" "PRODUCTION" "RESTORED" "RESULT"
printf '%s\n' "-------------------------------------------------------------"

FAILED=0
for t in $TABLES; do
    p="$(mysql --defaults-extra-file="$CNF" -N -B -e "SELECT COUNT(*) FROM \`$t\`;" "$PROD_DB" 2>/dev/null || echo "n/a")"
    r="$(mysql --defaults-extra-file="$CNF_DRILL" -N -B -e "SELECT COUNT(*) FROM \`$t\`;" "$TARGET_DB" 2>/dev/null || echo "MISSING")"
    if [ "$p" = "$r" ]; then
        printf '%-16s %12s %12s   OK\n' "$t" "$p" "$r"
    else
        printf '%-16s %12s %12s   MISMATCH\n' "$t" "$p" "$r"
        FAILED=1
    fi
done

printf '\n'
if [ "$FAILED" -eq 0 ]; then
    log "DRILL PASSED — the backup restores cleanly and all row counts match."
    log "Remember: production was never touched. Scratch DB '$TARGET_DB' holds the copy."
else
    die "DRILL FAILED — restored data does not match production. Investigate before relying on these backups."
fi
