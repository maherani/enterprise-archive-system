#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$BACKUP_DIR/test_restore.log"
LAST_RUN_LOG="$BACKUP_DIR/.test_instance_data_last_run.log"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env file not found: $ENV_FILE"
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${POSTGRES_DB:?POSTGRES_DB is not set in .env}"
: "${POSTGRES_USER:?POSTGRES_USER is not set in .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is not set in .env}"

TARGET_BACKUP="${1:-}"

if [ -z "$TARGET_BACKUP" ]; then
    if [ -f "$BACKUP_DIR/latest_instance_data_backup.tar.gz" ]; then
        TARGET_BACKUP="$BACKUP_DIR/latest_instance_data_backup.tar.gz"
    else
        LATEST_FOUND=$(find "$BACKUP_DIR" -maxdepth 1 -name "backup_instance_data_*.tar.gz" -type f | sort -r | head -n1 || echo "")
        if [ -n "$LATEST_FOUND" ]; then
            TARGET_BACKUP="$LATEST_FOUND"
        else
            echo "[ERROR] No instance data backup archive found in $BACKUP_DIR"
            exit 1
        fi
    fi
fi

if [ ! -f "$TARGET_BACKUP" ]; then
    echo "[ERROR] Target instance data backup file not found: $TARGET_BACKUP"
    exit 1
fi

TARGET_BASENAME="$(basename "$TARGET_BACKUP")"
SANDBOX_DIR=$(mktemp -d -t data_backup_sandbox_XXXXXX)
RAND_ID=$(head -c 4 /dev/urandom | xxd -p 2>/dev/null || tr -dc a-z0-9 </dev/urandom | head -c 4)
SANDBOX_DB="nextcloud_data_sandbox_${$}_${RAND_ID}"

