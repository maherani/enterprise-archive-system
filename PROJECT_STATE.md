# Enterprise Archive System ? Project State & Implementation Progress

This document serves as the persistent memory and operational reference for the **Enterprise Archive System** repository.

---

## System Overview & Current Architecture

```text
[ External Clients / AI Agents / Web Browser / WebDAV API ]
                         |
                     HTTP :80 (or 443 SSL)
                         v
                +------------------+
                |  archive_proxy   ?  (Nginx Alpine - Reverse Proxy)
                |  (Port 80:80)    ?  - Large file streaming (10GB)
                +--------+---------+  - proxy_request_buffering off
                         |  (archive_net bridge)
                         v
                +------------------+
                |   archive_app    ?  (Nextcloud 34 Apache)
                |   (Internal:80)  ?  - WebDAV Endpoint: /remote.php/dav/files/
                +--------+---------+  - Custom App: archive_autotag v1.5.0
                         |            - PSR-14 Hierarchical Event Engine
                         |            - SabreDAV Upload Limit & Folder Protection
                         |            - Native Multi-Tag Intersection Filter (AND)
                         |            - Strict User Account Governance (Admin-only deletion)
                         |            - Per-User File Access Isolation (ACL)
                         |            - Dynamic Tag Ownership & Visibility Isolation
                         v
                +------------------+
                |    archive_db    ?  (PostgreSQL 15 Alpine)
                |  (Internal:5432) ?  - Database: nextcloud
                |                  ?  - Tables: oc_archive_file_ownership
                |                  ?           oc_archive_file_grants
                |                  ?           oc_archive_tag_ownership
                +------------------+
```

### Current File Structure

