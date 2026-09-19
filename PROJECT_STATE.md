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
                +--------+---------+  - Custom App: archive_autotag v2.0.3
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
?   ??? archive_autotag/              # Custom native Nextcloud app (v2.0.3)
?       ??? appinfo/
?       ?   ??? info.xml              # App metadata (v2.0.3)
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
?           ?   ??? AiRetrievalController.php  # Zero-RAM chunked binary stream & AI retrieval API
?           ?   ??? FolderRequestController.php# Delegated folder creation workflow API
?           ?   ??? SwaggerController.php      # On-premise offline Swagger UI & OpenAPI 3.0 spec
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
?           ?   ??? AiAuditService.php         # Immutable AI audit logger & verification
?           ?   ??? AutoTagService.php         # Recursive hierarchical tagging logic
?           ?   ??? FileOwnershipService.php   # File ACL and admin grant manager
?           ?   ??? FolderPolicyService.php    # Folder creation decoupling policy
?           ?   ??? FolderRequestNotifier.php  # Native Nextcloud notification dispatcher
?           ?   ??? FolderRequestService.php   # Atomic folder & tag provisioning engine
?           ?   ??? TagOwnershipService.php    # Tag visibility & isolation filter
?           ?   ??? UploadLimitService.php     # Per-user streaming upload size enforcement
?           ??? SystemTag/
?               ??? IsolatedManagerFactory.php # System tag factory override
?               ??? IsolatedSystemTagManager.php # System tag isolation manager
??? db/                               # PostgreSQL persistent data volume
??? deploy/                           # Deployment automation & operations
?   ??? audit_user_roles.sh           # User role auditing and subadmin governance
│   ├── backup_db.sh                  # Automated full Nextcloud & PostgreSQL database backup engine
?   ??? check_health.sh               # Health check and consistency inspector
?   ??? deploy_from_scratch.sh        # Zero-to-production one-command bare server installer
│   ├── restore_db.sh                 # Full Nextcloud & database disaster recovery engine with credential alignment
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

### Step 8 ➔ Data Persistence, Automated Full Backup & Disaster Recovery
- Configured resilient volume mapping and safe restart policies (`restart: unless-stopped`).
- Developed `deploy/backup_db.sh`: Monolithic multi-component backup engine producing complete archives (`database.sql`, `data.tar.gz`, `config.tar.gz`, `custom_apps.tar.gz`, `manifest.txt`) with SHA256 checksum verification.
- Developed `deploy/restore_db.sh`: Enterprise disaster recovery engine featuring checksum validation, service orchestration, PostgreSQL role password synchronization from `.env`, containerized filesystem replacement, deterministic `config.php` credential alignment, and cache rebuild.
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

### Step 13 — Enterprise Archive Portal UI/UX Overhaul & Obsidian-Orange Design System (v1.7.1)
- **Problem & Requirements:**
  1. Default Nextcloud interface is blue, cluttered, and generic; users require an authoritative, minimalist, high-contrast Enterprise Theme.
  2. Complete elimination of default blue colors, fluid 3D wallpapers, and generic banners across the Portal, Navigation Header, Files App, and Login page.
  3. Seamless sliding push workspace (Accordion-style content compression): When right-docked drawers (quick-view file details) open, workspace data smoothly slides and compresses to the left, guaranteeing zero data obstruction (`#archive-portal-root.drawer-open`). When closed, it smoothly expands back to full width without any blank dead zones.
  4. Strict Zero Data Loss Policy: Retained all database records, restored user accounts, document permissions, and system tags intact.
- **Implementation & Architecture:**
  - **Color Palette & Tokens:** Deep Obsidian (`#090b0e`, `#11141b`, `#181d27`) with Industrial Orange accent (`#f97316`, `#ea580c`).
  - **Portal Stylesheet (`apps/archive_autotag/css/archive_portal.css`):**
    - Full-width canvas guarantee with glassmorphism card surfaces and glowing hover states.
    - Card Grid (`.ea-document-grid`) and List Table (`.ea-table`) views with persistent view switching in `localStorage`.
    - Dynamic push workspace: `#archive-portal-root.drawer-open` sets `padding-right: 460px !important`, smoothly sliding content left in 0.35s cubic-bezier.
    - Non-obstructive Quick-View drawer (`.ea-drawer`) anchored to the right (`width: 440px`), with detailed metadata, download links, and copy-link button.
  - **Global Header Theming (`apps/archive_autotag/css/app_menu_filter.css`):**
    - Overrides Nextcloud top header (`#header`) in `#0d1117`, `#202632` border, orange active tab indicator, orange search ring.
  - **Multi-Tag Files Filter (`apps/archive_autotag/css/multi_tag_filter.css`):**
    - Themed with dark obsidian chips, orange active states, and consistent typography.
  - **Login Page Theme (`core/css/guest.css`):** Dark obsidian card, carbon inputs, and orange gradient button.
  - **Portal Script (`apps/archive_autotag/js/archive_portal.js`):**
    - Clean DOM rendering with instant debounce search, faceted tag chips, and drawer interactions.
    - Added `Escape` key listener for closing drawer.
    - Removed false-positive user-menu observer to ensure full-width default canvas across all viewports.
  - **Cache Busting & Versioning (v1.7.1):**
    - Updated `apps/archive_autotag/appinfo/info.xml` to `1.7.1` and executed `occ upgrade` to generate new asset query hashes (`?v=f484827b-4`), overcoming browser `immutable` disk caching.
