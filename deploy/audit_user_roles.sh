#!/usr/bin/env bash
set -e

echo "=========================================================="
echo " Enterprise Archive System - User & Role Governance Audit"
echo "=========================================================="

APP_CONTAINER="${APP_CONTAINER:-archive_app}"
DB_CONTAINER="${DB_CONTAINER:-archive_db}"

# Check if containers are running
if ! docker inspect -f '{{.State.Running}}' "$APP_CONTAINER" 2>/dev/null | grep -q "true"; then
    echo "[ERROR] Container '$APP_CONTAINER' is not running."
    exit 1
fi

echo ""
echo "[1] System Administrators (Full Authority - admin group):"
echo "----------------------------------------------------------"
docker exec "$DB_CONTAINER" psql -U nextcloud_user -d nextcloud -t -A -c \
    "SELECT uid FROM oc_group_user WHERE gid = 'admin';" | while read -r admin_user; do
    if [ -n "$admin_user" ]; then
        echo "  👑 System Admin: $admin_user"
    fi
done

echo ""
echo "[2] Group Administrators (Subadmins - can modify their own group members only):"
echo "----------------------------------------------------------"
subadmin_count=$(docker exec "$DB_CONTAINER" psql -U nextcloud_user -d nextcloud -t -A -c \
    "SELECT count(*) FROM oc_group_admin;")

if [ "$subadmin_count" -eq 0 ]; then
    echo "  (No group administrators currently configured)"
else
    docker exec "$DB_CONTAINER" psql -U nextcloud_user -d nextcloud -t -A -c \
        "SELECT uid, gid FROM oc_group_admin;" | while IFS='|' read -r uid gid; do
        if [ -n "$uid" ]; then
            echo "  🛡️ Group Admin: $uid  -->  Group: $gid"
        fi
    done
fi

echo ""
echo "[3] Regular Users & Group Memberships:"
echo "----------------------------------------------------------"
docker exec "$DB_CONTAINER" psql -U nextcloud_user -d nextcloud -t -A -c \
    "SELECT u.uid, COALESCE(string_agg(g.gid, ', '), 'NO_GROUP') FROM oc_users u LEFT JOIN oc_group_user g ON u.uid = g.uid GROUP BY u.uid ORDER BY u.uid;" | while IFS='|' read -r uid groups; do
    
    is_admin=false
    if echo "$groups" | grep -qw "admin"; then
        is_admin=true
    fi
    
    if [ "$is_admin" = true ] && [ "$uid" != "admin" ]; then
        echo "  ⚠️  WARNING: User '$uid' has ADMIN privileges! Groups: [$groups]"
        echo "      -> To revoke admin: docker compose exec app php occ group:removeuser admin $uid"
    elif [ "$is_admin" = true ]; then
        echo "  ✓  Admin Root Account: $uid [admin]"
    else
        echo "  ✓  Standard User: $uid (Groups: [$groups])"
    fi
done

echo ""
echo "=========================================================="
echo " Role Enforcement Quick Reference:"
echo "----------------------------------------------------------"
echo " 1. Revoke admin from a user:"
echo "    docker compose exec app php occ group:removeuser admin <username>"
echo ""
echo " 2. Assign user as Group Admin (Subadmin for specific group):"
echo "    docker exec archive_db psql -U nextcloud_user -d nextcloud -c \\"
echo "      \"INSERT INTO oc_group_admin (gid, uid) VALUES ('<group>', '<username>') ON CONFLICT DO NOTHING;\""
echo ""
echo " 3. Revoke Group Admin role:"
echo "    docker exec archive_db psql -U nextcloud_user -d nextcloud -c \\"
echo "      \"DELETE FROM oc_group_admin WHERE gid = '<group>' AND uid = '<username>';\""
echo "=========================================================="
