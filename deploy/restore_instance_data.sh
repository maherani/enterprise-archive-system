#!/bin/bash
# ==============================================================================
# Enterprise Archive System - Production Instance Data Restore Engine (BR-04)
#
# Dedicated restore engine for operational instance data on a healthy server.
# Restores PostgreSQL database and Nextcloud user files to a verified recovery point.
# Utilizes Shared Restore Core (deploy/restore_core.sh) with ProductionTarget.
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

# Source Shared Restore Core
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/restore_core.sh"

# Define ProductionTarget Abstraction
TARGET_NAME="production"
TARGET_APP_CONTAINER="archive_app"
TARGET_DB_CONTAINER="archive_db"
TARGET_PROXY_CONTAINER="archive_proxy"
TARGET_DB_NAME="$POSTGRES_DB"
TARGET_DB_USER="$POSTGRES_USER"
TARGET_DB_PASSWORD="$POSTGRES_PASSWORD"
TARGET_DATA_PATH="/var/www/html/data"

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
        import os, shutil
        os.chmod(p, 0o660)
        try:
            shutil.chown(p, group='www-data')
        except Exception:
            pass
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
echo " Target Environment: $TARGET_NAME"
echo "======================================================================"
RESTORE_START_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

REQUESTED_BY="${RESTORE_REQUESTED_BY:-${2:-}}"
if [ -z "$REQUESTED_BY" ] && [ -n "${SUDO_USER:-}" ]; then
    REQUESTED_BY="cli:${SUDO_USER}"
elif [ -z "$REQUESTED_BY" ] && [ -n "${USER:-}" ]; then
    REQUESTED_BY="cli:${USER}"
fi

if [ -z "$REQUESTED_BY" ]; then
    echo "[ERROR] Missing or unavailable requester identity. Production restore requires verified requester (Fail-Closed)." >&2
    update_status "FAILED" 0 "خطا: هویت درخواست‌کننده عملیات بازیابی نامشخص است (Requester identity unavailable)."
    record_audit "unknown" "unknown" "${CURRENT_GIT_COMMIT:-unknown}" "none" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "requester identity unavailable" "unknown"
    exit 1
fi

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
# Stage 1: Validate Target Backup & Structure (via Shared Restore Core)
# ==============================================================================
echo ""
echo "[INFO] [1/9] Validating Target Backup and Checksums..."
update_status "IN_PROGRESS" 10 "مرحله ۱/۹: بررسی جامع صحت و ساختار فایل پشتیبان..."

if ! core_validate_archive_integrity "$TARGET_ARCHIVE"; then
    echo "[ERROR] Archive integrity check failed for $TARGET_ARCHIVE" >&2
    update_status "FAILED" 10 "خطا: عدم تطابق هش SHA-256 یا خرابی فایل پشتیبان."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Target backup integrity or checksum failure"
    exit 1
fi
echo "  [OK] Archive integrity and sidecar checksum verified."

TEMP_RESTORE_DIR=$(mktemp -d "/tmp/instance_data_restore_XXXXXX")
restore_dir_cleanup() {
    rm -rf "$TEMP_RESTORE_DIR"
    rm -f "$LOCK_FILE"
}
trap restore_dir_cleanup EXIT

echo "[INFO] Extracting manifest and component verification..."
MANIFEST_FILE=$(core_extract_and_validate_manifest "$TARGET_ARCHIVE" "$TEMP_RESTORE_DIR" "instance_data") || {
    echo "[ERROR] Manifest extraction or component validation failed." >&2
    update_status "FAILED" 10 "خطا: فایل مانیفست یا مؤلفه‌های پشتیبان معتبر نیستند."
    record_audit "$BACKUP_FILENAME" "UNKNOWN" "UNKNOWN" "NONE" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Manifest validation failed"
    exit 1
}

COMPONENT_DIR=$(dirname "$MANIFEST_FILE")
echo "  [OK] Target archive integrity and component layout verified."

# ==============================================================================
# Stage 2: Validate Baseline Compatibility (via Shared Restore Core)
# ==============================================================================
echo ""
echo "[INFO] [2/9] Validating System Baseline Compatibility..."
update_status "IN_PROGRESS" 20 "مرحله ۲/۹: بررسی سازگاری بیس‌لاین سیستم جاری با نسخه پشتیبان..."

CURRENT_GIT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
CURRENT_NC_VERSION=$(docker exec "$TARGET_APP_CONTAINER" php occ status 2>/dev/null | grep -i "versionstring" | awk '{print $3}' || echo "unknown")

