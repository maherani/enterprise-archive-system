#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env file not found: $ENV_FILE"
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
    if [ -f "$BACKUP_DIR/latest_instance_backup.tar.gz" ]; then
        TARGET="$BACKUP_DIR/latest_instance_backup.tar.gz"
    elif [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
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
echo " Enterprise Archive System - Disaster Recovery Engine (Restore)"
echo " Target Archive: $TARGET"
echo " Time:           $(date -Iseconds)"
echo "================================================================================"

echo "[INFO] [1/9] Validating backup archive integrity..."
if ! tar -tzf "$TARGET" >/dev/null 2>&1; then
    echo "[ERROR] Backup archive is corrupted or not a valid gzip tar stream."
    exit 1
fi

echo "[INFO] [2/9] Checking SHA-256 sidecar checksum..."
EXPECTED_SHA_FILE="$TARGET.sha256"
if [ -f "$EXPECTED_SHA_FILE" ]; then
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
    echo "[WARNING] Checksum sidecar file not found: $EXPECTED_SHA_FILE (Skipping sidecar check)"
fi

TMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

echo "[INFO] [3/9] Inspecting and verifying all backup components..."
tar -xzf "$TARGET" -C "$TMP_DIR"

BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)
if [ -z "${BACKUP_ROOT:-}" ]; then
    echo "[ERROR] Backup archive has no valid root directory."
    exit 1
fi

DB_DUMP="$BACKUP_ROOT/database.sql"
DATA_ARCHIVE="$BACKUP_ROOT/data.tar.gz"
CONFIG_ARCHIVE="$BACKUP_ROOT/config.tar.gz"
CONFIG_KEYS="$BACKUP_ROOT/config_keys.json"
CUSTOM_APPS_ARCHIVE="$BACKUP_ROOT/custom_apps.tar.gz"
MANIFEST_JSON="$BACKUP_ROOT/manifest.json"

# Strict component existence and non-empty assertions
for req_comp in "$DB_DUMP" "$DATA_ARCHIVE" "$CONFIG_ARCHIVE" "$CONFIG_KEYS" "$MANIFEST_JSON"; do
    if [ ! -s "$req_comp" ]; then
        echo "[ERROR] Critical backup component missing or empty: $(basename "$req_comp")"
        exit 1
    fi
done

# Verify streams of inner tar archives
if ! tar -tzf "$DATA_ARCHIVE" >/dev/null 2>&1; then
    echo "[ERROR] Corrupt inner data archive: data.tar.gz"
    exit 1
fi
if ! tar -tzf "$CONFIG_ARCHIVE" >/dev/null 2>&1; then
    echo "[ERROR] Corrupt inner config archive: config.tar.gz"
    exit 1
fi
if [ -f "$CUSTOM_APPS_ARCHIVE" ] && [ -s "$CUSTOM_APPS_ARCHIVE" ]; then
    if ! tar -tzf "$CUSTOM_APPS_ARCHIVE" >/dev/null 2>&1; then
        echo "[ERROR] Corrupt inner custom_apps archive: custom_apps.tar.gz"
        exit 1
    fi
fi

# Validate manifest.json structure and component hashes
python3 -c "
import json, sys, hashlib

def verify_file_sha(fpath, expected):
    if not expected:
        return
    h = hashlib.sha256()
    with open(fpath, 'rb') as f:
        while chunk := f.read(65536):
            h.update(chunk)
    actual = h.hexdigest()
    if actual != expected:
        print(f'[ERROR] Inner component checksum mismatch for {fpath}: expected {expected}, got {actual}', file=sys.stderr)
        sys.exit(1)

with open('$MANIFEST_JSON') as f:
    m = json.load(f)

print('  Backup ID:      ', m.get('backup_id', 'N/A'))
print('  Recovery Point: ', m.get('recovery_point', 'N/A'))
print('  Backup Type:    ', m.get('backup_type', 'N/A'))
print('  Created At:     ', m.get('created_at', 'N/A'))

if m.get('status') != 'SUCCESS':
    print('[ERROR] Backup status in manifest is not SUCCESS: ', m.get('status'), file=sys.stderr)
    sys.exit(1)

comps = m.get('components', {})
if 'database' in comps:
    verify_file_sha('$DB_DUMP', comps['database'].get('sha256'))
if 'user_data' in comps:
    verify_file_sha('$DATA_ARCHIVE', comps['user_data'].get('sha256'))
elif 'user_files' in comps:
    verify_file_sha('$DATA_ARCHIVE', comps['user_files'].get('sha256'))
if 'config' in comps:
    verify_file_sha('$CONFIG_ARCHIVE', comps['config'].get('sha256'))
if 'security_keys' in comps:
    verify_file_sha('$CONFIG_KEYS', comps['security_keys'].get('sha256'))
if 'custom_apps' in comps and os.path.exists('$CUSTOM_APPS_ARCHIVE'):
    verify_file_sha('$CUSTOM_APPS_ARCHIVE', comps['custom_apps'].get('sha256'))
"

# Validate config_keys.json contains identity attributes
python3 -c "
import json, sys
with open('$CONFIG_KEYS') as f:
    keys = json.load(f)
for k in ['instanceid', 'passwordsalt', 'secret']:
    if not keys.get(k):
        print(f'[ERROR] Missing required security identity key in config_keys.json: {k}', file=sys.stderr)
        sys.exit(1)
