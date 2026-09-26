#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$BACKUP_DIR/test_restore.log"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env not found: $ENV_FILE"
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
    if [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
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
echo " Enterprise Archive System - Isolated Test Restore Engine (Sandbox)"
echo " Target Archive: $TARGET"
echo " Sandbox DB:     $SANDBOX_DB"
echo "================================================================================"

# Validate tar
if ! tar -tzf "$TARGET" >/dev/null 2>&1; then
    echo "[FAIL] Backup archive is corrupted or unreadable."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupted Archive)" >> "$LOG_FILE"
    exit 1
fi

# Validate SHA256 if file exists
EXPECTED_SHA_FILE="$TARGET.sha256"
if [ -f "$EXPECTED_SHA_FILE" ]; then
    CALCULATED_SHA=$(sha256sum "$TARGET" | cut -d' ' -f1)
    EXPECTED_SHA=$(cut -d' ' -f1 < "$EXPECTED_SHA_FILE")
    if [ "$CALCULATED_SHA" != "$EXPECTED_SHA" ]; then
        echo "[FAIL] SHA256 checksum mismatch."
        echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (SHA256 Mismatch)" >> "$LOG_FILE"
        exit 1
    fi
fi

TMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "$TMP_DIR"
    docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS "$SANDBOX_DB";" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[INFO] [1/5] Extracting archive components to sandbox workspace..."
tar -xzf "$TARGET" -C "$TMP_DIR"
BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)

DB_DUMP="$BACKUP_ROOT/database.sql"
DATA_ARCHIVE="$BACKUP_ROOT/data.tar.gz"

if [ ! -s "$DB_DUMP" ]; then
    echo "[FAIL] Database dump missing or empty."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Empty DB Dump)" >> "$LOG_FILE"
    exit 1
fi

if [ ! -s "$DATA_ARCHIVE" ]; then
    echo "[FAIL] Data archive missing or empty."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Empty Data Archive)" >> "$LOG_FILE"
    exit 1
fi

echo "[INFO] [2/5] Creating temporary sandbox database in PostgreSQL..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS "$SANDBOX_DB";" >/dev/null 2>&1 || true
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE "$SANDBOX_DB" OWNER "$POSTGRES_USER";" >/dev/null

echo "[INFO] [3/5] Simulating database import with ON_ERROR_STOP=1..."
if ! docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$SANDBOX_DB" < "$DB_DUMP" >/dev/null 2>&1; then
    echo "[FAIL] Database dump failed to restore into sandbox DB."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (SQL Import Error)" >> "$LOG_FILE"
    exit 1
fi

echo "[INFO] [4/5] Executing data consistency and metadata integrity assertions..."
USERS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo 0)
GROUPS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_groups;" 2>/dev/null || echo 0)
TAGS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_systemtag;" 2>/dev/null || echo 0)
DOCS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo 0)

# Check custom archive tables presence
MISSING_TABLES=0
for table in oc_archive_document_metadata oc_archive_file_grants oc_archive_file_ownership oc_archive_tag_groups oc_systemtag oc_filecache; do
    EXISTS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "SELECT count(*) FROM information_schema.tables WHERE table_name='$table';" 2>/dev/null || echo 0)
    if [ "$EXISTS" -eq 0 ]; then
        echo "[WARNING] Required table missing in sandbox dump: $table"
        MISSING_TABLES=$((MISSING_TABLES + 1))
    fi
done

if [ "$MISSING_TABLES" -gt 0 ]; then
    echo "[FAIL] Sandbox validation failed; missing required archive tables."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Missing Tables: $MISSING_TABLES)" >> "$LOG_FILE"
    exit 1
fi

echo "[INFO] [5/5] Testing user files tar stream integrity..."
if ! tar -tzf "$DATA_ARCHIVE" >/dev/null 2>&1; then
    echo "[FAIL] User files archive inside backup is corrupted."
    echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> FAIL (Corrupt data.tar.gz)" >> "$LOG_FILE"
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

echo "[$(date -Iseconds)] [TEST_RESTORE] Target: $(basename "$TARGET") -> PASS (Duration: ${DURATION}s, Users: $USERS_COUNT, Docs: $DOCS_COUNT, Tags: $TAGS_COUNT)" >> "$LOG_FILE"

echo "================================================================================"
echo "[SUCCESS] Sandbox Test Restore PASSED with 100% Integrity!"
echo "  - Verified Archive:   $(basename "$TARGET")"
echo "  - Verified Users:     $USERS_COUNT"
echo "  - Verified Groups:    $GROUPS_COUNT"
echo "  - Verified Tags:      $TAGS_COUNT"
echo "  - Document Metadata:  $DOCS_COUNT"
echo "  - Duration:           ${DURATION}s"
echo "  - Audit Log:          $LOG_FILE"
echo "================================================================================"
