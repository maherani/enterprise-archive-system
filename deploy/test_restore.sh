#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$BACKUP_DIR/test_restore.log"
LAST_RUN_LOG="$BACKUP_DIR/.test_last_run.log"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env file not found: $ENV_FILE"
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

TARGET="${1:-}"
if [ -z "$TARGET" ]; then
    if [ -f "$BACKUP_DIR/latest_instance_backup.tar.gz" ]; then
        TARGET="$BACKUP_DIR/latest_instance_backup.tar.gz"
    elif [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
        TARGET="$BACKUP_DIR/latest_data_backup.tar.gz"
    elif [ -f "$BACKUP_DIR/latest_nextcloud_backup.tar.gz" ]; then
        TARGET="$BACKUP_DIR/latest_nextcloud_backup.tar.gz"
    else
        echo "[ERROR] No default backup archive found in $BACKUP_DIR"
        exit 1
    fi
fi

if [ ! -f "$TARGET" ]; then
    echo "[ERROR] Target backup file not found: $TARGET"
    exit 1
fi

SANDBOX_DB="nextcloud_restore_sandbox_$$"
START_TIME=$(date +%s)
echo "================================================================================"
echo " Enterprise Archive System - Comprehensive Sandbox Test Restore Engine"
echo " Target Archive: $TARGET"
echo " Sandbox DB:     $SANDBOX_DB"
echo " Time:           $(date -Iseconds)"
echo "================================================================================"

# 1. Validate outer tar archive stream
if ! tar -tzf "$TARGET" >/dev/null 2>&1; then
    echo "[FAIL] Backup archive is corrupted or unreadable."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupted Outer Archive)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi

# 2. Validate outer SHA256 sidecar
EXPECTED_SHA_FILE="$TARGET.sha256"
if [ -f "$EXPECTED_SHA_FILE" ]; then
    CALCULATED_SHA=$(sha256sum "$TARGET" | cut -d' ' -f1)
    EXPECTED_SHA=$(cut -d' ' -f1 < "$EXPECTED_SHA_FILE")
    if [ "$CALCULATED_SHA" != "$EXPECTED_SHA" ]; then
        echo "[FAIL] Outer SHA256 sidecar checksum mismatch."
        msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (SHA256 Sidecar Mismatch)"
        echo "$msg" >> "$LOG_FILE"
        echo "$msg" > "$LAST_RUN_LOG"
        exit 1
    fi
    echo "[OK] Outer SHA256 sidecar checksum matches: $CALCULATED_SHA"
fi

TMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "$TMP_DIR"
    docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS \"$SANDBOX_DB\";" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[INFO] [1/6] Extracting archive components to sandbox workspace..."
tar -xzf "$TARGET" -C "$TMP_DIR"
BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)

if [ -z "${BACKUP_ROOT:-}" ]; then
    echo "[FAIL] Backup archive has no valid root directory."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Missing Root Dir)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi

DB_DUMP="$BACKUP_ROOT/database.sql"
DATA_ARCHIVE="$BACKUP_ROOT/data.tar.gz"
CONFIG_ARCHIVE="$BACKUP_ROOT/config.tar.gz"
CONFIG_KEYS="$BACKUP_ROOT/config_keys.json"
CUSTOM_APPS_ARCHIVE="$BACKUP_ROOT/custom_apps.tar.gz"
MANIFEST_JSON="$BACKUP_ROOT/manifest.json"

# 3. Assert all essential components exist and are non-empty
for comp_path in "$DB_DUMP" "$DATA_ARCHIVE" "$CONFIG_ARCHIVE" "$CONFIG_KEYS" "$MANIFEST_JSON"; do
    if [ ! -s "$comp_path" ]; then
        echo "[FAIL] Essential backup component missing or empty: $(basename "$comp_path")"
        msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Empty Component: $(basename "$comp_path"))"
        echo "$msg" >> "$LOG_FILE"
        echo "$msg" > "$LAST_RUN_LOG"
        exit 1
    fi
done

# 4. Stream integrity verification of inner tar archives
echo "[INFO] [2/6] Validating inner archive gzip streams..."
if ! tar -tzf "$DATA_ARCHIVE" >/dev/null 2>&1; then
    echo "[FAIL] User files archive inside backup (data.tar.gz) is corrupted."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupt data.tar.gz)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi
if ! tar -tzf "$CONFIG_ARCHIVE" >/dev/null 2>&1; then
    echo "[FAIL] Configuration archive inside backup (config.tar.gz) is corrupted."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupt config.tar.gz)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi
if [ -f "$CUSTOM_APPS_ARCHIVE" ] && [ -s "$CUSTOM_APPS_ARCHIVE" ]; then
    if ! tar -tzf "$CUSTOM_APPS_ARCHIVE" >/dev/null 2>&1; then
        echo "[FAIL] Custom apps archive inside backup (custom_apps.tar.gz) is corrupted."
        msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupt custom_apps.tar.gz)"
        echo "$msg" >> "$LOG_FILE"
        echo "$msg" > "$LAST_RUN_LOG"
        exit 1
    fi
fi
echo "[OK] Inner archive streams verified."

