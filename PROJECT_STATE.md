# PROJECT_STATE.md

## Objective

Build an **Enterprise Archive System** based on Nextcloud, PostgreSQL, and Nginx. The system is designed for secure, high-capacity, auditable document archiving with LDAP directory integration, compliance-driven retention policies, dynamic hierarchical tagging, and programmatic ingestion via API/WebDAV for automated systems and AI agents.

## Current Architecture

The architecture separates the public reverse proxy from internal services:

```text
[ External Clients / AI Agents / API ]
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
        |   archive_app    |  (Nextcloud Apache)
        |   (Internal:80)  |  - WebDAV Endpoint: /remote.php/dav/files/
        +--------+---------+  - LDAP & App API Authentication
                 |            - Custom App: archive_autotag (PSR-14 Event Engine)
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
│   └── archive_autotag/              # Custom native Nextcloud app for dynamic tagging & upload limit
│       ├── appinfo/info.xml
│       └── lib/
│           ├── AppInfo/Application.php
│           ├── Command/
│           │   ├── RetagAllCommand.php
│           │   └── UserLimitCommand.php
│           ├── Listener/             # NodeCreated, NodeWritten, NodeRenamed, SabrePluginInit
│           │   ├── BeforeNodeCreatedListener.php
│           │   ├── BeforeNodeWrittenListener.php
│           │   ├── NodeCreatedListener.php
│           │   ├── NodeRenamedListener.php
│           │   ├── NodeWrittenListener.php
│           │   └── SabrePluginInitListener.php
│           └── Service/
│               ├── AutoTagService.php
│               └── UploadLimitService.php
├── db/                               # PostgreSQL persistent data volume
├── deploy/                           # Deployment scripts & automation
├── nextcloud/                        # Nextcloud persistent HTML & data volume
│   └── custom_apps/archive_autotag/  # Mounted live runtime app in Nextcloud container
├── nginx/
│   └── default.conf                  # Nginx reverse proxy & large-upload configuration
├── tests/
│   └── test_dynamic_archive_system.py # Automated E2E verification test suite (5 requirements)
├── venv/                             # Python virtual environment for automation scripts
├── .env                              # Environment credentials (git-ignored)
├── .gitignore
├── docker-compose.yml                # Core service definitions (db, app, proxy)
├── PROJECT_STATE.md                  # Primary repository memory and progress tracking
├── README.md                         # Project description and quick start guide
├── requirements.txt                  # Python dependencies (requests, urllib3, etc.)
└── test_api.py                       # Automated WebDAV upload test script
```

## Implemented Steps

### Step 1 — Environment Setup & Base Dependencies
- Initialized local Git repository and branch main.
- Created Python virtual environment (`venv`).
- Configured .gitignore for `venv/`, environment files, and sensitive directories.

Status: **Completed**

### Step 2 — Core Services via Docker Compose
- Created docker-compose.yml with PostgreSQL 15 and Nextcloud Apache.
- Implemented database healthcheck (pg_isready) for resilient container ordering.
- Configured .env file for credentials and trusted domain definitions.

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
- Developed `test_api.py` utilizing `requests` for WebDAV document ingestion (`audit_report_2026.txt`).
- Successfully verified automated upload via WebDAV API.

Status: **Completed**

### Step 5 — Nginx Reverse Proxy & Large-Upload Optimization
- Configured Nginx reverse proxy in `nginx/default.conf`.
- Tuned configuration for archive workloads:
  - `client_max_body_size 10G;` for large-scale enterprise backups and document archives.
  - `proxy_request_buffering off;` and `proxy_buffering off;` for streaming large files without disk write bottlenecks.
  - Extended proxy timeouts to 3600s for large payload transfers.
  - CalDAV / CardDAV service discovery redirects.
- Updated docker-compose.yml:
  - Added proxy service (`nginx:alpine`) on port 80:80.
  - Removed host port 8080 from `archive_app`, isolating the core application within Docker network.
- Configured Nextcloud `trusted_proxies` and `overwriteprotocol` via occ.
- Updated `test_api.py` to route traffic through Nginx on port 80 and verified HTTP 201/204 response.

Status: **Completed**