- **Automated Verification:**
  - `tests/test_archive_portal.py`: 5 out of 5 tests passed (100%).
  - Verified user access and ACL isolation for multiple users (`admin`, `maherani`, `archive_user1`, `api_worker`).

Status: **Completed**

---

### Step 14 — Delegated Folder Creation Workflow & Multi-Tier Governance (v1.8.0)
- **Problem & Requirements:**
  1. Regular users must never create folders or bypass archive policies.
  2. Group administrators require a formal channel to request new folders within their specific departmental archive scope, without granting them direct unmonitored filesystem creation permissions.
  3. System Administrators (`admin` group) must retain centralized oversight, review pending requests with full context, and have sole authority to approve or reject requests.
  4. Atomic & Rollback Guarantees: When approved, folder provisioning, group share inheritance (Read + Create), and restricted system tag generation must execute atomically. If any component fails, the request is marked as failed with a detailed audit trace and state is safely rolled back.
  5. Rejection Transparency: Rejections require a mandatory recorded reason visible to the requesting group administrator.
  6. Strict Backend & Frontend Boundary Isolation: Regular members cannot view or submit requests (HTTP 403). Cross-group spoofing between group administrators is prevented.
- **Implementation & Architecture:**
  - **Database Migration (`Version1800Date20260915000001.php`):**
    - Created `oc_archive_folder_requests` with fields: `id`, `folder_name`, `target_path`, `description`, `group_id`, `requester_uid`, `status`, timestamps, reviewer information, `rejection_reason`, `error_message`, and created IDs.
  - **Backend Service & Controller (`FolderRequestService.php`, `FolderRequestController.php`, `SecurityPermissionException.php`):**
    - `POST /api/folder-requests`: Validates group subadmin status, sanitizes folder names, enforces non-empty justifications, and sets status to `pending`.
    - `GET /api/folder-requests`: Enforces role-based filtering (regular users: 403, group admins: own group only, system admin: full access with filters).
    - `POST /api/folder-requests/{id}/approve`: Restricted exclusively to system administrators. Provisioning occurs under master archive tree (`admin` ownership), verifies group share on `Enterprise_Archive/<groupId>/...`, generates restricted system tag via `AutoTagService`, and binds tag to group via `TagOwnershipService`.
    - `POST /api/folder-requests/{id}/reject`: Validates mandatory justification and records reviewer audit log.
    - `GET /api/user-role`: Returns authenticated role profile (`is_admin`, `is_group_admin`, `subadmin_groups`, `member_groups`).
  - **Portal Frontend UI/UX (`apps/archive_autotag/js/archive_portal.js` & `apps/archive_portal.css`):**
    - Implemented Obsidian-Orange modal workflow.
    - Group Admin View: Action bar displays `[ + درخواست پوشه جدید ]` and `[ درخواست‌های گروه ]`.
    - System Admin View: Displays `[ مدیریت درخواست‌های پوشه ]` with glowing orange pending badge counter.
    - Admin Review Dashboard: Status and group filtering toolbar, direct approval prompt, and mandatory rejection prompt.
    - Non-admin regular users: Zero workflow buttons or forms exposed.
  - **App Version & Assets (v1.8.1):**
    - Updated `apps/archive_autotag/appinfo/info.xml` to `1.8.1` and completed database migration.
    - Integrated dynamic `#ea-workflow-actions` container in portal header (`renderApp`), enabling seamless reactive rendering of action buttons based on user role (`is_group_admin` vs `is_admin`).
    - Asset cache busting query strings refreshed (`?v=5dbcc382-4` and `?v=da35cf6b-4`) via `occ upgrade`.
- **Automated Verification:**
  - `tests/test_folder_request_workflow.py`: Passed 100% across all 11 governance checks (regular user block, anti-spoofing, admin exemption, isolation, rejection with reason, atomic folder/tag creation, WebDAV upload, and MKCOL restriction).

Status: **Completed**

---