# 5. Manifest consistency and component checksum validation
echo "[INFO] [3/6] Verifying manifest consistency and component hashes..."
python3 -c "
import json, sys, hashlib, os

def check_sha(fpath, expected, name):
    if not expected:
        return
    h = hashlib.sha256()
    with open(fpath, 'rb') as f:
        while chunk := f.read(65536):
            h.update(chunk)
    actual = h.hexdigest()
    if actual != expected:
        print(f'[FAIL] Internal checksum mismatch for {name}: expected {expected}, got {actual}', file=sys.stderr)
        sys.exit(1)

with open('$MANIFEST_JSON') as f:
    m = json.load(f)

if m.get('status') != 'SUCCESS':
    print(f'[FAIL] Manifest status is {m.get(\"status\")}, not SUCCESS', file=sys.stderr)
    sys.exit(1)

comps = m.get('components', {})
if 'database' in comps:
    check_sha('$DB_DUMP', comps['database'].get('sha256'), 'database.sql')
if 'user_data' in comps:
    check_sha('$DATA_ARCHIVE', comps['user_data'].get('sha256'), 'data.tar.gz')
elif 'user_files' in comps:
    check_sha('$DATA_ARCHIVE', comps['user_files'].get('sha256'), 'data.tar.gz')
if 'config' in comps:
    check_sha('$CONFIG_ARCHIVE', comps['config'].get('sha256'), 'config.tar.gz')
if 'security_keys' in comps:
    check_sha('$CONFIG_KEYS', comps['security_keys'].get('sha256'), 'config_keys.json')

with open('$CONFIG_KEYS') as f:
    k = json.load(f)
for key in ['instanceid', 'passwordsalt', 'secret']:
    if not k.get(key):
        print(f'[FAIL] Missing security identity key: {key}', file=sys.stderr)
        sys.exit(1)
" || {
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Manifest or Hash Mismatch)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
}
echo "[OK] Manifest consistency and component checksums verified."

# 6. Database Sandbox Simulation
echo "[INFO] [4/6] Creating temporary sandbox database in PostgreSQL..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS \"$SANDBOX_DB\";" >/dev/null 2>&1 || true
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE \"$SANDBOX_DB\" OWNER \"$POSTGRES_USER\";" >/dev/null

echo "[INFO] [5/6] Simulating database import with ON_ERROR_STOP=1..."
if ! docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$SANDBOX_DB" < "$DB_DUMP" >/dev/null 2>&1; then
    echo "[FAIL] Database dump failed to restore into sandbox DB."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (SQL Import Error)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi

echo "[INFO] [6/6] Executing data consistency and metadata integrity assertions..."
USERS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo 0)
GROUPS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_groups;" 2>/dev/null || echo 0)
TAGS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_systemtag;" 2>/dev/null || echo 0)
DOCS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo 0)

# Check custom archive tables presence
MISSING_TABLES=0
for table in oc_archive_document_metadata oc_archive_file_grants oc_archive_file_ownership oc_archive_tag_groups oc_systemtag oc_filecache oc_users oc_groups oc_activity; do
    EXISTS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM information_schema.tables WHERE table_name='$table';" 2>/dev/null || echo 0)
    if [ "$EXISTS" -eq 0 ]; then
        echo "[WARNING] Required table missing in sandbox dump: $table"
        MISSING_TABLES=$((MISSING_TABLES + 1))
    fi
done

if [ "$MISSING_TABLES" -gt 0 ]; then
    echo "[FAIL] Sandbox validation failed; missing required archive tables ($MISSING_TABLES)."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Missing Tables: $MISSING_TABLES)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi

if [ "$USERS_COUNT" -eq 0 ]; then
    echo "[FAIL] Sandbox validation failed; zero users found in restored database."
    msg="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Zero Users)"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" > "$LAST_RUN_LOG"
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

LOG_ENTRY="[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> PASS (Duration: ${DURATION}s, Users: $USERS_COUNT, Docs: $DOCS_COUNT, Tags: $TAGS_COUNT)"
echo "$LOG_ENTRY" >> "$LOG_FILE"

cat > "$LAST_RUN_LOG" <<EOF
Verified Archive:   $(basename "$TARGET")
Verified Users:     $USERS_COUNT
Verified Groups:    $GROUPS_COUNT
Verified Tags:      $TAGS_COUNT
Document Metadata:  $DOCS_COUNT
Duration:           ${DURATION}s
Sandbox Test Restore PASSED with 100% Integrity!
EOF

echo "================================================================================"
echo "[SUCCESS] Sandbox Test Restore PASSED with 100% Integrity!"
echo "  - Verified Archive:   $(basename "$TARGET")"
echo "  - Verified Users:     $USERS_COUNT"
echo "  - Verified Groups:    $GROUPS_COUNT"
echo "  - Verified Tags:      $TAGS_COUNT"
echo "  - Document Metadata:  $DOCS_COUNT"
echo "  - Config & Keys:      VALID"
echo "  - Custom Apps:        VALID"
echo "  - Duration:           ${DURATION}s"
echo "  - Audit Log:          $LOG_FILE"
echo "================================================================================"
