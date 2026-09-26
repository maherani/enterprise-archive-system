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

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

# Target resolution
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

echo "================================================================================"
echo " Enterprise Archive System - Disaster Recovery Engine"
echo " Target Archive: $TARGET"
echo "================================================================================"

python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'IN_PROGRESS',
        'action': 'restore',
        'started_at': datetime.datetime.now().isoformat(),
        'estimated_seconds': 60,
        'progress': 20,
        'message': 'عملیات بازیابی اضطراری اطلاعات در حال اجراست...'
    }, f, indent=2)
" 2>/dev/null || true

echo "[INFO] [1/9] Validating archive structure..." 
if ! tar -tzf "$TARGET" >/dev/null 2>&1; then
    echo "[ERROR] Backup archive is corrupted or invalid format: $TARGET"
    exit 1
fi

EXPECTED_SHA_FILE="$TARGET.sha256"
if [ -f "$EXPECTED_SHA_FILE" ]; then
    echo "[INFO] [2/9] Validating SHA-256 integrity checksum..."
    CALCULATED_SHA=$(sha256sum "$TARGET" | cut -d' ' -f1)
    EXPECTED_SHA=$(cut -d' ' -f1 < "$EXPECTED_SHA_FILE")
    if [ "$CALCULATED_SHA" != "$EXPECTED_SHA" ]; then
        echo "[ERROR] Checksum verification failed!"
        echo "  Expected:   $EXPECTED_SHA"
        echo "  Calculated: $CALCULATED_SHA"
        exit 1
    fi
    echo "[OK] SHA-256 Checksum verified: $CALCULATED_SHA"
else
    echo "[WARNING] Checksum file not found: $EXPECTED_SHA_FILE (Skipping hash verification)"
fi

TMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

echo "[INFO] [3/9] Inspecting backup manifest..."
tar -xzf "$TARGET" -C "$TMP_DIR"

BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)
if [ -z "${BACKUP_ROOT:-}" ]; then
    echo "[ERROR] Backup archive has no root directory."
    exit 1
fi

DB_DUMP="$BACKUP_ROOT/database.sql"
DATA_ARCHIVE="$BACKUP_ROOT/data.tar.gz"
CONFIG_ARCHIVE="$BACKUP_ROOT/config.tar.gz"
CONFIG_KEYS="$BACKUP_ROOT/config_keys.json"
MANIFEST_JSON="$BACKUP_ROOT/manifest.json"

if [ -f "$MANIFEST_JSON" ]; then
    python3 -c "
import json
with open('$MANIFEST_JSON') as f:
    m = json.load(f)
print('  Backup ID:      ', m.get('backup_id', 'N/A'))
print('  Recovery Point: ', m.get('recovery_point', 'N/A'))
print('  Backup Type:    ', m.get('backup_type', 'N/A'))
print('  Created At:     ', m.get('created_at', 'N/A'))
db_meta = m.get('components', {}).get('database', {})
print('  Expected Users: ', db_meta.get('users_count', 'N/A'))
print('  Expected Tables:', db_meta.get('tables_count', 'N/A'))
if m.get('status') != 'SUCCESS':
    print('[ERROR] Backup status is not SUCCESS: ', m.get('status'))
    exit(1)
"
fi

for required in "$DB_DUMP" "$DATA_ARCHIVE"; do
    if [ ! -s "$required" ]; then
        echo "[ERROR] Required backup component missing or empty: $required"
        exit 1
    fi
done

for container in archive_db archive_app; do
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "[ERROR] Container $container does not exist."
        exit 1
    fi
done

echo "[INFO] [4/9] Isolating application service (stopping app, keeping proxy online for maintenance page)..."
python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'IN_PROGRESS',
        'action': 'restore',
        'started_at': datetime.datetime.now().isoformat(),
        'estimated_seconds': 60,
        'progress': 30,
        'message': 'در حال بازیابی اطلاعات و بازنشانی پایگاه داده سامانه...'
    }, f, indent=2)
"
docker compose -f "$PROJECT_DIR/docker-compose.yml" stop app >/dev/null 2>&1 || true
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d db proxy >/dev/null

for _ in $(seq 1 30); do
    if docker exec archive_db pg_isready -U "$POSTGRES_USER" -d postgres >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

if ! docker exec archive_db pg_isready -U "$POSTGRES_USER" -d postgres >/dev/null 2>&1; then
    echo "[ERROR] PostgreSQL did not become ready."
    exit 1
fi

echo "[INFO] [5/9] Recreating database '$POSTGRES_DB' and synchronizing credentials..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres     -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB' AND pid <> pg_backend_pid();" >/dev/null 2>&1 || true