### Step 14.1 — Advanced Request Governance: Duplicate Prevention, Complete Audit Trail & Native Notifications (v1.9.0)
- **Problem & Requirements:**
  1. Prevent duplicate folder requests by evaluating both physical archive directory existence and concurrent/pending request status in the database (with race condition prevention).
  2. Implement a complete, tamper-proof audit trail tracking the full lifecycle of every folder request (`request_created`, `request_pending`, `request_approved`, `folder_created`, `permissions_applied`, `tag_created`, `request_completed`, `request_rejected`, `request_failed`).
  3. Send native Nextcloud notifications to the requesting Group Admin upon approval (with folder details and link), rejection (with mandatory reason), or processing errors.
  4. Provide dedicated UI controls in the Enterprise Archive Portal for viewing the audit trail timeline.
- **Implementation & Architecture:**
  - **Database Migration (`Version1900Date20260916000001.php`):**
    - Created `oc_archive_folder_request_audit` storing request ID, event type, actor, group, folder name/path, status transitions, rejection reasons, error info, and timestamps.
    - Created partial unique index `arch_folder_req_pending_uniq_idx` on `(group_id, target_path, folder_name)` where `status = 'pending'`.
  - **Backend Services (`FolderRequestService.php`, `FolderRequestController.php`, `FolderRequestNotifier.php`):**
    - `validateNoDuplicates()`: Validates physical folder existence via `IRootFolder` and verifies no pending request exists in DB; returns HTTP 409 Conflict.
    - `logAuditEvent()`: Records structured lifecycle events in DB and writes to `Psr\Log\LoggerInterface` (`admin_audit` compatible).
    - `sendNotificationToUser()`: Dispatches native Nextcloud notifications via `OCP\Notification\IManager` registered with `FolderRequestNotifier`.
    - `GET /api/folder-requests/{id}/audit`: Access-controlled endpoint returning full chronological audit events.
  - **Portal Frontend UI/UX (`archive_portal.js`, `archive_portal.css`):**
    - Added "📋 لاگ" (Audit) button to Group Admin Requests modal and System Admin Review modal.
    - Implemented `window._eaViewAudit(id, folderName)` rendering an Obsidian-Orange vertical timeline modal with distinct badges and icons.
  - **Automated Verification:**
    - `tests/test_folder_request_governance_v2.py`: 100% Passed across duplicate physical rejection, duplicate pending rejection, audit access control, approval lifecycle audit, rejection lifecycle audit, and database notification delivery.
    - Full regression suites verified: `test_folder_request_workflow.py` (100%), `test_archive_portal.py` (100%), `test_group_tag_isolation.py` (100%).

Status: **Completed**

---

### Step 14.2 — Responsive Full-Width Layout & Zero-Horizontal-Scroll Governance Modal (v1.9.1)
- **Problem & Requirements:**
  1. In the Folder Request Management & Review modal (`پنل مدیریت و بررسی درخواست‌های پوشه آرشیو`), the table width was constrained by an 880px container, pushing the `عملیات` (Actions) column offscreen on the right.
  2. Action buttons (`تأیید و ساخت` and `رد درخواست`) were forced inline without wrapping, causing horizontal overflow.
  3. LTR default direction from Nextcloud's body caused alignment inversions in Persian UI.
  4. Core Requirement: Guarantee that all table columns (ID, Folder Name & Description, Group, Requester Admin, Date, Status, Actions) are 100% visible with **zero horizontal scrolling** (`نیاز به اسکرول افقی نباشد`), while preserving smooth vertical scrolling for large datasets (`اسکرول عمودی اشکالی ندارد`).
- **Implementation & Architecture:**
  - **Styles (`archive_portal.css`):**
    - Expanded `.ea-modal-card-lg` to `width: 96vw; max-width: 1220px; max-height: 88vh;` to utilize full desktop/laptop canvas width.
    - Added explicit `direction: rtl; text-align: right;` to `.ea-modal-overlay`, `.ea-modal-card`, `.ea-modal-header`, `.ea-modal-toolbar`, `.ea-modal-body`, and `.ea-modal-footer`.
    - Enforced `overflow-x: hidden !important; overflow-y: auto !important;` on `.ea-modal-body` and `.ea-req-table-wrap`.
    - Structured `.ea-req-table` with flexible auto layout, distinct column widths, `word-break: break-word;` for names/descriptions, and `white-space: nowrap;` for IDs, badges, and dates.
    - Styled `.ea-table-actions` with `display: flex; flex-wrap: wrap; gap: 6px; justify-content: center;` so buttons neatly wrap vertically when viewport width decreases.
    - Added responsive breakpoints (`@media (max-width: 1200px)` and `@media (max-width: 768px)`).
  - **Portal Frontend (`archive_portal.js`):**
    - Updated `openAdminManageRequestsModal` toolbar with `.ea-modal-toolbar` and `.ea-modal-toolbar-filters` for natural RTL alignment.
    - Updated `loadAdminRequests` to output table within `.ea-req-table-wrap` with structured column headers and Persian digits.
    - Updated `openGroupRequestsModal` with responsive table wrap and RTL-safe column sizing.
  - **Cache Invalidation:**
    - Bumped app version in `info.xml` from `1.9.0` to `1.9.1`.
    - Synchronized with `nextcloud/custom_apps/archive_autotag/` and ran `php occ upgrade` to bust client asset caches.
