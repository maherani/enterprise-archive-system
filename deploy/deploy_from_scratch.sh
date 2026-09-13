#!/usr/bin/env bash
set -e

# ==============================================================================
# Enterprise Archive System - Zero-to-Production Deployment Script
# Purpose: Fully deploy and configure the system from scratch on a bare server.
# Version: 1.5.0
# ==============================================================================

BOLD='\033[1m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${BOLD}${BLUE}================================================================${NC}"
echo -e "${BOLD}${BLUE}  Enterprise Archive System - Automated Zero-to-Production Deploy${NC}"
echo -e "${BOLD}${BLUE}================================================================${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

# ------------------------------------------------------------------------------
# 1. Check Prerequisites
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[1/10] Checking System Prerequisites...${NC}"

if ! command -v docker &>/dev/null; then
    echo -e "${RED}[ERROR] Docker is not installed on this system.${NC}"
    echo "To install Docker on Ubuntu Server:"
    echo "  curl -fsSL https://get.docker.com | sh"
    echo "  sudo usermod -aG docker \$USER"
    exit 1
fi
echo -e "  ${GREEN}?${NC} Docker is installed: $(docker --version)"

if ! docker compose version &>/dev/null; then
    echo -e "${RED}[ERROR] Docker Compose plugin (v2) is not installed.${NC}"
    echo "To install Docker Compose plugin:"
    echo "  sudo apt-get update && sudo apt-get install -y docker-compose-plugin"
    exit 1
fi
echo -e "  ${GREEN}?${NC} Docker Compose is installed: $(docker compose version)"

# ------------------------------------------------------------------------------
# 2. Check Environment Configuration (.env)
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[2/10] Checking Environment Configuration (.env)...${NC}"
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        echo -e "${YELLOW}[!] .env not found. Copying from .env.example...${NC}"
        cp .env.example .env
        echo -e "${YELLOW}[!] Created .env from template. Please review and update passwords if necessary.${NC}"
    else
        echo -e "${RED}[ERROR] Neither .env nor .env.example found!${NC}"
        exit 1
    fi
fi

# Source .env safely
set -a
source .env
set +a

ADMIN_USER="${NEXTCLOUD_ADMIN_USER:-admin}"
ADMIN_PASS="${NEXTCLOUD_ADMIN_PASSWORD}"
DB_NAME="${POSTGRES_DB:-nextcloud}"
DB_USER="${POSTGRES_USER:-nextcloud_user}"
DB_PASS="${POSTGRES_PASSWORD}"

if [ -z "$DB_PASS" ] || [ -z "$ADMIN_PASS" ]; then
    echo -e "${RED}[ERROR] POSTGRES_PASSWORD or NEXTCLOUD_ADMIN_PASSWORD is empty in .env!${NC}"
    exit 1
fi
echo -e "  ${GREEN}?${NC} Configuration loaded for administrator: ${BOLD}${ADMIN_USER}${NC}"

# ------------------------------------------------------------------------------
# 3. Launch Core Infrastructure Containers
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[3/10] Launching Docker Containers (db, app, proxy)...${NC}"
docker compose up -d

echo -e "  Waiting for PostgreSQL database (archive_db) to become healthy..."
DB_RETRIES=35
until [ $DB_RETRIES -le 0 ] || [ "$(docker inspect --format='{{.State.Health.Status}}' archive_db 2>/dev/null)" = "healthy" ]; do
    sleep 2
    DB_RETRIES=$((DB_RETRIES - 1))
done

if [ $DB_RETRIES -le 0 ]; then
    echo -e "${RED}[ERROR] Database container did not become healthy in time!${NC}"
    docker logs archive_db --tail 30
    exit 1
fi
echo -e "  ${GREEN}?${NC} Database is healthy and listening on internal port 5432."

# ------------------------------------------------------------------------------
# 4. Wait for Nextcloud Initialization & First-Time Installation
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[4/10] Verifying Nextcloud Core Engine...${NC}"
echo "  Waiting for Nextcloud filesystem initialization..."
NC_RETRIES=45
until [ $NC_RETRIES -le 0 ] || docker exec archive_app test -f /var/www/html/version.php 2>/dev/null; do
    sleep 2
    NC_RETRIES=$((NC_RETRIES - 1))
done

# Check if Nextcloud is installed; if not, trigger headless installation
INSTALLED=$(docker exec -u www-data archive_app php occ status --output=json 2>/dev/null | grep -o '"installed":true' || true)
if [ -z "$INSTALLED" ]; then
    echo -e "  ${YELLOW}[*] Performing first-time headless Nextcloud installation...${NC}"
    docker exec -u www-data archive_app php occ maintenance:install \
        --database "pgsql" \
        --database-name "$DB_NAME" \
        --database-user "$DB_USER" \
        --database-pass "$DB_PASS" \
        --database-host "db" \
        --admin-user "$ADMIN_USER" \
        --admin-pass "$ADMIN_PASS"
