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

# Load project environment. Values with spaces (for example trusted domains)
# must be quoted in .env.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

TARGET="${1:-$BACKUP_DIR/latest_nextcloud_backup.tar.gz}"

if [ ! -f "$TARGET" ]; then
    echo "[ERROR] Full backup not found: $TARGET"
    exit 1
fi

if ! tar -tzf "$TARGET" >/dev/null 2>&1; then
    echo "[ERROR] Backup archive is invalid or corrupted: $TARGET"
    exit 1
fi

EXPECTED_SHA_FILE="$TARGET.sha256"
if [ -f "$EXPECTED_SHA_FILE" ]; then
    echo "[INFO] Verifying backup checksum..."
    (
        cd "$(dirname "$TARGET")"
        sha256sum -c "$(basename "$EXPECTED_SHA_FILE")"
    )
fi

TMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

tar -xzf "$TARGET" -C "$TMP_DIR"

BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)
if [ -z "${BACKUP_ROOT:-}" ]; then
    echo "[ERROR] Backup archive has no root directory."
    exit 1
fi

DB_DUMP="$BACKUP_ROOT/database.sql"
DATA_ARCHIVE="$BACKUP_ROOT/data.tar.gz"
CONFIG_ARCHIVE="$BACKUP_ROOT/config.tar.gz"
CUSTOM_APPS_ARCHIVE="$BACKUP_ROOT/custom_apps.tar.gz"

for required in "$DB_DUMP" "$DATA_ARCHIVE" "$CONFIG_ARCHIVE" "$CUSTOM_APPS_ARCHIVE"; do
    if [ ! -s "$required" ]; then
        echo "[ERROR] Backup component missing or empty: $required"
        exit 1
    fi
done

for container in archive_db archive_app; do
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "[ERROR] Container $container does not exist."
        exit 1
    fi
done

echo "[WARNING] This will replace the current Nextcloud database, data, config, and custom apps."
echo "[INFO] Backup source: $TARGET"
echo "[INFO] Database: $POSTGRES_DB (owner: $POSTGRES_USER)"

# Stop application/proxy so no application process can write during restore.
docker compose -f "$PROJECT_DIR/docker-compose.yml" stop app proxy >/dev/null

# Keep PostgreSQL running for database administration.
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d db >/dev/null

# Wait for PostgreSQL readiness.
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

echo "[INFO] Terminating active database connections..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB' AND pid <> pg_backend_pid();" >/dev/null

echo "[INFO] Dropping and recreating database '$POSTGRES_DB'..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS \"$POSTGRES_DB\";"
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres \
    -c "CREATE DATABASE \"$POSTGRES_DB\" OWNER \"$POSTGRES_USER\";"

# Local container authentication may allow the administrative psql command
# without a password. Explicitly synchronize the role password before starting
# Nextcloud so TCP authentication matches the current .env configuration.
echo "[INFO] Synchronizing PostgreSQL role password with .env..."
docker exec archive_db psql -U "$POSTGRES_USER" -d postgres <<SQL
ALTER ROLE "$POSTGRES_USER" PASSWORD '$POSTGRES_PASSWORD';
SQL

echo "[INFO] Importing database dump..."
docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$DB_DUMP"

# Use a temporary Compose container with the same bind mount to manipulate
# protected Nextcloud paths while archive_app itself is stopped.
echo "[INFO] Replacing file-backed Nextcloud state..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps --entrypoint sh app -c \
    'rm -rf /var/www/html/data /var/www/html/config /var/www/html/custom_apps && mkdir -p /var/www/html/data /var/www/html/config /var/www/html/custom_apps' >/dev/null

echo "[INFO] Restoring Nextcloud data..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
    -xzf - -C /var/www/html < "$DATA_ARCHIVE"

echo "[INFO] Restoring Nextcloud config..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
    -xzf - -C /var/www/html < "$CONFIG_ARCHIVE"
echo "[INFO] Restoring custom apps..."
docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps -T --entrypoint tar app \
    -xzf - -C /var/www/html < "$CUSTOM_APPS_ARCHIVE"

if ! docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm --no-deps --entrypoint sh app -c \
    'test -f /var/www/html/config/config.php && test -d /var/www/html/data && test -d /var/www/html/custom_apps'; then
    echo "[ERROR] Restored Nextcloud filesystem state is incomplete."
    exit 1
fi

# The restored config.php can contain the database password from the backup.
# Replace only the dbpassword value before the first occ command so Nextcloud
# can connect to the restored database using the current .env credentials.
echo "[INFO] Aligning restored Nextcloud database config with .env..."
DB_PASSWORD_B64="$(printf '%s' "$POSTGRES_PASSWORD" | base64 -w 0)"

docker exec -i \
    -e "RESTORE_DB_PASSWORD_B64=$DB_PASSWORD_B64" \
    archive_app php <<'PHP'
<?php
$path = "/var/www/html/config/config.php";

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Unable to read config.php\n");
    exit(1);
}

$password = base64_decode(getenv("RESTORE_DB_PASSWORD_B64"), true);
if ($password === false) {
    fwrite(STDERR, "Unable to decode database password\n");
    exit(1);
}

$pattern = "/^(\\s*'dbpassword'\\s*=>\\s*')[^']*(',?\\s*)$/m";

$updated = preg_replace_callback(
    $pattern,
    static function (array $m) use ($password): string {
        return $m[1] . addcslashes($password, "\\'") . $m[2];
    },
    $content,
    1,
    $count
);

if ($updated === null || $count !== 1) {
    fwrite(STDERR, "Unable to update dbpassword in config.php\n");
    exit(1);
}

if (file_put_contents($path, $updated) === false) {
    fwrite(STDERR, "Unable to write config.php\n");
    exit(1);
}
PHP

unset DB_PASSWORD_B64

# Start the application against the restored database/filesystem.
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d app >/dev/null

# Ensure file ownership is compatible with Nextcloud.
docker exec archive_app chown -R www-data:www-data /var/www/html/data /var/www/html/config /var/www/html/custom_apps

# Re-apply the current environment's database connection explicitly so the
# restored deployment remains aligned with .env.
# The PostgreSQL role password has already been synchronized, so occ can connect successfully now.
docker exec -u www-data archive_app php occ config:system:set dbtype --value="pgsql"
docker exec -u www-data archive_app php occ config:system:set dbhost --value="db"
docker exec -u www-data archive_app php occ config:system:set dbname --value="$POSTGRES_DB"
docker exec -u www-data archive_app php occ config:system:set dbuser --value="$POSTGRES_USER"
docker exec -u www-data archive_app php occ config:system:set dbpassword --value="$POSTGRES_PASSWORD"

# Clear stale file locks if the table exists.
docker exec archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "DO \$\$ BEGIN IF to_regclass('oc_file_locks') IS NOT NULL THEN TRUNCATE TABLE oc_file_locks; END IF; END \$\$;" >/dev/null

echo "[INFO] Rebuilding file cache from restored storage..."
docker exec -u www-data archive_app php occ files:scan --all

# Bring proxy back only after the application and file scan succeed.
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d proxy >/dev/null

echo "[SUCCESS] Full Nextcloud backup successfully restored from $TARGET"
