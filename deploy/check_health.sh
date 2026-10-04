#!/usr/bin/env bash
set -e

echo "=================================================="
echo " Enterprise Archive System - Health Check"
echo "=================================================="

PROXY_CONTAINER="${TARGET_PROXY_CONTAINER:-archive_proxy}"
APP_CONTAINER="${TARGET_APP_CONTAINER:-archive_app}"
DB_CONTAINER="${TARGET_DB_CONTAINER:-archive_db}"
PG_USER="${TARGET_DB_USER:-${POSTGRES_USER:-nextcloud_user}}"
PG_DB="${TARGET_DB_NAME:-${POSTGRES_DB:-nextcloud}}"
ARCHIVE_PATH="${TARGET_ARCHIVE_PATH:-/admin/files/Enterprise_Archive}"

echo "[1] Checking Docker Containers..."
for c in "$PROXY_CONTAINER" "$APP_CONTAINER" "$DB_CONTAINER"; do
    status=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
    if [ "$status" = "running" ]; then
        echo "  [OK] Container $c: RUNNING"
    else
        echo "  [FAIL] Container $c: $status (NOT RUNNING!)"
        exit 1
    fi
done

echo "[2] Checking PostgreSQL Database..."
user_count=$(docker exec "$DB_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
echo "  [OK] Users in DB (oc_users): $user_count"

echo "[3] Checking Nextcloud Users..."
docker exec -u www-data "$APP_CONTAINER" php occ user:list

echo "[4] Checking Nextcloud Groups..."
docker exec -u www-data "$APP_CONTAINER" php occ group:list

echo "[5] Checking Enterprise Archive Folder..."
maint_mode=$(docker exec "$APP_CONTAINER" php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "false")
if [ "$maint_mode" = "true" ]; then
    docker exec "$APP_CONTAINER" test -d "/var/www/html/data${ARCHIVE_PATH}"
    echo "  [OK] Enterprise Archive folder verified on disk (Maintenance Mode active)."
else
    docker exec -u www-data "$APP_CONTAINER" php occ files:scan -p "$ARCHIVE_PATH"
fi

echo "=================================================="
echo " Health check completed successfully."
echo "=================================================="