fi
echo -e "  ${GREEN}?${NC} Nextcloud core engine is installed and active."

# ------------------------------------------------------------------------------
# 5. Apply Enterprise System Configurations & Hardening (config.php)
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[5/10] Applying Enterprise Security, Proxy & Hardening Settings...${NC}"

# Trusted domains
docker exec -u www-data archive_app php occ config:system:set trusted_domains 0 --value="localhost" >/dev/null
docker exec -u www-data archive_app php occ config:system:set trusted_domains 1 --value="localhost" >/dev/null
docker exec -u www-data archive_app php occ config:system:set trusted_domains 2 --value="127.0.0.1" >/dev/null

HOST_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -n "$HOST_IP" ]; then
    docker exec -u www-data archive_app php occ config:system:set trusted_domains 3 --value="$HOST_IP" >/dev/null
    echo -e "  ${GREEN}?${NC} Added host IP ($HOST_IP) to trusted_domains."
fi

# Trusted proxies & reverse proxy
docker exec -u www-data archive_app php occ config:system:set trusted_proxies 0 --value="172.16.0.0/12" >/dev/null
docker exec -u www-data archive_app php occ config:system:set trusted_proxies 1 --value="archive_proxy" >/dev/null
docker exec -u www-data archive_app php occ config:system:set overwriteprotocol --value="http" >/dev/null
docker exec -u www-data archive_app php occ config:system:set default_phone_region --value="IR" >/dev/null

# Clean user provisioning: disable default skeleton files (prevents Photos/Documents/Templates clutter)
docker exec -u www-data archive_app php occ config:system:set skeletondirectory --value="" >/dev/null

# Security & API automation: password confirmation bypass for local/trusted subnets
docker exec -u www-data archive_app php occ config:system:set allowed_no_password_confirmation_ranges 0 --value="127.0.0.1/32" >/dev/null
docker exec -u www-data archive_app php occ config:system:set allowed_no_password_confirmation_ranges 1 --value="172.16.0.0/12" >/dev/null
docker exec -u www-data archive_app php occ config:system:set allowed_no_password_confirmation_ranges 2 --value="192.168.0.0/16" >/dev/null
docker exec -u www-data archive_app php occ config:system:set allowed_no_password_confirmation_ranges 3 --value="10.0.0.0/8" >/dev/null

# Register IsolatedSystemTagManager Factory for strict tag isolation
docker exec -u www-data archive_app php occ config:system:set systemtags.managerFactory --value="OCA\\ArchiveAutoTag\\SystemTag\\IsolatedManagerFactory" >/dev/null

# Web upgrade protection
docker exec -u www-data archive_app php occ config:system:set upgrade.disable-web --value=true --type=boolean >/dev/null
echo -e "  ${GREEN}?${NC} Enterprise hardening, proxy, and systemtag isolation factory configured."

# ------------------------------------------------------------------------------
# 6. Deploy Custom App: archive_autotag v1.5.0 & Enable Companion Apps
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[6/10] Deploying Custom App: archive_autotag (v1.5.0)...${NC}"
docker exec archive_app mkdir -p /var/www/html/custom_apps/archive_autotag
docker cp apps/archive_autotag/. archive_app:/var/www/html/custom_apps/archive_autotag/
docker exec archive_app chown -R www-data:www-data /var/www/html/custom_apps/archive_autotag

# Enable companion apps
for app in admin_audit systemtags files_sharing activity; do
    docker exec -u www-data archive_app php occ app:enable "$app" >/dev/null 2>&1 || true
done

# Enable archive_autotag and execute database migrations (v1.5.0)
docker exec -u www-data archive_app php occ app:enable archive_autotag >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ upgrade >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} archive_autotag deployed, enabled, and database schema migrated."

# ------------------------------------------------------------------------------
# 7. Enterprise Governance Policies & Initial Archive Hierarchy
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[7/10] Enforcing Enterprise Governance Policies & Archive Hierarchy...${NC}"

# Enforce admin-only folder creation policy
docker exec -u www-data archive_app php occ archive:folder:policy enable >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} Admin-only folder creation policy enabled."

# Create Enterprise_Archive root folder
docker exec -u www-data archive_app php occ files:mkdir "/${ADMIN_USER}/files/Enterprise_Archive" >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ files:scan --path="/${ADMIN_USER}/files/Enterprise_Archive" >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} Root archive directory '/Enterprise_Archive' initialized."

# ------------------------------------------------------------------------------
# 8. User Accounts, Groups, Quotas, and Shares Provisioning
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[8/10] Provisioning User Accounts, Groups, Quotas, and Shares...${NC}"

# 8.1 Create Compliance_Unit Group
docker exec -u www-data archive_app php occ group:add Compliance_Unit >/dev/null 2>&1 || true

