# PROJECT_STATE.md

## Objective

Build an **Enterprise Archive System** based on Nextcloud, PostgreSQL, and Nginx. The system is designed for secure, high-capacity, auditable document archiving with LDAP directory integration, compliance-driven retention policies, and programmatic ingestion via API/WebDAV for automated systems and AI agents.

## Current Architecture

The architecture separates the public reverse proxy from internal services:

`	ext
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
                 |
                 v
        +------------------+
        |    archive_db    |  (PostgreSQL 15 Alpine)
        |  (Internal:5432) |  - Database: nextcloud
        +------------------+
`

### Current File Structure

`	ext
enterprise-archive-system/
├── db/                         # PostgreSQL persistent data volume
├── deploy/                     # Deployment scripts & automation
├── nextcloud/                  # Nextcloud persistent HTML & data volume
├── nginx/
│   └── default.conf            # Nginx reverse proxy & large-upload configuration
├── venv/                       # Python virtual environment for automation scripts
├── .env                        # Environment credentials (git-ignored)
├── .gitignore
├── docker-compose.yml          # Core service definitions (db, app, proxy)
├── PROJECT_STATE.md            # Primary repository memory and progress tracking
├── README.md                   # Project description and quick start guide
├── requirements.txt            # Python dependencies (requests, urllib3, etc.)
└── test_api.py                 # Automated WebDAV upload test script
`

## Implemented Steps

### Step 1 — Environment Setup & Base Dependencies
- Initialized local Git repository and branch main.
- Created Python virtual environment (env).
- Configured .gitignore for env/, environment files, and sensitive directories.

Status: **Completed**

### Step 2 — Core Services via Docker Compose
- Created docker-compose.yml with PostgreSQL 15 and Nextcloud Apache.
- Implemented database healthcheck (pg_isready) for resilient container ordering.
- Configured .env file for credentials and trusted domain definitions.

Status: **Completed**

### Step 3 — Container Deployment & LDAP Module Activation
- Successfully deployed containers on isolated bridge network rchive_net.
- Enabled official Nextcloud LDAP user backend (user_ldap) via occ app:enable user_ldap.
- Verified database and application readiness.

Status: **Completed**

### Step 4 — Compliance Group, API Worker & WebDAV Upload Test
- Created dedicated group Compliance_Unit.
- Provisioned service account pi_worker and assigned it to Compliance_Unit.
- Generated dedicated application token for non-interactive API access.
- Developed 	est_api.py utilizing equests for WebDAV document ingestion (udit_report_2026.txt).
- Successfully verified automated upload via WebDAV API.

Status: **Completed**

### Step 5 — Nginx Reverse Proxy & Large-Upload Optimization
- Configured Nginx reverse proxy in 
ginx/default.conf.
- Tuned configuration for archive workloads:
  - client_max_body_size 10G; for large-scale enterprise backups and document archives.
  - proxy_request_buffering off; and proxy_buffering off; for streaming large files without disk write bottlenecks.
  - Extended proxy timeouts to 3600s for large payload transfers.
  - CalDAV / CardDAV service discovery redirects.
- Updated docker-compose.yml:
  - Added proxy service (
ginx:alpine) on port 80:80.
  - Removed host port 8080 from rchive_app, isolating the core application within Docker network.
- Configured Nextcloud 	rusted_proxies and overwriteprotocol via occ.
- Updated 	est_api.py to route traffic through Nginx on port 80 and verified HTTP 201/204 response.

Status: **Completed**

## Major Lessons Learned

- Directly exposing the application container (rchive_app) bypasses reverse proxy buffering and timeout controls. Placing Nginx in front standardizes the entry point and isolates Nextcloud.
- When Nextcloud is behind a reverse proxy, 	rusted_proxies and overwriteprotocol must be explicitly configured in config.php via occ config:system:set.
- Enterprise archives deal with multi-gigabyte files; disabling proxy_request_buffering in Nginx is required to avoid memory and disk saturation during large WebDAV uploads.
- Application passwords in Nextcloud are cryptographically bound to system salt/secret parameters. Whenever credentials or configuration are updated, app tokens must be managed using occ user:add-app-password.

## Repository Status

- Repository: maherani/enterprise-archive-system
- Branch: main
- Current working state: Fully operational and verified.

## Pending Work & Next Roadmap

### Immediate Next Step: Step 6
- **Retention Rules & Automated Tagging**:
  - Enable and configure iles_retention and iles_automatedtagging apps.
  - Define WORM (Write Once, Read Many) compliance policies so archived documents cannot be altered or prematurely deleted by regular users.

### Future Enhancements
- **Step 7**: Audit Logging (dmin_audit) to trace all file access and downloads.
- **Step 8**: SSL/TLS certificate termination via Let's Encrypt / Certbot in Nginx.
- **Step 9**: Automated backup and disaster recovery scripts in deploy/.
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