# PROJECT_STATE.md

## Objective

Build an **Enterprise Archive System** based on Nextcloud, PostgreSQL, and Nginx. The system is designed for secure, high-capacity, auditable document archiving with LDAP directory integration, compliance-driven retention policies, dynamic hierarchical tagging, multi-tag intersection search, strict user account governance, and programmatic ingestion via API/WebDAV for automated systems and AI agents.

## Current Architecture

The architecture separates the public reverse proxy from internal services:

```text
[ External Clients / AI Agents / API / Web Browser ]
                         |
                      HTTP :80
                         v
                +------------------+
                |  archive_proxy   |  (Nginx Alpine - Reverse Proxy)
                |  (Port 80:80)    |  - client_max_body_size 10G
                +--------+---------+  - request_buffering off
                         |  (archive_net bridge)
                         v
                +------------------+
                |   archive_app    |  (Nextcloud 34 Apache)
                |   (Internal:80)  |  - WebDAV Endpoint: /remote.php/dav/files/
                +--------+---------+  - Custom App: archive_autotag v1.3.4
                         |            - PSR-14 Hierarchical Event Engine
                         |            - SabreDAV Upload Limit & Folder Protection
                         |            - Native Multi-Tag Intersection Filter (AND)
                         |            - Strict User Account Governance (Admin-only deletion)
                         v
                +------------------+
                |    archive_db    |  (PostgreSQL 15 Alpine)
                |  (Internal:5432) |  - Database: nextcloud
                +------------------+
```

### Current File Structure

```text
enterprise-archive-system/
├── apps/
│   └── archive_autotag/              # Custom native Nextcloud app (v1.3.4)
│       ├── appinfo/
│       │   ├── info.xml              # App metadata and version 1.3.4
│       │   └── routes.php            # REST API endpoints for tags and filter
│       ├── css/
│       │   └── multi_tag_filter.css  # Full-width RTL-aware UI styling for tag filter
│       ├── js/
│       │   └── multi_tag_filter.js   # Vue-compatible resilient tag filter bar & table
│       └── lib/
│           ├── AppInfo/
│           │   └── Application.php   # App bootstrap & event listener registrations
│           ├── Command/
│           │   ├── FolderPolicyCommand.php
│           │   ├── RetagAllCommand.php
│           │   └── UserLimitCommand.php
│           ├── Controller/
│           │   └── TagFilterController.php # Tag query & multi-tag intersection API with ACL
│           ├── Listener/             # PSR-14 event listeners
│           │   ├── BeforeNodeCreatedListener.php
│           │   ├── BeforeNodeWrittenListener.php
│           │   ├── BeforeUserDeletedListener.php # Blocks non-admin user deletion
│           │   ├── FolderPolicyListener.php
│           │   ├── LoadAdditionalScriptsListener.php # Injects multi-tag UI assets
│           │   ├── NodeCreatedListener.php
│           │   ├── NodeRenamedListener.php
│           │   ├── NodeWrittenListener.php
│           │   └── SabrePluginInitListener.php
│           └── Service/
│               ├── AutoTagService.php
│               ├── FolderPolicyService.php
│               └── UploadLimitService.php
├── db/                               # PostgreSQL persistent data volume
├── deploy/                           # Deployment automation & operations
│   ├── audit_user_roles.sh           # User role auditing and subadmin governance
│   ├── backup_db.sh                  # Automated PostgreSQL database backup
│   ├── check_health.sh               # Health check and consistency inspector
│   ├── deploy_from_scratch.sh        # Zero-to-production one-command bare server installer
│   ├── restore_db.sh                 # Database disaster recovery and restore
│   └── set-group-quota.sh            # Automated batch quota configurator for groups
├── docs/                             # Architectural and operational documentation
│   ├── AI_ON_PREMISE_ARCHITECTURE.md
│   ├── DATA_PERSISTENCE_AND_RELIABILITY.md
│   └── DEPLOYMENT_RUNBOOK.md         # Comprehensive runbook and bare server deployment guide
├── nextcloud/                        # Nextcloud persistent HTML & data volume
├── nginx/
│   └── default.conf                  # Nginx reverse proxy & large-upload configuration
├── tests/                            # Automated verification test suites
│   ├── test_dynamic_archive_system.py # Core autotag, quota, and policy verification (100% pass)
│   ├── test_multi_tag_filter.py      # Multi-tag intersection and ACL verification (100% pass)
│   └── test_user_governance.py       # Strict account governance and role boundaries (100% pass)
├── .env                              # Environment credentials (git-ignored)
├── .env.example                      # Production environment configuration template
├── .gitignore
├── docker-compose.yml                # Core service definitions (db, app, proxy)
├── PROJECT_STATE.md                  # Primary repository memory and progress tracking
├── README.md                         # Project description and quick start guide
├── requirements.txt                  # Python dependencies for automated testing
└── test_api.py                       # Automated WebDAV upload test script
```

---

## Implemented Steps

### Step 1 — Environment Setup & Base Dependencies
- Initialized local Git repository and branch `main`.
- Created Python virtual environment (`venv`).
- Configured `.gitignore` for `venv/`, `.env`, persistent volumes, and SQL dumps.

Status: **Completed**

### Step 2 — Core Services via Docker Compose
- Created `docker-compose.yml` with PostgreSQL 15 and Nextcloud Apache.
- Implemented database healthcheck (`pg_isready`) for resilient container ordering.
- Configured `.env` file for credentials and trusted domain definitions.