- **Verification:**
    - Full test suite passed 100%: `test_folder_request_governance_v2.py`, `test_folder_request_workflow.py`, `test_archive_portal.py`.

Status: **Completed**

---

### Step 14.3 — Dynamic Parent Folder Tree & Group Archive Hierarchy Dropdown (v1.9.2)
- **Problem & Requirements:**
  1. Previously, in the folder creation request modal (`درخواست ایجاد پوشه جدید در آرشیو`), the parent path (`مسیر والد در آرشیو`) was a free-text input where group admins had to manually type paths (e.g. `افتا`), risking syntax errors and path mismatches.
  2. The user required that the parent path field be automatically and dynamically populated from the real-time directory tree of the requesting group, allowing the group admin to pick the target parent folder from a clean dropdown list (`<select>`).
- **Implementation & Architecture:**
  - **Backend Service (`FolderRequestService::getGroupFolders`):**
    - Recursively scans the group's physical archive root (`Enterprise_Archive/<groupId>/`) using `IRootFolder` and `Folder::getDirectoryListing()`.
    - Generates a structured hierarchy containing `path` (relative to group root), `name`, `level`, and formatted `display` label with indentation (e.g. `📁 ریشه گروه (اصلی)`, `📁 افتا`, `  ↳ 📁 افتا / گزارش‌ها`).
  - **Backend Controller & API (`FolderRequestController::getGroupFolders`):**
    - Registered endpoint `GET /api/group-folders?group_id=<groupId>`.
    - Enforced strict authorization: Admins can inspect any group; Group Admins can ONLY inspect groups where they hold subadmin privileges (rejecting cross-group attempts with `HTTP 403 Forbidden`).
  - **Frontend UI/UX (`archive_portal.js`):**
    - Replaced text input `#ea-form-target-path` with an interactive `<select id="ea-form-target-path" class="ea-form-select">`.
    - Automatically loads parent folders on modal open and dynamically refreshes when the selected group changes (`#ea-form-group-id.onchange`).
    - Defaults to `📁 ریشه گروه (اصلی)` (empty relative path).
  - **Automated Verification:**
    - `tests/test_group_folders_api.py`: 100% Passed across admin discovery, group admin discovery, regular user rejection (403), and cross-group spoofing rejection (403).
    - Full regression suites verified: `test_folder_request_governance_v2.py` (100%), `test_folder_request_workflow.py` (100%), `test_archive_portal.py` (100%).

Status: **Completed**

---

### Step 14.4 — CSP-Safe Event Handlers for Folder Request Approval & Rejection (v1.9.3)
- **Problem & Root Cause:**
  - Clicking "تأیید و ساخت" or "رد درخواست" in the Folder Request Review modal had no effect because the buttons used inline `onclick="..."` HTML attributes.
  - Nextcloud enforces strict Content Security Policy (CSP: `script-src 'self' 'nonce-...'`) without `'unsafe-inline'`, causing the browser to block inline handlers silently and prevent execution of approval/rejection workflows.
- **Implementation & Architecture:**
  - **CSP-Compliant DOM Event Listeners (`archive_portal.js`):**
    - Removed inline `onclick` attributes from `actionsHtml`.
    - Bound metadata to HTML5 data attributes (`data-id`, `data-folder-name`, `data-group-id`).
    - Attached explicit DOM click listeners (`btn.onclick = ...`) in `loadAdminRequests`.
    - Included CSRF token header (`requesttoken: window.OC.requestToken`) in all POST requests.
    - Added real-time loading feedback on buttons (`btn.innerText = '⏳ در حال ساخت...'` and `btn.innerText = '⏳ در حال ثبت...'`).
    - Upon approval/rejection, automatically reloaded the request list in-place (`loadAdminRequests(...)`) and refreshed the pending counter without closing the modal.
  - **Version & Cache Busting (`info.xml`):**
    - Bumped app version to `1.9.3` and executed `occ upgrade`.
- **Verification:**
  - All test suites passed 100%: `test_folder_request_governance_v2.py`, `test_folder_request_workflow.py`, `test_group_folders_api.py`.

Status: **Completed**

---

