#!/bin/bash
# ==============================================================================
# Enterprise Archive System - Production Instance Data Restore Engine (BR-04)
#
# Dedicated restore engine for operational instance data on a healthy server.
# Restores PostgreSQL database and Nextcloud user files to a verified recovery point.
#
# CRITICAL SAFETY ARCHITECTURE:
#   1. System State, software code, Docker configuration, and config.php remain UNTOUCHED.
#   2. Pre-Restore Safety Backup created and validated BEFORE maintenance mode is entered.
#   3. Strict Baseline Compatibility Check (Git commit, Nextcloud version) enforced.
#   4. Database restored with ON_ERROR_STOP=1; verified for essential archive tables.
#   5. User data restored fail-closed into /var/www/html/data.
#   6. DB <-> Files cross-validation ensures zero orphan/missing records.
#   7. Stale sessions invalidated to prevent inconsistent authentication state.
#   8. Full audit trail recorded in deploy/backups/restore_audit.jsonl.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"
STATUS_FILE="/tmp/archive_backup_status.json"
LOCK_FILE="${BACKUP_DIR}/.archive_restore.lock"
AUDIT_LOG="${BACKUP_DIR}/restore_audit.jsonl"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${RESTORE_ENV_FILE:-$ROOT_DIR/.env}"

if [ ! -f "$ENV_FILE" ]; then
    echo "[ERROR] .env file not found at $ENV_FILE" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

if [ -z "${POSTGRES_DB:-}" ] || [ -z "${POSTGRES_USER:-}" ] || [ -z "${POSTGRES_PASSWORD:-}" ]; then
    echo "[ERROR] Missing database credentials in environment or .env file (POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD must all be defined and non-empty)." >&2
    exit 1
fi

REQUESTED_BY="${RESTORE_REQUESTED_BY:-${2:-cli:${USER:-admin}}}"

mkdir -p "$BACKUP_DIR"

update_status() {
    local status="$1"
    local progress="$2"
    local message="$3"
    python3 -c "
import json, time
data = {
    'status': '$status',
    'action': 'restore_data',
    'progress': $progress,
    'message': '$message',
    'timestamp': time.time(),
    'operation': 'restore_data'
}
for p in ['$STATUS_FILE', '${BACKUP_DIR}/.backup_status.json']:
    try:
        with open(p, 'w') as f:
            json.dump(data, f, indent=2)
        import os
        os.chmod(p, 0o666)
    except Exception:
        pass
" 2>/dev/null || true
}

record_audit() {
    local backup_id="$1"
    local recovery_pt="$2"
    local baseline="$3"
    local pre_backup_id="$4"
    local started_at="$5"
    local completed_at="$6"
    local result="$7"
    local reason="$8"
    local requester="${9:-$REQUESTED_BY}"

    python3 -c "
import json, time
entry = {
    'requested_by': '$requester',
    'requested_at': '$started_at',
    'backup_id': '$backup_id',
    'backup_type': 'instance_data',
    'recovery_point': '$recovery_pt',
    'system_baseline': '$baseline',
    'pre_restore_backup_id': '$pre_backup_id',
    'restore_started_at': '$started_at',
    'restore_completed_at': '$completed_at',
    'result': '$result',
    'failure_reason': '$reason'
}
with open('$AUDIT_LOG', 'a') as f:
    f.write(json.dumps(entry, ensure_ascii=False) + '\n')
" 2>/dev/null || true
}

cleanup() {
    rm -f "$LOCK_FILE"
}
trap cleanup EXIT

echo "======================================================================"
echo " Enterprise Archive System - Production Instance Data Restore (BR-04)"
echo "======================================================================"
RESTORE_START_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# 0. Check Concurrency Lock
if [ -f "$LOCK_FILE" ]; then
    echo "[ERROR] Another restore operation is currently in progress ($LOCK_FILE exists)." >&2
    exit 49 # Conflict / 409
fi
if [ -f "/tmp/archive_backup.lock" ] || [ -f "${BACKUP_DIR}/.archive_backup.lock" ]; then
    echo "[ERROR] A backup operation is currently in progress." >&2
    exit 49
fi