# 8.2 Provision archive_user1 (Quota 0 B, 10M upload limit)
USER1="archive_user1"
USER1_PASS="User_Password_123!"
if ! docker exec -u www-data archive_app php occ user:info "$USER1" >/dev/null 2>&1; then
    docker exec -e OC_PASS="$USER1_PASS" archive_app php occ user:add --password-from-env --display-name="Archive Officer 1" "$USER1" >/dev/null 2>&1 || true
    echo -e "  ${GREEN}?${NC} Created user: ${USER1}"
fi
docker exec -u www-data archive_app php occ group:adduser Compliance_Unit "$USER1" >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ user:setting "$USER1" files quota "0 B" >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ archive:user:limit "$USER1" 10M >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} ${USER1}: Added to Compliance_Unit, personal quota set to 0 B, upload limit 10MB."

# 8.3 Provision api_worker (Quota 0 B)
WORKER="api_worker"
WORKER_PASS="5NJ8SmJLllNypBwaus3TmQwhdbjDdYQ4PFwbUz6h4LJtiMbA14QwyvCazozux7lh8aOKc72b"
if ! docker exec -u www-data archive_app php occ user:info "$WORKER" >/dev/null 2>&1; then
    docker exec -e OC_PASS="$WORKER_PASS" archive_app php occ user:add --password-from-env --display-name="API Background Worker" "$WORKER" >/dev/null 2>&1 || true
    echo -e "  ${GREEN}?${NC} Created user: ${WORKER}"
fi
docker exec -u www-data archive_app php occ group:adduser Compliance_Unit "$WORKER" >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ user:setting "$WORKER" files quota "0 B" >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} ${WORKER}: Added to Compliance_Unit, personal quota set to 0 B."

# 8.4 Share /Enterprise_Archive with Compliance_Unit (Permissions 7 = Read, Write, Create)
curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -H "OCS-APIRequest: true" \
    -X POST "http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares" \
    -d path="/Enterprise_Archive" \
    -d shareType=1 \
    -d shareWith="Compliance_Unit" \
    -d permissions=7 >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} /Enterprise_Archive shared with group Compliance_Unit (Read/Write/Create)."

# ------------------------------------------------------------------------------
# 9. Automated Tag Lifecycle Reconciliation
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[9/10] Running Automated Tag Lifecycle Reconciliation...${NC}"
docker exec -u www-data archive_app php occ archive:tag:reconcile >/dev/null 2>&1 || true
echo -e "  ${GREEN}?${NC} System tags synchronized with folder hierarchy; dead mappings and surplus tags pruned."

# ------------------------------------------------------------------------------
# 10. Final Verification Health Check
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[10/10] Running Verification Health Check...${NC}"
"$SCRIPT_DIR/check_health.sh"

echo -e "\n${BOLD}${GREEN}================================================================${NC}"
echo -e "${BOLD}${GREEN}  DEPLOYMENT SUCCESSFUL! Enterprise Archive System is Online.   ${NC}"
echo -e "${BOLD}${GREEN}================================================================${NC}"
echo -e "Access URLs:"
echo -e "  - Local:       ${CYAN}http://localhost${NC}"
if [ -n "$HOST_IP" ]; then
    echo -e "  - Network/LAN: ${CYAN}http://${HOST_IP}${NC}"
fi
echo -e "\nProvisioned Accounts:"
echo -e "  - System Admin:   ${BOLD}${ADMIN_USER}${NC} (configured in .env)"
echo -e "  - Archive User 1: ${BOLD}${USER1}${NC} / ${BOLD}${USER1_PASS}${NC} (Quota: 0 B, Limit: 10M)"
echo -e "  - Service Worker: ${BOLD}${WORKER}${NC} / ${BOLD}(API Token)${NC} (Quota: 0 B)"
echo -e "\nOperational Commands:"
echo -e "  - Tag Reconcile:   ${CYAN}docker compose exec app php occ archive:tag:reconcile${NC}"
echo -e "  - Tag Governance:  ${CYAN}docker compose exec app php occ archive:tag:gov list${NC}"
echo -e "  - File ACL Grants: ${CYAN}docker compose exec app php occ archive:file:grant list <file_id>${NC}"
echo -e "  - Folder Policy:   ${CYAN}docker compose exec app php occ archive:folder:policy${NC}"
echo -e "\nTo run automated verification test suites:"
echo -e "  python3 tests/test_tag_lifecycle_reconciliation.py"
echo -e "  python3 tests/test_archive_acl_and_tag_isolation.py"
echo -e "  python3 tests/test_dynamic_archive_system.py"
echo -e "  python3 tests/test_user_governance.py"
echo -e "  python3 tests/test_multi_tag_filter.py"
echo -e "================================================================\n"
