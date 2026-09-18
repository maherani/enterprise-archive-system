# Enterprise Archive System

A secure, scalable, and audit-compliant enterprise document archiving system built on **Nextcloud**, **PostgreSQL**, and **Nginx**.

## Architecture Overview

```text
[ External Clients / AI Agents / Web Browser / WebDAV API ]
                             ?
                         HTTP :80 (or 443 SSL)
                             ?
                    ????????????????????
                    ?  archive_proxy   ?  (Nginx Alpine - Reverse Proxy)
                    ?  (Port 80:80)    ?  - Large file upload (10GB)
                    ????????????????????  - Request buffering disabled
                             ?  (archive_net bridge)
                             ?
                    ????????????????????
                    ?   archive_app    ?  (Nextcloud 34 Apache)
                    ?   (Internal:80)  ?  - WebDAV Endpoint: /remote.php/dav/files/
                    ????????????????????  - LDAP & Token Authentication
                             ?            - Custom App: archive_autotag v2.0.0
                             ?            - Dynamic Hierarchical Auto-Tagging
                             ?            - Native Multi-Tag Intersection Search (AND)
                             ?            - Granular Per-User File Upload Size Limit
                             ?            - Admin-Only Folder Policy Enforcement
                             ?            - Strict Account Governance (Admin-only deletion)
                             ?            - Strict Per-User File Access Isolation (ACL)
                             ?            - Dynamic Tag Ownership & Visibility Isolation
                             ?
                    ????????????????????
                    ?    archive_db    ?  (PostgreSQL 15 Alpine)
                    ?  (Internal:5432) ?  - Database: nextcloud
                    ?                  ?  - Tables: oc_archive_file_ownership
                    ?                  ?           oc_archive_file_grants
                    ?                  ?           oc_archive_tag_ownership
                    ????????????????????
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
9. **Native Multi-Tag Intersection Filter (`archive_autotag v1.8.0`)**:
   - Interactive, Persian RTL-aware filter toolbar embedded directly into the Nextcloud Files Web UI.
   - Allows users to select multiple tags simultaneously (e.g. `????` AND `??????? ??????`), narrowing documents strictly by logical mathematical intersection.
   - Displays real-time matching document counts, full archive paths, human-readable file sizes, direct folder navigation, and instant downloads with strict ACL isolation.
10. **Zero-to-Production Automated Bare Server Deployment (`deploy/deploy_from_scratch.sh`)**:
    - Complete turnkey deployment script enabling immediate, single-command setup on clean Ubuntu 22.04 / 24.04 LTS servers.
11. **Granular Per-User File Access Control & Ownership Isolation (ACL)**:
    - Every regular user strictly only has access to files they personally uploaded or an administrator explicitly granted them access to.
    - Deeply integrated into the WebDAV/SabreDAV kernel (`propFind` and `beforeMethod` hooks): unauthorized files are suppressed from directory listings (`PROPFIND`), and direct access attempts (`GET`, `DELETE`, `PROPFIND`, `PUT`) return `HTTP 404 Not Found`.
    - Administrators retain full system-wide visibility and management rights across all files.
    - Admin file grant CLI: `occ archive:file:grant [grant|revoke|list|set-owner]`.
12. **Dynamic Tag Ownership & Visibility Isolation**:
    - Custom `IsolatedSystemTagManager` and `IsolatedManagerFactory` intercepting tag queries and modifications.
    - Regular users only see tags they created themselves or system/admin-generated hierarchical tags.
    - Private tags created by user A are strictly hidden from user B (omitted from `/api/tags` and return `HTTP 404` in WebDAV).
    - System/Admin tags remain globally visible to all archive users.
    - Administrators have full authority to view, modify, or delete any tag (`occ archive:tag:gov`).
13. **Enterprise Archive Portal UI/UX & Obsidian-Orange Design System (v1.7.1)**:
    - High-performance, authoritative Enterprise Archive Portal with deep Obsidian (`#090b0e`, `#11141b`) and Industrial Orange (`#f97316`) theme.
    - Full-width canvas guarantee, instant debounce search, multi-tag faceted chips, and view switching (Card Grid vs. Table List).
    - Dynamic responsive push workspace (Accordion sliding): Opening the right-docked quick-view drawer smoothly compresses the document workspace to the left, guaranteeing that menus never obstruct files or cards.
    - Comprehensive dark theme applied globally to the Top Navigation Header, Files App, and Login page.
14. **Delegated Folder Creation Workflow & Multi-Tier Governance (`archive_autotag v2.0.0`)**:
    - Regular users are strictly prohibited from creating folders or submitting folder creation requests (`HTTP 403 Forbidden`).
    - Group Administrators (Subadmins) possess dedicated portal controls (`[ + درخواست پوشه جدید ]` and `[ درخواست‌های گروه ]`) to submit folder requests within their departmental archive scope.
    - System Administrators have centralized oversight via `[ مدیریت درخواست‌های پوشه ]` with real-time pending badge counter, multi-criteria filtering, and one-click atomic approval / reasoned rejection.
    - Atomic Approval: Provisions physical folder, configures group share inheritance (Read + Create), creates restricted system tag, and binds tag to requesting group.
    - Full audit logging for rejections with mandatory reason visible to group admins.

---

## Current Project State

