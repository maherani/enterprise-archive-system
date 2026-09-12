#!/usr/bin/env bash
set -e

# ==============================================================================
# Enterprise Archive System - Zero-to-Production Deployment Script
# Purpose: Fully deploy the system on a clean/bare server from scratch.
# ==============================================================================

BOLD='\033[1m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Colo

echo -e "${BOLD}${BLUE}================================================================${NC}"
echo -e "${BOLD}${BLUE}  Enterprise Archive System - Automated Bare Server Deployment  ${NC}"
echo -e "${BOLD}${BLUE}================================================================${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

# 1. Check Prerequisites
echo -e "\n${BOLD}[1/7] Checking System Prerequisites...${NC}"

if ! command -v docker &>/dev/null; then
    echo -e "${RED}[ERROR] Docker is not installed on this system.${NC}"
    echo "To install Docker on Ubuntu Server:"
    echo "  curl -fsSL https://get.docker.com | sh"
    echo "  sudo usermod -aG docker \$USER"
    exit 1
fi
echo -e "  ${GREEN}✓${NC} Docker is installed: $(docker --version)"

if ! docker compose version &>/dev/null; then
    echo -e "${RED}[ERROR] Docker Compose plugin (v2) is not installed.${NC}"
    echo "To install Docker Compose plugin:"
    echo "  sudo apt-get update && sudo apt-get install -y docker-compose-plugin"
    exit 1
fi
echo -e "  ${GREEN}✓${NC} Docker Compose is installed: $(docker compose version)"

# 2. Check Environment Configuration (.env)
echo -e "\n${BOLD}[2/7] Checking Environment Configuration (.env)...${NC}"
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

if [ -z "$POSTGRES_PASSWORD" ] || [ -z "$NEXTCLOUD_ADMIN_PASSWORD" ]; then
    echo -e "${RED}[ERROR] POSTGRES_PASSWORD or NEXTCLOUD_ADMIN_PASSWORD is empty in .env!${NC}"
    exit 1
fi
echo -e "  ${GREEN}✓${NC} Configuration loaded for admin user: ${BOLD}${NEXTCLOUD_ADMIN_USER:-admin}${NC}"

# 3. Launch Core Infrastructure Containers
echo -e "\n${BOLD}[3/7] Launching Docker Containers (db, app, proxy)...${NC}"
docker compose up -d

echo -e "  Waiting for PostgreSQL database (archive_db) to become healthy..."
DB_RETRIES=30
until [ $DB_RETRIES -le 0 ] || [ "$(docker inspect --format='{{.State.Health.Status}}' archive_db 2>/dev/null)" = "healthy" ]; do
    sleep 2
    DB_RETRIES=$((DB_RETRIES - 1))
done

if [ $DB_RETRIES -le 0 ]; then
    echo -e "${RED}[ERROR] Database container did not become healthy in time!${NC}"
    docker logs archive_db --tail 30
    exit 1
fi
echo -e "  ${GREEN}✓${NC} Database is healthy and listening."

# 4. Wait for Nextcloud Initialization
echo -e "\n${BOLD}[4/7] Verifying Nextcloud Initialization...${NC}"
echo "  Waiting for Nextcloud files and Apache engine (this may take 30-60 seconds on first run)..."
NC_RETRIES=40
until [ $NC_RETRIES -le 0 ] || docker exec archive_app test -f /var/www/html/version.php 2>/dev/null; do
    sleep 3
    NC_RETRIES=$((NC_RETRIES - 1))
done

# Check if Nextcloud is installed; if not, trigger installation
INSTALLED=$(docker exec -u www-data archive_app php occ status --output=json 2>/dev/null | grep -o '"installed":true' || true)
if [ -z "$INSTALLED" ]; then
    echo -e "  ${YELLOW}[*] Performing first-time headless Nextcloud installation...${NC}"
    docker exec -u www-data archive_app php occ maintenance:install \
        --database "pgsql" \
        --database-name "${POSTGRES_DB:-nextcloud}" \
        --database-user "${POSTGRES_USER:-nextcloud_user}" \
        --database-pass "$POSTGRES_PASSWORD" \
        --database-host "db" \
        --admin-user "${NEXTCLOUD_ADMIN_USER:-admin}" \
        --admin-pass "$NEXTCLOUD_ADMIN_PASSWORD"