```text
enterprise-archive-system/
??? apps/
?   ??? archive_autotag/              # Custom native Nextcloud app (v1.4.0)
?       ??? appinfo/
?       ?   ??? info.xml              # App metadata (v1.4.0)
?       ?   ??? routes.php            # REST API endpoints for tags and filter
?       ??? css/
?       ?   ??? multi_tag_filter.css  # Full-width RTL-aware UI styling for tag filter
?       ??? js/
?       ?   ??? multi_tag_filter.js   # Vue-compatible resilient tag filter bar & table
?       ??? lib/
?           ??? AppInfo/
?           ?   ??? Application.php   # App bootstrap & event listener registrations
?           ??? Command/
?           ?   ??? FileGrantCommand.php       # occ archive:file:grant
?           ?   ??? FolderPolicyCommand.php    # occ archive:folder:policy
?           ?   ??? RetagAllCommand.php        # occ archive:retag
?           ?   ??? TagGovernanceCommand.php   # occ archive:tag:gov
?           ?   ??? TagReconcileCommand.php    # occ archive:tag:reconcile / sync
?           ?   ??? UserLimitCommand.php       # occ archive:user:limit
?           ??? Controller/
?           ?   ??? TagFilterController.php    # Tag query & multi-tag intersection API with ACL
?           ??? Listener/             # PSR-14 event listeners
?           ?   ??? BeforeNodeCreatedListener.php
?           ?   ??? BeforeNodeWrittenListener.php
?           ?   ??? BeforeUserDeletedListener.php # Blocks non-admin user deletion
?           ?   ??? LoadAdditionalScriptsListener.php # Injects multi-tag UI assets
?           ?   ??? NodeCreatedListener.php    # Sets file ownership and tags
?           ?   ??? NodeRenamedListener.php
?           ?   ??? NodeDeletedListener.php    # OCP\Files\Events\Node\NodeDeletedEvent    # Propagates tag changes on rename
?           ?   ??? NodeWrittenListener.php    # Sets file ownership, limits & tags
?           ?   ??? SabrePluginInitListener.php # SabreDAV ACL & isolation hooks
?           ??? Migration/
?           ?   ??? Version1400Date20260913000001.php # DB schema migration for ACL & Tag isolation
?           ??? Service/
?           ?   ??? AutoTagService.php         # Recursive hierarchical tagging logic
?           ?   ??? FileOwnershipService.php   # File ACL and admin grant manager
?           ?   ??? FolderPolicyService.php    # Folder creation decoupling policy
?           ?   ??? TagOwnershipService.php    # Tag visibility & isolation filter
?           ?   ??? UploadLimitService.php     # Per-user streaming upload size enforcement
?           ??? SystemTag/
?               ??? IsolatedManagerFactory.php # System tag factory override
?               ??? IsolatedSystemTagManager.php # System tag isolation manager
??? db/                               # PostgreSQL persistent data volume
??? deploy/                           # Deployment automation & operations
?   ??? audit_user_roles.sh           # User role auditing and subadmin governance
?   ??? backup_db.sh                  # Automated PostgreSQL database backup
?   ??? check_health.sh               # Health check and consistency inspector
?   ??? deploy_from_scratch.sh        # Zero-to-production one-command bare server installer
?   ??? restore_db.sh                 # Database disaster recovery and restore
?   ??? set-group-quota.sh            # Automated batch quota configurator for groups
??? docs/                             # Architectural and operational documentation
?   ??? AI_ON_PREMISE_ARCHITECTURE.md
?   ??? DATA_PERSISTENCE_AND_RELIABILITY.md
?   ??? DEPLOYMENT_RUNBOOK.md         # Comprehensive runbook and bare server deployment guide
?   ??? FILE_ACL_AND_TAG_ISOLATION.md # Architecture & operational guide for File ACL & Tag Isolation
?   ??? implementation_plan.md
?   ??? test_plan.md                  # Comprehensive manual and automated test plan
??? nextcloud/                        # Nextcloud persistent HTML & data volume
??? nginx/
?   ??? default.conf                  # Nginx reverse proxy & large-upload configuration
??? tests/                            # Automated verification test suites
?   ??? test_archive_acl_and_tag_isolation.py # Comprehensive ACL & tag isolation test (100% pass)
?   ??? test_dynamic_archive_system.py # Core autotag, quota, and policy verification (100% pass)
?   ??? test_multi_tag_filter.py      # Multi-tag intersection and ACL verification (100% pass)
?   ??? test_user_governance.py       # Strict account governance and role boundaries (100% pass)
??? .env                              # Environment credentials (git-ignored)
??? .env.example                      # Production environment configuration template
??? .gitignore
??? docker-compose.yml                # Core service definitions (db, app, proxy)
??? PROJECT_STATE.md                  # Primary repository memory and progress tracking
??? README.md                         # Project description and quick start guide
??? requirements.txt                  # Python dependencies for automated testing
??? test_api.py                       # Automated WebDAV upload test script
```

---

## Implemented Steps

### Step 1 ? Environment Setup & Base Dependencies
- Initialized local Git repository and branch `main`.
- Created Python virtual environment (`venv`).
- Configured `.gitignore` for `venv/`, `.env`, persistent volumes, and SQL dumps.

Status: **Completed**

### Step 2 ? Core Services via Docker Compose
- Created `docker-compose.yml` with PostgreSQL 15 and Nextcloud Apache.
- Implemented database healthcheck (`pg_isready`) for resilient container ordering.
- Configured `.env` file for credentials and trusted domain definitions.

Status: **Completed**

### Step 3 ? Container Deployment & LDAP Module Activation
- Successfully deployed containers on isolated bridge network `archive_net`.
- Enabled official Nextcloud LDAP user backend (`user_ldap`) via `occ app:enable user_ldap`.
- Verified database and application readiness.

Status: **Completed**

### Step 4 ? Compliance Group, API Worker & WebDAV Upload Test
- Created dedicated group `Compliance_Unit`.
- Provisioned service account `api_worker` and assigned it to `Compliance_Unit`.
- Generated dedicated application token for non-interactive API access.
- Verified automated upload via WebDAV API.

