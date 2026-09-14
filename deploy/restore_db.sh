#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"
TARGET_FILE="${1:-$BACKUP_DIR/latest_db_backup.sql}"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] Environment file not found: $ENV_FILE"
    exit 1
fi

# Load project database configuration used by docker-compose.yml.
set -a
# shellcheck disable=SC1091
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"

if [ ! -f "$TARGET_FILE" ]; then
    echo "[ERROR] Backup file not found: $TARGET_FILE"
    exit 1
fi

if ! docker inspect -f '{{.State.Running}}' archive_db 2>/dev/null | grep -q '^true$'; then
    echo "[ERROR] Container archive_db is not running."
    exit 1
fi

echo "[WARNING] Restoring database '$POSTGRES_DB' from $TARGET_FILE..."
echo "[INFO] Database role: $POSTGRES_USER"
echo "[INFO] Terminating active PostgreSQL connections..."
docker exec -i archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB' AND pid <> pg_backend_pid();"

echo "[INFO] Dropping and recreating database '$POSTGRES_DB'..."
docker exec -i archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS \"$POSTGRES_DB\";"
docker exec -i archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "CREATE DATABASE \"$POSTGRES_DB\" OWNER \"$POSTGRES_USER\";"

echo "[INFO] Importing SQL dump..."
docker exec -i archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$TARGET_FILE"

echo "[INFO] Clearing file locks..."
docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "TRUNCATE oc_file_locks;"

echo "[INFO] Scanning Nextcloud files..."
docker exec -u www-data archive_app php occ files:scan --all

echo "[SUCCESS] Database successfully restored from $TARGET_FILE"
