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

MAX_BACKUPS=14
RETENTION_DAYS=30
if [ -f "$CONFIG_FILE" ]; then
    CONFIG_STORAGE=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('storage_location', ''))" 2>/dev/null || echo "")
    if [ -n "$CONFIG_STORAGE" ] && [ -d "$CONFIG_STORAGE" ]; then
        BACKUP_DIR="$CONFIG_STORAGE"
    fi
    MAX_BACKUPS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('max_backups_count', 14))" 2>/dev/null || echo 14)
    RETENTION_DAYS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('retention_days', 30))" 2>/dev/null || echo 30)
fi

if ! docker inspect archive_app >/dev/null 2>&1; then
    echo "[ERROR] Container archive_app does not exist or is stopped."
    exit 1
fi

if ! docker exec archive_app test -d "/var/www/html/config"; then
    echo "[ERROR] Nextcloud config directory is not available inside archive_app."
    exit 1
fi

mkdir -p "$BACKUP_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
RAND_SUFFIX=$(head -c 6 /dev/urandom | xxd -p 2>/dev/null || tr -dc a-z0-9 </dev/urandom | head -c 6)
BACKUP_ID="bk-sys-${TIMESTAMP}-${RAND_SUFFIX}"
BACKUP_TYPE="system_only"

WORK_DIR="$BACKUP_DIR/.backup_sys_${TIMESTAMP}_${RAND_SUFFIX}"
BACKUP_FILE="$BACKUP_DIR/backup_system_${TIMESTAMP}_${RAND_SUFFIX}.tar.gz"
LATEST_SYSTEM_FILE="$BACKUP_DIR/latest_system_backup.tar.gz"
LISTING_FILE="$WORK_DIR/.archive_listing.txt"

cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$WORK_DIR"

echo "================================================================================"
echo " Enterprise Archive System - System Backup Engine (BR-01)"
echo " Backup ID:   $BACKUP_ID"
echo " Backup Type: $BACKUP_TYPE (Software State + Configuration + Identity)"
echo " Target File: $(basename "$BACKUP_FILE")"
echo " Time:        $(date -Iseconds)"
echo "================================================================================"

echo "[INFO] [1/5] Capturing Software State & Git Baseline..."
cd "$PROJECT_DIR"
GIT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
GIT_DATE=$(git log -1 --format=%cI 2>/dev/null || echo "$(date -Iseconds)")
GIT_SUBJECT=$(git log -1 --format=%s 2>/dev/null || echo "N/A")

NC_VERSION=$(docker exec -u www-data archive_app php occ config:system:get version 2>/dev/null || echo "unknown")
PHP_VERSION=$(docker exec archive_app php -r 'echo PHP_VERSION;' 2>/dev/null || echo "unknown")
APP_VERSION="2.9.0"

DOCKER_APP_IMAGE=$(docker inspect archive_app --format '{{.Config.Image}}' 2>/dev/null || echo "unknown")
DOCKER_DB_IMAGE=$(docker inspect archive_db --format '{{.Config.Image}}' 2>/dev/null || echo "unknown")
DOCKER_PROXY_IMAGE=$(docker inspect archive_proxy --format '{{.Config.Image}}' 2>/dev/null || echo "unknown")

ENABLED_APPS_JSON=$(docker exec -u www-data archive_app php occ app:list --output=json 2>/dev/null || echo "{}")

cat > "$WORK_DIR/software_info.json" <<EOF
{
  "software_baseline": {
    "git_commit": "$GIT_COMMIT",
    "git_branch": "$GIT_BRANCH",
    "git_commit_date": "$GIT_DATE",
    "git_commit_subject": "$GIT_SUBJECT",
    "nextcloud_version": "$NC_VERSION",
    "php_version": "$PHP_VERSION",
    "app_version": "$APP_VERSION"
  },
  "container_images": {
    "app": "$DOCKER_APP_IMAGE",
    "db": "$DOCKER_DB_IMAGE",
    "proxy": "$DOCKER_PROXY_IMAGE"
  },
  "enabled_apps": $ENABLED_APPS_JSON
}
EOF

