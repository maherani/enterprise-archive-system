#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_DIR="$SCRIPT_DIR/backups"
ENV_FILE="$PROJECT_ROOT/.env"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env file not found: $ENV_FILE"
    exit 1
fi

# Load the same environment configuration used by Docker Compose.
set -a
# shellcheck disable=SC1091
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="$BACKUP_DIR/db_backup_$TIMESTAMP.sql"

if ! docker inspect -f '{{.State.Running}}' archive_db 2>/dev/null | grep -q '^true$'; then
    echo "[ERROR] Container archive_db is not running."
    exit 1
fi

echo "[INFO] Creating PostgreSQL database backup to $BACKUP_FILE..."
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" > "$BACKUP_FILE"

cp "$BACKUP_FILE" "$BACKUP_DIR/latest_db_backup.sql"

SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
echo "[SUCCESS] Database backup completed: $BACKUP_FILE (Size: $SIZE)"
echo "[SUCCESS] Latest backup: $BACKUP_DIR/latest_db_backup.sql"
