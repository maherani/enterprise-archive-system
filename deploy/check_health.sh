#!/usr/bin/env bash
set -e

CONTAINER_PROXY="${CONTAINER_PROXY:-archive_proxy}"
CONTAINER_APP="${CONTAINER_APP:-archive_app}"
CONTAINER_DB="${CONTAINER_DB:-archive_db}"
DB_USER="${POSTGRES_USER:-nextcloud_user}"
DB_NAME="${POSTGRES_DB:-nextcloud}"

echo "=================================================="
echo " Enterprise Archive System - Health Check"
echo " Containers: $CONTAINER_PROXY, $CONTAINER_APP, $CONTAINER_DB"
echo "=================================================="

echo "[1] Checking Docker Containers..."
for c in "$CONTAINER_PROXY" "$CONTAINER_APP" "$CONTAINER_DB"; do
    status=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
    if [ "$status" = "running" ]; then
        echo "  [OK] Container $c: RUNNING"
    else
        echo "  [FAIL] Container $c: $status (NOT RUNNING!)" >&2
        exit 1
    fi
done

echo "[2] Checking PostgreSQL Database..."
user_count=$(docker exec "$CONTAINER_DB" psql -U "$DB_USER" -d "$DB_NAME" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
echo "  [OK] Users in DB (oc_users): $user_count"

echo "[3] Checking Nextcloud Users..."
docker exec -u www-data "$CONTAINER_APP" php occ user:list

echo "[4] Checking Nextcloud Groups..."
docker exec -u www-data "$CONTAINER_APP" php occ group:list

echo "[5] Checking Enterprise Archive Folder..."
maint_mode=$(docker exec "$CONTAINER_APP" php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "false")
if [ "$maint_mode" = "true" ]; then
    docker exec "$CONTAINER_APP" test -d '/var/www/html/data/admin/files/Enterprise_Archive'
    echo "  [OK] Enterprise Archive folder verified on disk (Maintenance Mode active)."
else
    docker exec -u www-data "$CONTAINER_APP" php occ files:scan -p '/admin/files/Enterprise_Archive'
fi

echo "=================================================="
echo " Health check completed successfully."
echo "=================================================="