- **Step 1 ? Baseline Infrastructure**: Nextcloud + PostgreSQL + Redis (Verified)
- **Step 2 ? User Directory Integration**: OpenLDAP / Active Directory connector (Configured)
- **Step 3 ? High-Capacity Ingestion & Proxy**: Nginx reverse proxy with 10GB unbuffered uploads (Verified)
- **Step 4 ? Automated Ingestion & API Authentication**: WebDAV token authentication (Verified)
- **Step 5 ? Compliance Group & Service Accounts**: Audited role structure (Verified)
- **Step 6 ? Folder Governance, Dynamic Hierarchical Tagging & User Upload Limits**: Verified with 100% pass rate.
- **Step 7 ? Audit Logging & Health Monitoring**: `admin_audit` enabled and verified.
- **Step 8 ? Data Persistence, Automated Backup & Recovery**: Resilient volumes, `backup_db.sh`, `restore_db.sh`, `check_health.sh`.
- **Step 9 ? Strict User Account Governance**: Admin-only user deletion and group admin isolation verified.
- **Step 10 ? Multi-Tag Intersection Filter**: Web UI integration and REST API verified via automated E2E tests and live browser CDP runs.
- **Step 11 ? Bare-Metal Deployment Automation**: `deploy/deploy_from_scratch.sh`, `.env.example`, and updated runbook.
- **Step 12 ? Per-User File Access Control & Tag Isolation (v1.4.0)**: Zero-data-leak file and tag isolation, admin grant workflows, and automated E2E test suite `tests/test_archive_acl_and_tag_isolation.py` (100% pass rate).
- **Step 13 ? Enterprise Archive Portal UI/UX & Obsidian-Orange Design System (v1.7.1)**: Modern enterprise dashboard, dynamic sliding push workspace, global Obsidian-Orange theme, cache busting, and automated test suite `tests/test_archive_portal.py` (100% pass rate).
- **Step 14 — Delegated Folder Creation Workflow & Advanced Governance (v1.9.6)**: Duplicate physical & pending folder prevention, complete 10-point audit trail (`oc_archive_folder_request_audit`), native Nextcloud notifications, visual audit timeline modal, dynamic parent folder tree dropdown, CSP-safe action button handlers, canonical Nextcloud `/f/{fileId}` deep-linking and native non-blocked navigation, and automated test suites (100% pass rate).

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
# Manage file access grants (Grant, Revoke, List, Set-Owner)
docker compose exec app php occ archive:file:grant grant <file_id> <username>
docker compose exec app php occ archive:file:grant grant <file_id> <group_name> --group
docker compose exec app php occ archive:file:grant revoke <file_id> <username>
docker compose exec app php occ archive:file:grant list <file_id>

# Synchronize, audit, and reconcile tags (remove surplus/orphan tags and dead mappings)
docker compose exec app php occ archive:tag:reconcile
docker compose exec app php occ archive:tag:gov reconcile

# Manage tag governance (List, Delete)
docker compose exec app php occ archive:tag:gov list
docker compose exec app php occ archive:tag:gov delete <tag_id>

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

# Test 1: File Access Control (ACL) and Tag Isolation
python3 tests/test_archive_acl_and_tag_isolation.py

# Test 2: Full governance, dynamic hierarchical tagging, and upload limits
python3 tests/test_dynamic_archive_system.py

# Test 3: Strict user account governance and role boundaries
python3 tests/test_user_governance.py

# Test 4: Multi-tag intersection filtering (AND logic) and ACL isolation
python3 tests/test_multi_tag_filter.py

# Test 5: Dynamic Folder-Driven Tag Lifecycle & Automatic Reconciliation
python3 tests/test_tag_lifecycle_reconciliation.py

# Test 6: Enterprise Archive Portal UI/UX & Integration Verification
python3 tests/test_archive_portal.py

# Test 7: Delegated Folder Creation Workflow & Multi-Tier Governance
python3 tests/test_folder_request_workflow.py

# Test 8: Vertical Scroll & Non-Destructive Layout Isolation
python3 tests/test_vertical_scroll_and_layout.py

# --- Master Test Suite Runner (All 17 Automated Suites) ---
python3 run_all_tests.py
```

---

## Data Persistence & Operational Utilities

Automated operations scripts are available in `deploy/`:
- **Automated Bare Server Installer**: `./deploy/deploy_from_scratch.sh`
- **Full Nextcloud & Database Backup**: `./deploy/backup_db.sh` (Produces complete portable backup with SHA256 verification)
- **Disaster Recovery Restore**: `./deploy/restore_db.sh [backup.tar.gz]` (Atomic full-system restore with credential synchronization)
- **System Health & Integrity Check**: `./deploy/check_health.sh`
- **Batch Group Quota Provisioning**: `./deploy/set-group-quota.sh <group> <quota>`
- **User Role Audit & Remediation**: `./deploy/audit_user_roles.sh`
13. **Secure AI File Retrieval API & Air-Gapped Swagger UI (`archive_autotag v2.0.0`)**:
    - High-throughput, memory-constant ($O(1)$ RAM) binary file streaming API (`GET /api/v1/ai/files/{fileId}`) designed for on-premise AI assistants and RAG pipelines.
    - Dual-mode authentication: HTTP Basic Auth (user credentials) and Dedicated AI Machine Bearer Token (`Authorization: Bearer <token>`) with optional dynamic delegation (`X-On-Behalf-Of: <user_uid>`).
    - Strict enforcement of the enterprise Archive ACL via `FileOwnershipService` preventing cross-department access and IDOR attacks.
    - 100% self-hosted, air-gapped Swagger UI (`/api/docs`) and OpenAPI 3.0.3 specification (`/api/openapi.json`) without any external CDN dependencies.
    - Immutable audit trail recorded in PostgreSQL table `oc_archive_ai_audit` with correlation IDs (`X-Request-ID`), actor UID, client ID, auth type, byte count, and outcome.