Status: **Completed**

### Step 5 ? Nginx Reverse Proxy & Large-Upload Optimization
- Configured Nginx reverse proxy in `nginx/default.conf`.
  - `client_max_body_size 10G;` for large-scale enterprise backups and document archives.
  - `proxy_request_buffering off;` and `proxy_buffering off;` for streaming large files without disk write bottlenecks.
  - Extended proxy timeouts to 3600s for large payload transfers.
- Isolated Nextcloud container inside Docker bridge network.

Status: **Completed**

### Step 6 ? Admin Folder Governance, Dynamic Hierarchical Auto-Tagging & Upload Limits
- Developed and enabled native Nextcloud custom application `archive_autotag` (v1.0.0-v1.2.0):
  - **Dynamic Hierarchical Tagging:** Automatically traverses folder hierarchy up to the archive root and applies all parent folder tags.
  - **Protected System Tags:** Tags are created with `restricted` access (`userVisible=true`, `userAssignable=false`). Users can view and filter by these tags, but regular users cannot delete or modify them (HTTP 403 Forbidden).
  - **Folder Rename Propagation:** Renaming an archive folder automatically propagates to all descendant files.
  - **Admin Folder Governance:** User personal quota set to `0 B`, preventing regular users from creating personal storage folders/files (HTTP 507 Insufficient Storage).
  - **Configurable Per-User File Upload Size Limit:** Admin CLI `occ archive:user:limit <user> <limit>` enforced at SabreDAV and filesystem stream layers.
  - **Admin-Only Folder Hierarchy Protection:** Intercepts `MKCOL` and `mkdir` to block non-admins from creating folders while allowing file uploads.

Status: **Completed**

### Step 7 ? Security Audit Logging & Health Monitoring
- Enabled Nextcloud native audit logging (`admin_audit`).
- Developed `deploy/check_health.sh` for multi-service container, database, and archive consistency checks.

Status: **Completed**

### Step 8 ? Data Persistence, Automated Backup & Disaster Recovery
- Configured resilient volume mapping and safe restart policies.
- Developed `deploy/backup_db.sh` for timestamped SQL backups.
- Developed `deploy/restore_db.sh` for instant disaster recovery with session termination and lock cleanup.
- Published architectural analysis in `docs/DATA_PERSISTENCE_AND_RELIABILITY.md`.

Status: **Completed**

### Step 9 ? Strict User Account Governance & Role Boundaries
- Resolved enterprise privilege escalation concerns by establishing hard role boundaries:
  - **System Administrator (`admin`):** Sole authority to delete accounts and manage global settings.
  - **Group Administrator (`Subadmin`):** Strictly restricted to modifying members of their assigned group; prohibited from deleting accounts via `BeforeUserDeletedListener` (HTTP 403 Forbidden).
  - **Regular Users:** Possess zero account administration privileges.
- Developed `deploy/audit_user_roles.sh` for role auditing and privilege de-escalation.
- Developed `deploy/set-group-quota.sh` for batch user quota enforcement.
- Created `tests/test_user_governance.py` with 5 automated test cases passing at 100%.

Status: **Completed**

### Step 10 ? Multi-Tag Intersection Filter in Files Web UI & REST API (v1.3.4)
- Implemented native multi-tag intersection filter directly inside the Nextcloud Files Web UI:
  - **REST API Endpoints:** `/api/tags` and `/api/filter` in `TagFilterController.php` supporting strict logical `AND` intersection via SQL `HAVING COUNT(DISTINCT systemtagid) = N`.
  - **Strict User ACL:** Enforced via `$userFolder->getById()` so users only see files in their authorized storage.
  - **Interactive Web UI:** Persian RTL-aware filter bar (`multi_tag_filter.js` & `multi_tag_filter.css`) with live tag counts, active filter badges, and matching file table.
  - **End-to-End Test Suite:** `tests/test_multi_tag_filter.py` validating 6 test cases with 100% pass rate.
  - **Live Browser Verification:** Verified in actual browser session via CDP automation, narrowing documents from single tag to multi-tag intersection (`1.md`).