docker exec archive_db psql -U "$POSTGRES_USER" -d postgres     -c "DROP DATABASE IF EXISTS "$POSTGRES_DB";" >/dev/null
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres     -c "CREATE DATABASE "$POSTGRES_DB" OWNER "$POSTGRES_USER";" >/dev/null

docker exec archive_db psql -U "$POSTGRES_USER" -d postgres <<SQL >/dev/null
ALTER ROLE "$POSTGRES_USER" PASSWORD '$POSTGRES_PASSWORD';
SQL

echo "[INFO] [6/9] Importing database dump with ON_ERROR_STOP=1..."
docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$DB_DUMP" >/dev/null

echo "[INFO] [7/9] Restoring user documents and files..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps --entrypoint sh app -c     'rm -rf /var/www/html/data && mkdir -p /var/www/html/data' >/dev/null

docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app     -xzf - -C /var/www/html < "$DATA_ARCHIVE"

if [ -s "$CONFIG_ARCHIVE" ]; then
    echo "[INFO] Restoring Nextcloud config..."
    docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app         -xzf - -C /var/www/html < "$CONFIG_ARCHIVE"
fi

if [ -s "$BACKUP_ROOT/custom_apps.tar.gz" ]; then
    docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app         -xzf - -C /var/www/html < "$BACKUP_ROOT/custom_apps.tar.gz" >/dev/null 2>&1 || true
fi

echo "[INFO] [8/9] Synchronizing config.php security keys and credentials with current environment..."
DB_USER_B64="$(printf '%s' "$POSTGRES_USER" | base64 -w 0)"
DB_PASSWORD_B64="$(printf '%s' "$POSTGRES_PASSWORD" | base64 -w 0)"

docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T     -e "RESTORE_DB_USER_B64=$DB_USER_B64"     -e "RESTORE_DB_PASSWORD_B64=$DB_PASSWORD_B64"     --entrypoint php app <<'PHP'
<?php
$path = "/var/www/html/config/config.php";
if (!file_exists($path)) {
    exit(0);
}
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Unable to read config.php
");
    exit(1);
}
$user = base64_decode(getenv("RESTORE_DB_USER_B64"), true);
$password = base64_decode(getenv("RESTORE_DB_PASSWORD_B64"), true);

$replacements = [
    'dbuser' => $user,
    'dbpassword' => $password,
    'dbhost' => 'db',
    'dbtype' => 'pgsql',
];

foreach ($replacements as $key => $value) {
    $pattern = "/^(\s*'" . preg_quote($key, "/") . "'\s*=>\s*')[^']*(',?\s*)$/m";
    $updated = preg_replace_callback(
        $pattern,
        static function (array $matches) use ($value): string {
            return $matches[1] . addcslashes($value, "\'") . $matches[2];
        },
        $content,
        1,
        $count
    );
    if ($updated !== null && $count === 1) {
        $content = $updated;
    }
}
$content = preg_replace("/'maintenance'\s*=>\s*true/", "'maintenance' => false", $content);
file_put_contents($path, $content);
PHP

# Start application container
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d app >/dev/null

echo "[INFO] Setting file permissions and resetting file locks..."
docker exec archive_app chown -R www-data:www-data /var/www/html/data /var/www/html/config

docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"     -c "DO \$\$ BEGIN IF to_regclass('oc_file_locks') IS NOT NULL THEN TRUNCATE TABLE oc_file_locks; END IF; END \$\$;" >/dev/null

echo "[INFO] [9/9] Rebuilding file cache (occ files:scan --all)..."
docker exec -u www-data archive_app php occ files:scan --all >/dev/null

echo "[INFO] Ensuring maintenance mode is disabled..."
docker exec -u www-data archive_app php occ maintenance:mode --off >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ config:system:set maintenance --type=bool --value=false >/dev/null 2>&1 || true

python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'SUCCESS',
        'action': 'restore',
        'started_at': datetime.datetime.now().isoformat(),
        'estimated_seconds': 0,
        'progress': 100,
        'message': 'بازیابی اطلاعات با موفقیت تکمیل شد.'
    }, f, indent=2)
"
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d proxy >/dev/null

echo "================================================================================"
echo "[SUCCESS] Disaster Recovery Completed Successfully!"
echo "  - Restored From: $TARGET"
RESTORED_USERS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo 0)
RESTORED_DOCS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo 0)
echo "  - Total Users:   $RESTORED_USERS"
echo "  - Document Meta: $RESTORED_DOCS"
echo "  - Proxy Status:  ONLINE"
echo "================================================================================"