### Step 6 — Admin Folder Governance & Dynamic Hierarchical System Auto-Tagging
- Developed and enabled native Nextcloud custom application `archive_autotag` (v1.0.0) in `custom_apps/archive_autotag`:
  - Implemented PSR-14 event listeners: `NodeCreatedEvent`, `NodeWrittenEvent`, and `NodeRenamedEvent`.
  - **Dynamic Hierarchical Tagging:** Automatically traverses folder hierarchy up to the archive root and applies all parent folder tags (e.g. `/Enterprise_Archive/Finance/2026/Invoices_Archive/file.pdf` receives tags `Enterprise_Archive`, `Finance`, `2026`, `Invoices_Archive`).
  - **Protected System Tags:** Tags are created with `restricted` access (`userVisible=true`, `userAssignable=false`). Users can view and filter by these tags, but regular users are strictly forbidden from deleting or modifying them (HTTP 403 Forbidden).
  - **Folder Rename Propagation:** Renaming an archive folder (e.g., `Invoices` -> `Invoices_Archive`) automatically propagates to all descendant files, detaching the old tag and assigning the new tag.
  - **Admin Folder Governance:** User personal quota set to `0 B`, preventing regular users from creating personal storage folders/files (HTTP 507 Insufficient Storage). Users only operate within Admin-created and Admin-shared archive folders.
  - **Collaborative User Tagging:** Authorized users can freely assign and remove public collaborative tags (e.g. `Audited_OK`, `Verified_E2E`).
  - Added OCC CLI command `occ archive:retag [<user>]` for batch and retroactive scanning.
  - **Configurable Per-User File Upload Size Limit:**
    - Admin can set granular maximum upload size limits per user via `occ archive:user:limit <user> <limit>` (e.g. `10M`, `500M`, `1G`, or `0` for unlimited).
    - WebDAV storage engine enforcement via `SabrePluginInitListener` hooking `beforeCreateFile` and `beforeWriteContent`. Inspects `Content-Length` before storage and terminates oversized uploads immediately with `HTTP 403 Forbidden`.
    - Secondary filesystem stream enforcement via `BeforeNodeCreatedListener` and `NodeWrittenListener` to guarantee zero bypass even on chunked uploads.
  - Built comprehensive automated verification test suite in `tests/test_dynamic_archive_system.py` verifying all 6 requirements with 100% pass rate.
  - **Admin-Only Folder Hierarchy Protection (File Upload Allowed, Folder Creation Prohibited):**
    - Decoupled document uploading from folder creation. Regular users retain full permission to upload files into existing archive folders, while folder and subfolder creation (WebDAV `MKCOL` and `mkdir`) is strictly restricted to Administrators.
    - Implemented `FolderPolicyService` and enhanced `SabrePluginInitListener` and `Application::preMkdirHook` to reject unauthorized folder creation with `HTTP 403 Forbidden`.
    - Added administrative OCC command `occ archive:folder:policy [status|enable|disable]` for runtime policy control.
    - Created and executed dedicated automated verification test suite `tests/test_folder_creation_restriction.py` with 100% pass rate.

Status: **Completed**

## Major Lessons Learned

- Native Nextcloud permissions bundle file creation and folder creation into a single permission flag (`PERMISSION_CREATE`). Decoupling these capabilities in enterprise archiving requires intercepting the `MKCOL` WebDAV method and `mkdir` filesystem hooks via custom Sabre plugins, enabling users to upload documents while preventing unauthorized folder tree sprawl.

- Directly exposing the application container (`archive_app`) bypasses reverse proxy buffering and timeout controls. Placing Nginx in front standardizes the entry point and isolates Nextcloud.
- When Nextcloud is behind a reverse proxy, `trusted_proxies` and `overwriteprotocol` must be explicitly configured in config.php via occ config:system:set.
- Enterprise archives deal with multi-gigabyte files; disabling proxy_request_buffering in Nginx is required to avoid memory and disk saturation during large WebDAV uploads.
- Application passwords in Nextcloud are cryptographically bound to system salt/secret parameters. Whenever credentials or configuration are updated, app tokens must be managed using `occ user:add-app-password`.
- Nextcloud App Store metadata retrieval can be a bottleneck in restricted or slow network environments; native PSR-14 custom applications installed into `custom_apps` provide zero-latency, offline-capable, and completely custom business logic.
- Restricted system tags (`userVisible=true, userAssignable=false`) are essential for enterprise compliance because they prevent regular users from deleting or altering audit tags, while public tags remain available for collaborative workflows.
- Setting regular user storage quota to `0 B` enforces strict governance, preventing clutter in user personal roots and ensuring all archived assets reside within Admin-governed folder structures.

## Repository Status

- Repository: `maherani/enterprise-archive-system`
- Branch: `main`
- Current project checkpoint: **Step 6 completed and verified**.
- GitHub Remote: synchronizing native app code, test suite, and state documentation.
- Runtime data and credentials remain outside version control as intended.

## Pending Work & Next Roadmap

### Future Enhancements
- **Step 7**: Audit Logging (`admin_audit`) to trace file access and downloads.
- **Step 8**: SSL/TLS certificate termination via Let's Encrypt / Certbot in Nginx.
- **Step 9**: Automated backup and disaster recovery scripts in `deploy/`.
- **Step 10**: Scheduled retention cleanup and automated reporting agent.

## Development Rules