CHECK_BASELINE=$(core_validate_baseline_compatibility "$MANIFEST_FILE" "$CURRENT_GIT_COMMIT" "$CURRENT_NC_VERSION" 2>&1 || true)

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
# Stage 3: Create Emergency Pre-Restore Safety Backup (Production Specific)
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

if ! core_validate_archive_integrity "$PRE_RESTORE_ARCHIVE"; then
    echo "[ERROR] Pre-restore safety backup archive failed integrity check!" >&2
    update_status "FAILED" 40 "خطا: پیش‌پشتیبان امنیتی ایجاد شده معتبر نیست."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Pre-restore backup validation failed"
    exit 1
fi
echo "  [OK] Pre-restore safety backup validated: $PRE_RESTORE_BACKUP_ID"

# ==============================================================================
# Stage 5: Activate Maintenance Mode (Point of No Return Protection)
# ==============================================================================
echo ""
echo "[INFO] [5/9] Engaging Maintenance Mode on Production Application..."
update_status "IN_PROGRESS" 50 "مرحله ۵/۹: فعال‌سازی حالت تعمیرات (Maintenance Mode) جهت انجماد وضعیت..."

if [ "${TEST_SIMULATE_MAINT_ON_FAIL:-0}" = "1" ] || ! docker exec "$TARGET_APP_CONTAINER" php occ maintenance:mode --on > /dev/null 2>&1; then
    echo "[ERROR] Failed to activate maintenance mode on $TARGET_APP_CONTAINER." >&2
    update_status "FAILED" 50 "خطا: فعال‌سازی حالت تعمیرات (Maintenance Mode) با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Failed to activate maintenance mode" "$REQUESTED_BY"
    exit 1
fi

MAINT_ON_VERIFY=$(docker exec "$TARGET_APP_CONTAINER" php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "unknown")
if [ "$MAINT_ON_VERIFY" != "true" ]; then
    echo "[ERROR] Maintenance mode verification failed: status is '$MAINT_ON_VERIFY', expected 'true'." >&2
    update_status "FAILED" 50 "خطا: وضعیت حالت تعمیرات تایید نشد. عملیات بازیابی لغو گردید."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Maintenance mode verification failed: $MAINT_ON_VERIFY" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Maintenance mode successfully activated and verified (status: true)."

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
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "FAILED" "Failed during destructive execution step" "$REQUESTED_BY"
    exit $err_code
}
trap on_restore_failure ERR

# ==============================================================================
# Stage 6: Database Restore (via Shared Restore Core)
# ==============================================================================
echo ""
echo "[INFO] [6/9] Restoring PostgreSQL Database..."
update_status "IN_PROGRESS" 60 "مرحله ۶/۹: بازیابی دقیق پایگاه داده PostgreSQL (ON_ERROR_STOP=1)..."

DB_SQL_FILE="${COMPONENT_DIR}/database.sql"

if ! core_prepare_database "$TARGET_DB_CONTAINER" "$TARGET_DB_USER" "$TARGET_DB_PASSWORD" "$TARGET_DB_NAME"; then
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 55 "خطا: آماده‌سازی پایگاه داده با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Failed to disallow connections on database" "$REQUESTED_BY"
    exit 1
fi

core_setup_db_role "$TARGET_APP_CONTAINER" "$TARGET_DB_CONTAINER" "$TARGET_DB_USER" "$TARGET_DB_PASSWORD" "$TARGET_DB_NAME"

if ! core_restore_database_dump "$TARGET_DB_CONTAINER" "$TARGET_DB_USER" "$TARGET_DB_PASSWORD" "$TARGET_DB_NAME" "$DB_SQL_FILE"; then
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 60 "خطا: وارد کردن ساختار و داده‌های SQL با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Database SQL import failed" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Database successfully restored and core tables verified."

# ==============================================================================
# Stage 7: User Data Restore (via Shared Restore Core)
# ==============================================================================
echo ""
echo "[INFO] [7/9] Restoring User Files Storage ($TARGET_DATA_PATH)..."
update_status "IN_PROGRESS" 75 "مرحله ۷/۹: بازگردانی داده‌های فایل کاربران و تنظیم دسترسی‌ها..."

DATA_TAR_FILE="${COMPONENT_DIR}/data.tar.gz"

