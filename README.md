# Enterprise Archive System

A secure, scalable, and audit-compliant document archiving system built on **Nextcloud**, **PostgreSQL**, and **Nginx**.

## Architecture Overview

`	ext
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
                 │
                 ▼
        ┌──────────────────┐
        │    archive_db    │  (PostgreSQL 15 Alpine)
        │  (Internal:5432) │  - Database: nextcloud
        └──────────────────┘
`

## Features

- **Isolated Network**: Application and database are not exposed directly to the host network; all external access flows through Nginx.
- **Enterprise-Scale Ingestion**: Configured for up to 10GB file uploads with unbuffered streaming for minimal memory footprint.
- **LDAP Integration Ready**: Nextcloud LDAP backend enabled for enterprise identity management.
- **Automated API Integration**: WebDAV file ingestion for automated workers and AI agents.
- **Role-Based Compliance**: Compliance groups and service accounts with granular permissions.

## Getting Started

### 1. Prerequisites
- Docker & Docker Compose
- Python 3.10+ (for integration test scripts)

### 2. Configuration
Create a .env file based on your environment:
`ini
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud_user
POSTGRES_PASSWORD=YourSecurePassword
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=YourAdminPassword
NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1
`

### 3. Launch Services
`ash
docker compose up -d
`

### 4. Test Ingestion via API
Activate the Python environment and run the upload test:
`ash
source venv/bin/activate
python test_api.py
`

## Project State & Documentation
See [PROJECT_STATE.md](PROJECT_STATE.md) for full architecture records, step logs, and planned roadmap items.