### Step 14.5 — Accurate Files App Navigation & 'Locate in Folder' Deep Linking (v1.9.4)
- **Problem & Root Cause:**
  - Clicking "مکان در پوشه" (Locate in Folder) in the Quick View Drawer opened the Files app at the root directory (`/`) instead of the file's containing folder.
  - Root cause: `TagFilterController.php` previously generated a legacy URL `/apps/files/?dir=...`. In modern Nextcloud (Vue 3 Files App), the route is mounted at `/apps/files/files`. Navigating to `/apps/files/` caused Vue Router to discard query parameters and default to `/`.
- **Implementation & Architecture:**
  - **Accurate Nextcloud Deep Linking (`TagFilterController.php`):**
    - Updated `web_url` to `/apps/files/files/{fileId}?dir={targetDir}&openfile=false`.
    - Added `folder_url` to `/apps/files/files?dir={targetDir}`.
    - Path normalization: ensured proper slash formatting (`$targetDir = '/' . ltrim(..., '/')`).
  - **Drawer UI & Navigation Guarantee (`archive_portal.js`):**
    - "مکان در پوشه" button now opens in a new tab (`target="_blank" rel="noopener noreferrer"`) with a reliable JavaScript click handler (`window.open(file.web_url, '_blank')`).
    - The file path in the drawer metadata is now also an interactive clickable link (`📁 مسیر فایل: ... ↗`) opening the parent folder.
  - **Version & Cache Invalidation:**
    - Bumped app version to `1.9.4` and executed `occ upgrade`.
- **Verification:**
  - All test suites passed 100%: `test_archive_portal.py`, `test_folder_request_governance_v2.py`, `test_folder_request_workflow.py`, `test_group_folders_api.py`.

Status: **Completed**

---

### Step 14.6 — Multi-Tag Filter In-Page SPA Navigation & Folder Deep-Linking (v1.9.5)
- **Problem & Root Cause:**
  - In Nextcloud Files App, clicking "مشاهده در پوشه" (View in Folder) in the embedded Multi-Tag search results table failed to navigate to the target directory.
  - Root causes identified:
    1. For directories/folders, `web_url` incorrectly included `/{fileId}` in the path (`/apps/files/files/{fileId}?dir=...`), which conflicted with Vue Router directory resolution for folders.
    2. In `multi_tag_filter.js`, links were unhandled standard `<a>` tags inside a Vue 3 SPA without explicit event listeners.
    3. The active tag filter overlay (`#archive-tag-results-container`) kept standard file list hidden (`display: none`), so any directory change behind it was not revealed.
- **Implementation & Architecture:**
  - **Directory vs File URL Distinction (`TagFilterController.php`):**
    - For folders: `web_url` and `folder_url` point directly to `/apps/files/files?dir={targetDir}` (no fileId parameter).
    - For files: `web_url` points to `/apps/files/files/{fileId}?dir={targetDir}&openfile=false`.
    - Added `target_dir` field to API file payloads.
  - **In-Page SPA Navigation & Filter Reset (`multi_tag_filter.js`):**
    - Attached standard click listeners to `.archive-nav-link` and `.archive-locate-btn`.
    - On click, clears active tag filter state (`state.selectedTagIds.clear()`), removes the results table container, and un-hides the native Nextcloud file list (`toggleStandardFileList(true)`).
    - Uses `window.OCP.Files.Router.goToRoute('filelist', { view: 'files' }, { dir: targetDir })` (and `{ fileid: fileId }` for files) for instantaneous in-page Vue Router navigation.
    - Fallback to `window.location.href = webUrl` and preserved standard new-tab opening for Ctrl/middle clicks.
  - **Version Bump & Cache Invalidation:**
    - Bumped app version to `1.9.5` in `info.xml` and executed `occ upgrade`.
- **Verification:**
  - All 4 test suites passed 100%: `test_archive_portal.py`, `test_folder_request_governance_v2.py`, `test_folder_request_workflow.py`, `test_group_folders_api.py`.

Status: **Completed**

---

### Step 14.7 — Canonical Nextcloud Deep-Linking (`/f/{fileId}`) & Native Navigation (v1.9.6)
- **Problem & Root Cause:**
  - Clicking "مکان در پوشه" (Locate in Folder) or the file path link in the quick-view drawer opened the Files app at the root or failed to navigate to the target directory.
  - Root causes identified:
    1. Query string `/` was encoded as `%2F` via `urlencode()`, which Vue Router and WebDAV treat literally rather than as directory path separators.
    2. An unnecessary `e.preventDefault()` on the drawer locate button intercepted native `<a>` tag navigation, making it subject to browser popup blockers.
