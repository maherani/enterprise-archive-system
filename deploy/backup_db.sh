#!/usr/bin/env bash
set -Eeuo pipefail

START_TIME=$(date +%s)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
CONFIG_FILE="$SCRIPT_DIR/backup_config.json"
BACKUP_DIR="$SCRIPT_DIR/backups"

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

MAX_BACKUPS=7
RETENTION_DAYS=30
BACKUP_TYPE="data_only"
if [ -f "$CONFIG_FILE" ]; then
    CONFIG_STORAGE=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('storage_location', ''))" 2>/dev/null || echo "")
    if [ -n "$CONFIG_STORAGE" ] && [ -d "$CONFIG_STORAGE" ]; then
        BACKUP_DIR="$CONFIG_STORAGE"
    fi
    MAX_BACKUPS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('max_backups_count', 7))" 2>/dev/null || echo 7)
    RETENTION_DAYS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('retention_days', 30))" 2>/dev/null || echo 30)
    BACKUP_TYPE=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('backup_type', 'data_only'))" 2>/dev/null || echo "data_only")
fi

for container in archive_db archive_app; do
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "[ERROR] Container $container does not exist."
        exit 1
    fi
done

if ! docker exec archive_db pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then
    echo "[ERROR] PostgreSQL is not ready using POSTGRES_USER/POSTGRES_DB from .env."
    exit 1
fi

for required_dir in data config; do
    if ! docker exec archive_app test -d "/var/www/html/$required_dir"; then
        echo "[ERROR] Nextcloud directory is not available inside archive_app: $required_dir"
        exit 1
    fi
done

mkdir -p "$BACKUP_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
RAND_SUFFIX=$(head -c 6 /dev/urandom | xxd -p 2>/dev/null || tr -dc a-z0-9 </dev/urandom | head -c 6)
BACKUP_ID="bk-${TIMESTAMP}-${RAND_SUFFIX}"
WORK_DIR="$BACKUP_DIR/.backup_${TIMESTAMP}_${RAND_SUFFIX}"
BACKUP_FILE="$BACKUP_DIR/backup_data_${TIMESTAMP}_${RAND_SUFFIX}.tar.gz"
LATEST_FILE="$BACKUP_DIR/latest_data_backup.tar.gz"
LEGACY_LATEST_FILE="$BACKUP_DIR/latest_nextcloud_backup.tar.gz"
LISTING_FILE="$WORK_DIR.archive_listing.txt"

maintenance_enabled=0
cleanup() {
    if [ "$maintenance_enabled" -eq 1 ]; then
        echo "[INFO] Disabling Nextcloud maintenance mode in cleanup..."
        docker exec archive_app php occ maintenance:mode --off >/dev/null 2>&1 || true
    fi
    rm -rf "$WORK_DIR"
    rm -f "$LISTING_FILE"
}
trap cleanup EXIT

mkdir -p "$WORK_DIR"

python3 -c "
import json
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'IN_PROGRESS',
        'action': 'backup',
        'started_at': '$(date -Iseconds)',
        'estimated_seconds': 45,
        'progress': 25,
        'message': 'عملیات پشتیبان‌گیری در حال اجراست...'
    }, f, indent=2)
" 2>/dev/null || true

echo "[INFO] [1/6] Engaging point-in-time consistency lock (Maintenance Mode)..." 
docker exec archive_app php occ maintenance:mode --on >/dev/null
maintenance_enabled=1
RECOVERY_POINT=$(date -Iseconds)

echo "[INFO] [2/6] Creating PostgreSQL atomic dump..."
docker exec archive_db pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" > "$WORK_DIR/database.sql"

if [ ! -s "$WORK_DIR/database.sql" ]; then
    echo "[ERROR] Database dump is empty."
    exit 1
fi

echo "[INFO] [3/6] Archiving Nextcloud user data and security configuration..."
docker exec archive_app tar -C /var/www/html -czf - data > "$WORK_DIR/data.tar.gz"
docker exec archive_app tar -C /var/www/html -czf - config > "$WORK_DIR/config.tar.gz"

if docker exec archive_app test -d /var/www/html/custom_apps; then
    docker exec archive_app tar -C /var/www/html -czf - custom_apps > "$WORK_DIR/custom_apps.tar.gz"
else
    tar -czf "$WORK_DIR/custom_apps.tar.gz" --files-from /dev/null
fi

INSTANCE_ID=$(docker exec -u www-data archive_app php occ config:system:get instanceid 2>/dev/null || echo "")
PASSWORD_SALT=$(docker exec -u www-data archive_app php occ config:system:get passwordsalt 2>/dev/null || echo "")
SECRET_KEY=$(docker exec -u www-data archive_app php occ config:system:get secret 2>/dev/null || echo "")
NC_VERSION=$(docker exec -u www-data archive_app php occ config:system:get version 2>/dev/null || echo "")

cat > "$WORK_DIR/config_keys.json" <<EOF
{
  "instanceid": "$INSTANCE_ID",
  "passwordsalt": "$PASSWORD_SALT",
  "secret": "$SECRET_KEY",
  "version": "$NC_VERSION"
}
EOF

for component in database.sql data.tar.gz config.tar.gz config_keys.json; do
    if [ ! -s "$WORK_DIR/$component" ]; then
        echo "[ERROR] Backup component is empty: $component"
        exit 1
    fi
done

echo "[INFO] [4/6] Releasing maintenance mode lock..."
docker exec archive_app php occ maintenance:mode --off >/dev/null
maintenance_enabled=0

echo "[INFO] [5/6] Gathering metadata and generating manifest.json..."
USERS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
TABLES_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null || echo "0")
DOCS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo "0")
GROUPS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_groups;" 2>/dev/null || echo "0")
TAGS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_systemtag;" 2>/dev/null || echo "0")

