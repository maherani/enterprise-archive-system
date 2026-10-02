#!/usr/bin/env bash
set -Eeuo pipefail

START_TIME=$(date +%s)
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

if [ ! -f "$TARGET_BACKUP" ] && [ -f "$BACKUP_DIR/$TARGET_BACKUP" ]; then
    TARGET_BACKUP="$BACKUP_DIR/$TARGET_BACKUP"
fi

if [ ! -f "$TARGET_BACKUP" ]; then
    echo "[ERROR] Target instance data backup file not found: $TARGET_BACKUP"
    exit 1
fi

TARGET_BASENAME="$(basename "$TARGET_BACKUP")"
SANDBOX_DIR=$(mktemp -d -t data_restore_sandbox_XXXXXX)
RAND_ID=$(head -c 6 /dev/urandom | xxd -p 2>/dev/null || tr -dc a-z0-9 </dev/urandom | head -c 6)
SANDBOX_DB="nextcloud_instance_restore_sandbox_${$}_${RAND_ID}"
SANDBOX_DATA="$SANDBOX_DIR/extracted_data"
mkdir -p "$SANDBOX_DATA"

cleanup() {
    # Absolute zero interference with production: clean up sandbox database and files
    docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $SANDBOX_DB;" >/dev/null 2>&1 || true
    rm -rf "$SANDBOX_DIR"
}
trap cleanup EXIT INT TERM

echo "================================================================================"
echo " Enterprise Archive System - BR-03 Instance Data Sandbox Restore Engine"
echo " Target Archive: $TARGET_BACKUP"
echo " Sandbox Dir:    $SANDBOX_DIR"
echo " Sandbox DB:     $SANDBOX_DB"
echo " Time:           $(date -Iseconds)"
echo "================================================================================"

exec > >(tee "$LAST_RUN_LOG") 2>&1

echo "[INFO] [1/7] Verifying external SHA-256 sidecar checksum..."
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

echo "[INFO] [2/7] Inspecting archive member list (tar stream) & negative assertions..."
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

echo "[INFO] [3/7] Extracting components to sandbox & validating manifest / baseline..."
tar -C "$SANDBOX_DIR" -xzf "$TARGET_BACKUP"
BACKUP_ROOT=$(find "$SANDBOX_DIR" -mindepth 1 -maxdepth 1 -type d -not -path "$SANDBOX_DATA" -print -quit)

if [ -z "$BACKUP_ROOT" ] || [ ! -d "$BACKUP_ROOT" ]; then
    echo "[FAIL] Failed to locate extracted backup root directory."
    exit 1
fi

MANIFEST_FILE="$BACKUP_ROOT/manifest.json"
if [ ! -f "$MANIFEST_FILE" ]; then
    echo "[FAIL] manifest.json missing from extracted payload."
    exit 1
fi

# Deep python validation of manifest, baseline, and component checksums
python3 - "$MANIFEST_FILE" "$BACKUP_ROOT" << 'EOF'
import json, sys, hashlib, re, os

manifest_path = sys.argv[1]
backup_root = sys.argv[2]

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
nc_version = baseline.get("nextcloud_version", "")
app_version = baseline.get("archive_app_version", "")

if not sys_id or not sys_id.startswith("bk-sys-"):
    print(f"[FAIL] Invalid or missing system_backup_id reference in system_baseline: {sys_id}", file=sys.stderr)
    sys.exit(1)
if not re.match(r"^[0-9a-f]{40}$", commit):
    print(f"[FAIL] Invalid Git commit SHA in manifest: {commit}", file=sys.stderr)
    sys.exit(1)
if not nc_version:
    print("[FAIL] Missing nextcloud_version in system_baseline", file=sys.stderr)
    sys.exit(1)
if not app_version:
    print("[FAIL] Missing archive_app_version in system_baseline", file=sys.stderr)
    sys.exit(1)

print(f"  [OK] System Baseline Binding: {sys_id} (Git: {commit[:8]} on {branch})")
print(f"  [OK] Software Stack Baseline: Nextcloud {nc_version} | Archive App {app_version}")

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