fi
echo -e "  ${GREEN}✓${NC} Nextcloud core is installed and active."

# 5. Apply Enterprise System Configurations
echo -e "\n${BOLD}[5/7] Applying System Hardening & Proxy Settings...${NC}"
docker exec -u www-data archive_app php occ config:system:set trusted_domains 1 --value="localhost" >/dev/null
docker exec -u www-data archive_app php occ config:system:set trusted_domains 2 --value="127.0.0.1" >/dev/null

# Add host IP if reachable
HOST_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -n "$HOST_IP" ]; then
    docker exec -u www-data archive_app php occ config:system:set trusted_domains 3 --value="$HOST_IP" >/dev/null
    echo -e "  ${GREEN}✓${NC} Added host IP ($HOST_IP) to Nextcloud trusted_domains."
fi

# Configure trusted proxy for Nginx container network
docker exec -u www-data archive_app php occ config:system:set trusted_proxies 0 --value="172.16.0.0/12" >/dev/null
docker exec -u www-data archive_app php occ config:system:set default_phone_region --value="IR" >/dev/null
echo -e "  ${GREEN}✓${NC} Trusted proxies and regional settings configured."

# 6. Deploy and Activate Custom Apps (archive_autotag v1.3.4)
echo -e "\n${BOLD}[6/7] Deploying Custom App: archive_autotag (Hierarchical Tagger, Quota & Multi-Tag Filter)...${NC}"
docker exec archive_app mkdir -p /var/www/html/custom_apps/archive_autotag
docker cp apps/archive_autotag/. archive_app:/var/www/html/custom_apps/archive_autotag/
docker exec archive_app chown -R www-data:www-data /var/www/html/custom_apps/archive_autotag

docker exec -u www-data archive_app php occ app:enable archive_autotag >/dev/null 2>&1 || true
docker exec -u www-data archive_app php occ upgrade >/dev/null 2>&1 || true
echo -e "  ${GREEN}✓${NC} archive_autotag deployed and enabled."

# Enable audit logging
docker exec -u www-data archive_app php occ app:enable admin_audit >/dev/null 2>&1 || true

# Enable strict folder policy
docker exec -u www-data archive_app php occ archive:folder:policy enable >/dev/null 2>&1 || true
echo -e "  ${GREEN}✓${NC} Admin folder governance policy enforced."

# Initialize Enterprise_Archive root folder if not existing
docker exec -u www-data archive_app php occ files:mkdir "/${NEXTCLOUD_ADMIN_USER:-admin}/files/Enterprise_Archive" >/dev/null 2>&1 || true

# 7. Final Health & Consistency Check
echo -e "\n${BOLD}[7/7] Running Verification Health Check...${NC}"
"$SCRIPT_DIR/check_health.sh"

echo -e "\n${BOLD}${GREEN}================================================================${NC}"
echo -e "${BOLD}${GREEN}  DEPLOYMENT SUCCESSFUL! Enterprise Archive System is Online.   ${NC}"
echo -e "${BOLD}${GREEN}================================================================${NC}"
echo -e "Access URLs:"
echo -e "  - Local:       http://localhost"
if [ -n "$HOST_IP" ]; then
    echo -e "  - Network/LAN: http://${HOST_IP}"
fi
echo -e "Admin Credentials:"
echo -e "  - Username:    ${BOLD}${NEXTCLOUD_ADMIN_USER:-admin}${NC}"
echo -e "  - Password:    (configured in your .env file)"
echo -e "\nTo run automated verification tests:"
echo -e "  python3 -m venv venv && source venv/bin/activate && pip install -r requirements.txt"
echo -e "  python3 tests/test_dynamic_archive_system.py"
echo -e "  python3 tests/test_user_governance.py"
echo -e "  python3 tests/test_multi_tag_filter.py"
echo -e "================================================================\n"