- **Implementation & Architecture:**
  - **Canonical Nextcloud Deep-Link Endpoint (`/f/{fileId}?openfile=false`):**
    - Updated `TagFilterController.php` to generate `/f/{fileId}?openfile=false` for both files and folders.
    - Uses Nextcloud's built-in `ViewController::showFile` server-side redirector which dynamically resolves user-specific storage, relative pathing, proper query parameters, and unencoded path separators before issuing an HTTP 303 redirect directly to the Files app.
  - **Native Browser Tab Navigation (`archive_portal.js`):**
    - Removed `e.preventDefault()` from `#ea-drawer-locate-btn`, allowing modern browsers to natively open the target URL in a new tab via `target="_blank" rel="noopener noreferrer"` without triggering popup blockers.
  - **Version Bump & Cache Invalidation:**
    - Bumped app version to `1.9.6` in `info.xml` and executed `occ upgrade`.
- **Verification:**
  - Verified `/f/660?openfile=false` and `/f/547?openfile=false` return 303 redirect with 200 OK final destination for both Bakbari and Admin.
  - All 4 automated test suites passed 100%: `test_archive_portal.py`, `test_folder_request_governance_v2.py`, `test_folder_request_workflow.py`, `test_group_folders_api.py`.

Status: **Completed**

---
### Step 15 — Secure AI File Retrieval API & On-Premise AI Gateway (v2.0.0)
- **Problem & Requirement (Requirement 13):**
  - External local AI agents (e.g. running on CPU Intel i7) require programmatic, secure, and performant access to archived organizational files without exposing internal file paths or overloading server memory.
- **Implementation & Architecture:**
  - **Zero-RAM Chunked Binary Streaming:**
    - Implemented `AiRetrievalController::download()` using PHP stream wrappers (`fopen('php://output', 'wb')`) directly piped from Nextcloud's storage layer in 64KB chunks ($O(1)$ memory consumption regardless of file size).
  - **Dual-Mode Authentication & Subject-Bound Authorization:**
    - Supports standard HTTP Basic Auth and long-lived Bearer tokens.
    - Implemented `X-On-Behalf-Of` impersonation header with strict admin authorization (`UserSession` validation) preventing horizontal privilege escalation.
  - **Tamper-Evident Audit Trail (`oc_archive_ai_audit`):**
    - Every retrieval attempt (successful or rejected) is logged with SHA-256 integrity digest, timestamp, actor UID, target file ID, client IP, and response code.
  - **Interactive On-Premise Swagger UI & OpenAPI Spec:**
    - Embedded offline Swagger UI accessible at `/index.php/apps/archive_autotag/api/docs` with auto-configured CSRF token injection and dynamic endpoint definitions.
- **Verification:**
  - Comprehensive automated test suite `tests/test_ai_file_retrieval_api.py` passed 10/10 tests (Metadata, Content streaming, Audit logging, ACL enforcement, Bearer auth, Swagger UI).

Status: **Completed**

---

### Step 16 — Resolving Nextcloud 34 Exact Parent Folder Navigation & URL Masking Exclusions (v2.0.1)
- **Problem & Root Cause:**
  - Clicking "مکان در پوشه" (Locate in Folder) or the file path link in the quick-view drawer opened the Files app at the root directory (`/apps/files`) instead of the file's exact parent folder, especially for deep nested folders and non-ASCII (Persian) names (e.g., `/Enterprise_Archive/SOC/افتا/عملیات_امنیتی_۱۴۰۵`).
  - Deep architectural investigation identified two root causes:
    1. **Nextcloud 34 Core Controller Redirect Loop:**
       `OCA\Files\Controller\ViewController::index()` contains a check: `if ($fileid && $dir !== '')`. It compares `$relativePath !== $dir`. Because `$relativePath` has no leading slash and is decoded, while `$dir` has a leading slash and is URL-encoded, they never match. Calling `/f/{fileId}` caused an infinite 303 redirect loop with `openfile=true`, collapsing Vue Router back to root (`/apps/files/`).
    2. **Stealth URL Masking Interception:**
       `apps/archive_autotag/js/url_mask.js` previously executed a blanket `history.replaceState(..., '/')` every 150ms. When opening a new tab to `/index.php/apps/files/files?dir=...`, `url_mask.js` stripped the `?dir=...` query parameter before Vue Router finished mounting, causing it to fall back to the root folder.