if ! core_restore_user_filesystem "$TARGET_APP_CONTAINER" "$DATA_TAR_FILE" "$TARGET_DATA_PATH"; then
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 75 "خطا: استخراج و بازیابی فایل‌های کاربران با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "User filesystem restore failed" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] User files extracted and ownership established."

# ==============================================================================
# Stage 8: Session Invalidation & DB <-> Files Consistency Validation
# ==============================================================================
echo ""
echo "[INFO] [8/9] Invalidating old sessions and cross-validating DB <-> Files..."
update_status "IN_PROGRESS" 85 "مرحله ۸/۹: ابطال سشن‌های قدیمی و ممیزی تطابق کامل پایگاه داده با فایل‌ها..."

core_invalidate_sessions_and_locks "$TARGET_DB_CONTAINER" "$TARGET_DB_USER" "$TARGET_DB_PASSWORD" "$TARGET_DB_NAME"
echo "  [OK] Old user sessions invalidated and file locks cleared."

CONSISTENCY_RESULT=$(core_validate_db_files_consistency "$MANIFEST_FILE" "$TARGET_APP_CONTAINER" "$TARGET_DB_CONTAINER" "$TARGET_DB_USER" "$TARGET_DB_PASSWORD" "$TARGET_DB_NAME" 2>&1 || true)

if ! echo "$CONSISTENCY_RESULT" | grep -q "SUCCESS"; then
    echo "[ERROR] Post-Restore DB <-> Files consistency validation failed:" >&2
    echo "$CONSISTENCY_RESULT" >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 85 "خطا: عدم تطابق ممیزی پایگاه داده با فایل‌های فیزیکی."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "DB files consistency check failed: $CONSISTENCY_RESULT" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] DB <-> Files consistency verified."

# ==============================================================================
# Stage 9: Post-Restore Health Gate & Service Recovery
# ==============================================================================
echo ""
echo "[INFO] [9/9] Running Post-Restore Health Check Gate (Maintenance remains ON)..."
update_status "IN_PROGRESS" 92 "مرحله ۹/۹: اجرای گیت بررسی سلامت سامانه در حالت Maintenance..."

if [ "${TEST_SIMULATE_HEALTH_FAIL:-0}" = "1" ] || ! "${SCRIPT_DIR}/check_health.sh" > "${BACKUP_DIR}/.restore_health_check.log" 2>&1; then
    echo "[ERROR] Post-Restore Health Check FAILED! System remains in protected MAINTENANCE MODE." >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 92 "خطا: آزمون سلامت سامانه پس از بازیابی با شکست مواجه شد. سیستم در حالت امن Maintenance باقی مانده است."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Post-restore health check gate failed" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Post-restore health check passed with 100% healthy status."

# ONLY AFTER HEALTH CHECK PASSES: Turn maintenance mode OFF
update_status "IN_PROGRESS" 96 "مرحله ۹/۹: خروج از حالت تعمیرات و اعتبارسنجی نهایی..."
echo "[INFO] Returning service to normal: Turning maintenance mode OFF..."
if [ "${TEST_SIMULATE_MAINT_OFF_FAIL:-0}" = "1" ] || ! docker exec "$TARGET_APP_CONTAINER" php occ maintenance:mode --off > /dev/null 2>&1; then
    echo "[ERROR] Failed to execute 'occ maintenance:mode --off' command!" >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 96 "خطا: غیرفعال‌سازی حالت تعمیرات با شکست مواجه شد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Failed to disable maintenance mode" "$REQUESTED_BY"
    exit 1
fi

MAINT_OFF_VERIFY=$(docker exec "$TARGET_APP_CONTAINER" php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "unknown")
if [ "$MAINT_OFF_VERIFY" != "false" ]; then
    echo "[ERROR] Verification failed: Maintenance mode is still ACTIVE (status: $MAINT_OFF_VERIFY)!" >&2
    RESTORE_END_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    update_status "FAILED" 96 "خطا: حالت تعمیرات همچنان فعال است و خاموش نشد."
    record_audit "$BACKUP_FILENAME" "$TARGET_RECOVERY_POINT" "$CURRENT_GIT_COMMIT" "$PRE_RESTORE_BACKUP_ID" "$RESTORE_START_TIME" "$RESTORE_END_TIME" "FAILED" "Maintenance mode still active after restore: $MAINT_OFF_VERIFY" "$REQUESTED_BY"
    exit 1
fi
echo "  [OK] Maintenance mode successfully disabled and verified (status: false)."

# Reset trap
trap cleanup EXIT

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