Status: **Completed**

### Step 11 ? Bare-Metal Production Deployment & Automation
- Created `.env.example` configuration template for zero-touch deployments.
- Updated `docker-compose.yml` with environment-driven admin credentials for headless unattended installs.
- Developed `deploy/deploy_from_scratch.sh` automating all 7 phases of bare-server deployment.
- Completely overhauled and updated `docs/DEPLOYMENT_RUNBOOK.md` with comprehensive guides for bare-metal servers.

Status: **Completed**

### Step 12 ? Per-User File Access Control & Dynamic Tag Isolation (v1.4.0)
- **Problem & Requirements:**
  1. Every regular user must ONLY have access to files that either they uploaded or an administrator explicitly granted access to.
  2. Every regular user must ONLY see tags that either they created or the system/admin generated.
  3. The Administrator (`admin`) retains full system-wide visibility and management over all files and tags, with complete permissions to modify or delete any of them.
  4. Absolute isolation: No regular user may see, query, download, or delete another regular user's files or private tags.
- **Database Architecture (Migration 1400):**
  - Table `oc_archive_file_ownership`: Records file uploader UID upon upload.
  - Table `oc_archive_file_grants`: Tracks explicit user/group access grants granted by administrators.
  - Table `oc_archive_tag_ownership`: Records tag creator UID (`system`, `admin`, or user UID).
- **Core Implementation:**
  - `FileOwnershipService.php`: Core ACL evaluator checking admin privileges, uploader ownership, explicit grants, and native shares.
  - `TagOwnershipService.php`: Tag visibility filter isolating user-created tags while keeping system tags visible.
  - `SabrePluginInitListener.php`: WebDAV/SabreDAV kernel integration:
    - Omit unauthorized files from directory PROPFIND listings.
    - Intercept direct file requests (`GET`, `HEAD`, `DELETE`, `PROPPATCH`, `COPY`, `MOVE`, `PROPFIND`, `PUT` overwrite), returning `HTTP 404 Not Found` for unauthorized files.
  - `IsolatedSystemTagManager.php` & `IsolatedManagerFactory.php`: Overrides Nextcloud `systemtags.managerFactory` to isolate tags in WebDAV (`HTTP 404` for unauthorized tags, full access for admin).
  - `TagFilterController.php`: Updated `/api/tags` and `/api/filter` to enforce strict ownership and visibility filters.
  - `FileGrantCommand.php`: Admin CLI `occ archive:file:grant [grant|revoke|list|set-owner]`.
  - `TagGovernanceCommand.php`: Admin CLI `occ archive:tag:gov [list|delete]`.
- **Automated Verification:**
  - `tests/test_archive_acl_and_tag_isolation.py`: 7 out of 7 tests passed (100%).
  - Full regression suites verified: `test_dynamic_archive_system.py` (100%), `test_user_governance.py` (100%), `test_multi_tag_filter.py` (100%).

Status: **Completed**

---

## Repository Status

- Repository: `maherani/enterprise-archive-system`
- Branch: `main`
- Current Checkpoint: **Steps 1 through 12 fully completed, verified, and synchronized.**

### 5. Dynamic Folder-Driven Tag Lifecycle & Reconciliation (`tests/test_tag_lifecycle_reconciliation.py`)
- **Step 1**: Folder creation triggers automatic tag registration & hierarchy tagging.
- **Step 2**: File upload into folder receives ancestor tags automatically.
- **Step 3**: Folder rename in-place updates tag name and updates descendant files, purging old tag.
- **Step 4**: Folder deletion automatically reconciles tags and purges surplus/orphaned tag.
- **Step 5**: CLI command `occ archive:tag:reconcile` executes cleanly with full audit table.
- **Result**: 100% Passed.