- **Implementation & Architecture:**
  - **Direct Canonical Directory Deep-Linking (`TagFilterController.php`):**
    - Updated `folder_url` and `web_url` to bypass `/f/{fileId}` and directly output:
      `/index.php/apps/files/files?dir=` + `rawurlencode($targetDir)`.
    - Omitting `fileid` completely circumvents Nextcloud's buggy core check while delivering an instant HTTP 200 destination.
  - **Smart Navigation Route Guard in URL Masking (`url_mask.js`):**
    - Added explicit guard:
      ```javascript
      if (window.location.pathname.includes('/apps/files') && window.location.search.includes('dir=')) {
          return; // Do not mask address bar; preserve directory navigation parameter for Vue Router
      }
      ```
  - **Dual Client Navigation & State Persistence (`archive_portal.js` & `multi_tag_filter.js`):**
    - Click handlers for `#ea-drawer-locate-btn` and `.ea-drawer-path-link` store the target directory in `sessionStorage.setItem('ea_target_dir', targetDir)`.
    - If in the same window/SPA, cleanly invokes `window.OCP.Files.Router.goToRoute('filelist', { view: 'files' }, { dir: targetDir })`.
    - Fallback and native tab links point directly to `file.folder_url`.
    - In `multi_tag_filter.js`, on mount inside the Files app, reads `sessionStorage.getItem('ea_target_dir')` and navigates smoothly if arriving via SPA transition.
  - **Container Synchronization & Cache Invalidation:**
    - Synced changes to `/var/www/html/custom_apps/archive_autotag/` in Docker container `archive_app`.
    - Bumped app version to `2.0.1` in `appinfo/info.xml` and executed `occ upgrade` (updating asset hash buster).
- **Verification:**
  - Validated with both Administrator and standard users for deeply nested Persian directories (`/Enterprise_Archive/SOC/افتا/عملیات_امنیتی_۱۴۰۵`).
  - Automated test suite `tests/test_file_location_navigation.py` passed 5/5 tests with 100% success.
  - Regression verified: `test_url_masking.py` (5/5 passed), `test_ai_file_retrieval_api.py` (10/10 passed), `test_archive_portal.py` (4/4 passed).

Status: **Completed**

---

### Step 17 — Full-Width Canvas Harmonization & Multi-User Layout Guarantee (v2.0.3)
- **Problem & Root Cause:**
  - Standard users (e.g. `Bakbari`) experienced a boxed/narrow container with large left and right empty spaces in the Archive Portal, showing only 3 cards per row, whereas `admin` had a full-width edge-to-edge canvas with 4 cards per row.
  - Root causes identified:
    1. **Per-User Nextcloud Theming Overrides:** User `Bakbari` had personal theming preferences stored in `oc_preferences` (`background_image: hannah-maclean-soft-floral.jpg`, `primary_color: #9f652f`, `background_color: #e4d2c1`). When a custom background image or theme is active, Nextcloud core injects `--body-container-margin` and applies floating-card constraints on `#content` (`position: fixed; width: calc(100% - var(--body-container-margin) * 2)`), creating huge artificial margins.
    2. **CSS Specificity & Flexbox Shrinking:** `#content` and `#archive-portal-root` lacked absolute full-width overrides (`width: 100% !important; max-width: 100% !important; margin: 0 !important; position: static !important;`), allowing Nextcloud's core container rules to constrict the layout.
- **Implementation & Architecture:**
  - **Full-Width Canvas Guarantee (`archive_portal.css` & `app_menu_filter.css`):**
    - Enforced `:root { --body-container-margin: 0px !important; --body-container-radius: 0px !important; }`.
    - Overrode `#content.app-archive_autotag`, `#content`, `#app-content` to `width: 100% !important; max-width: 100% !important; margin-top: 50px !important; margin-left: 0 !important; margin-right: 0 !important; margin-bottom: 0 !important; padding: 0 !important; position: relative !important; border-radius: 0 !important; display: block !important;`.
    - Preserved 50px top header clearance (`margin-top: 50px !important;`) to prevent action buttons and title header from sliding underneath Nextcloud's fixed top toolbar.
    - Set `#archive-portal-root.archive-portal-app` to `flex: 1 1 100% !important; width: 100% !important; max-width: 100% !important; padding: 32px 28px 80px 28px !important;`.
    - Updated `.ea-container` to `width: 100% !important; max-width: 100% !important; margin: 0 !important;`.
  - **Theming & Color Unification:**
    - Purged per-user `oc_preferences` theming overrides for non-admin accounts, ensuring all users inherit the unified Obsidian & Industrial Orange corporate identity (`#f97316` / `#090b0e`).
  - **Cache Invalidation & Upgrade:**
    - Bumped app version to `2.0.2` in `appinfo/info.xml` and executed `occ upgrade`.
- **Verification:**
  - Verified `initial-state-theming-data` for Bakbari returns exact admin values (`primaryColor: #f97316`, `backgroundColor: #090b0e`, `inverted: true`).
  - Validated edge-to-edge layout, responsive 4-column card grid, and identical visual appearance across both standard and administrator users.
  - Regression verified: `test_file_location_navigation.py` (5/5), `test_url_masking.py` (5/5), `test_archive_portal.py` (5/5).

Status: **Completed**

---


---