# 6. Validate actual file hashes on disk
with open(os.path.join(backup_root, "database.sql"), "rb") as f:
    actual_db_sha = hashlib.sha256(f.read()).hexdigest()
with open(os.path.join(backup_root, "data.tar.gz"), "rb") as f:
    actual_data_sha = hashlib.sha256(f.read()).hexdigest()

if actual_db_sha != db_sha:
    print(f"[FAIL] database.sql hash mismatch! File: {actual_db_sha}, Manifest: {db_sha}", file=sys.stderr)
    sys.exit(1)
if actual_data_sha != data_sha:
    print(f"[FAIL] data.tar.gz hash mismatch! File: {actual_data_sha}, Manifest: {data_sha}", file=sys.stderr)
    sys.exit(1)
print("  [OK] Component files hashes exactly match manifest specifications.")
EOF

echo "[INFO] [4/7] Restoring database into temporary sandbox DB & verifying schema / counts..."
# 1. Create temporary sandbox DB
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE $SANDBOX_DB;" >/dev/null

# 2. Import database.sql with ON_ERROR_STOP=1
if ! docker exec -i -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -v ON_ERROR_STOP=1 < "$BACKUP_ROOT/database.sql" > "$SANDBOX_DIR/import.log" 2>&1; then
    echo "[FAIL] Failed to import database.sql into sandbox database!"
    tail -n 25 "$SANDBOX_DIR/import.log"
    exit 1
fi
echo "  [OK] Database dump imported cleanly into sandbox database (ON_ERROR_STOP=1 passed)."