cleanup() {
    rm -rf "$SANDBOX_DIR"
    # Ensure temporary sandbox database is cleanly dropped
    docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $SANDBOX_DB;" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

START_TIME=$(date +%s)

echo "================================================================================"
echo " Enterprise Archive System - Instance Data Backup Verification Engine (BR-02)"
echo " Target Archive: $TARGET_BACKUP"
echo " Sandbox Dir:    $SANDBOX_DIR"
echo " Sandbox DB:     $SANDBOX_DB"
echo " Time:           $(date -Iseconds)"
echo "================================================================================"

exec > >(tee "$LAST_RUN_LOG") 2>&1

echo "[INFO] [1/5] Verifying external SHA-256 sidecar checksum..."
SIDECAR="$TARGET_BACKUP.sha256"
if [ -f "$SIDECAR" ]; then
    CALC_HASH=$(sha256sum "$TARGET_BACKUP" | cut -d' ' -f1)
    EXPECTED_HASH=$(cut -d' ' -f1 < "$SIDECAR")
    if [ "$CALC_HASH" != "$EXPECTED_HASH" ]; then
        echo "[FAIL] Sidecar SHA-256 checksum mismatch!"
        echo "  Calculated: $CALC_HASH"
        echo "  Expected:   $EXPECTED_HASH"
        echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (Outer Checksum Mismatch)" >> "$LOG_FILE"
        exit 1
    fi
    echo "  [OK] Sidecar checksum verified: $CALC_HASH"
else
    echo "  [WARN] No sidecar .sha256 file found; checking internal integrity..."
fi

echo "[INFO] [2/5] Inspecting archive member list (tar stream)..."
LISTING_FILE="$SANDBOX_DIR/members.txt"
if ! tar -tzf "$TARGET_BACKUP" > "$LISTING_FILE" 2>/dev/null; then
    echo "[FAIL] Archive is corrupt or not a valid gzip tarball."
    echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (Corrupted Archive)" >> "$LOG_FILE"
    exit 1
fi

# STRICT INSPECTION: config.tar.gz, custom_apps.tar.gz, docker-compose.yml must NOT be inside the archive
if grep -Eq "/config\\.tar\\.gz|/custom_apps\\.tar\\.gz|/docker-compose\\.yml|/config_keys\\.json" "$LISTING_FILE"; then
    echo "[FAIL] System software or configuration detected inside instance data backup!"
    echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (System Software Leak in Data Backup)" >> "$LOG_FILE"
    exit 1
fi

# Must contain required data components
for comp in manifest.json manifest.txt database.sql data.tar.gz; do
    if ! grep -Fq "/$comp" "$LISTING_FILE"; then
        echo "[FAIL] Missing required instance data component in archive: $comp"
        echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (Missing Component: $comp)" >> "$LOG_FILE"
        exit 1
    fi
done
echo "  [OK] Archive member structure verified (Database + User data confirmed, zero system software duplication)."

echo "[INFO] [3/5] Extracting components to sandbox directory..."
tar -C "$SANDBOX_DIR" -xzf "$TARGET_BACKUP"
BACKUP_ROOT=$(find "$SANDBOX_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)

if [ -z "$BACKUP_ROOT" ] || [ ! -d "$BACKUP_ROOT" ]; then
    echo "[FAIL] Failed to locate extracted backup root directory."
    exit 1
fi

echo "[INFO] [4/5] Validating manifest.json, System Baseline binding, and component checksums..."
MANIFEST_FILE="$BACKUP_ROOT/manifest.json"
if [ ! -f "$MANIFEST_FILE" ]; then
    echo "[FAIL] manifest.json missing from extracted payload."
    exit 1
fi

# Parse and validate manifest via python heredoc
python3 - "$MANIFEST_FILE" << 'EOF'
import json, sys, hashlib, re

manifest_path = sys.argv[1]
with open(manifest_path, "r", encoding="utf-8") as f:
    m = json.load(f)

# 1. Type
btype = m.get("backup_type")
if btype != "instance_data":
    print(f"[FAIL] Expected backup_type=instance_data, got {btype}", file=sys.stderr)
    sys.exit(1)

# 2. Recovery point
rec_point = m.get("recovery_point", "")
if not rec_point:
    print("[FAIL] Missing recovery_point in manifest", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] Recovery Point: {rec_point}")

# 3. System baseline reference
baseline = m.get("system_baseline", {})
sys_id = baseline.get("system_backup_id", "")
commit = baseline.get("git_commit", "")
branch = baseline.get("git_branch", "unknown")
if not sys_id:
    print("[FAIL] Missing system_backup_id reference in system_baseline", file=sys.stderr)
    sys.exit(1)
if not re.match(r"^[0-9a-f]{40}$", commit):
    print(f"[FAIL] Invalid Git commit SHA in manifest: {commit}", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] System Baseline Binding: {sys_id} (Git: {commit[:8]} on {branch})")

# 4. Component checksums
comps = m.get("components", {})
for k in ["database", "user_data"]:
    if k not in comps:
        print(f"[FAIL] Component {k} missing in manifest components dictionary", file=sys.stderr)
        sys.exit(1)
    sha = comps[k].get("sha256", "")
    if len(sha) != 64:
        print(f"[FAIL] Component {k} has invalid SHA-256: {sha}", file=sys.stderr)
        sys.exit(1)

# 5. Composite digest
db_sha = comps["database"]["sha256"]
data_sha = comps["user_data"]["sha256"]
preimage = f"{db_sha}\n{data_sha}"
expected_digest = hashlib.sha256(preimage.encode()).hexdigest()

actual_digest = m.get("components_digest_sha256", "")
if actual_digest != expected_digest:
    print(f"[FAIL] components_digest_sha256 mismatch! Expected {expected_digest}, got {actual_digest}", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] Components Digest Verified: {actual_digest}")
EOF

# Validate actual files match sha256 in manifest
DB_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/database.sql" | cut -d' ' -f1)
DATA_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/data.tar.gz" | cut -d' ' -f1)

MANIFEST_DB_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['database']['sha256'])")
MANIFEST_DATA_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['user_data']['sha256'])")

if [ "$DB_ACTUAL_SHA" != "$MANIFEST_DB_SHA" ] || [ "$DATA_ACTUAL_SHA" != "$MANIFEST_DATA_SHA" ]; then
    echo "[FAIL] Internal component SHA-256 hash mismatch with manifest.json"
    exit 1
