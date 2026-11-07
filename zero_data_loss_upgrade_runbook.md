# Enterprise Archive System — Zero-Data-Loss Upgrade Runbook (v2.1)

**Target Environment:** Docker-based Nextcloud Stack (Nextcloud, PostgreSQL 15, Nginx Proxy) + AI-Box Subsystem  
**Target Architecture:** WSL / Ubuntu / Production Linux  
**Principle:** Single-step execution with mandatory verification before proceeding. Zero data loss tolerance.

---

## Pre-requisites & Pre-flight Checklist
- Root / sudo / docker group privileges available for user `alborz`.
- Working directories:
  - Archive stack: `/home/alborz/enterprise-archive-system`
  - AI engine: `/home/alborz/ai-box`
- Dedicated backup directory with write access: `/home/alborz/backups/`

---

## Phase 1: Pre-Upgrade Binary Backup

### Step 1: Enable Maintenance Mode
Isolate the application layer to freeze user transactions and guarantee zero concurrent data mutations.
```bash
docker exec -u www-data archive_app php occ maintenance:mode --on
```
*Verification:*
- Terminal outputs: `Maintenance mode enabled`.

### Step 2: Binary Database Dump
Generate a timestamped custom PostgreSQL binary dump.
```bash
mkdir -p /home/alborz/backups
docker exec archive_db pg_dump -U nextcloud_user -d nextcloud -Fc > /home/alborz/backups/backup_pre_upgrade_$(date +%Y%m%d_%H%M%S).dump
```
*Verification:*
```bash
ls -lh /home/alborz/backups/
```
- Confirm the generated file is non-empty (expected size > 500 KB) and uncorrupted.

---

## Phase 2: Code Pull & Workspace Sync

### Step 3: Fetch & Synchronize Release Baseline
Ensure code repositories are aligned with the designated production release tag/branch.
```bash
cd /home/alborz/enterprise-archive-system
git status
git pull origin main
```
*Verification:*
- Clean working directory with no untracked or conflicting files.

---

## Phase 3: Schema Migrations & Safe DDL

### Step 4: Core Framework Migration
Trigger the internal Nextcloud database schema and application migrations.
```bash
docker exec -u www-data archive_app php occ upgrade
```
*Verification:*
- Command returns `No upgrade required` or successfully lists completed upgrade steps.

### Step 5: Standard Indices and Columns Reconciliation
Ensure database tables adhere to the expected Nextcloud schema definition.
```bash
docker exec -u www-data archive_app php occ db:add-missing-indices
docker exec -u www-data archive_app php occ db:add-missing-columns
```
*Verification:*
- Commands complete with zero errors (empty output or `Done.`).

### Step 6: Idempotent Custom DDL Execution (Safe DDL)
Safely apply custom columns to existing tables without raising duplicate column errors.
```bash
docker exec -i archive_db psql -U nextcloud_user -d nextcloud << 'EOF'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name='oc_archive_document_metadata' AND column_name='ai_processed_version'
    ) THEN
        ALTER TABLE oc_archive_document_metadata ADD COLUMN ai_processed_version VARCHAR(64) DEFAULT '1.0';
    END IF;
END $$;
EOF
```
*Verification:*
- PostgreSQL returns: `DO`.

---

## Phase 4: Container & Service Reconciliation

### Step 7: Restart Enterprise Archive Stack
Apply changes and bring up the container stack while pruning orphaned containers.
```bash
cd /home/alborz/enterprise-archive-system
docker compose up -d --remove-orphans
docker compose ps
```
*Verification:*
- `archive_app`, `archive_db`, and `archive_proxy` are all in `Up` status.

### Step 8: Reconcile AI Subsystem Services
Start or reload the AI-Box components.
```bash
cd /home/alborz/ai-box
docker compose up -d
docker compose ps
```
*Verification:*
- Subsystem containers (including `ai_box_gateway`) are in `Up` status.
*(Note: If deployed on standard bare-metal systemd Linux, verify `systemctl status archive-integration-layer.service`)*.

---

## Phase 5: Health Validation & Maintenance Exit

### Step 9: App Verification & Full Filesystem Rescan
Confirm custom app states and synchronize metadata across existing user storage.
```bash
docker exec -u www-data archive_app php occ app:list | grep archive_autotag
docker exec -u www-data archive_app php occ files:scan --all
```
*Verification:*
- `archive_autotag` shows as `Enabled` (e.g., version 2.2.6).
- `files:scan` summary finishes reporting `Errors: 0`.

### Step 10: Disable Maintenance Mode
Restore end-user web and API traffic.
```bash
docker exec -u www-data archive_app php occ maintenance:mode --off
```
*Verification:*
- Output displays: `Maintenance mode disabled`.

---

## Phase 6: Post-Upgrade Sanity Checks

### Check 1: Web Tier Gateway
Verify the HTTP reverse proxy routes correctly.
```bash
curl -I http://localhost
```
*Verification:*
- Response code is `HTTP/1.1 200 OK` or `HTTP/1.1 302 Found`.

### Check 2: AI Subsystem Streaming & Gateway Logs
Inspect real-time gateway authentication and event listeners.
```bash
docker logs --tail 30 ai_box_gateway
```
*Verification:*
- Logs confirm active session ACL sync (`[SESSION ACL SYNC]`) and `200 OK` responses without crash loops.

### Check 3: Database Column Population
Verify persistence of the new column and data integrity.
```bash
docker exec -i archive_db psql -U nextcloud_user -d nextcloud -c "SELECT id, file_id, ai_processed_version FROM oc_archive_document_metadata ORDER BY id DESC LIMIT 3;"
```
*Verification:*
- Rows display valid IDs, file references, and `ai_processed_version = '1.0'`.

---

## Emergency Rollback Plan

If unresolvable errors or application crashes occur during Phases 3 or 4:

1. **Re-engage Maintenance Mode:**
   ```bash
   docker exec -u www-data archive_app php occ maintenance:mode --on
   ```
2. **Stop Core Services:**
   ```bash
   cd /home/alborz/enterprise-archive-system && docker compose down
   ```
3. **Restore PostgreSQL Binary Dump:**
   ```bash
   docker compose up -d db
   docker exec -i archive_db pg_restore -U nextcloud_user -d nextcloud --clean --if-exists < /home/alborz/backups/backup_pre_upgrade_<TIMESTAMP>.dump
   ```
4. **Revert Git Working Tree:**
   ```bash
   git reset --hard HEAD@{1}
   ```
5. **Restart Stack and Disable Maintenance:**
   ```bash
   docker compose up -d
   docker exec -u www-data archive_app php occ maintenance:mode --off
   ```