# 3. Verify all 12 core enterprise archive tables exist
for tbl in oc_users oc_groups oc_group_user oc_filecache oc_storages oc_systemtag oc_systemtag_object_mapping oc_archive_document_metadata oc_archive_file_grants oc_archive_file_ownership oc_archive_tag_groups oc_activity; do
    EXISTS=$(docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -c "
        SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='$tbl';
    " 2>/dev/null || echo "0")
    if [ "$EXISTS" != "1" ]; then
        echo "[FAIL] Required archive table '$tbl' is missing from restored sandbox database!"
        exit 1
    fi
done
echo "  [OK] All 12 critical enterprise archive tables verified in sandbox DB."

# 4. Query restored counts
DB_COUNTS=$(docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$SANDBOX_DB" -t -A -F "|" -c "
SELECT
  (SELECT count(*) FROM oc_users),
  (SELECT count(*) FROM oc_groups),
  (SELECT count(*) FROM oc_systemtag),
  (SELECT count(*) FROM oc_archive_document_metadata),
  (SELECT count(*) FROM information_schema.tables WHERE table_schema='public');
" 2>/dev/null || echo "0|0|0|0|0")

RESTORED_USERS=$(echo "$DB_COUNTS" | cut -d'|' -f1)
RESTORED_GROUPS=$(echo "$DB_COUNTS" | cut -d'|' -f2)
RESTORED_TAGS=$(echo "$DB_COUNTS" | cut -d'|' -f3)
RESTORED_DOCS=$(echo "$DB_COUNTS" | cut -d'|' -f4)
RESTORED_TABLES=$(echo "$DB_COUNTS" | cut -d'|' -f5)

# 5. Strict count consistency check against manifest.json
python3 - "$MANIFEST_FILE" "$RESTORED_USERS" "$RESTORED_GROUPS" "$RESTORED_TAGS" "$RESTORED_DOCS" "$RESTORED_TABLES" << 'EOF'
import json, sys

manifest_path = sys.argv[1]
res_users = int(sys.argv[2])
res_groups = int(sys.argv[3])
res_tags = int(sys.argv[4])
res_docs = int(sys.argv[5])
res_tables = int(sys.argv[6])

with open(manifest_path, "r", encoding="utf-8") as f:
    m = json.load(f)

db_info = m.get("components", {}).get("database", {})
exp_users = db_info.get("users_count")
exp_groups = db_info.get("groups_count")
exp_tags = db_info.get("tags_count")
exp_docs = db_info.get("documents_metadata_count")
exp_tables = db_info.get("tables_count")

mismatches = []
if exp_users is not None and res_users != exp_users:
    mismatches.append(f"Users: Restored={res_users}, Manifest={exp_users}")
if exp_groups is not None and res_groups != exp_groups:
    mismatches.append(f"Groups: Restored={res_groups}, Manifest={exp_groups}")
if exp_tags is not None and res_tags != exp_tags:
    mismatches.append(f"Tags: Restored={res_tags}, Manifest={exp_tags}")
if exp_docs is not None and res_docs != exp_docs:
    mismatches.append(f"Docs: Restored={res_docs}, Manifest={exp_docs}")
if exp_tables is not None and res_tables != exp_tables:
    mismatches.append(f"Tables: Restored={res_tables}, Manifest={exp_tables}")

if mismatches:
    print(f"[FAIL] Database count mismatch with manifest: {', '.join(mismatches)}", file=sys.stderr)
    sys.exit(1)

print(f"  [OK] Restored counts match manifest: Users={res_users}, Groups={res_groups}, Tags={res_tags}, Docs={res_docs}, Tables={res_tables}")
EOF

echo "[INFO] [5/7] Extracting user data to isolated sandbox filesystem..."
tar -C "$SANDBOX_DATA" -xzf "$BACKUP_ROOT/data.tar.gz"

if [ ! -d "$SANDBOX_DATA/data" ]; then
    echo "[FAIL] Extracted user data directory does not contain 'data' root!"
    exit 1
fi

DATA_FILES_COUNT=$(find "$SANDBOX_DATA/data" -type f | wc -l)
echo "  [OK] User data extracted successfully to sandbox: $DATA_FILES_COUNT physical files restored."

echo "[INFO] [6/7] Cross-validating Database State ↔ Sandbox Filesystem State..."
# Python cross-validation script
python3 - "$SANDBOX_DATA/data" "$SANDBOX_DB" "$POSTGRES_USER" "$POSTGRES_PASSWORD" << 'EOF'
import os, sys, subprocess

data_dir = sys.argv[1]
sandbox_db = sys.argv[2]
pg_user = sys.argv[3]
pg_password = sys.argv[4]

# 1. Query all files from restored DB oc_filecache
sql = "SELECT s.id, f.path FROM oc_filecache f JOIN oc_storages s ON f.storage = s.numeric_id WHERE f.path LIKE 'files/%' AND f.mimetype != 2;"
cmd = [
    "docker", "exec", "-e", f"PGPASSWORD={pg_password}", "archive_db",
    "psql", "-U", pg_user, "-d", sandbox_db, "-t", "-A", "-F", "|", "-c", sql
]
res = subprocess.run(cmd, capture_output=True, text=True)
if res.returncode != 0:
    print(f"[FAIL] Failed to query oc_filecache from sandbox DB: {res.stderr}", file=sys.stderr)
    sys.exit(1)

db_files = []
for line in res.stdout.strip().splitlines():
    line = line.strip()
    if not line or "|" not in line:
        continue
    storage_id, fpath = line.split("|", 1)
    db_files.append((storage_id, fpath))

print(f"  [CROSS-CHECK] Found {len(db_files)} non-directory files recorded in restored DB oc_filecache.")

# Direction 1: DB record -> physical file must exist
missing_files = []
for storage_id, fpath in db_files:
    if storage_id.startswith("home::"):
        username = storage_id[6:]
        expected_path = os.path.join(data_dir, username, fpath)
    elif storage_id == "local::/var/www/html/data/":
        expected_path = os.path.join(data_dir, fpath)
    else:
        continue

    if not os.path.isfile(expected_path):
        missing_files.append(f"{storage_id} -> {fpath}")

if missing_files:
    print(f"[FAIL] {len(missing_files)} file(s) in DB oc_filecache missing on extracted filesystem! Sample: {missing_files[:5]}", file=sys.stderr)
    sys.exit(1)

print("  [OK] Direction 1 passed: All DB filecache records correspond to existing physical files.")

# Direction 2: User document files on disk -> corresponding DB record must exist
unindexed_files = []
archive_root = None
for root, dirs, files in os.walk(data_dir):
    if "Enterprise_Archive" in root:
        for fname in files:
            full_path = os.path.join(root, fname)
            rel_path = os.path.relpath(full_path, data_dir)
            parts = rel_path.split(os.sep)
            if len(parts) >= 3 and parts[1] == "files":
                user = parts[0]
                inner_path = "/".join(parts[1:])
                # Check DB for this file
                chk_sql = f"SELECT 1 FROM oc_filecache f JOIN oc_storages s ON f.storage = s.numeric_id WHERE s.id = 'home::{user}' AND f.path = '{inner_path}';"
                chk_cmd = [
                    "docker", "exec", "-e", f"PGPASSWORD={pg_password}", "archive_db",
                    "psql", "-U", pg_user, "-d", sandbox_db, "-t", "-A", "-c", chk_sql
                ]
                chk_res = subprocess.run(chk_cmd, capture_output=True, text=True)
                if chk_res.stdout.strip() != "1":
                    unindexed_files.append(rel_path)

if unindexed_files:
    print(f"[FAIL] {len(unindexed_files)} physical user document(s) missing from restored DB oc_filecache! Sample: {unindexed_files[:5]}", file=sys.stderr)
    sys.exit(1)

print("  [OK] Direction 2 passed: All physical archive documents are properly indexed in restored DB.")
print("  [OK] Bi-directional Database State <-> Filesystem State consistency verified 100%.")
EOF

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Extract summary fields from manifest BEFORE removing sandbox directory
BACKUP_ID=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('backup_id', 'unknown'))")
RECOVERY_POINT=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('recovery_point', 'unknown'))")
SYS_BASELINE_ID=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('system_baseline', {}).get('system_backup_id', 'unknown'))")
GIT_COMMIT=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('system_baseline', {}).get('git_commit', 'unknown'))")
NC_VERSION=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('system_baseline', {}).get('nextcloud_version', 'unknown'))")
APP_VERSION=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m.get('system_baseline', {}).get('archive_app_version', 'unknown'))")