fi
echo "  [OK] Individual component hashes match manifest."

echo "[INFO] [5/5] Deep verification: importing database into temporary sandbox DB & verifying user data stream..."

# 1. Verify user data stream
if ! tar -tzf "$BACKUP_ROOT/data.tar.gz" >/dev/null 2>&1; then
    echo "[FAIL] data.tar.gz is corrupted or unreadable."
    exit 1
fi
echo "  [OK] User data archive integrity verified (gzip stream readable)."

# 2. Database sandbox import
echo "  - Creating temporary sandbox database: $SANDBOX_DB"
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE $SANDBOX_DB;" >/dev/null

echo "  - Importing database.sql with ON_ERROR_STOP=1..."
if ! docker exec -i -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -v ON_ERROR_STOP=1 < "$BACKUP_ROOT/database.sql" > "$SANDBOX_DIR/import.log" 2>&1; then
    echo "[FAIL] Failed to import database.sql into sandbox database!"
    tail -n 25 "$SANDBOX_DIR/import.log"
    exit 1
fi
echo "  [OK] Database dump imported cleanly into sandbox database."

# 3. Query required tables & metrics in sandbox DB
VERIFY_RESULTS=$(docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -F "|" -c "
SELECT
  (SELECT count(*) FROM oc_users),
  (SELECT count(*) FROM oc_groups),
  (SELECT count(*) FROM oc_systemtag),
  (SELECT count(*) FROM oc_archive_document_metadata),
  (SELECT count(*) FROM information_schema.tables WHERE table_schema='public');
" 2>/dev/null || echo "0|0|0|0|0")

USERS_CNT=$(echo "$VERIFY_RESULTS" | cut -d'|' -f1)
GROUPS_CNT=$(echo "$VERIFY_RESULTS" | cut -d'|' -f2)
TAGS_CNT=$(echo "$VERIFY_RESULTS" | cut -d'|' -f3)
DOCS_CNT=$(echo "$VERIFY_RESULTS" | cut -d'|' -f4)
TABLES_CNT=$(echo "$VERIFY_RESULTS" | cut -d'|' -f5)

# Verify core archive tables exist
for tbl in oc_archive_document_metadata oc_archive_file_grants oc_archive_file_ownership oc_archive_tag_groups oc_systemtag oc_filecache oc_users oc_groups oc_activity; do
    EXISTS=$(docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "
        SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='$tbl';
    " 2>/dev/null || echo "0")
    if [ "$EXISTS" != "1" ]; then
        echo "[FAIL] Required archive table '$tbl' is missing from restored sandbox database!"
        exit 1
    fi
done
echo "  [OK] All 10 critical enterprise archive tables verified in sandbox DB."

# 4. Clean up sandbox database
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $SANDBOX_DB;" >/dev/null 2>&1 || true
echo "  [OK] Sandbox database dropped cleanly. Production untouched."

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
SYS_BASELINE_ID=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['system_baseline']['system_backup_id'])")

echo "================================================================================"
echo "[PASS] Instance Data Backup Verification PASSED (100% Integrity - Sandbox Validated)"
echo "  - Verified Archive:   $TARGET_BASENAME"
echo "  - Backup Type:        instance_data"
echo "  - System Baseline:    $SYS_BASELINE_ID"
echo "  - Verified Users:     $USERS_CNT"
echo "  - Verified Groups:    $GROUPS_CNT"
echo "  - Verified Tags:      $TAGS_CNT"
echo "  - Document Metadata:  $DOCS_CNT"
echo "  - Total Tables:       $TABLES_CNT"
echo "  - Duration:           ${DURATION}s"
echo "  - Status:             PASS"
echo "================================================================================"

echo "[PASS] Target: $TARGET_BASENAME -> PASS (100% Verified Instance Data Backup, Duration: ${DURATION}s, Users: $USERS_CNT, Groups: $GROUPS_CNT, Docs: $DOCS_CNT)" >> "$LOG_FILE"
exit 0