echo "[INFO] [2/5] Archiving System Configuration and Identity Keys..."
docker exec archive_app tar -C /var/www/html -czf - config > "$WORK_DIR/config.tar.gz"

INSTANCE_ID=$(docker exec -u www-data archive_app php occ config:system:get instanceid 2>/dev/null || echo "")
PASSWORD_SALT=$(docker exec -u www-data archive_app php occ config:system:get passwordsalt 2>/dev/null || echo "")
SECRET_KEY=$(docker exec -u www-data archive_app php occ config:system:get secret 2>/dev/null || echo "")

cat > "$WORK_DIR/config_keys.json" <<EOF
{
  "instanceid": "$INSTANCE_ID",
  "passwordsalt": "$PASSWORD_SALT",
  "secret": "$SECRET_KEY",
  "version": "$NC_VERSION"
}
EOF

echo "[INFO] [3/5] Archiving Custom Companion Apps..."
if docker exec archive_app test -d /var/www/html/custom_apps; then
    docker exec archive_app tar -C /var/www/html -czf - custom_apps > "$WORK_DIR/custom_apps.tar.gz"
else
    tar -czf "$WORK_DIR/custom_apps.tar.gz" --files-from /dev/null
fi

# Validation: ensure NO operational data components were produced
if [ -f "$WORK_DIR/database.sql" ]; then
    echo "[FATAL ERROR] Operational database.sql detected in system-only backup workspace! Aborting."
    exit 1
fi

if [ -f "$WORK_DIR/data.tar.gz" ]; then
    echo "[FATAL ERROR] User data.tar.gz detected in system-only backup workspace! Aborting."
    exit 1
fi

# Check required system components exist and are non-empty
for comp in config.tar.gz config_keys.json custom_apps.tar.gz software_info.json; do
    if [ ! -s "$WORK_DIR/$comp" ]; then
        echo "[ERROR] System backup component is missing or empty: $comp"
        exit 1
    fi
done

echo "[INFO] [4/5] Computing Component Checksums and Manifest..."
CONFIG_SHA=$(sha256sum "$WORK_DIR/config.tar.gz" | cut -d' ' -f1)
KEYS_SHA=$(sha256sum "$WORK_DIR/config_keys.json" | cut -d' ' -f1)
CUSTOM_APPS_SHA=$(sha256sum "$WORK_DIR/custom_apps.tar.gz" | cut -d' ' -f1)
SOFTWARE_SHA=$(sha256sum "$WORK_DIR/software_info.json" | cut -d' ' -f1)

# Deterministic composite payload digest of all component hashes
COMPONENTS_DIGEST_SHA256=$(printf "%s\n%s\n%s\n%s" "$CONFIG_SHA" "$KEYS_SHA" "$CUSTOM_APPS_SHA" "$SOFTWARE_SHA" | sha256sum | cut -d' ' -f1)

cat > "$WORK_DIR/manifest.json" <<EOF
{
  "backup_id": "$BACKUP_ID",
  "backup_type": "$BACKUP_TYPE",
  "status": "SUCCESS",
  "created_at": "$(date -Iseconds)",
  "software_baseline": {
    "git_commit": "$GIT_COMMIT",
    "git_branch": "$GIT_BRANCH",
    "git_commit_date": "$GIT_DATE",
    "git_commit_subject": "$GIT_SUBJECT",
    "nextcloud_version": "$NC_VERSION",
    "app_version": "$APP_VERSION"
  },
  "components_digest_sha256": "$COMPONENTS_DIGEST_SHA256",
  "components": {
    "config": {
      "file": "config.tar.gz",
      "sha256": "$CONFIG_SHA"
    },
    "security_keys": {
      "file": "config_keys.json",
      "sha256": "$KEYS_SHA"
    },
    "custom_apps": {
      "file": "custom_apps.tar.gz",
      "sha256": "$CUSTOM_APPS_SHA"
    },
    "software_info": {
      "file": "software_info.json",
      "sha256": "$SOFTWARE_SHA"
    }
  },
  "excluded_data": {
    "database_dump": "EXCLUDED - No operational database dump included",
    "user_data": "EXCLUDED - No user files or document storage included",
    "operational_metadata": "EXCLUDED - No document metadata or tag records included",
    "audit_records": "EXCLUDED - No operational audit records included"
  },
  "error_info": null
}
EOF