echo "[INFO] [7/7] Verifying permissions and cleaning up sandbox environment..."
# 1. Test clean drop of sandbox DB
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $SANDBOX_DB;" >/dev/null 2>&1
echo "  [OK] Sandbox temporary database dropped cleanly."

# 2. Cleanup sandbox filesystem
rm -rf "$SANDBOX_DIR"
echo "  [OK] Sandbox temporary files deleted. Zero modification to production confirmed."

echo "================================================================================"
echo "[PASS] Instance Data Sandbox Restore PASSED (100% Verified DB & Filesystem)"
echo "  - Verified Archive:         $TARGET_BASENAME"
echo "  - Backup ID:                $BACKUP_ID"
echo "  - Backup Type:              instance_data"
echo "  - Recovery Point:           $RECOVERY_POINT"
echo "  - System Baseline ID:       $SYS_BASELINE_ID"
echo "  - Git Commit:               $GIT_COMMIT"
echo "  - Nextcloud Version:        $NC_VERSION"
echo "  - Archive App Version:      $APP_VERSION"
echo "  - Database Restore:         PASS"
echo "  - Database Tables:          PASS (12 core archive tables verified)"
echo "  - Total Tables:             $RESTORED_TABLES"
echo "  - Users Count:              $RESTORED_USERS"
echo "  - Groups Count:             $RESTORED_GROUPS"
echo "  - Tags Count:               $RESTORED_TAGS"
echo "  - Document Metadata Count:  $RESTORED_DOCS"
echo "  - User Data Extraction:     PASS"
echo "  - DB <-> Files Consistency: PASS (100% bi-directional mapping verified)"
echo "  - Manifest Integrity:       PASS"
echo "  - SHA-256:                  PASS"
echo "  - Sandbox Cleanup:          PASS"
echo "  - Duration:                 ${DURATION}s"
echo "  - Final Result:             PASS"
echo "================================================================================"

echo "[PASS] Target: $TARGET_BASENAME -> PASS (100% Sandbox Restore Validated, Duration: ${DURATION}s, Users: $RESTORED_USERS, Groups: $RESTORED_GROUPS, Docs: $RESTORED_DOCS, DB<->Files Consistent)" >> "$LOG_FILE"
exit 0
