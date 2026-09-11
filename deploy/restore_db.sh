#!/usr/bin/env bash
set -e

BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/backups"
TARGET_FILE="${1:-$BACKUP_DIR/latest_db_backup.sql}"

if [ ! -f "$TARGET_FILE" ]; then
    echo "[ERROR] Backup file not found: $TARGET_FILE"
    exit 1
fi

echo "[WARNING] Restoring database from $TARGET_FILE..."
echo "[INFO] Terminating active PostgreSQL connections..."
docker exec -i archive_db psql -U nextcloud_user -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'nextcloud' AND pid <> pg_backend_pid();"

echo "[INFO] Dropping and recreating database 'nextcloud'..."
docker exec -i archive_db psql -U nextcloud_user -d postgres -c "DROP DATABASE IF EXISTS nextcloud;"
docker exec -i archive_db psql -U nextcloud_user -d postgres -c "CREATE DATABASE nextcloud OWNER nextcloud_user;"

echo "[INFO] Importing SQL dump..."
docker exec -i archive_db psql -U nextcloud_user -d nextcloud < "$TARGET_FILE"

echo "[INFO] Scanning Nextcloud files and clearing locks..."
docker exec archive_db psql -U nextcloud_user -d nextcloud -c "TRUNCATE oc_file_locks;"
docker exec -u www-data archive_app php occ files:scan --all

echo "[SUCCESS] Database successfully restored from $TARGET_FILE"
