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

MAX_BACKUPS=14
RETENTION_DAYS=30
if [ -f "$CONFIG_FILE" ]; then
    CONFIG_STORAGE=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('storage_location', ''))" 2>/dev/null || echo "")
    if [ -n "$CONFIG_STORAGE" ] && [ -d "$CONFIG_STORAGE" ]; then
        BACKUP_DIR="$CONFIG_STORAGE"
    fi
    MAX_BACKUPS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); p=c.get('instance_data_backup', c.get('retention_policy', {})); print(p.get('max_backups_count', 14))" 2>/dev/null || echo 14)
    RETENTION_DAYS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); p=c.get('instance_data_backup', c.get('retention_policy', {})); print(p.get('retention_days', 30))" 2>/dev/null || echo 30)
fi

for container in archive_db archive_app; do
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "[ERROR] Container $container does not exist or is stopped."
        exit 1
    fi
done

if ! docker exec archive_db pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then
    echo "[ERROR] PostgreSQL is not ready using POSTGRES_USER/POSTGRES_DB from .env."
    exit 1
fi

if ! docker exec archive_app test -d "/var/www/html/data"; then
    echo "[ERROR] Nextcloud data directory is not available inside archive_app."
    exit 1
fi

mkdir -p "$BACKUP_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
RAND_SUFFIX=$(head -c 6 /dev/urandom | xxd -p 2>/dev/null || tr -dc a-z0-9 </dev/urandom | head -c 6)
BACKUP_PURPOSE="${1:-scheduled}"
if [ "$BACKUP_PURPOSE" = "pre_restore_safety" ]; then
    BACKUP_ID="bk-data-pre_restore-${TIMESTAMP}-${RAND_SUFFIX}"
    BACKUP_FILE="$BACKUP_DIR/backup_instance_data_pre_restore_${TIMESTAMP}_${RAND_SUFFIX}.tar.gz"
else
    BACKUP_ID="bk-data-${TIMESTAMP}-${RAND_SUFFIX}"
    BACKUP_FILE="$BACKUP_DIR/backup_instance_data_${TIMESTAMP}_${RAND_SUFFIX}.tar.gz"
fi
BACKUP_TYPE="instance_data"

WORK_DIR="$BACKUP_DIR/.backup_data_${TIMESTAMP}_${RAND_SUFFIX}"
LATEST_INSTANCE_DATA_FILE="$BACKUP_DIR/latest_instance_data_backup.tar.gz"
LISTING_FILE="$WORK_DIR/.archive_listing.txt"

maintenance_enabled=0
cleanup() {
    if [ "$maintenance_enabled" -eq 1 ]; then
        echo "[INFO] Disabling Nextcloud maintenance mode in cleanup..."
        docker exec archive_app php occ maintenance:mode --off >/dev/null 2>&1 || true
    fi
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$WORK_DIR"

python3 -c "
import json
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'IN_PROGRESS',
        'action': 'backup_data',
        'backup_type': '$BACKUP_TYPE',
        'started_at': '$(date -Iseconds)',
        'estimated_seconds': 40,
        'progress': 15,
        'message': 'عملیات پشتیبان‌گیری داده‌های سازمانی (Instance Data Backup) در حال اجراست...'
    }, f, indent=2)
" 2>/dev/null || true

echo "================================================================================"
echo " Enterprise Archive System - Instance Data Backup Engine (BR-02)"
echo " Backup ID:   $BACKUP_ID"
echo " Backup Type: $BACKUP_TYPE (PostgreSQL Dump + User Data Files)"
echo " Target File: $(basename "$BACKUP_FILE")"
echo " Time:        $(date -Iseconds)"
echo "================================================================================"

echo "[INFO] [1/6] Discovering System Baseline Reference..."
cd "$PROJECT_DIR"
GIT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
GIT_DATE=$(git log -1 --format=%cI 2>/dev/null || echo "$(date -Iseconds)")
NC_VERSION=$(docker exec -u www-data archive_app php occ config:system:get version 2>/dev/null || echo "unknown")
APP_VERSION="2.9.0"

SYS_BACKUP_ID="unknown"
SYS_BACKUP_FILE="unknown"
SYS_BACKUP_SHA256="unknown"

LATEST_SYS_FILE="$BACKUP_DIR/latest_system_backup.tar.gz"
if [ ! -f "$LATEST_SYS_FILE" ]; then
    LATEST_FOUND_SYS=$(find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f | sort -r | head -n1 || echo "")
    if [ -n "$LATEST_FOUND_SYS" ]; then
        LATEST_SYS_FILE="$LATEST_FOUND_SYS"
    fi
fi

