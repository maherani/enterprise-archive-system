# Enterprise Archive System

A secure, scalable, and audit-compliant enterprise document archiving system built on **Nextcloud**, **PostgreSQL**, and **Nginx**.

## Architecture Overview

```text
[ External Clients / AI Agents / API ]
                 │
             HTTP :80
                 ▼
        ┌──────────────────┐
        │  archive_proxy   │  (Nginx Alpine - Reverse Proxy)
        │  (Port 80:80)    │  - Large file upload (10GB)
        └────────┬─────────┘  - Request buffering disabled
                 │  (archive_net bridge)
                 ▼
        ┌──────────────────┐
        │   archive_app    │  (Nextcloud Apache)
        │   (Internal:80)  │  - WebDAV Endpoint: /remote.php/dav/files/
        └────────┬─────────┘  - LDAP & App API Authentication
                 │            - Custom App: archive_autotag (PSR-14 Event Engine)
                 │            - Quota: 0 B (Admin-only Folder Governance)
                 ▼
        ┌──────────────────┐
        │    archive_db    │  (PostgreSQL 15 Alpine)
        │  (Internal:5432) │  - Database: nextcloud
        └──────────────────┘
```

## Core Features & Governance Capabilities

1. **Admin-Only Folder Governance**:
   - Regular users cannot create random personal folders or clutter root storage (Quota set to `0 B`).
   - Admin centrally creates and governs organizational archive structures (e.g. `/Enterprise_Archive/Finance/2026/Invoices`), sharing them with appropriate permissions (Read, Create, Edit, no root deletion).
2. **Dynamic Hierarchical Auto-Tagging (`archive_autotag`)**:
   - Custom native Nextcloud application listening to PSR-14 file lifecycle events (`NodeCreatedEvent`, `NodeWrittenEvent`).
   - Recursively traverses parent folder structures and automatically applies hierarchical folder names as system tags upon file upload.
3. **Protected System Tags & Collaborative User Tagging**:
   - Parent folder tags are generated with `restricted` access (`userVisible=true`, `userAssignable=false`). Users can view and filter files by these tags, but cannot remove or alter them (HTTP 403 Forbidden).
   - Authorized users can freely assign and remove additional collaborative `public` tags.
4. **Folder Rename & Delete Propagation**:
   - Renaming an archive folder automatically updates all contained files, detaching the old parent tag and assigning the new tag.
5. **Configurable Per-User File Upload Size Limit**:
   - Admin can configure granular maximum upload limits per user (e.g., `10M`, `500M`, `1G`, or `0` for unlimited) via `occ archive:user:limit`.
   - Directly enforced at the WebDAV storage engine layer via SabreDAV hooks, rejecting oversized uploads before payload storage with `HTTP 403 Forbidden`.
6. **Isolated Infrastructure & Enterprise Ingestion**:
   - Application and database isolated from host network; only Nginx port 80/443 exposed.
   - Up to 10GB streaming uploads with disabled request buffering for minimal memory consumption.
7. **Admin-Only Folder Creation & File Upload Decoupling**:
   - Nextcloud native permissions bundle file uploading and folder creation under one permission. The `archive_autotag` (v1.2.0) module decouples these capabilities by intercepting WebDAV `MKCOL` requests and filesystem `mkdir` hooks.
   - Non-admin users are strictly blocked from polluting the archive tree with unauthorized folders/subfolders (HTTP 403 Forbidden), while document uploads into existing folders remain completely permitted.
   - Admin policy management CLI: `occ archive:folder:policy [status|enable|disable]`.
8. **Strict User Account Governance & Admin-Only Deletion Policy**:
   - Rigid security boundaries: Full System Administrators (`admin`) retain sole authority to delete accounts or administer global system settings.
   - Group Administrators (`Subadmins`) are strictly restricted to modifying members of their assigned group (display name, password, quota) and are prohibited from deleting accounts (HTTP 403 Forbidden via `BeforeUserDeletedListener`).
   - Regular users possess zero account management privileges.
   - Includes `deploy/audit_user_roles.sh` for role auditing and `deploy/set-group-quota.sh` for automated batch quota configuration.

## Current Project State

- **Step 1 — Baseline Infrastructure**: Nextcloud + PostgreSQL + Redis (Verified)
- **Step 2 — User Directory Integration**: OpenLDAP / Active Directory connector (Configured)
- **Step 3 — High-Capacity Ingestion & Proxy**: Nginx reverse proxy with 10GB unbuffered uploads (Verified)
- **Step 4 — Automated Ingestion & API Authentication**: WebDAV token authentication (Verified)
- **Step 5 — Compliance Group & Service Accounts**: Audited role structure (Verified)
- **Step 6 — Folder Governance, Dynamic Hierarchical Tagging & User Upload Limits**:
  - Native custom application `archive_autotag` built, installed, and enabled.
  - Granular per-user upload limit CLI (`occ archive:user:limit`) and SabreDAV security plugin.
  - End-to-end automated verification test suite ([tests/test_dynamic_archive_system.py](tests/test_dynamic_archive_system.py)) with **100% pass rate** across all 6 core requirements.