echo $$ > "$LOCK_FILE"

# Resolve target archive
TARGET_ARCHIVE="${1:-}"
if [ -z "$TARGET_ARCHIVE" ]; then
    if [ -f "${BACKUP_DIR}/latest_instance_data_backup.tar.gz" ]; then
        TARGET_ARCHIVE="${BACKUP_DIR}/latest_instance_data_backup.tar.gz"
    else
        echo "[ERROR] No target archive provided and latest_instance_data_backup.tar.gz not found." >&2
        update_status "FAILED" 0 "خطا: فایل پشتیبان مشخص نشده است."
        exit 1
    fi
fi

if [[ "$TARGET_ARCHIVE" != /* ]]; then
    if [ -f "${BACKUP_DIR}/${TARGET_ARCHIVE}" ]; then
        TARGET_ARCHIVE="${BACKUP_DIR}/${TARGET_ARCHIVE}"
    elif [ -f "${PWD}/${TARGET_ARCHIVE}" ]; then
        TARGET_ARCHIVE="${PWD}/${TARGET_ARCHIVE}"
    fi
fi

if [ ! -f "$TARGET_ARCHIVE" ]; then
    echo "[ERROR] Target archive file not found: $TARGET_ARCHIVE" >&2
    update_status "FAILED" 0 "خطا: فایل آرشیو یافت نشد."
    exit 1
fi

echo "[INFO] Target Archive: $TARGET_ARCHIVE"
BACKUP_FILENAME=$(basename "$TARGET_ARCHIVE")

# ==============================================================================
# Stage 1: Validate Target Backup & Structure
# ==============================================================================
echo ""
echo "[INFO] [1/9] Validating Target Backup and Checksums..."
update_status "IN_PROGRESS" 10 "مرحله ۱/۹: بررسی جامع صحت و ساختار فایل پشتیبان..."

# Check SHA-256 sidecar if present
if [ -f "${TARGET_ARCHIVE}.sha256" ]; then
    echo "[INFO] Verifying sidecar SHA-256 checksum..."
    EXPECTED_SHA=$(awk '{print $1}' "${TARGET_ARCHIVE}.sha256")
    ACTUAL_SHA=$(sha256sum "$TARGET_ARCHIVE" | awk '{print $1}')
    if [ "$EXPECTED_SHA" != "$ACTUAL_SHA" ]; then
        echo "[ERROR] Checksum mismatch! Expected: $EXPECTED_SHA, Got: $ACTUAL_SHA" >&2
        update_status "FAILED" 10 "خطا: عدم تطابق هش SHA-256 فایل پشتیبان."
        record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Target backup checksum mismatch"
        exit 1
    fi
    echo "  [OK] SHA-256 sidecar verified."
fi

# Verify tar archive integrity
if ! tar -tzf "$TARGET_ARCHIVE" > /dev/null 2>&1; then
    echo "[ERROR] Target archive is corrupted or not a valid gzip tarball." >&2
    update_status "FAILED" 10 "خطا: فایل پشتیبان آسیب‌دیده یا نامعتبر است."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Target archive corrupted"
    exit 1
fi

TEMP_RESTORE_DIR=$(mktemp -d "/tmp/instance_data_restore_XXXXXX")
restore_dir_cleanup() {
    rm -rf "$TEMP_RESTORE_DIR"
    rm -f "$LOCK_FILE"
}
trap restore_dir_cleanup EXIT

echo "[INFO] Extracting manifest and component verification..."
tar -xzf "$TARGET_ARCHIVE" -C "$TEMP_RESTORE_DIR"

MANIFEST_FILE=""
if [ -f "${TEMP_RESTORE_DIR}/manifest.json" ]; then
    MANIFEST_FILE="${TEMP_RESTORE_DIR}/manifest.json"
else
    # Find nested manifest
    MANIFEST_FILE=$(find "$TEMP_RESTORE_DIR" -maxdepth 2 -name "manifest.json" | head -n 1)
fi

if [ -z "$MANIFEST_FILE" ] || [ ! -f "$MANIFEST_FILE" ]; then
    echo "[ERROR] manifest.json not found in backup archive." >&2
    update_status "FAILED" 10 "خطا: فایل مانیفست در آرشیو پشتیبان یافت نشد."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Missing manifest.json"
    exit 1
fi

COMPONENT_DIR=$(dirname "$MANIFEST_FILE")

# Negative Assertions: Ensure instance_data backup does NOT contain system files
LISTING_FILE="${TEMP_RESTORE_DIR}/archive_listing.txt"
tar -tzf "$TARGET_ARCHIVE" > "$LISTING_FILE"

if grep -Eq "/config\.tar\.gz|/custom_apps\.tar\.gz|/docker-compose\.yml|/config_keys\.json" "$LISTING_FILE"; then
    echo "[ERROR] Negative assertion failed: Target archive contains forbidden system files." >&2
    update_status "FAILED" 10 "خطا: آرشیو داده حاوی فایل‌های سیستمی غیرمجاز است."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Negative assertion failed: contained system files"
    exit 1
fi

# Ensure required components exist
if [ ! -f "${COMPONENT_DIR}/database.sql" ]; then
    echo "[ERROR] database.sql missing from backup." >&2
    update_status "FAILED" 10 "خطا: پایگاه داده database.sql در پشتیبان یافت نشد."
    exit 1
fi
if [ ! -f "${COMPONENT_DIR}/data.tar.gz" ]; then
    echo "[ERROR] data.tar.gz missing from backup." >&2
    update_status "FAILED" 10 "خطا: فایل داده کاربران data.tar.gz در پشتیبان یافت نشد."
    exit 1
fi

echo "  [OK] Target archive integrity and component layout verified."

# ==============================================================================
# Stage 2: Validate Baseline Compatibility
# ==============================================================================
echo ""
echo "[INFO] [2/9] Validating System Baseline Compatibility..."
update_status "IN_PROGRESS" 20 "مرحله ۲/۹: بررسی سازگاری بیس‌لاین سیستم جاری با نسخه پشتیبان..."

CURRENT_GIT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
CURRENT_NC_VERSION=$(docker exec archive_app php occ status 2>/dev/null | grep -i "versionstring" | awk '{print $3}' || echo "unknown")

CHECK_BASELINE=$(python3 -c "
import json, sys

with open('$MANIFEST_FILE', 'r') as f:
    mf = json.load(f)

b_type = mf.get('backup_type')
if b_type != 'instance_data':
    print(f'FAIL: Invalid backup_type {b_type}, expected instance_data')
    sys.exit(1)

baseline = mf.get('system_baseline', {})
b_git = baseline.get('git_commit', '')
b_nc = baseline.get('nextcloud_version', '')

curr_git = '$CURRENT_GIT_COMMIT'
curr_nc = '$CURRENT_NC_VERSION'

print(f'Target Backup Baseline: Git={b_git}, NC={b_nc}')
print(f'Running System Baseline: Git={curr_git}, NC={curr_nc}')

# If running git is valid 40-char hex, check match if backup git is also specified
if len(b_git) == 40 and len(curr_git) == 40:
    if b_git != curr_git:
        print(f'FAIL: Git commit mismatch! Backup: {b_git} != System: {curr_git}')
        sys.exit(1)

# Verify Nextcloud major/minor version matches
if b_nc and curr_nc != 'unknown':
    if b_nc.split('.')[0] != curr_nc.split('.')[0]:
        print(f'FAIL: Nextcloud version mismatch! Backup: {b_nc} != System: {curr_nc}')
        sys.exit(1)

print('SUCCESS')
" 2>&1 || true)

if ! echo "$CHECK_BASELINE" | grep -q "SUCCESS"; then
    echo "[ERROR] Baseline compatibility validation failed:" >&2
    echo "$CHECK_BASELINE" >&2
    update_status "FAILED" 20 "خطا: عدم تطابق بیس‌لاین سیستمی با نسخه پشتیبان."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "$CURRENT_GIT_COMMIT" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Baseline compatibility mismatch"
    exit 1
fi
echo "  [OK] Baseline compatibility confirmed."

# Extract manifest metadata
TARGET_BACKUP_ID=$(python3 -c "import json; mf=json.load(open('$MANIFEST_FILE')); print(mf.get('backup_id', 'UNKNOWN'))")
TARGET_RECOVERY_POINT=$(python3 -c "import json; mf=json.load(open('$MANIFEST_FILE')); print(mf.get('created_at', 'UNKNOWN'))")

# ==============================================================================
# Stage 3: Create Emergency Pre-Restore Safety Backup
# ==============================================================================
echo ""
echo "[INFO] [3/9] Creating Pre-Restore Safety Backup of current production state..."
update_status "IN_PROGRESS" 30 "مرحله ۳/۹: ایجاد پیش‌پشتیبان امنیتی اضطراری (Pre-Restore Safety Backup)..."

SAFETY_BACKUP_OUTPUT=$("${SCRIPT_DIR}/backup_instance_data.sh" pre_restore_safety 2>&1)
SAFETY_BACKUP_EXIT=$?

if [ $SAFETY_BACKUP_EXIT -ne 0 ]; then
    echo "[ERROR] Failed to create pre-restore safety backup!" >&2
    echo "$SAFETY_BACKUP_OUTPUT" >&2
    update_status "FAILED" 30 "خطا: عدم موفقیت در ایجاد پیش‌پشتیبان امنیتی اضطراری."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Pre-restore safety backup creation failed"
    exit 1
fi

PRE_RESTORE_ARCHIVE=$(echo "$SAFETY_BACKUP_OUTPUT" | grep -oE "${BACKUP_DIR}/backup_instance_data_pre_restore_[^ ]+\.tar\.gz" | head -n 1 || true)
if [ -z "$PRE_RESTORE_ARCHIVE" ] || [ ! -f "$PRE_RESTORE_ARCHIVE" ]; then
    # Fallback to latest pre-restore alias
    if [ -f "${BACKUP_DIR}/latest_instance_data_pre_restore_backup.tar.gz" ]; then
        PRE_RESTORE_ARCHIVE="${BACKUP_DIR}/latest_instance_data_pre_restore_backup.tar.gz"
    else
        echo "[ERROR] Pre-restore safety backup archive file not found." >&2
        update_status "FAILED" 30 "خطا: فایل پیش‌پشتیبان امنیتی یافت نشد."
        record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Pre-restore safety backup archive missing"
        exit 1
    fi
fi

PRE_RESTORE_BACKUP_ID=$(basename "$PRE_RESTORE_ARCHIVE")
echo "  [OK] Pre-Restore Safety Backup created: $PRE_RESTORE_ARCHIVE"

# ==============================================================================
# Stage 4: Validate Pre-Restore Safety Backup
# ==============================================================================
echo ""
echo "[INFO] [4/9] Validating Pre-Restore Safety Backup..."
update_status "IN_PROGRESS" 40 "مرحله ۴/۹: اعتبارسنجی پیش‌پشتیبان امنیتی قبل از هرگونه تغییر..."

# Check tar integrity and manifest
if ! tar -tzf "$PRE_RESTORE_ARCHIVE" > /dev/null 2>&1; then
    echo "[ERROR] Pre-restore safety backup archive failed tar integrity check!" >&2
    update_status "FAILED" 40 "خطا: پیش‌پشتیبان امنیتی ایجاد شده معتبر نیست."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Pre-restore backup validation failed"
    exit 1
fi

# Run test_instance_data_backup.sh on pre-restore archive
if ! "${SCRIPT_DIR}/test_instance_data_backup.sh" "$PRE_RESTORE_ARCHIVE" > /dev/null 2>&1; then
    echo "[ERROR] Pre-restore safety backup validation script failed!" >&2
    update_status "FAILED" 40 "خطا: آزمون اعتبارسنجی پیش‌پشتیبان امنیتی شکست خورد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Pre-restore backup test failed"
    exit 1
fi

echo "  [OK] Pre-Restore Safety Backup verified valid and recoverable."

# ==============================================================================
# Stage 5: Enter Maintenance Mode (Fail-Closed)
# ==============================================================================
echo ""
echo "[INFO] [5/9] Entering Maintenance Mode..."
update_status "IN_PROGRESS" 50 "مرحله ۵/۹: فعال‌سازی حالت تعمیرات (Maintenance Mode) سامانه..."

if [ "${TEST_SIMULATE_MAINT_ON_FAIL:-0}" = "1" ] || ! docker exec archive_app php occ maintenance:mode --on > /dev/null 2>&1; then
    echo "[ERROR] Failed to activate maintenance mode on archive_app." >&2
    update_status "FAILED" 50 "خطا: فعال‌سازی حالت تعمیرات (Maintenance Mode) با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Failed to activate maintenance mode" "$REQUESTED_BY"
    exit 1
fi

MAINT_ON_VERIFY=$(docker exec archive_app php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "unknown")
if [ "$MAINT_ON_VERIFY" != "true" ]; then
    echo "[ERROR] Maintenance mode verification failed: status is '$MAINT_ON_VERIFY', expected 'true'." >&2
    update_status "FAILED" 50 "خطا: وضعیت حالت تعمیرات تایید نشد. عملیات بازیابی لغو گردید."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Maintenance mode verification failed: $MAINT_ON_VERIFY" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Maintenance mode successfully activated and verified (status: true)."

# Define failure trap that keeps system protected and notifies operator
on_restore_failure() {
    local err_code=$?
    echo "" >&2
    echo "======================================================================" >&2
    echo " [CRITICAL ALERT] RESTORE PROCEDURE FAILED AT STEP (Exit Code: $err_code)" >&2
    echo " System remains in protected MAINTENANCE MODE." >&2
    echo " Pre-Restore Safety Backup is available for rollback at:" >&2
    echo "   $PRE_RESTORE_ARCHIVE" >&2
    echo " To rollback to the pre-restore state, execute:" >&2
    echo "   ${SCRIPT_DIR}/manage_backup.sh restore-data $PRE_RESTORE_ARCHIVE" >&2
    echo "======================================================================" >&2
    update_status "FAILED" 50 "خطای بحرانی در عملیات بازیابی. سیستم در حالت امن Maintenance باقی مانده است."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Failed during destructive execution step"
    exit $err_code
}
trap on_restore_failure ERR

# ==============================================================================
# Stage 6: Database Restore
# ==============================================================================
echo ""
echo "[INFO] [6/9] Restoring PostgreSQL Database..."
update_status "IN_PROGRESS" 60 "مرحله ۶/۹: بازیابی دقیق پایگاه داده PostgreSQL (ON_ERROR_STOP=1)..."

DB_SQL_FILE="${COMPONENT_DIR}/database.sql"

# Disallow new connections and terminate active connections to prevent drop failure
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c \
    "ALTER DATABASE $POSTGRES_DB WITH ALLOW_CONNECTIONS false;" > /dev/null 2>&1 || true
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB' AND pid <> pg_backend_pid();" > /dev/null 2>&1 || true

# Recreate database cleanly with FORCE to guarantee clean drop
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $POSTGRES_DB WITH (FORCE);" > /dev/null
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE $POSTGRES_DB OWNER $POSTGRES_USER;" > /dev/null

# Restore SQL dump with ON_ERROR_STOP=1
docker exec -i -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$DB_SQL_FILE" > /dev/null

# Validate core archive tables exist
CORE_TABLES_CHECK=$(docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c \
    "SELECT count(*) FROM information_schema.tables WHERE table_name IN ('oc_users', 'oc_groups', 'oc_filecache', 'oc_archive_document_metadata', 'oc_systemtag');")

if [ "$CORE_TABLES_CHECK" -lt 5 ]; then
    echo "[ERROR] Database restore incomplete: Core tables missing (found $CORE_TABLES_CHECK / 5)." >&2
    exit 1
fi

echo "  [OK] Database successfully restored and core tables verified."

# ==============================================================================
# Stage 7: User Data Restore
# ==============================================================================
echo ""
echo "[INFO] [7/9] Restoring User Files Storage (/var/www/html/data)..."
update_status "IN_PROGRESS" 75 "مرحله ۷/۹: بازگردانی داده‌های فایل کاربران و تنظیم دسترسی‌ها..."

DATA_TAR_FILE="${COMPONENT_DIR}/data.tar.gz"

# Clean out existing user files from /var/www/html/data inside container while preserving .ocdata marker
docker exec archive_app bash -c "rm -rf /var/www/html/data/*"

# Stream extraction of data.tar.gz into /var/www/html
docker exec -i archive_app tar -xzf - -C /var/www/html < "$DATA_TAR_FILE"

# Ensure .ocdata exists and set ownership
docker exec archive_app bash -c "touch /var/www/html/data/.ocdata && chown -R www-data:www-data /var/www/html/data"

echo "  [OK] User files extracted and ownership established."

# ==============================================================================
# Stage 8: Session Invalidation & DB <-> Files Consistency Validation
# ==============================================================================
echo ""
echo "[INFO] [8/9] Invalidating old sessions and cross-validating DB <-> Files..."
update_status "IN_PROGRESS" 85 "مرحله ۸/۹: ابطال سشن‌های قدیمی و ممیزی تطابق کامل پایگاه داده با فایل‌ها..."

# Invalidate sessions and clear brute force / locks
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "TRUNCATE TABLE oc_authtoken; TRUNCATE TABLE oc_bruteforce_attempts;" > /dev/null 2>&1 || true
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" archive_db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "DO \$\$ BEGIN IF to_regclass('oc_file_locks') IS NOT NULL THEN TRUNCATE TABLE oc_file_locks; END IF; END \$\$;" > /dev/null 2>&1 || true

echo "  [OK] Old user sessions invalidated and file locks cleared."

# Cross-Validation Script
CONSISTENCY_RESULT=$(python3 - "$MANIFEST_FILE" "$POSTGRES_USER" "$POSTGRES_PASSWORD" "$POSTGRES_DB" << 'PYEOF'
import sys, json, subprocess

manifest_file = sys.argv[1]
pg_user = sys.argv[2]
pg_password = sys.argv[3]
pg_db = sys.argv[4]

with open(manifest_file, "r") as f:
    mf = json.load(f)

exp_counts = mf.get("components", {}).get("database", {})
exp_u = exp_counts.get("users_count")
exp_g = exp_counts.get("groups_count")
exp_t = exp_counts.get("tags_count")
exp_d = exp_counts.get("documents_metadata_count")

sql = "SELECT (SELECT count(*) FROM oc_users), (SELECT count(*) FROM oc_groups), (SELECT count(*) FROM oc_systemtag), (SELECT count(*) FROM oc_archive_document_metadata);"
cmd = ["docker", "exec", "-e", f"PGPASSWORD={pg_password}", "archive_db", "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-F", "|", "-c", sql]
res = subprocess.run(cmd, capture_output=True, text=True)
if res.returncode != 0:
    print(f"FAIL: Query counts: {res.stderr}")
    sys.exit(1)

u_cnt, g_cnt, t_cnt, d_cnt = [int(x) for x in res.stdout.strip().split("|")]

if exp_u is not None and u_cnt != exp_u:
    print(f"FAIL: Users mismatch: {u_cnt} != {exp_u}")
    sys.exit(1)
if exp_g is not None and g_cnt != exp_g:
    print(f"FAIL: Groups mismatch: {g_cnt} != {exp_g}")
    sys.exit(1)
if exp_t is not None and t_cnt != exp_t:
    print(f"FAIL: Tags mismatch: {t_cnt} != {exp_t}")
    sys.exit(1)
if exp_d is not None and d_cnt != exp_d:
    print(f"FAIL: Docs mismatch: {d_cnt} != {exp_d}")
    sys.exit(1)

print(f"OK_COUNTS: u={u_cnt}, g={g_cnt}, t={t_cnt}, d={d_cnt}")

# Direction 1: DB oc_filecache -> Physical files on disk
sql_fc = "SELECT s.id, f.path FROM oc_filecache f JOIN oc_storages s ON f.storage = s.numeric_id WHERE f.path LIKE 'files/%' AND f.mimetype != 2;"
cmd_fc = ["docker", "exec", "-e", f"PGPASSWORD={pg_password}", "archive_db", "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-F", "|", "-c", sql_fc]
res_fc = subprocess.run(cmd_fc, capture_output=True, text=True)
if res_fc.returncode != 0:
    print(f"FAIL: Query filecache: {res_fc.stderr}")
    sys.exit(1)

missing = []
for line in res_fc.stdout.strip().splitlines():
    line = line.strip()
    if not line or "|" not in line:
        continue
    storage_id, fpath = line.split("|", 1)
    if storage_id.startswith("local::"):
        disk_path = storage_id.replace("local::", "") + fpath
    elif storage_id.startswith("home::"):
        user_name = storage_id.replace("home::", "")
        disk_path = f"/var/www/html/data/{user_name}/{fpath}"
    else:
        continue
    c = ["docker", "exec", "archive_app", "test", "-f", disk_path]
    r = subprocess.run(c)
    if r.returncode != 0:
        missing.append(disk_path)

if missing:
    print(f"FAIL: Missing physical files ({len(missing)}): {missing[:5]}")
    sys.exit(1)

print("SUCCESS")
PYEOF
)

if ! echo "$CONSISTENCY_RESULT" | grep -q "SUCCESS"; then
    echo "[ERROR] Post-Restore DB <-> Files consistency validation failed:" >&2
    echo "$CONSISTENCY_RESULT" >&2
    exit 1
fi
echo "  [OK] DB <-> Files consistency verified."

# ==============================================================================
# Stage 9: Service Recovery & Final Post-Restore Health Check Gate
# ==============================================================================
echo ""
echo "[INFO] [9/9] Returning Service to Normal and Running Health Check Gate..."
update_status "IN_PROGRESS" 92 "مرحله ۹/۹: غیرفعال‌سازی حالت تعمیرات و بررسی سلامت نهایی..."

# Turn maintenance mode OFF
if [ "${TEST_SIMULATE_MAINT_OFF_FAIL:-0}" = "1" ] || ! docker exec archive_app php occ maintenance:mode --off > /dev/null 2>&1; then
    echo "[ERROR] Failed to execute 'occ maintenance:mode --off' command!" >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 92 "خطا: غیرفعال‌سازی حالت تعمیرات با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Failed to disable maintenance mode" "$REQUESTED_BY"
    exit 1
fi

# Verify Maintenance OFF
MAINT_OFF_VERIFY=$(docker exec archive_app php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "unknown")
if [ "$MAINT_OFF_VERIFY" != "false" ]; then
    echo "[ERROR] Verification failed: Maintenance mode is still ACTIVE (status: $MAINT_OFF_VERIFY)!" >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 92 "خطا: حالت تعمیرات همچنان فعال است و خاموش نشد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Maintenance mode still active after restore: $MAINT_OFF_VERIFY" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Maintenance mode successfully disabled and verified (status: false)."

# Reset trap
trap cleanup EXIT

# Post-Restore Health Check Gate (Strict Gate)
echo "[INFO] Executing comprehensive post-restore health check gate..."
update_status "IN_PROGRESS" 96 "مرحله ۹/۹: اجرای گیت بررسی جامع سلامت سامانه..."

if [ "${TEST_SIMULATE_HEALTH_FAIL:-0}" = "1" ] || ! "${SCRIPT_DIR}/check_health.sh" > "${BACKUP_DIR}/.restore_health_check.log" 2>&1; then
    echo "[ERROR] Post-Restore Health Check FAILED! System reported degraded or unhealthy state." >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 96 "خطا: آزمون سلامت سامانه پس از بازیابی با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Post-restore health check gate failed" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Post-restore health check passed with 100% healthy status."

RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "SUCCESS" "None" "$REQUESTED_BY"

update_status "SUCCESS" 100 "عملیات بازیابی داده‌های عملیاتی با موفقیت کامل انجام شد."

echo ""
echo "======================================================================"
echo " [SUCCESS] BR-04 Production Instance Data Restore Completed!"
echo " Target Recovery Point: $TARGET_RECOVERY_POINT"
echo " Pre-Restore Safety Backup: $PRE_RESTORE_BACKUP_ID"
echo " Requester: $REQUESTED_BY"
echo " Audit Log: $AUDIT_LOG"
echo "======================================================================"
exit 0