print('  Security Keys:   instanceid, passwordsalt, secret verified.')
"

for container in archive_db archive_app; do
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "[ERROR] Container $container does not exist."
        exit 1
    fi
done

# Failure handler to keep system safe and fail-closed
on_restore_failure() {
    echo ""
    echo "================================================================================"
    echo "[FATAL] Restore failed during execution! System remains in protected isolation."
    echo "================================================================================"
    python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'FAILED',
        'action': 'restore',
        'failed_at': datetime.datetime.now().isoformat(),
        'message': 'عملیات بازیابی با خطا مواجه شد. سامانه در حالت ایزوله باقی ماند.'
    }, f, indent=2)
" 2>/dev/null || true
}
trap on_restore_failure ERR

echo "[INFO] [4/9] Isolating application service (stopping app, keeping proxy online)..."
python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'IN_PROGRESS',
        'action': 'restore',
        'started_at': datetime.datetime.now().isoformat(),
        'estimated_seconds': 60,
        'progress': 25,
        'message': 'در حال ایزولاسیون سامانه و بازنشانی پایگاه داده...'
    }, f, indent=2)
" 2>/dev/null || true

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
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB' AND pid <> pg_backend_pid();" >/dev/null 2>&1 || true

docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS \"$POSTGRES_DB\";" >/dev/null
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "CREATE DATABASE \"$POSTGRES_DB\" OWNER \"$POSTGRES_USER\";" >/dev/null

docker exec archive_db psql -U "$POSTGRES_USER" -d postgres <<SQL >/dev/null
ALTER ROLE "$POSTGRES_USER" PASSWORD '$POSTGRES_PASSWORD';
SQL

echo "[INFO] [6/9] Importing database dump with ON_ERROR_STOP=1..."
docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$DB_DUMP" >/dev/null

echo "[INFO] [7/9] Restoring user documents, configuration, and companion apps..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps --entrypoint sh app -c \
    'rm -rf /var/www/html/data && mkdir -p /var/www/html/data' >/dev/null

docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
    -xzf - -C /var/www/html < "$DATA_ARCHIVE"

echo "[INFO] Restoring Nextcloud config..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
    -xzf - -C /var/www/html < "$CONFIG_ARCHIVE"

# Restore custom apps without masking errors
if [ -f "$CUSTOM_APPS_ARCHIVE" ] && [ -s "$CUSTOM_APPS_ARCHIVE" ]; then
    echo "[INFO] Restoring companion custom apps..."
    docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
        -xzf - -C /var/www/html < "$CUSTOM_APPS_ARCHIVE"
fi

echo "[INFO] [8/9] Synchronizing config.php security keys and credentials with current environment..."
DB_USER_B64="$(printf '%s' "$POSTGRES_USER" | base64 -w 0)"
DB_PASSWORD_B64="$(printf '%s' "$POSTGRES_PASSWORD" | base64 -w 0)"

docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T \
    -e "RESTORE_DB_USER_B64=$DB_USER_B64" \
    -e "RESTORE_DB_PASSWORD_B64=$DB_PASSWORD_B64" \
    --entrypoint php app <<'PHP'
<?php
$path = "/var/www/html/config/config.php";
if (!file_exists($path)) {
    fwrite(STDERR, "config.php not found!\n");
    exit(1);
}
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Unable to read config.php\n");
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
docker exec archive_app chown -R www-data:www-data /var/www/html/data /var/www/html/config /var/www/html/custom_apps 2>/dev/null || docker exec archive_app chown -R www-data:www-data /var/www/html/data /var/www/html/config

docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "DO \$\$ BEGIN IF to_regclass('oc_file_locks') IS NOT NULL THEN TRUNCATE TABLE oc_file_locks; END IF; END \$\$;" >/dev/null

echo "[INFO] [9/9] Rebuilding file cache (occ files:scan --all)..."
docker exec -u www-data archive_app php occ files:scan --all >/dev/null

echo "[INFO] Ensuring maintenance mode is disabled..."
docker exec -u www-data archive_app php occ maintenance:mode --off >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ config:system:set maintenance --type=bool --value=false >/dev/null 2>&1 || true

# Assert post-restore database integrity
RESTORED_USERS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo 0)
RESTORED_DOCS=$(docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "SELECT count(*) FROM oc_archive_document_metadata;" 2>/dev/null || echo 0)

if [ "$RESTORED_USERS" -eq 0 ]; then
    echo "[ERROR] Post-restore validation failed: zero users found in restored database!"
    exit 1
fi

python3 -c "
import json, datetime
with open('$BACKUP_DIR/.backup_status.json', 'w') as f:
    json.dump({
        'status': 'SUCCESS',
        'action': 'restore',
        'started_at': datetime.datetime.now().isoformat(),
        'estimated_seconds': 0,
        'progress': 100,
        'message': 'بازیابی جامع اطلاعات با موفقیت تکمیل شد.'
    }, f, indent=2)
" 2>/dev/null || true

docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d proxy >/dev/null

echo "================================================================================"
echo "[SUCCESS] Full Instance Disaster Recovery Completed Successfully!"
echo "  - Restored From: $TARGET"
echo "  - Total Users:   $RESTORED_USERS"
echo "  - Document Meta: $RESTORED_DOCS"
echo "  - Proxy Status:  ONLINE"
echo "================================================================================"
