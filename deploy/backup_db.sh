#!/usr/bin/env bash
set -e

BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/backups"
mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="$BACKUP_DIR/db_backup_$TIMESTAMP.sql"

echo "[INFO] Creating PostgreSQL database backup to $BACKUP_FILE..."
docker exec archive_db pg_dump -U nextcloud_user -d nextcloud > "$BACKUP_FILE"
cp "$BACKUP_FILE" "$BACKUP_DIR/latest_db_backup.sql"

SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
echo "[SUCCESS] Database backup completed: $BACKUP_FILE (Size: $SIZE)"