DB_SHA256=$(sha256sum "$WORK_DIR/database.sql" | cut -d' ' -f1)
DATA_SHA256=$(sha256sum "$WORK_DIR/data.tar.gz" | cut -d' ' -f1)
CONFIG_SHA256=$(sha256sum "$WORK_DIR/config_keys.json" | cut -d' ' -f1)

cat > "$WORK_DIR/manifest.json" <<EOF
{
  "backup_id": "$BACKUP_ID",
  "backup_type": "$BACKUP_TYPE",
  "status": "SUCCESS",
  "created_at": "$(date -Iseconds)",
  "recovery_point": "$RECOVERY_POINT",
  "duration_seconds": 0,
  "size_bytes": 0,
  "size_human": "",
  "checksum_sha256": "",
  "components": {
    "database": {
      "file": "database.sql",
      "sha256": "$DB_SHA256",
      "tables_count": $TABLES_COUNT,
      "users_count": $USERS_COUNT,
      "groups_count": $GROUPS_COUNT,
      "tags_count": $TAGS_COUNT,
      "documents_metadata_count": $DOCS_COUNT
    },
    "user_files": {
      "file": "data.tar.gz",
      "sha256": "$DATA_SHA256"
    },
    "security_keys": {
      "file": "config_keys.json",
      "sha256": "$CONFIG_SHA256"
    }
  },
  "environment": {
    "postgres_db": "$POSTGRES_DB",
    "postgres_user": "$POSTGRES_USER"
  },
  "error_info": null
}
EOF

cat > "$WORK_DIR/manifest.txt" <<EOF
backup_id=$BACKUP_ID
backup_type=$BACKUP_TYPE
status=SUCCESS
created_at=$(date -Iseconds)
recovery_point=$RECOVERY_POINT
postgres_db=$POSTGRES_DB
postgres_user=$POSTGRES_USER
components=database,data,config,config_keys,custom_apps
EOF

# Pack into single unified archive
tar -C "$BACKUP_DIR" -czf "$BACKUP_FILE" "$(basename "$WORK_DIR")"
cp "$BACKUP_FILE" "$LATEST_FILE"
cp "$BACKUP_FILE" "$LEGACY_LATEST_FILE"

# Verification
tar -tzf "$BACKUP_FILE" > "$LISTING_FILE"
for component in database.sql data.tar.gz config.tar.gz config_keys.json manifest.json manifest.txt; do
    if ! grep -Fq "/$component" "$LISTING_FILE"; then
        echo "[ERROR] Backup verification failed; missing component: $component"
        exit 1
    fi
done

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
SIZE_BYTES=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || stat -f%z "$BACKUP_FILE" 2>/dev/null || echo 0)
SHA256=$(sha256sum "$BACKUP_FILE" | cut -d' ' -f1)

# Update manifest inside archive with final size and duration
python3 -c "
import json
with open('$WORK_DIR/manifest.json') as f:
    m = json.load(f)
m['duration_seconds'] = $DURATION
m['size_bytes'] = $SIZE_BYTES
m['size_human'] = '$SIZE'
m['checksum_sha256'] = '$SHA256'
with open('$WORK_DIR/manifest.json', 'w') as f:
    json.dump(m, f, indent=2)
"

# Re-tar with final manifest
tar -C "$BACKUP_DIR" -czf "$BACKUP_FILE" "$(basename "$WORK_DIR")"
cp "$BACKUP_FILE" "$LATEST_FILE"
cp "$BACKUP_FILE" "$LEGACY_LATEST_FILE"

SHA256=$(sha256sum "$BACKUP_FILE" | cut -d' ' -f1)
printf '%s  %s
' "$SHA256" "$(basename "$BACKUP_FILE")" > "$BACKUP_FILE.sha256"
cp "$BACKUP_FILE.sha256" "$LATEST_FILE.sha256"
cp "$BACKUP_FILE.sha256" "$LEGACY_LATEST_FILE.sha256"

echo "[INFO] [6/6] Executing retention pruning policy (Max: $MAX_BACKUPS, Days: $RETENTION_DAYS)..."
find "$BACKUP_DIR" -maxdepth 1 -name "backup_data_*.tar.gz" -type f | sort -r | tail -n +"$((MAX_BACKUPS + 1))" | while read -r old_backup; do
    if [ -n "$old_backup" ] && [ -f "$old_backup" ]; then
        echo "[RETENTION] Pruning expired backup: $(basename "$old_backup")"
        rm -f "$old_backup" "$old_backup.sha256"
    fi
done

find "$BACKUP_DIR" -maxdepth 1 -name "backup_data_*.tar.gz" -type f -mtime +"$RETENTION_DAYS" | while read -r old_backup; do
    if [ -n "$old_backup" ] && [ -f "$old_backup" ]; then
        echo "[RETENTION] Pruning aged backup (> $RETENTION_DAYS days): $(basename "$old_backup")"
        rm -f "$old_backup" "$old_backup.sha256"
    fi
done

echo "================================================================================"
python3 -c "
import json
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'SUCCESS',
        'action': 'backup',
        'progress': 100,
        'message': 'پشتیبان‌گیری با موفقیت تکمیل گردید.'
    }, f, indent=2)
" 2>/dev/null || true

echo "[SUCCESS] Point-in-time Consistent Backup Completed!" 
echo "  - Backup ID:       $BACKUP_ID"
echo "  - Recovery Point:  $RECOVERY_POINT"
echo "  - File:            $BACKUP_FILE"
echo "  - Size:            $SIZE ($SIZE_BYTES bytes)"
echo "  - Duration:        ${DURATION}s"
echo "  - SHA256:          $SHA256"
echo "  - Symlink:         $LATEST_FILE"
echo "================================================================================"
