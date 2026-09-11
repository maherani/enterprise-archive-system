#!/usr/bin/env bash
set -e

echo "=================================================="
echo " Enterprise Archive System - Health Check"
echo "=================================================="

echo "[1] Checking Docker Containers..."
for c in archive_proxy archive_app archive_db; do
    status=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
    if [ "$status" = "running" ]; then
        echo "  [OK] Container $c: RUNNING"
    else
        echo "  [FAIL] Container $c: $status (NOT RUNNING!)"
    fi
done

echo "[2] Checking PostgreSQL Database..."
user_count=$(docker exec archive_db psql -U nextcloud_user -d nextcloud -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
echo "  [OK] Users in DB (oc_users): $user_count"

echo "[3] Checking Nextcloud Users..."
docker exec -u www-data archive_app php occ user:list

echo "[4] Checking Nextcloud Groups..."
docker exec -u www-data archive_app php occ group:list

echo "[5] Checking Enterprise Archive Folder..."
docker exec -u www-data archive_app php occ files:scan -p '/admin/files/Enterprise_Archive'

echo "=================================================="
echo " Health check completed successfully."
echo "=================================================="