See [PROJECT_STATE.md](PROJECT_STATE.md) and [docs/DEPLOYMENT_RUNBOOK.md](docs/DEPLOYMENT_RUNBOOK.md) for full operational guides and architectural records.

## Getting Started

### 1. Prerequisites
- Docker Engine & Docker Compose (v2)
- Python 3.10+ (for integration test suite)

### 2. Configuration
Create a `.env` file in the project root:

```ini
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud_user
POSTGRES_PASSWORD=YourSecurePassword
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=YourAdminPassword
NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1
```

### 3. Launch Services

```bash
docker compose up -d
```

### 4. Admin Management Commands

```bash
# Set per-user upload limit (e.g., 10MB)
docker compose exec app php occ archive:user:limit archive_user1 10M

# List configured user limits
docker compose exec app php occ archive:user:limit --list

# Batch-set personal quota (e.g. 0 B) for all members of a group
./deploy/set-group-quota.sh SOC "0 B"

# Audit user account roles and identify accidental admin privileges
./deploy/audit_user_roles.sh

# Retroactively scan and re-tag existing files
docker compose exec app php occ archive:retag

# Query or toggle folder creation policy (status, enable, disable)
docker compose exec app php occ archive:folder:policy
```

### 5. Run Automated E2E Verification Tests

```bash
source venv/bin/activate

# Test 1: Full governance, dynamic hierarchical tagging, and user upload limits
python tests/test_dynamic_archive_system.py

# Test 2: Admin-only folder creation and document upload decoupling
python tests/test_folder_creation_restriction.py

# Test 3: Strict user account governance and role boundaries
python tests/test_user_governance.py
```



## Data Persistence & Operational Runbook

### 1. Persistent Storage Architecture
- **PostgreSQL Database**: Persisted on host filesystem in `./db` (`/var/lib/postgresql/data`).
- **Nextcloud Data & Config**: Persisted on host filesystem in `./nextcloud` (`/var/www/html`).
- **Safety Policy**: Automated re-installation parameters (`NEXTCLOUD_ADMIN_*`) have been decoupled from `docker-compose.yml` to prevent unintended database overwrites.

### 2. Backup & Restore Utilities
Automated operations scripts are available in `deploy/`:
- **Create Database Backup**:
  ```bash
  ./deploy/backup_db.sh
  ```
  Exports full timestamped SQL dumps to `deploy/backups/db_backup_<timestamp>.sql` and updates `latest_db_backup.sql`.
- **Restore Database**:
  ```bash
  ./deploy/restore_db.sh [path/to/backup.sql]
  ```
- **System Health & Integrity Check**:
  ```bash
  ./deploy/check_health.sh
  ```
  Verifies running containers, database connectivity, user list, group hierarchy, and archive folders.
- **Batch Group Quota Provisioning**:
  ```bash
  ./deploy/set-group-quota.sh <group> <quota>
  ```
  Applies storage quotas across all members of an organizational group (e.g. `0 B`).
- **User Role Audit & Remediation**:
  ```bash
  ./deploy/audit_user_roles.sh
  ```
  Audits user accounts, flags unintentional admin privileges, and provides one-click remediation.

> [!CAUTION]
> **Never run `docker compose down -v`!**
> The `-v` flag deletes all volumes. Always use `docker compose stop` or `docker compose down` (without `-v`) to preserve database and file archives.
>
> **WSL2 Startup Note**:
> When booting Windows, ensure your WSL2 environment is active before accessing the browser. If containers were started prior to WSL mount synchronization, running `./deploy/check_health.sh` or `docker compose restart` immediately validates live filesystem mounts.


## AI Knowledge Base & On-Premise LLM Integration

The repository is structured to serve as an **Air-Gapped, Zero-GPU Knowledge Base** for an on-premise Large Language Model (LLM) to automatically evaluate project progress, extract financial statistics, and generate executive reports.

- **Zero Data Egress**: 100% on-premise document processing, metadata indexing, and LLM inference.
- **Zero-GPU Efficiency**: Runs quantized GGUF models (`Qwen2.5-1.5B-Instruct`) on CPU (Intel Core i7-1355U AVX2) with ~1.5 GB RAM footprint.
- **Local Indexing**: Blazing fast search and extraction via local `SQLite FTS5`.
- **Complete Blueprint**: Full technical specifications and phased roadmap are documented in [docs/AI_ON_PREMISE_ARCHITECTURE.md](docs/AI_ON_PREMISE_ARCHITECTURE.md).
