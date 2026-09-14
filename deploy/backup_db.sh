#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env not found: $ENV_FILE"
    exit 1
fi

# Load project environment. Values containing spaces must be quoted in .env.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

if ! docker inspect archive_db >/dev/null 2>&1; then
    echo "[ERROR] Container archive_db does not exist."
    exit 1
fi
if ! docker inspect archive_app >/dev/null 2>&1; then
    echo "[ERROR] Container archive_app does not exist."
    exit 1
fi

NEXTCLOUD_DIR="$PROJECT_DIR/nextcloud"
for required_dir in "$NEXTCLOUD_DIR/data" "$NEXTCLOUD_DIR/config" "$NEXTCLOUD_DIR/custom_apps"; do
    if [ ! -d "$required_dir" ]; then
        echo "[ERROR] Required Nextcloud directory not found: $required_dir"
        exit 1
    fi
done

if ! docker exec archive_db pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then
    echo "[ERROR] PostgreSQL is not ready using POSTGRES_USER/POSTGRES_DB from .env."
    exit 1
fi

mkdir -p "$BACKUP_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
WORK_DIR="$BACKUP_DIR/.full_backup_$TIMESTAMP"
BACKUP_FILE="$BACKUP_DIR/nextcloud_full_backup_$TIMESTAMP.tar.gz"
LATEST_FILE="$BACKUP_DIR/latest_nextcloud_backup.tar.gz"

cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

mkdir -p "$WORK_DIR"

echo "[INFO] Creating PostgreSQL dump..."
docker exec archive_db pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" > "$WORK_DIR/database.sql"

if [ ! -s "$WORK_DIR/database.sql" ]; then
    echo "[ERROR] Database dump is empty."
    exit 1
fi

echo "[INFO] Archiving Nextcloud data, config, and custom apps..."
tar -C "$NEXTCLOUD_DIR" -czf "$WORK_DIR/data.tar.gz" data

tar -C "$NEXTCLOUD_DIR" -czf "$WORK_DIR/config.tar.gz" config

tar -C "$NEXTCLOUD_DIR" -czf "$WORK_DIR/custom_apps.tar.gz" custom_apps

cat > "$WORK_DIR/manifest.txt" <<EOF
backup_type=full_nextcloud
created_at=$(date -Iseconds)
postgres_db=$POSTGRES_DB
postgres_user=$POSTGRES_USER
components=database,data,config,custom_apps
EOF

# Create one portable archive containing all required recovery components.
tar -C "$BACKUP_DIR" -czf "$BACKUP_FILE" "$(basename "$WORK_DIR")"
cp "$BACKUP_FILE" "$LATEST_FILE"

# Verify archive readability and presence of all components.
tar -tzf "$BACKUP_FILE" >/dev/null
for component in database.sql data.tar.gz config.tar.gz custom_apps.tar.gz manifest.txt; do
    if ! tar -tzf "$BACKUP_FILE" | grep -q "/$component$"; then
        echo "[ERROR] Backup verification failed; missing component: $component"
        exit 1
    fi
done

SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
SHA256=$(sha256sum "$BACKUP_FILE" | cut -d' ' -f1)
printf '%s  %s\n' "$SHA256" "$(basename "$BACKUP_FILE")" > "$BACKUP_FILE.sha256"
cp "$BACKUP_FILE.sha256" "$LATEST_FILE.sha256"

echo "[SUCCESS] Full Nextcloud backup completed: $BACKUP_FILE (Size: $SIZE)"
echo "[INFO] SHA256: $SHA256"
echo "[INFO] Latest backup: $LATEST_FILE"