cat > "$WORK_DIR/manifest.txt" <<EOF
backup_id=$BACKUP_ID
backup_type=$BACKUP_TYPE
status=SUCCESS
created_at=$(date -Iseconds)
git_commit=$GIT_COMMIT
git_branch=$GIT_BRANCH
nextcloud_version=$NC_VERSION
components=config,config_keys,custom_apps,software_info
components_digest_sha256=$COMPONENTS_DIGEST_SHA256
excluded=database.sql,data.tar.gz
EOF

echo "[INFO] [5/5] Packing Archive & Generating Sidecar Checksum..."
tar -C "$BACKUP_DIR" -czf "$BACKUP_FILE" "$(basename "$WORK_DIR")"

# Verification of archive contents
tar -tzf "$BACKUP_FILE" > "$LISTING_FILE"

# Must have required system components
for comp in config.tar.gz config_keys.json custom_apps.tar.gz software_info.json manifest.json manifest.txt; do
    if ! grep -Fq "/$comp" "$LISTING_FILE"; then
        echo "[ERROR] Verification failed; missing component: $comp"
        exit 1
    fi
done

# STRICT ASSERTION: database.sql and data.tar.gz must NOT be inside the archive
if grep -Eq "/database\.sql|/data\.tar\.gz" "$LISTING_FILE"; then
    echo "[FATAL ERROR] Verification failed: operational database or user data found inside system backup archive!"
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

# Canonical latest system backup alias
cp "$BACKUP_FILE" "$LATEST_SYSTEM_FILE"
cp "$BACKUP_FILE.sha256" "$LATEST_SYSTEM_FILE.sha256"

# Retention policy for system backups
find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f | sort -r | tail -n +"$((MAX_BACKUPS + 1))" | while read -r old; do
    if [ -n "$old" ] && [ -f "$old" ]; then
        echo "[RETENTION] Pruning expired system backup: $(basename "$old")"
        rm -f "$old" "$old.sha256"
    fi
done

find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f -mtime +"$RETENTION_DAYS" | while read -r old; do
    if [ -n "$old" ] && [ -f "$old" ]; then
        echo "[RETENTION] Pruning aged system backup (> $RETENTION_DAYS days): $(basename "$old")"
        rm -f "$old" "$old.sha256"
    fi
done

python3 -c "
import json
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'SUCCESS',
        'action': 'backup_system',
        'progress': 100,
        'message': 'پشتیبان‌گیری سیستم (System Backup) با موفقیت تکمیل گردید.'
    }, f, indent=2)
" 2>/dev/null || true

echo "================================================================================"
echo "[SUCCESS] System Backup Completed Successfully (Zero Operational Data)!"
echo "  - Backup ID:       $BACKUP_ID"
echo "  - Backup Type:     $BACKUP_TYPE"
echo "  - Git Commit:      $GIT_COMMIT"
echo "  - Git Branch:      $GIT_BRANCH"
echo "  - File:            $BACKUP_FILE"
echo "  - Size:            $SIZE ($SIZE_BYTES bytes)"
echo "  - Duration:        ${DURATION}s"
echo "  - SHA256:          $SHA256"
echo "  - Digest:          $COMPONENTS_DIGEST_SHA256"
echo "  - Latest Alias:    $LATEST_SYSTEM_FILE"
echo "================================================================================"