1. No step is complete without a successful test.
2. Do not move to the next step before the current step is tested.
3. Documentation must be updated during development.
4. Use one recommended solution instead of presenting multiple alternatives.
5. Add useful comments to new or modified code.
6. Commit and push after every completed step.
7. Keep PROJECT_STATE.md synchronized with the actual project state.
8. Explain the reason for every installation, file creation, tool usage, code change, and configuration change.
9. Keep the project state clear enough to continue development in a new chat.
10. Before starting a new development step, verify documentation and GitHub state.
11. Prefer inspection before modification.
12. Never remove existing data or functionality without first verifying its purpose and impact.



### Step 7 - Database Persistence Hardening & WSL2 Operational Reliability (Verified)
- **Root Cause Analysis**:
  - Investigated reported data loss upon container startup.
  - Identified race condition between Docker Desktop auto-start on Windows boot and WSL2 distro mount availability when using restart: always.
  - Identified that presence of NEXTCLOUD_ADMIN_USER and NEXTCLOUD_ADMIN_PASSWORD in docker-compose.yml environment could trigger automated re-install if mount latency occurred.
  - Observed user execution of docker compose down -v in shell history which clears volumes.
- **Architectural Hardening**:
  - Updated docker-compose.yml: switched restart policy to unless-stopped and stripped runtime admin bootstrap variables to prevent automatic installer execution.
  - Developed and verified operational maintenance suite in deploy/:
    - deploy/backup_db.sh: Automated full PostgreSQL dump creation with latest_db_backup.sql tracking.
    - deploy/restore_db.sh: Database reset and snapshot restoration with connection termination and file cache rescan.
    - deploy/check_health.sh: System status reporting validating container health, user entries, group memberships, and archive directory tree.
  - Configured .gitignore to protect backups and SQL dumps from repository commits.
  - Validated 100% data persistence across container stop/start/recreate cycles with all 4 accounts (admin, api_worker, archive_user1, maherani) and full folder/tag hierarchies intact.


### Step 8 - AI-Ready Knowledge Base & On-Premise LLM Integration Roadmap (Planned)
- **Objective**: Transform the categorized archive into a structured, air-gapped knowledge base feeding an on-premise Large Language Model (LLM) to perform automated project progress evaluation, financial document discrepancy checking, and executive reporting.
- **Hardware & Resource Profile**:
  - Target CPU: 13th Gen Intel Core i7-1355U (12 vCPUs, AVX2 architecture).
  - Target RAM: 8 GB (utilizing ~1.5 GB allocated budget for quantized model inference).
  - Zero-GPU: 100% CPU inference without requiring dedicated graphics cards.
- **Security & Air-Gap Compliance**:
  - Zero Data Egress: All parsing, indexing, and LLM reasoning run completely on-premise without cloud API dependencies.
  - Temporary network access permitted only during dependency bootstrapping and one-time GGUF model download.
- **Architecture Highlights**:
  - Local CPU document extraction: `pypdf`, `openpyxl`, `python-docx` (`ai_engine/`).
  - Offline metadata and fast full-text index: `SQLite FTS5` (`ai_engine/archive_kb.sqlite`).
  - Local LLM engine: Quantized 4-bit GGUF (`Qwen2.5-1.5B-Instruct`) running via `llama.cpp`.
  - Comprehensive documentation codified in [docs/AI_ON_PREMISE_ARCHITECTURE.md](docs/AI_ON_PREMISE_ARCHITECTURE.md).


### Step 9 - Strict User Account Governance & Role Boundary Enforcement (Verified)
- **Problem & Root Cause**:
  - Addressed vulnerability where non-system-admin accounts on external environments could acquire unauthorized deletion or modification privileges.
- **Architectural Hardening**:
  - Registered `BeforeUserDeletedListener` in `apps/archive_autotag`: Intercepts `BeforeUserDeletedEvent` and blocks account deletion with `403 Forbidden` if initiated by anyone other than a full System Administrator (`admin` group).
  - Maintained Nextcloud native subadmin isolation: Group Administrators are restricted strictly to modifying users within their assigned group and cannot access or modify users from other groups.
  - Implemented `deploy/set-group-quota.sh`: Automation script to query group members via OCC JSON output and batch-configure personal storage quotas (e.g. `0 B` or custom limits) across all members of a group.
  - Implemented `deploy/audit_user_roles.sh`: Operational audit tool to identify user memberships, detect any unauthorized `admin` accounts, and provide quick remediation commands across environments.
  - Built comprehensive automated test suite `tests/test_user_governance.py` validating:
    1. System Admin full authority (modify + delete).
    2. Group Admin authority within assigned group.
    3. Group Admin cross-group modification isolation (rejection).
    4. Group Admin deletion rejection (403 Forbidden).
    5. Regular user total management prohibition (rejection).
  - All 5 test cases verified with 100% success rate.