### Step 18 — Custom Enterprise Archive Onboarding & 2-Slide Wizard
- **Problem & Business Need:**
  - Standard Nextcloud displayed consumer-oriented promotional slides upon login or clicking 'About': Slide 1 featured a celebratory banner with blue balloons for Nextcloud Hub 26 with a 'Skip' button, and Slide 2 featured generic Nextcloud marketing copy (Privacy, Productivity, Interoperability, Community) with external download links and documentation references.
  - In an isolated, high-security, Air-Gapped banking/enterprise archive system (`docs.maskan`), these screens broke organizational identity and violated security lockdown policies.
- **Implementation & Architecture:**
  - **Custom 2-Slide Architecture (`apps/archive_autotag/firstrunwizard_patch/`):**
    - **Slide 1 (Hero & Intro Splash):** Deep Obsidian (`#090b0e`, `#121620`) and Industrial Orange (`#f97316`) theme with custom SVG archival vault shield, glowing circuit nodes, formal title "سامانه جامع بایگانی اسناد سازمانی", English subtitle "Enterprise Document Archiving & Governance", air-gapped status pills, and action buttons.
    - **Slide 2 (Core Capabilities & Architecture):** Glassmorphic 2x2 card grid presenting the 4 core system pillars:
      1. Data Isolation & Security (Air-Gapped On-Premise isolation)
      2. Hierarchical Auto-Tagging (Automated metadata tagging)
      3. Multi-Tag Intersection Search (AND Filter engine)
      4. Enterprise Governance & Storage Policies (Quota limits & audit trail)
    - **Navigation & Lifecycle:** Carousel slider with RTL animation (`translateX`), clickable slide indicators, Esc/backdrop closure, and dismissal persistence via `DELETE /apps/firstrunwizard/wizard`.
  - **Repository & Bare-Metal Deployment Synchronization:**
    - Committed patch files into `apps/archive_autotag/firstrunwizard_patch/` (`main-DypLm1fH.chunk.mjs` and `firstrunwizard-style.css`).
    - Updated `deploy/deploy_from_scratch.sh` to automatically install the patch upon bare-metal deployment.
    - Deployed live to `nextcloud/apps/firstrunwizard/` and `nextcloud/custom_apps/archive_autotag/`.
- **Verification:**
  - Verified HTTP delivery of JS chunk and CSS stylesheet via Nginx/Apache.
  - Validated 18 core test suites passing across archive portal, app menu isolation, app store isolation, and air-gapped policies.
  - Documented in Requirement 10 (`docs/requirements/10_branding_masking_and_app_menu_filter.md`).

Status: **Completed**

## Repository Status

- Repository: `maherani/enterprise-archive-system`
- Branch: `main`
- Current Checkpoint: **Steps 1 through 18 fully completed, verified, and synchronized (v2.0.3).**

### 5. Dynamic Folder-Driven Tag Lifecycle & Reconciliation (`tests/test_tag_lifecycle_reconciliation.py`)
- **Step 1**: Folder creation triggers automatic tag registration & hierarchy tagging.
- **Step 2**: File upload into folder receives ancestor tags automatically.
- **Step 3**: Folder rename in-place updates tag name and updates descendant files, purging old tag.
- **Step 4**: Folder deletion automatically reconciles tags and purges surplus/orphaned tag.
- **Step 5**: CLI command `occ archive:tag:reconcile` executes cleanly with full audit table.
- **Result**: 100% Passed.

### 6. Secure AI File Retrieval API Test Suite (`tests/test_ai_file_retrieval_api.py`)
- **Metadata API**: Verifies document metadata, permissions, tags, and human-readable sizes.
- **Binary Stream Download**: Validates O(1) memory chunked streaming and byte-level payload integrity.
- **Audit Logging**: Confirms tamper-evident recording in `oc_archive_ai_audit` with SHA-256 verification.
- **ACL Enforcement**: Ensures unauthorized or unpermitted users receive strict 403 Forbidden.
- **Bearer Token Auth**: Validates programmatic machine-to-machine authentication.
- **Swagger UI**: Validates interactive documentation and OpenAPI 3.0 specification delivery.
- **Result**: 10/10 Passed (100%).

### 7. File Location Navigation & Deep Linking (`tests/test_file_location_navigation.py`)
- **Folder URL Endpoint Structure**: Validates canonical `/index.php/apps/files/files?dir=...` format.
- **Persian & Special Characters URL Encoding**: Validates correct `rawurlencode` handling for deep Persian paths.
- **HTTP Status 200 OK**: Verifies that navigating to folder URL returns 200 OK without redirect loop.
- **URL Masking Exclusion**: Verifies `url_mask.js` contains the guard preserving `dir=` in `/apps/files`.
- **Portal Drawer Binding**: Verifies `archive_portal.js` connects locate button and path link to `folder_url`.
- **Result**: 5/5 Passed (100%).