Status: **Completed**

### Step 3 — Container Deployment & LDAP Module Activation
- Successfully deployed containers on isolated bridge network `archive_net`.
- Enabled official Nextcloud LDAP user backend (`user_ldap`) via `occ app:enable user_ldap`.
- Verified database and application readiness.

Status: **Completed**

### Step 4 — Compliance Group, API Worker & WebDAV Upload Test
- Created dedicated group `Compliance_Unit`.
- Provisioned service account `api_worker` and assigned it to `Compliance_Unit`.
- Generated dedicated application token for non-interactive API access.
- Verified automated upload via WebDAV API.

Status: **Completed**

### Step 5 — Nginx Reverse Proxy & Large-Upload Optimization
- Configured Nginx reverse proxy in `nginx/default.conf`.
  - `client_max_body_size 10G;` for large-scale enterprise backups and document archives.
  - `proxy_request_buffering off;` and `proxy_buffering off;` for streaming large files without disk write bottlenecks.
  - Extended proxy timeouts to 3600s for large payload transfers.
- Isolated Nextcloud container inside Docker bridge network.

Status: **Completed**

### Step 6 — Admin Folder Governance, Dynamic Hierarchical Auto-Tagging & Upload Limits
- Developed and enabled native Nextcloud custom application `archive_autotag` (v1.0.0-v1.2.0):
  - **Dynamic Hierarchical Tagging:** Automatically traverses folder hierarchy up to the archive root and applies all parent folder tags.
  - **Protected System Tags:** Tags are created with `restricted` access (`userVisible=true`, `userAssignable=false`). Users can view and filter by these tags, but regular users cannot delete or modify them (HTTP 403 Forbidden).
  - **Folder Rename Propagation:** Renaming an archive folder automatically propagates to all descendant files.
  - **Admin Folder Governance:** User personal quota set to `0 B`, preventing regular users from creating personal storage folders/files (HTTP 507 Insufficient Storage).
  - **Configurable Per-User File Upload Size Limit:** Admin CLI `occ archive:user:limit <user> <limit>` enforced at SabreDAV and filesystem stream layers.
  - **Admin-Only Folder Hierarchy Protection:** Intercepts `MKCOL` and `mkdir` to block non-admins from creating folders while allowing file uploads.

Status: **Completed**

### Step 7 — Security Audit Logging & Health Monitoring
- Enabled Nextcloud native audit logging (`admin_audit`).
- Developed `deploy/check_health.sh` for multi-service container, database, and archive consistency checks.

Status: **Completed**

### Step 8 — Data Persistence, Automated Backup & Disaster Recovery
- Configured resilient volume mapping and safe restart policies.
- Developed `deploy/backup_db.sh` for timestamped SQL backups.
- Developed `deploy/restore_db.sh` for instant disaster recovery with session termination and lock cleanup.
- Published architectural analysis in `docs/DATA_PERSISTENCE_AND_RELIABILITY.md`.

Status: **Completed**

### Step 9 — Strict User Account Governance & Role Boundaries
- Resolved enterprise privilege escalation concerns by establishing hard role boundaries:
  - **System Administrator (`admin`):** Sole authority to delete accounts and manage global settings.
  - **Group Administrator (`Subadmin`):** Strictly restricted to modifying members of their assigned group; prohibited from deleting accounts via `BeforeUserDeletedListener` (HTTP 403 Forbidden).
  - **Regular Users:** Possess zero account administration privileges.
- Developed `deploy/audit_user_roles.sh` for role auditing and privilege de-escalation.
- Developed `deploy/set-group-quota.sh` for batch user quota enforcement.
- Created `tests/test_user_governance.py` with 5 automated test cases passing at 100%.

Status: **Completed**

### Step 10 — Multi-Tag Intersection Filter in Files Web UI & REST API (v1.3.4)
- Implemented native multi-tag intersection filter directly inside the Nextcloud Files Web UI:
  - **REST API Endpoints:** `/api/tags` and `/api/filter` in `TagFilterController.php` supporting strict logical `AND` intersection via SQL `HAVING COUNT(DISTINCT systemtagid) = N`.
  - **Strict User ACL:** Enforced via `$userFolder->getById()` so users only see files in their authorized storage.
  - **Interactive Web UI:** Persian RTL-aware filter bar (`multi_tag_filter.js` & `multi_tag_filter.css`) with live tag counts, active filter badges, and matching file table.
  - **End-to-End Test Suite:** `tests/test_multi_tag_filter.py` validating 6 test cases with 100% pass rate.
  - **Live Browser Verification:** Verified in actual browser session via CDP automation, narrowing documents from single tag to multi-tag intersection (`1.md`).

Status: **Completed**

### Step 11 — Bare-Metal Production Deployment & Automation
- Created `.env.example` configuration template for zero-touch deployments.
- Updated `docker-compose.yml` with environment-driven admin credentials for headless unattended installs.
- Developed `deploy/deploy_from_scratch.sh` automating all 7 phases of bare-server deployment.
- Completely overhauled and updated `docs/DEPLOYMENT_RUNBOOK.md` with comprehensive guides for bare-metal servers.

Status: **Completed**

---

## Repository Status

- Repository: `maherani/enterprise-archive-system`
- Branch: `main`
- Current Checkpoint: **Steps 1 through 11 fully completed, verified, and synchronized.**