if [ -f "$LATEST_SYS_FILE" ]; then
    SYS_BACKUP_FILE="$(basename "$LATEST_SYS_FILE")"
    if [ -f "$LATEST_SYS_FILE.sha256" ]; then
        SYS_BACKUP_SHA256=$(cut -d' ' -f1 < "$LATEST_SYS_FILE.sha256")
    fi
    SYS_BACKUP_ID=$(python3 -c "
import tarfile, json, sys
try:
    with tarfile.open('$LATEST_SYS_FILE', 'r:gz') as t:
        for m in t.getmembers():
            if m.name.endswith('manifest.json'):
                mf = json.load(t.extractfile(m))
                print(mf.get('backup_id', 'unknown'))
                sys.exit(0)
except Exception:
    pass
print('unknown')
" 2>/dev/null || echo "unknown")
fi

echo "  System Baseline ID:     $SYS_BACKUP_ID"
echo "  System Baseline File:   $SYS_BACKUP_FILE"
echo "  System Baseline SHA256: $SYS_BACKUP_SHA256"
echo "  Git Baseline:           $GIT_COMMIT ($GIT_BRANCH)"

echo "[INFO] [2/6] Engaging point-in-time consistency lock (Maintenance Mode)..."
docker exec archive_app php occ maintenance:mode --on >/dev/null
maintenance_enabled=1
RECOVERY_POINT=$(date -Iseconds)

echo "[INFO] [3/6] Capturing PostgreSQL atomic operational dump..."
docker exec archive_db pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" > "$WORK_DIR/database.sql"

if [ ! -s "$WORK_DIR/database.sql" ]; then
    echo "[FATAL ERROR] PostgreSQL database dump is empty or failed."
    exit 1
fi

echo "[INFO] [4/6] Archiving Nextcloud User Data and files..."
set +e
docker exec archive_app tar --warning=no-file-changed -C /var/www/html -czf - data > "$WORK_DIR/data.tar.gz"
tar_rc=$?
set -e
if [ "$tar_rc" -ne 0 ] && [ "$tar_rc" -ne 1 ]; then
    echo "[FATAL ERROR] tar failed with exit code $tar_rc"
    exit 1
fi

if [ ! -s "$WORK_DIR/data.tar.gz" ]; then
    echo "[FATAL ERROR] User data archive is empty or failed."
    exit 1
fi

echo "[INFO] Releasing maintenance mode lock..."
docker exec archive_app php occ maintenance:mode --off >/dev/null
maintenance_enabled=0

# STRICT ASSERTION: System configuration and application source must NOT be duplicated here
if [ -f "$WORK_DIR/config.tar.gz" ] || [ -f "$WORK_DIR/custom_apps.tar.gz" ] || [ -f "$WORK_DIR/docker-compose.yml" ]; then
    echo "[FATAL ERROR] System software or configuration detected in instance data backup workspace!"
    exit 1
fi

echo "[INFO] [5/6] Computing Checksums, Manifest, and Metrics..."
DB_SHA256=$(sha256sum "$WORK_DIR/database.sql" | cut -d' ' -f1)
DATA_SHA256=$(sha256sum "$WORK_DIR/data.tar.gz" | cut -d' ' -f1)

COMPONENTS_DIGEST_SHA256=$(printf "%s\n%s" "$DB_SHA256" "$DATA_SHA256" | sha256sum | cut -d' ' -f1)

USERS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
TABLES_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null || echo "0")
DOCS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo "0")
GROUPS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_groups;" 2>/dev/null || echo "0")
TAGS_COUNT=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_systemtag;" 2>/dev/null || echo "0")

cat > "$WORK_DIR/manifest.json" <<EOF
{
  "backup_id": "$BACKUP_ID",
  "backup_type": "$BACKUP_TYPE",
  "backup_purpose": "$BACKUP_PURPOSE",
  "status": "SUCCESS",
  "created_at": "$(date -Iseconds)",
  "recovery_point": "$RECOVERY_POINT",
  "system_baseline": {
    "system_backup_id": "$SYS_BACKUP_ID",
    "system_backup_file": "$SYS_BACKUP_FILE",
    "system_backup_sha256": "$SYS_BACKUP_SHA256",
    "git_commit": "$GIT_COMMIT",
    "git_branch": "$GIT_BRANCH",
    "git_commit_date": "$GIT_DATE",
    "nextcloud_version": "$NC_VERSION",
    "archive_app_version": "$APP_VERSION"
  },
  "components_digest_sha256": "$COMPONENTS_DIGEST_SHA256",
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
    "user_data": {
      "file": "data.tar.gz",
      "sha256": "$DATA_SHA256"
    }
  },
  "excluded_data": {
    "system_configuration": "EXCLUDED - Config belongs to system_only baseline (config.tar.gz)",
    "custom_companion_apps": "EXCLUDED - Application source belongs to system_only baseline (custom_apps.tar.gz)",
    "deployment_and_compose": "EXCLUDED - System deployment code belongs to git repository",
    "git_repository": "EXCLUDED - Version control history belongs to git"
  },
  "error_info": null
}
EOF

