# Enterprise Archive System

A secure, scalable, and audit-compliant enterprise document archiving system built on **Nextcloud**, **PostgreSQL**, and **Nginx**.

## Architecture Overview

```text
[ External Clients / AI Agents / Web Browser / WebDAV API ]
                             │
                         HTTP :80 (or 443 SSL)
                             ▼
                    ┌──────────────────┐
                    │  archive_proxy   │  (Nginx Alpine - Reverse Proxy)
                    │  (Port 80:80)    │  - Large file upload (10GB)
                    └────────┬─────────┘  - Request buffering disabled
                             │  (archive_net bridge)
                             ▼
                    ┌──────────────────┐
                    │   archive_app    │  (Nextcloud 34 Apache)
                    │   (Internal:80)  │  - WebDAV Endpoint: /remote.php/dav/files/
                    └────────┬─────────┘  - LDAP & Token Authentication
                             │            - Custom App: archive_autotag v1.3.4
                             │            - Dynamic Hierarchical Auto-Tagging
                             │            - Native Multi-Tag Intersection Search (AND)
                             │            - Granular Per-User File Upload Size Limit
                             │            - Admin-Only Folder Policy Enforcement
                             │            - Strict Account Governance (Admin-only deletion)
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
   - Nextcloud native permissions bundle file uploading and folder creation under one permission. The `archive_autotag` module decouples these capabilities by intercepting WebDAV `MKCOL` requests and filesystem `mkdir` hooks.
   - Non-admin users are strictly blocked from polluting the archive tree with unauthorized folders/subfolders (HTTP 403 Forbidden), while document uploads into existing folders remain completely permitted.
   - Admin policy management CLI: `occ archive:folder:policy [status|enable|disable]`.
8. **Strict User Account Governance & Admin-Only Deletion Policy**:
   - Rigid security boundaries: Full System Administrators (`admin`) retain sole authority to delete accounts or administer global system settings.
   - Group Administrators (`Subadmins`) are strictly restricted to modifying members of their assigned group (display name, password, quota) and are prohibited from deleting accounts (HTTP 403 Forbidden via `BeforeUserDeletedListener`).
   - Regular users possess zero account management privileges.
   - Includes `deploy/audit_user_roles.sh` for role auditing and `deploy/set-group-quota.sh` for automated batch quota configuration.
9. **Native Multi-Tag Intersection Filter (`archive_autotag v1.3.4`)**:
   - Interactive, Persian RTL-aware filter toolbar embedded directly into the Nextcloud Files Web UI.
   - Allows users to select multiple tags simultaneously (e.g. `افتا` AND `الزامات امنیتی`), narrowing documents strictly by logical mathematical intersection.
   - Displays real-time matching document counts, full archive paths, human-readable file sizes, direct folder navigation, and instant downloads with strict ACL isolation.
10. **Zero-to-Production Automated Bare Server Deployment (`deploy/deploy_from_scratch.sh`)**:
    - Complete turnkey deployment script enabling immediate, single-command setup on clean Ubuntu 22.04 / 24.04 LTS servers.

---

## Current Project State

- **Step 1 — Baseline Infrastructure**: Nextcloud + PostgreSQL + Redis (Verified)
- **Step 2 — User Directory Integration**: OpenLDAP / Active Directory connector (Configured)
- **Step 3 — High-Capacity Ingestion & Proxy**: Nginx reverse proxy with 10GB unbuffered uploads (Verified)
- **Step 4 — Automated Ingestion & API Authentication**: WebDAV token authentication (Verified)
- **Step 5 — Compliance Group & Service Accounts**: Audited role structure (Verified)
- **Step 6 — Folder Governance, Dynamic Hierarchical Tagging & User Upload Limits**: Verified with 100% pass rate.
- **Step 7 — Audit Logging & Health Monitoring**: `admin_audit` enabled and verified.
- **Step 8 — Data Persistence, Automated Backup & Recovery**: Resilient volumes, `backup_db.sh`, `restore_db.sh`, `check_health.sh`.
- **Step 9 — Strict User Account Governance**: Admin-only user deletion and group admin isolation verified.
- **Step 10 — Multi-Tag Intersection Filter**: Web UI integration and REST API verified via automated E2E tests and live browser CDP runs.
- **Step 11 — Bare-Metal Deployment Automation**: `deploy/deploy_from_scratch.sh`, `.env.example`, and updated runbook.

See [PROJECT_STATE.md](PROJECT_STATE.md) and [docs/DEPLOYMENT_RUNBOOK.md](docs/DEPLOYMENT_RUNBOOK.md) for full operational guides and architectural records.

---

## Getting Started

### 1. Bare Server Rapid Deployment (Recommended)
On a clean Ubuntu Server 22.04 or 24.04 LTS:

```bash
# Clone the repository
git clone https://github.com/maherani/enterprise-archive-system.git
cd enterprise-archive-system

# Create .env from template and configure passwords
cp .env.example .env
nano .env

# Run automated zero-to-production installer
./deploy/deploy_from_scratch.sh
```

### 2. Manual Launch Services

```bash
# Copy and configure environment variables
cp .env.example .env

# Launch containers
docker compose up -d

# Verify container health
./deploy/check_health.sh
```

### 3. Admin Management Commands

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

### 4. Run Automated E2E Verification Tests

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Test 1: Full governance, dynamic hierarchical tagging, and upload limits
python3 tests/test_dynamic_archive_system.py

# Test 2: Strict user account governance and role boundaries
python3 tests/test_user_governance.py

# Test 3: Multi-tag intersection filtering (AND logic) and ACL isolation
python3 tests/test_multi_tag_filter.py
```

---

## Data Persistence & Operational Utilities

Automated operations scripts are available in `deploy/`:
- **Automated Bare Server Installer**: `./deploy/deploy_from_scratch.sh`
- **Database Backup**: `./deploy/backup_db.sh`
- **Disaster Recovery Restore**: `./deploy/restore_db.sh [backup.sql]`
- **System Health & Integrity Check**: `./deploy/check_health.sh`
- **Batch Group Quota Provisioning**: `./deploy/set-group-quota.sh <group> <quota>`
- **User Role Audit & Remediation**: `./deploy/audit_user_roles.sh`