cat > "$WORK_DIR/manifest.txt" <<EOF
backup_id=$BACKUP_ID
backup_type=$BACKUP_TYPE
backup_purpose=$BACKUP_PURPOSE
status=SUCCESS
created_at=$(date -Iseconds)
recovery_point=$RECOVERY_POINT
system_backup_id=$SYS_BACKUP_ID
system_backup_file=$SYS_BACKUP_FILE
system_backup_sha256=$SYS_BACKUP_SHA256
git_commit=$GIT_COMMIT
git_branch=$GIT_BRANCH
nextcloud_version=$NC_VERSION
components=database,user_data
components_digest_sha256=$COMPONENTS_DIGEST_SHA256
excluded=config.tar.gz,custom_apps.tar.gz,docker-compose.yml
EOF

echo "[INFO] [6/6] Packing Archive & Generating Sidecar Checksum..."
tar -C "$BACKUP_DIR" -czf "$BACKUP_FILE" "$(basename "$WORK_DIR")"

# Verify archive contents
tar -tzf "$BACKUP_FILE" > "$LISTING_FILE"
for comp in database.sql data.tar.gz manifest.json manifest.txt; do
    if ! grep -Fq "/$comp" "$LISTING_FILE"; then
        echo "[ERROR] Verification failed; missing component: $comp"
        exit 1
    fi
done

# STRICT ASSERTION: System software or configuration must NOT be inside the archive
if grep -Eq "/config\.tar\.gz|/custom_apps\.tar\.gz|/docker-compose\.yml|/config_keys\.json" "$LISTING_FILE"; then
    echo "[FATAL ERROR] Verification failed: system software or configuration found inside instance data backup archive!"
    rm -f "$BACKUP_FILE"
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
SIZE_BYTES=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || stat -f%z "$BACKUP_FILE" 2>/dev/null || echo 0)
SHA256=$(sha256sum "$BACKUP_FILE" | cut -d' ' -f1)

# Write sidecar checksum
printf '%s  %s\n' "$SHA256" "$(basename "$BACKUP_FILE")" > "$BACKUP_FILE.sha256"

# Canonical latest instance data alias
if [ "$BACKUP_PURPOSE" = "pre_restore_safety" ]; then
    LATEST_PRE_FILE="$BACKUP_DIR/latest_instance_data_pre_restore_backup.tar.gz"
    cp "$BACKUP_FILE" "$LATEST_PRE_FILE"
    cp "$BACKUP_FILE.sha256" "$LATEST_PRE_FILE.sha256"
else
    cp "$BACKUP_FILE" "$LATEST_INSTANCE_DATA_FILE"
    cp "$BACKUP_FILE.sha256" "$LATEST_INSTANCE_DATA_FILE.sha256"
fi

# Independent Retention Policy for Instance Data Backups (Protecting pre_restore safety snapshots)
find "$BACKUP_DIR" -maxdepth 1 -name "backup_instance_data_*.tar.gz" ! -name "*pre_restore*" -type f | sort -r | tail -n +"$((MAX_BACKUPS + 1))" | while read -r old; do
    if [ -n "$old" ] && [ -f "$old" ]; then
        echo "[RETENTION] Pruning expired instance data backup: $(basename "$old")"
        rm -f "$old" "$old.sha256"
    fi
done

find "$BACKUP_DIR" -maxdepth 1 -name "backup_instance_data_*.tar.gz" -type f -mtime +"$RETENTION_DAYS" | while read -r old; do
    if [ -n "$old" ] && [ -f "$old" ]; then
        echo "[RETENTION] Pruning aged instance data backup (> $RETENTION_DAYS days): $(basename "$old")"
        rm -f "$old" "$old.sha256"
    fi
done

python3 -c "
import json
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'SUCCESS',
        'action': 'backup_data',
        'backup_type': '$BACKUP_TYPE',
        'progress': 100,
        'message': 'پشتیبان‌گیری داده‌های سازمانی (Instance Data Backup) با موفقیت تکمیل گردید.'
    }, f, indent=2)
" 2>/dev/null || true

echo "================================================================================"
echo "[SUCCESS] Instance Data Backup Completed Successfully!"
echo "  - Backup ID:       $BACKUP_ID"
echo "  - Backup Type:     $BACKUP_TYPE"
echo "  - Recovery Point:  $RECOVERY_POINT"
echo "  - System Baseline: $SYS_BACKUP_ID ($GIT_COMMIT)"
echo "  - File:            $BACKUP_FILE"
echo "  - Size:            $SIZE ($SIZE_BYTES bytes)"
echo "  - Duration:        ${DURATION}s"
echo "  - SHA256:          $SHA256"
echo "  - Digest:          $COMPONENTS_DIGEST_SHA256"
echo "  - Latest Alias:    $LATEST_INSTANCE_DATA_FILE"
echo "================================================================================"
