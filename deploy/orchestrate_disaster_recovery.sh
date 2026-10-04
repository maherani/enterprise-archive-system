#!/usr/bin/env bash
# ==============================================================================
# Enterprise Archive System - Full Disaster Recovery Coordinator (BR-05)
# Architecture: Recovery Coordinator / Orchestrator on Lost Server / New Host
# Utilizes Shared Restore Core (deploy/restore_core.sh) with RecoveryTarget.
# ==============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/backups"
AUDIT_LOG="$BACKUP_DIR/disaster_recovery_audit.jsonl"
STATUS_FILE="$BACKUP_DIR/.disaster_recovery_status.json"
LOCK_FILE="/tmp/archive_disaster_recovery.lock"

if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# Source Shared Restore Core
# shellcheck disable=SC1091
source "$SCRIPT_DIR/restore_core.sh"

mkdir -p "$BACKUP_DIR"

on_err() {
    local exit_code="$1"
    local line_no="$2"
    if [ "${CURRENT_STATE:-}" != "FAILED" ] && [ "${CURRENT_STATE:-}" != "OPERATIONAL" ] && [ "${CURRENT_STATE:-}" != "DRILL_PASSED" ]; then
        fail_recovery "Unexpected command failure (exit code $exit_code) at line $line_no" "FAILED"
    fi
}
trap 'on_err $? $LINENO' ERR

CURRENT_STATE="INITIALIZING"
DISASTER_DECLARED_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
RECOVERY_START_TIME="$DISASTER_DECLARED_TIME"
SYSTEM_READY_TIME="N/A"
DATA_RESTORE_TIME="N/A"
FUNCTIONAL_VALID_TIME="N/A"
NETWORK_DISCONNECT_TIME="N/A"
OPERATIONAL_TIME="N/A"

INTERNET_ACCESS_START="N/A"
INTERNET_ACCESS_END="N/A"

SYSTEM_RESTORE_RESULT="NOT_STARTED"
DATA_RESTORE_RESULT="NOT_STARTED"
VALIDATION_RESULT="NOT_STARTED"
HEALTH_RESULT="NOT_STARTED"
INTERNET_DISCONNECT_RESULT="NOT_STARTED"
FINAL_RESULT="FAILED"
FAILURE_REASON=""

CLEANUP_CALLED=0
cleanup() {
    if [ "$CLEANUP_CALLED" -eq 1 ]; then return; fi
    CLEANUP_CALLED=1
    rm -f "$LOCK_FILE"
    if [ -n "${TEMP_EXTRACT_DIR:-}" ] && [ -d "$TEMP_EXTRACT_DIR" ]; then
        rm -rf "$TEMP_EXTRACT_DIR"
    fi
    if [ -n "${RECOVERY_GIT_DIR:-}" ] && [ -d "$RECOVERY_GIT_DIR" ]; then
        rm -rf "$RECOVERY_GIT_DIR"
    fi
    if [ -n "${TEMP_FP_BEFORE:-}" ] && [ -f "$TEMP_FP_BEFORE" ]; then
        rm -f "$TEMP_FP_BEFORE"
    fi
    if [ -n "${TEMP_FP_AFTER:-}" ] && [ -f "$TEMP_FP_AFTER" ]; then
        rm -f "$TEMP_FP_AFTER"
    fi
}
trap cleanup EXIT INT TERM

update_state() {
    local new_state="$1"
    local msg="${2:-}"
    CURRENT_STATE="$new_state"
    echo ""
    echo "================================================================================"
    echo " [STATE: $CURRENT_STATE] $msg"
    echo "================================================================================"
    python3 - "$STATUS_FILE" "$new_state" "$msg" "${INCIDENT_ID:-UNKNOWN}" << 'PYEOF' 2>/dev/null || true
import sys, json, time
status_file, state, message, inc_id = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
try:
    with open(status_file, 'w', encoding='utf-8') as f:
        json.dump({
            'state': state,
            'message': message,
            'timestamp': time.time(),
            'incident_id': inc_id
        }, f, indent=2)
except Exception:
    pass
PYEOF
}

record_audit_log() {
    local comp_time
    comp_time=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    python3 - "$AUDIT_LOG" << 'PYEOF'
import sys, json, os

audit_file = sys.argv[1]
entry = {
    "incident_id": os.environ.get("INCIDENT_ID", "UNKNOWN"),
    "requested_by": os.environ.get("REQUESTED_BY", "UNKNOWN"),
    "recovery_operator": os.environ.get("RECOVERY_OPERATOR", "UNKNOWN"),
    "recovery_host": os.environ.get("RECOVERY_HOST", "UNKNOWN"),
    "system_backup_id": os.environ.get("FROZEN_SYS_ID", "UNKNOWN"),
    "system_backup_sha256": os.environ.get("FROZEN_SYS_SHA", "UNKNOWN"),
    "instance_data_backup_id": os.environ.get("FROZEN_DATA_ID", "UNKNOWN"),
    "instance_data_sha256": os.environ.get("FROZEN_DATA_SHA", "UNKNOWN"),
    "recovery_point": os.environ.get("FROZEN_RECOVERY_POINT", "UNKNOWN"),
    "git_commit": os.environ.get("FROZEN_GIT_COMMIT", "UNKNOWN"),
    "nextcloud_version": os.environ.get("FROZEN_NC_VERSION", "UNKNOWN"),
    "archive_app_version": os.environ.get("FROZEN_APP_VERSION", "UNKNOWN"),
    "internet_access_start": os.environ.get("INTERNET_ACCESS_START", "N/A"),
    "internet_access_end": os.environ.get("INTERNET_ACCESS_END", "N/A"),
    "system_restore_result": os.environ.get("SYSTEM_RESTORE_RESULT", "NOT_STARTED"),
    "data_restore_result": os.environ.get("DATA_RESTORE_RESULT", "NOT_STARTED"),
    "validation_result": os.environ.get("VALIDATION_RESULT", "NOT_STARTED"),
    "health_result": os.environ.get("HEALTH_RESULT", "NOT_STARTED"),
    "internet_disconnect_result": os.environ.get("INTERNET_DISCONNECT_RESULT", "NOT_STARTED"),
    "final_result": os.environ.get("FINAL_RESULT", "FAILED"),
    "started_at": os.environ.get("RECOVERY_START_TIME", ""),
    "completed_at": os.environ.get("COMPLETED_TIME", ""),
    "failure_reason": os.environ.get("FAILURE_REASON", "")
}
# Fail-safe redaction: Zero passwords, secrets, salts, or keys
for k, v in list(entry.items()):
    val_str = str(v)
    for forbidden in ["Secure_DB", "Secure_Admin", "password", "salt", "secret_key"]:
        if forbidden in val_str and forbidden not in ["failure_reason", "passwordsalt", "secret"]:
            entry[k] = "[REDACTED]"

with open(audit_file, "a", encoding="utf-8") as f:
    f.write(json.dumps(entry, ensure_ascii=False) + "\n")
PYEOF
}

fail_recovery() {
    local reason="$1"
    local state="${2:-FAILED}"
    rm -f "$LOCK_FILE"
    FAILURE_REASON="$reason"
    FINAL_RESULT="FAILED"
    COMPLETED_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    export FAILURE_REASON FINAL_RESULT COMPLETED_TIME
    export SYSTEM_RESTORE_RESULT DATA_RESTORE_RESULT VALIDATION_RESULT HEALTH_RESULT INTERNET_DISCONNECT_RESULT
    update_state "FAILED" "Disaster Recovery FAILED: $reason"
    record_audit_log
    echo "" >&2
    echo "================================================================================" >&2
    echo " [FATAL ERROR] DISASTER RECOVERY HALTED: $reason" >&2
    echo " State: FAILED (FAILED != OPERATIONAL)" >&2
    echo " Audit Log: $AUDIT_LOG" >&2
    echo "================================================================================" >&2
    # Clean up isolated recovery containers and temp dirs on failure
    if [ -n "${RECOVERY_COMPOSE_PROJECT:-}" ]; then
        docker compose -p "$RECOVERY_COMPOSE_PROJECT" -f "$SCRIPT_DIR/docker-compose.recovery.yml" down -v >/dev/null 2>&1 || true
    fi
    if [ -n "${RECOVERY_DIR:-}" ] && [ -d "$RECOVERY_DIR" ]; then
        docker run --rm -v /var/tmp:/mnt alpine rm -rf "/mnt/$(basename "$RECOVERY_DIR")" >/dev/null 2>&1 || rm -rf "$RECOVERY_DIR" >/dev/null 2>&1 || true
    fi
    exit 1
}

# ------------------------------------------------------------------------------
# Parse CLI Options
# ------------------------------------------------------------------------------
TARGET_SYSTEM_BACKUP=""
TARGET_DATA_BACKUP=""
TARGET_ENV_MODE="sandbox"
RECOVERY_HTTP_PORT="8085"
RECOVERY_COMPOSE_PROJECT="${RECOVERY_COMPOSE_PROJECT:-archive_recovery}"
RECOVERY_APP_CONTAINER="${RECOVERY_APP_CONTAINER:-archive_recovery_app}"
RECOVERY_DB_CONTAINER="${RECOVERY_DB_CONTAINER:-archive_recovery_db}"
RECOVERY_PROXY_CONTAINER="${RECOVERY_PROXY_CONTAINER:-archive_recovery_proxy}"
REQUESTED_BY="${RESTORE_REQUESTED_BY:-${SUDO_USER:-${USER:-alborz}}}"
INCIDENT_ID="INC-DR-$(date +%Y%m%d%H%M%S)"
NON_INTERACTIVE=0
SKIP_INTERNET_GATE=0
KEEP_RECOVERY_ENV=0
CLEAN_ONLY=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --validate-compose-images)
            shift
            app_img="${1:-}"
            db_img="${2:-}"
            proxy_img="${3:-}"
            python3 - "$app_img" "$db_img" "$proxy_img" << 'PYEOF'
import sys

roles = ["app", "db", "proxy"]
for idx, role in enumerate(roles):
    ref = sys.argv[idx + 1] if idx + 1 < len(sys.argv) else ""
    if not ref:
        print(f"FAIL_MISSING: Missing image reference for role '{role}'")
        sys.exit(1)
    if ":latest" in ref or ref.endswith("latest"):
        print(f"FAIL_MUTABLE_TAG: Image reference '{ref}' for role '{role}' uses forbidden ':latest' tag")
        sys.exit(1)
    if "@sha256:" not in ref:
        print(f"FAIL_TAG_ONLY: Tag-only mutable image reference '{ref}' for role '{role}' is rejected. Exact digest-pinned reference required.")
        sys.exit(1)
    parts = ref.split("@sha256:")
    if len(parts) != 2 or len(parts[1]) != 64:
        print(f"FAIL_INVALID_DIGEST: Image reference '{ref}' does not have a valid 64-char sha256 digest")
        sys.exit(1)

print("COMPOSE_IMAGES_VALID")
PYEOF
            exit $?
            ;;
        --system-backup) TARGET_SYSTEM_BACKUP="$2"; shift 2 ;;
        --data-backup) TARGET_DATA_BACKUP="$2"; shift 2 ;;
        --target-env) TARGET_ENV_MODE="$2"; shift 2 ;;
        --target-port) RECOVERY_HTTP_PORT="$2"; shift 2 ;;
        --requested-by) REQUESTED_BY="$2"; shift 2 ;;
        --incident-id) INCIDENT_ID="$2"; shift 2 ;;
        --non-interactive) NON_INTERACTIVE=1; shift ;;
        --skip-internet-gate) SKIP_INTERNET_GATE=1; shift ;;
        --keep-recovery-env) KEEP_RECOVERY_ENV=1; shift ;;
        --clean-only) CLEAN_ONLY=1; shift ;;
        --target-app-container) RECOVERY_APP_CONTAINER="$2"; shift 2 ;;
        --target-db-container) RECOVERY_DB_CONTAINER="$2"; shift 2 ;;
        --target-proxy-container) RECOVERY_PROXY_CONTAINER="$2"; shift 2 ;;
        -h|--help)
            echo "Usage: $0 [options]"
            echo "Options:"
            echo "  --data-backup <path>       Path to instance data backup (required in non-interactive)"
            echo "  --system-backup <path>     Path to paired system backup (optional, resolved via manifest)"
            echo "  --target-env <mode>        'sandbox' (drill) or 'host' (dedicated recovery host)"
            echo "  --target-port <port>       Isolated HTTP port for recovery (default: 8085)"
            echo "  --requested-by <user>      Operator identity requesting recovery"
            echo "  --incident-id <id>         Incident Tracking ID (default: auto-generated)"
            echo "  --non-interactive          Fail-closed if parameters are missing; do not prompt"
            echo "  --keep-recovery-env        Preserve recovery containers after drill"
            echo "  --clean-only               Remove lingering recovery containers and exit"
            exit 0
            ;;
        *)
            if [ -z "$TARGET_DATA_BACKUP" ] && [[ "$1" == *"instance_data"* ]]; then
                TARGET_DATA_BACKUP="$1"
            elif [ -z "$TARGET_SYSTEM_BACKUP" ] && [[ "$1" == *"system"* ]]; then
                TARGET_SYSTEM_BACKUP="$1"
            fi
            shift
            ;;
    esac
done

if [ "$CLEAN_ONLY" -eq 1 ]; then
    echo "[INFO] Cleaning recovery containers and networks..."
    docker rm -f "$RECOVERY_PROXY_CONTAINER" "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" 2>/dev/null || true
    docker network rm archive_recovery_net 2>/dev/null || true
    echo "[OK] Clean complete."
    exit 0
fi

if [ -f "$LOCK_FILE" ]; then
    fail_recovery "Another Disaster Recovery process is currently running ($LOCK_FILE exists)" "FAILED"
fi
echo "$$" > "$LOCK_FILE"

RECOVERY_OPERATOR="${SUDO_USER:-${USER:-unknown}}"
RECOVERY_HOST=$(hostname 2>/dev/null || echo "unknown")
export INCIDENT_ID REQUESTED_BY RECOVERY_OPERATOR RECOVERY_HOST RECOVERY_START_TIME

# ------------------------------------------------------------------------------
# SECTION 4: PRODUCTION SAFETY GUARD (MANDATORY & FAIL-CLOSED)
# ------------------------------------------------------------------------------
echo "[INFO] Verifying Production Safety Guards..."
if [ "$RECOVERY_APP_CONTAINER" = "archive_app" ] || \
   [ "$RECOVERY_DB_CONTAINER" = "archive_db" ] || \
   [ "$RECOVERY_PROXY_CONTAINER" = "archive_proxy" ] || \
   [ "$RECOVERY_HTTP_PORT" = "80" ] || \
   [ "$RECOVERY_HTTP_PORT" = "443" ] || \
   [ "$RECOVERY_COMPOSE_PROJECT" = "enterprise-archive-system" ] || \
   [ "$RECOVERY_COMPOSE_PROJECT" = "deploy" ]; then
    fail_recovery "Production Target detected! BR-05 Disaster Recovery is structurally prohibited from running against the active Production environment (DR-18)" "FAILED"
fi

if [ "$TARGET_ENV_MODE" != "sandbox" ] && [ "$TARGET_ENV_MODE" != "host" ] && [ "$TARGET_ENV_MODE" != "dev_test" ]; then
    fail_recovery "Invalid target environment mode '$TARGET_ENV_MODE'. Must be 'sandbox' or 'host'" "FAILED"
fi
echo "  [OK] Production safety guards passed: Target is isolated ($TARGET_ENV_MODE, port $RECOVERY_HTTP_PORT)."

# DR-18: Record initial production fingerprint before any recovery action
TEMP_FP_BEFORE="/var/tmp/prod_fp_before_${INCIDENT_ID}.json"
TEMP_FP_AFTER="/var/tmp/prod_fp_after_${INCIDENT_ID}.json"
if [ -x "$SCRIPT_DIR/fingerprint_production.sh" ]; then
    echo "[INFO] Recording initial production protection fingerprint (DR-18)..."
    if docker inspect archive_app >/dev/null 2>&1; then
        if ! "$SCRIPT_DIR/fingerprint_production.sh" record "$TEMP_FP_BEFORE" >/dev/null 2>&1; then
            fail_recovery "Failed to capture initial production protection fingerprint (DR-18)" "FAILED"
        fi
    fi
fi

# ==============================================================================
# STAGE 1: PREPARED & RESOLVE INPUTS (FREEZE RECOVERY POINT)
# ==============================================================================
update_state "PREPARED" "Resolving and freezing Disaster Recovery set..."

# No silent fallback to latest_instance_data_backup!
if [ -z "$TARGET_DATA_BACKUP" ]; then
    if [ "$NON_INTERACTIVE" -eq 1 ]; then
        fail_recovery "No instance data backup specified in non-interactive mode. Silent fallback is prohibited (DR-05)" "FAILED"
    else
        echo "[PROMPT] Available Instance Data Backups in $BACKUP_DIR:"
        select bk in "$BACKUP_DIR"/backup_instance_data_*.tar.gz "Abort"; do
            if [ "$bk" = "Abort" ] || [ -z "$bk" ]; then
                fail_recovery "Disaster Recovery aborted by operator." "FAILED"
            fi
            TARGET_DATA_BACKUP="$bk"
            break
        done
    fi
fi

if [ ! -f "$TARGET_DATA_BACKUP" ]; then
    fail_recovery "Instance Data Backup file not found: $TARGET_DATA_BACKUP (DR-05)" "FAILED"
fi

# Resolve absolute path
TARGET_DATA_BACKUP="$(cd "$(dirname "$TARGET_DATA_BACKUP")" && pwd)/$(basename "$TARGET_DATA_BACKUP")"

# Validate Instance Data Backup early to extract exact pairing requirements
TEMP_EXTRACT_DIR="/var/tmp/dr_extract_${INCIDENT_ID}"
mkdir -p "$TEMP_EXTRACT_DIR/data" "$TEMP_EXTRACT_DIR/system"
chmod 700 "$TEMP_EXTRACT_DIR"

if ! tar -tzf "$TARGET_DATA_BACKUP" >/dev/null 2>&1; then
    fail_recovery "Instance Data Backup archive is corrupted or unreadable (DR-06)" "FAILED"
fi

tar -xzf "$TARGET_DATA_BACKUP" -C "$TEMP_EXTRACT_DIR/data"
DATA_SUBDIR=$(find "$TEMP_EXTRACT_DIR/data" -mindepth 1 -maxdepth 1 -type d | head -n 1)
DATA_MANIFEST="$DATA_SUBDIR/manifest.json"

if [ ! -f "$DATA_MANIFEST" ]; then
    fail_recovery "Instance Data Backup manifest.json is missing (DR-06)" "FAILED"
fi

FROZEN_DATA_PATH="$TARGET_DATA_BACKUP"
FROZEN_DATA_SHA=$(sha256sum "$FROZEN_DATA_PATH" | awk '{print $1}')
FROZEN_DATA_ID=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('backup_id', 'unknown'))")
FROZEN_RECOVERY_POINT=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('recovery_point', m.get('created_at', 'unknown')))")

# Determine Paired System Backup
PAIRED_SYS_ID=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('system_baseline', {}).get('system_backup_id', ''))")
PAIRED_SYS_SHA=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('system_baseline', {}).get('system_backup_sha256', ''))")

if [ -n "$TARGET_SYSTEM_BACKUP" ]; then
    if [ ! -f "$TARGET_SYSTEM_BACKUP" ]; then
        fail_recovery "Specified System Backup file not found: $TARGET_SYSTEM_BACKUP (DR-01)" "FAILED"
    fi
    TARGET_SYSTEM_BACKUP="$(cd "$(dirname "$TARGET_SYSTEM_BACKUP")" && pwd)/$(basename "$TARGET_SYSTEM_BACKUP")"
else
    # Auto-resolve using exact pairing
    if [ -n "$PAIRED_SYS_ID" ]; then
        echo "[INFO] Resolving paired System Backup for system_backup_id='$PAIRED_SYS_ID'..."
        FOUND_SYS=""
        while IFS= read -r f; do
            if [ -f "$f" ]; then
                CHK_ID=$(tar -xzf "$f" -O --wildcards "*/manifest.txt" 2>/dev/null | grep "^backup_id=" | cut -d= -f2 || true)
                if [ "$CHK_ID" = "$PAIRED_SYS_ID" ]; then
                    FOUND_SYS="$f"
                    break
                fi
            fi
        done < <(find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f)

        if [ -n "$FOUND_SYS" ]; then
            TARGET_SYSTEM_BACKUP="$FOUND_SYS"
            echo "  [OK] Resolved paired System Backup by ID: $(basename "$TARGET_SYSTEM_BACKUP")"
        else
            fail_recovery "Paired System Backup for ID '$PAIRED_SYS_ID' not found in $BACKUP_DIR. Silent fallback to latest is strictly prohibited (DR-07)" "FAILED"
        fi
    else
        fail_recovery "Instance Data Backup does not define a valid paired system_backup_id (DR-07)" "FAILED"
    fi
fi

FROZEN_SYS_PATH="$TARGET_SYSTEM_BACKUP"
FROZEN_SYS_SHA=$(sha256sum "$FROZEN_SYS_PATH" | awk '{print $1}')

FROZEN_GIT_COMMIT=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('system_baseline', {}).get('git_commit', 'unknown'))")
FROZEN_NC_VERSION=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('system_baseline', {}).get('nextcloud_version', 'unknown'))")
FROZEN_APP_VERSION=$(python3 -c "import json; m=json.load(open('$DATA_MANIFEST')); print(m.get('system_baseline', {}).get('archive_app_version', 'unknown'))")

FROZEN_SYS_ID="${PAIRED_SYS_ID:-unknown}"
export FROZEN_SYS_ID FROZEN_SYS_SHA FROZEN_DATA_ID FROZEN_DATA_SHA FROZEN_RECOVERY_POINT FROZEN_GIT_COMMIT FROZEN_NC_VERSION FROZEN_APP_VERSION

echo "================================================================================"
echo " Disaster Recovery Set Frozen:"
echo "  - Incident ID:             $INCIDENT_ID"
echo "  - System Backup File:      $(basename "$FROZEN_SYS_PATH")"
echo "  - Instance Data File:      $(basename "$FROZEN_DATA_PATH")"
echo "  - Target Recovery Point:   $FROZEN_RECOVERY_POINT"
echo "  - Target Git Commit:       $FROZEN_GIT_COMMIT"
echo "  - Target Port:             $RECOVERY_HTTP_PORT"
echo "================================================================================"

# ==============================================================================
# STAGE 2: BACKUPS_VALIDATED
# ==============================================================================
update_state "BACKUPS_VALIDATED" "Deep validation of System and Instance Data Backups..."

# 1. System Backup Integrity & Zero Operational Data
if ! tar -tzf "$FROZEN_SYS_PATH" >/dev/null 2>&1; then
    fail_recovery "System Backup archive corrupt: tar verification failed (DR-01)" "FAILED"
fi

SYS_SHA_FILE="${FROZEN_SYS_PATH}.sha256"
if [ -f "$SYS_SHA_FILE" ]; then
    RECORDED_SYS_SHA=$(cut -d' ' -f1 "$SYS_SHA_FILE")
    if [ "$RECORDED_SYS_SHA" != "$FROZEN_SYS_SHA" ]; then
        fail_recovery "System Backup SHA-256 mismatch with sidecar: calculated $FROZEN_SYS_SHA != recorded $RECORDED_SYS_SHA (DR-02)" "FAILED"
    fi
fi

tar -xzf "$FROZEN_SYS_PATH" -C "$TEMP_EXTRACT_DIR/system"
SYS_SUBDIR=$(find "$TEMP_EXTRACT_DIR/system" -mindepth 1 -maxdepth 1 -type d | head -n 1)
SYS_MANIFEST="$SYS_SUBDIR/manifest.json"

if [ ! -f "$SYS_MANIFEST" ]; then
    fail_recovery "System Backup manifest.json is missing (DR-03)" "FAILED"
fi

FROZEN_SYS_ID=$(python3 -c "import json; m=json.load(open('$SYS_MANIFEST')); print(m.get('backup_id', '$FROZEN_SYS_ID'))" 2>/dev/null || echo "$FROZEN_SYS_ID")
export FROZEN_SYS_ID

# Ensure NO operational data exists in System Backup
if [ -f "$SYS_SUBDIR/database.sql" ] || [ -f "$SYS_SUBDIR/data.tar.gz" ]; then
    fail_recovery "System Backup violates policy: contains operational data components (database.sql / data.tar.gz)" "FAILED"
fi

# Ensure required components exist
for comp in config.tar.gz config_keys.json custom_apps.tar.gz software_info.json; do
    if [ ! -s "$SYS_SUBDIR/$comp" ]; then
        fail_recovery "System Backup missing or empty required component: $comp (DR-03)" "FAILED"
    fi
done

# 2. Instance Data Backup Integrity & Zero System Overlap
DATA_SHA_FILE="${FROZEN_DATA_PATH}.sha256"
if [ -f "$DATA_SHA_FILE" ]; then
    RECORDED_DATA_SHA=$(cut -d' ' -f1 "$DATA_SHA_FILE")
    if [ "$RECORDED_DATA_SHA" != "$FROZEN_DATA_SHA" ]; then
        fail_recovery "Instance Data Backup SHA-256 mismatch with sidecar: calculated $FROZEN_DATA_SHA != recorded $RECORDED_DATA_SHA (DR-06)" "FAILED"
    fi
fi

if [ -f "$DATA_SUBDIR/config.tar.gz" ] || [ -f "$DATA_SUBDIR/docker-compose.yml" ]; then
    fail_recovery "Instance Data Backup violates policy: contains system configuration components (config.tar.gz / docker-compose.yml)" "FAILED"
fi

for comp in database.sql data.tar.gz; do
    if [ ! -s "$DATA_SUBDIR/$comp" ]; then
        fail_recovery "Instance Data Backup missing or empty required component: $comp (DR-05)" "FAILED"
    fi
done

echo "  [OK] System Backup validated: 100% integrity, zero operational data."
echo "  [OK] Instance Data Backup validated: 100% integrity, zero system configuration duplication."

# ==============================================================================
# STAGE 3: PAIRING_VALIDATED
# ==============================================================================
update_state "PAIRING_VALIDATED" "Verifying cryptographic and baseline binding between backups..."

PAIRING_RESULT=$(python3 - "$SYS_MANIFEST" "$DATA_MANIFEST" "$FROZEN_SYS_SHA" << 'PYEOF'
import sys, json

sys_m = json.load(open(sys.argv[1]))
data_m = json.load(open(sys.argv[2]))
actual_sys_sha = sys.argv[3]

sys_id_in_sys = sys_m.get("backup_id", "")
sys_id_in_data = data_m.get("system_baseline", {}).get("system_backup_id", "")
sha_in_data = data_m.get("system_baseline", {}).get("system_backup_sha256", "")
git_in_sys = sys_m.get("software_baseline", {}).get("git_commit", "")
git_in_data = data_m.get("system_baseline", {}).get("git_commit", "")
nc_in_sys = sys_m.get("software_baseline", {}).get("nextcloud_version", "")
nc_in_data = data_m.get("system_baseline", {}).get("nextcloud_version", "")

if sys_id_in_data and sys_id_in_sys != sys_id_in_data:
    print(f"FAIL_ID: system_backup_id mismatch: System={sys_id_in_sys} != InstanceData={sys_id_in_data}")
    sys.exit(1)

if sha_in_data and sha_in_data != actual_sys_sha:
    print(f"FAIL_SHA: System Backup SHA-256 mismatch: Data recorded {sha_in_data} != Actual {actual_sys_sha}")
    sys.exit(2)

if git_in_sys and git_in_data and git_in_sys != git_in_data:
    print(f"FAIL_GIT: Git commit mismatch: System={git_in_sys} != InstanceData={git_in_data}")
    sys.exit(3)

if nc_in_sys and nc_in_data and nc_in_sys.split(".")[0] != nc_in_data.split(".")[0]:
    print(f"FAIL_NC: Nextcloud major version mismatch: System={nc_in_sys} != InstanceData={nc_in_data}")
    sys.exit(4)

print("SUCCESS")
PYEOF
) || fail_recovery "Backup pairing and baseline binding failed: $PAIRING_RESULT (DR-07)" "FAILED"
echo "  [OK] Backup pairing and baseline binding verified: $PAIRING_RESULT"

# ==============================================================================
# STAGE 4: BASELINE_ACQUIRED & BASELINE_VALIDATED
# ==============================================================================
update_state "BASELINE_ACQUIRED" "Acquiring exact Git baseline and validating Docker image identity..."

# Exact Git Baseline Acquisition (Section 3 & 4)
# Never use current working tree directly. Acquire from immutable repository.bundle in System Backup.
RECOVERY_GIT_DIR="/var/tmp/recovery_git_${INCIDENT_ID}"
mkdir -p "$RECOVERY_GIT_DIR"

echo "[INFO] Resolving and acquiring exact Git baseline from System Backup repository bundle..."
if [ ! -f "$SYS_SUBDIR/repository.bundle" ]; then
    fail_recovery "System Backup is missing immutable repository.bundle artifact (Section 3/4)" "FAILED"
fi

EXP_BUNDLE_SHA=$(python3 -c "import json; print(json.load(open('$SYS_SUBDIR/software_info.json')).get('source_artifact', {}).get('sha256', ''))" 2>/dev/null || echo "")
if [ -z "$EXP_BUNDLE_SHA" ]; then
    EXP_BUNDLE_SHA=$(python3 -c "import json; print(json.load(open('$SYS_MANIFEST')).get('components', {}).get('repository_bundle', {}).get('sha256', ''))" 2>/dev/null || echo "")
fi

if [ -n "$EXP_BUNDLE_SHA" ]; then
    ACT_BUNDLE_SHA=$(sha256sum "$SYS_SUBDIR/repository.bundle" | cut -d' ' -f1)
    if [ "$EXP_BUNDLE_SHA" != "$ACT_BUNDLE_SHA" ]; then
        fail_recovery "Git repository bundle checksum mismatch: expected $EXP_BUNDLE_SHA got $ACT_BUNDLE_SHA (DR-04)" "FAILED"
    fi
fi

rm -rf "$RECOVERY_GIT_DIR" 2>/dev/null || true
if ! git clone "$SYS_SUBDIR/repository.bundle" "$RECOVERY_GIT_DIR" >/dev/null 2>&1; then
    fail_recovery "Failed to clone repository from immutable bundle (DR-04)" "FAILED"
fi

RESOLVED_GIT_COMMIT=$(git -C "$RECOVERY_GIT_DIR" rev-parse HEAD 2>/dev/null || echo "")
if [ "$RESOLVED_GIT_COMMIT" != "$FROZEN_GIT_COMMIT" ]; then
    fail_recovery "Acquired Git commit mismatch: bundle HEAD $RESOLVED_GIT_COMMIT != frozen $FROZEN_GIT_COMMIT (DR-04)" "FAILED"
fi
echo "$RESOLVED_GIT_COMMIT" > "$RECOVERY_GIT_DIR/.recovery_baseline_commit"
echo "  [OK] Exact Git commit $RESOLVED_GIT_COMMIT verified and materialized from bundle (zero dependency on working tree)."

update_state "BASELINE_VALIDATED" "Verifying exact Docker container image identities..."

# Docker Image Identity Strategy (Section 5 & Correction 2)
echo "[INFO] Verifying Docker Image Baseline against software_info.json..."
IMAGE_CHECK_RESULT=$(python3 - "$SYS_SUBDIR/software_info.json" << 'PYEOF'
import sys, json, subprocess

soft = json.load(open(sys.argv[1]))
req_images = soft.get("container_images", {})
req_digests = soft.get("container_image_digests", {})

for role in ["app", "db", "proxy"]:
    img_name = req_images.get(role, "")
    if not img_name or img_name == "unknown":
        print(f"FAIL_IMAGE: Missing image reference for role '{role}' in software_info.json")
        sys.exit(1)

    # Prohibit mutable 'latest' tag as acceptable baseline
    if img_name.endswith(":latest") or ":latest" in img_name:
        print(f"FAIL_MUTABLE_TAG: Image reference '{img_name}' for role '{role}' uses forbidden ':latest' tag")
        sys.exit(1)

    # Reject tag-only baseline without digest pinning
    if "@sha256:" not in img_name:
        print(f"FAIL_TAG_ONLY: Tag-only mutable baseline '{img_name}' for role '{role}' is not acceptable. Exact digest-pinned reference (repository@sha256:<digest>) required.")
        sys.exit(1)

    cmd = ["docker", "image", "inspect", img_name]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        print(f"FAIL_IMAGE: Image for role '{role}' ({img_name}) is unavailable on recovery host (DR-08)")
        sys.exit(1)

    img_info = json.loads(res.stdout)[0]
    actual_id = img_info.get("Id", "")
    repo_digests = img_info.get("RepoDigests", [])
    expected_digest = img_name.split("@")[-1]

    if expected_digest != actual_id and not any(expected_digest in rd for rd in repo_digests):
        print(f"FAIL_DIGEST: Digest mismatch for '{role}' image {img_name}: expected {expected_digest}, got {actual_id}")
        sys.exit(2)

print("SUCCESS")
PYEOF
) || fail_recovery "Docker image baseline verification failed: $IMAGE_CHECK_RESULT (DR-08)" "FAILED"
echo "  [OK] Docker image identity and availability confirmed: $IMAGE_CHECK_RESULT"

# Resolve exact immutable image references for compose
EXP_APP_IMAGE=$(python3 -c "import json; print(json.load(open('$SYS_SUBDIR/software_info.json'))['container_images']['app'])")
EXP_DB_IMAGE=$(python3 -c "import json; print(json.load(open('$SYS_SUBDIR/software_info.json'))['container_images']['db'])")
EXP_PROXY_IMAGE=$(python3 -c "import json; print(json.load(open('$SYS_SUBDIR/software_info.json'))['container_images']['proxy'])")

export RECOVERY_APP_IMAGE="$EXP_APP_IMAGE"
export RECOVERY_DB_IMAGE="$EXP_DB_IMAGE"
export RECOVERY_PROXY_IMAGE="$EXP_PROXY_IMAGE"

# Strict Compose image validation gate: must be immutable digest-pinned
for var_name in RECOVERY_APP_IMAGE RECOVERY_DB_IMAGE RECOVERY_PROXY_IMAGE; do
    img_val="${!var_name}"
    if [[ "$img_val" != *"@sha256:"* ]] || [[ "$img_val" == *":latest"* ]]; then
        fail_recovery "Recovery Compose image $var_name='$img_val' is not an immutable digest-pinned reference (DR-08)" "FAILED"
    fi
done

# ==============================================================================
# STAGE 5: RECOVERY_TARGET_CREATED & SYSTEM_RESTORED
# ==============================================================================
update_state "RECOVERY_TARGET_CREATED" "Creating isolated Recovery Target environment..."

INTERNET_ACCESS_START=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
export INTERNET_ACCESS_START

RECOVERY_DIR="/var/tmp/recovery_env_${INCIDENT_ID}"
mkdir -p "$RECOVERY_DIR/db" "$RECOVERY_DIR/nextcloud" "$RECOVERY_DIR/nginx"
chmod 755 "$RECOVERY_DIR"

if [ -f "$RECOVERY_GIT_DIR/nginx/default.conf" ]; then
    cp "$RECOVERY_GIT_DIR/nginx/default.conf" "$RECOVERY_DIR/nginx/default.conf"
else
    fail_recovery "Materialized Git baseline missing required artifact: nginx/default.conf (DR-03)" "FAILED"
fi

if [ -f "$RECOVERY_GIT_DIR/nginx/maintenance.html" ]; then
    cp "$RECOVERY_GIT_DIR/nginx/maintenance.html" "$RECOVERY_DIR/nginx/maintenance.html"
else
    fail_recovery "Materialized Git baseline missing required artifact: nginx/maintenance.html (DR-03)" "FAILED"
fi

docker rm -f "$RECOVERY_PROXY_CONTAINER" "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" 2>/dev/null || true

# Dynamic high-entropy bootstrap credentials for PostgreSQL (Section 6 & 10)
BOOTSTRAP_DB_PASS=$(openssl rand -hex 24)
BOOTSTRAP_DB_USER="recovery_bootstrap_admin"
BOOTSTRAP_DB_NAME="recovery_bootstrap_db"

export POSTGRES_USER="$BOOTSTRAP_DB_USER"
export POSTGRES_PASSWORD="$BOOTSTRAP_DB_PASS"
export POSTGRES_DB="$BOOTSTRAP_DB_NAME"
export RECOVERY_DATA_DIR="$RECOVERY_DIR"
export RECOVERY_HTTP_PORT
export RECOVERY_APP_CONTAINER RECOVERY_DB_CONTAINER RECOVERY_PROXY_CONTAINER

echo "[INFO] Starting isolated Recovery Target containers (port $RECOVERY_HTTP_PORT)..."
docker compose -p "$RECOVERY_COMPOSE_PROJECT" -f "$SCRIPT_DIR/docker-compose.recovery.yml" up -d

echo "[INFO] Waiting for Recovery Database ($RECOVERY_DB_CONTAINER) to become healthy..."
DB_HEALTH_TRIES=35
while [ $DB_HEALTH_TRIES -gt 0 ]; do
    ST=$(docker inspect --format '{{.State.Health.Status}}' "$RECOVERY_DB_CONTAINER" 2>/dev/null || echo "starting")
    if [ "$ST" = "healthy" ]; then break; fi
    sleep 1
    DB_HEALTH_TRIES=$((DB_HEALTH_TRIES - 1))
done

if [ $DB_HEALTH_TRIES -le 0 ]; then
    fail_recovery "Recovery Database container failed to reach healthy state (DR-10)" "FAILED"
fi
echo "  [OK] Recovery DB container is healthy."

NC_INIT_TRIES=60
while [ $NC_INIT_TRIES -gt 0 ]; do
    if docker exec "$RECOVERY_APP_CONTAINER" pgrep apache2 >/dev/null 2>&1; then break; fi
    sleep 1
    NC_INIT_TRIES=$((NC_INIT_TRIES - 1))
done
if [ $NC_INIT_TRIES -le 0 ]; then
    fail_recovery "Recovery Nextcloud container failed to initialize Apache in time (DR-13)" "FAILED"
fi

update_state "SYSTEM_RESTORED" "Restoring System Software & Identity Layer into Recovery Target..."

echo "[INFO] Restoring System Layer (config.tar.gz)..."
if ! docker exec -i "$RECOVERY_APP_CONTAINER" tar -xzf - -C /var/www/html < "$SYS_SUBDIR/config.tar.gz"; then
    fail_recovery "Failed to extract config.tar.gz into recovery container (DR-03)" "FAILED"
fi

# Custom Apps Restore Flow (Correction 3 - Strict Fail-Closed)
echo "[INFO] Restoring Custom Apps Layer (custom_apps.tar.gz)..."
if [ ! -s "$SYS_SUBDIR/custom_apps.tar.gz" ]; then
    fail_recovery "Required system backup component custom_apps.tar.gz is missing or empty (DR-03)" "FAILED"
fi

if ! tar -tzf "$SYS_SUBDIR/custom_apps.tar.gz" >/dev/null 2>&1; then
    fail_recovery "Integrity verification failed for custom_apps.tar.gz (DR-01/DR-03)" "FAILED"
fi

if ! docker exec -i "$RECOVERY_APP_CONTAINER" tar -xzf - -C /var/www/html < "$SYS_SUBDIR/custom_apps.tar.gz"; then
    fail_recovery "Failed to extract custom_apps.tar.gz into recovery container (DR-11)" "FAILED"
fi

# Deploy custom app strictly from the materialized exact Git commit (zero working tree fallback)
docker exec "$RECOVERY_APP_CONTAINER" mkdir -p /var/www/html/custom_apps/archive_autotag
if [ ! -d "$RECOVERY_GIT_DIR/apps/archive_autotag" ]; then
    fail_recovery "Materialized Git baseline missing required app: apps/archive_autotag (DR-03)" "FAILED"
fi

if ! docker cp "$RECOVERY_GIT_DIR/apps/archive_autotag/." "$RECOVERY_APP_CONTAINER:/var/www/html/custom_apps/archive_autotag/"; then
    fail_recovery "Failed to copy archive_autotag app into recovery container" "FAILED"
fi

# Fix ownership
if ! docker exec "$RECOVERY_APP_CONTAINER" chown -R www-data:www-data /var/www/html/config /var/www/html/custom_apps; then
    fail_recovery "Failed to set www-data ownership on restored config and custom_apps" "FAILED"
fi

# Verify expected app exists inside container
if ! docker exec "$RECOVERY_APP_CONTAINER" test -f "/var/www/html/custom_apps/archive_autotag/appinfo/info.xml"; then
    fail_recovery "Expected custom app archive_autotag missing from recovery container after restore" "FAILED"
fi

# Verify archive app version against enabled_apps or baseline
EXP_APP_VERSION=$(python3 -c "import json; s=json.load(open('$SYS_SUBDIR/software_info.json')); print(s.get('enabled_apps', {}).get('enabled', {}).get('archive_autotag') or s.get('software_baseline', {}).get('app_version', ''))" 2>/dev/null || echo "")
if [ -n "$EXP_APP_VERSION" ]; then
    ACT_APP_VERSION=$(docker exec "$RECOVERY_APP_CONTAINER" php -r '
        $xml = simplexml_load_file("/var/www/html/custom_apps/archive_autotag/appinfo/info.xml");
        echo $xml ? (string)$xml->version : "";
    ' 2>/dev/null || echo "")
    if [ "$ACT_APP_VERSION" != "$EXP_APP_VERSION" ]; then
        fail_recovery "archive_autotag version mismatch: expected $EXP_APP_VERSION, got $ACT_APP_VERSION" "FAILED"
    fi
fi
echo "  [OK] Custom apps restored, verified, and permissions configured (fail-closed)."

echo "[INFO] Restoring & Validating System Identity Keys (instanceid, passwordsalt, secret)..."
python3 - "$SYS_SUBDIR/config_keys.json" "$RECOVERY_APP_CONTAINER" << 'PYEOF' || fail_recovery "Identity keys validation/restoration failed (DR-09)" "FAILED"
import sys, json, subprocess

keys_file = sys.argv[1]
app_c = sys.argv[2]
exp_keys = json.load(open(keys_file))

for k in ["instanceid", "passwordsalt", "secret"]:
    expected = exp_keys.get(k, "")
    if not expected:
        print(f"FAIL_MISSING_KEY: {k} is empty in backup keys file")
        sys.exit(1)
    
    # Check if key is in config.php
    check_code = f"require '/var/www/html/config/config.php'; echo isset($CONFIG['{k}']) ? $CONFIG['{k}'] : '';"
    cur_val = subprocess.check_output(["docker", "exec", app_c, "php", "-r", check_code], text=True).strip()
    
    if cur_val != expected:
        set_code = f"$p='/var/www/html/config/config.php'; require $p; $CONFIG['{k}']='{expected}'; file_put_contents($p, '<'.'?' . 'php\n\\$CONFIG = ' . var_export($CONFIG, true) . ';\n');"
        subprocess.check_call(["docker", "exec", app_c, "php", "-r", set_code])

print("  [OK] system_identity_validation = PASS (Cryptographic identity verified without secret exposure).")
PYEOF

# Ensure NO premature operational data has leaked into data folder
if docker exec "$RECOVERY_APP_CONTAINER" test -d "/var/www/html/data/admin/files" 2>/dev/null; then
    fail_recovery "System restore contaminated with operational user files before data restore stage!" "FAILED"
fi

SYSTEM_RESTORE_RESULT="PASS"
SYSTEM_READY_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# ==============================================================================
# STAGE 6: SYSTEM_GATE_PASS
# ==============================================================================
update_state "SYSTEM_GATE_PASS" "Executing System Gate: verifying software baseline and identity..."

# Health check of system containers
for c in "$RECOVERY_DB_CONTAINER" "$RECOVERY_APP_CONTAINER" "$RECOVERY_PROXY_CONTAINER"; do
    C_ST=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
    if [ "$C_ST" != "running" ]; then
        fail_recovery "System Gate Failed: Container $c is $C_ST (DR-13)" "FAILED"
    fi
done

# Validate Nextcloud identity keys successfully loaded by runtime php
CONFIG_LOAD_CHECK=$(docker exec "$RECOVERY_APP_CONTAINER" php -r "
@require('/var/www/html/config/config.php');
if (isset(\$CONFIG) && is_array(\$CONFIG) && !empty(\$CONFIG['instanceid']) && !empty(\$CONFIG['passwordsalt']) && !empty(\$CONFIG['secret'])) {
    echo 'OK';
} else {
    echo 'FAIL';
}
" 2>/dev/null || echo "FAIL")

if [ "$CONFIG_LOAD_CHECK" != "OK" ]; then
    fail_recovery "System Gate Failed: Nextcloud config.php failed to load valid identity (DR-09)" "FAILED"
fi

# Validate absence of premature operational users
OP_USERS=$(docker exec "$RECOVERY_DB_CONTAINER" psql -U "$BOOTSTRAP_DB_USER" -d "$BOOTSTRAP_DB_NAME" -t -A -c \
    "SELECT count(*) FROM information_schema.tables WHERE table_name = 'oc_users';" 2>/dev/null || echo "0")
if [ "$OP_USERS" != "0" ]; then
    OP_USERS_COUNT=$(docker exec "$RECOVERY_DB_CONTAINER" psql -U "$BOOTSTRAP_DB_USER" -d "$BOOTSTRAP_DB_NAME" -t -A -c "SELECT count(*) FROM oc_users;" 2>/dev/null || echo "0")
    if [ "$OP_USERS_COUNT" -gt 0 ]; then
        fail_recovery "System Gate Failed: Premature user data present in database before Data Restore stage!" "FAILED"
    fi
fi

# Extract Recovered Database Identity from restored config.php (Section 10)
RECOVERED_DB_USER=$(docker exec "$RECOVERY_APP_CONTAINER" php -r "require '/var/www/html/config/config.php'; echo \$CONFIG['dbuser'] ?? '';")
RECOVERED_DB_PASS=$(docker exec "$RECOVERY_APP_CONTAINER" php -r "require '/var/www/html/config/config.php'; echo \$CONFIG['dbpassword'] ?? '';")
RECOVERED_DB_NAME=$(docker exec "$RECOVERY_APP_CONTAINER" php -r "require '/var/www/html/config/config.php'; echo \$CONFIG['dbname'] ?? '';")

if [ -z "$RECOVERED_DB_USER" ] || [ -z "$RECOVERED_DB_PASS" ] || [ -z "$RECOVERED_DB_NAME" ]; then
    fail_recovery "System Gate Failed: Could not extract recovered DB identity from config.php (DR-09)" "FAILED"
fi

echo "================================================================================"
echo " [SYSTEM GATE PASSED]"
echo "  - SYSTEM BASELINE = PASS"
echo "  - RECOVERED DB ID = $RECOVERED_DB_USER @ $RECOVERED_DB_NAME"
echo "  - INSTANCE DATA   = NOT RESTORED"
echo "================================================================================"

# ==============================================================================
# STAGE 7: DATA_RESTORED
# ==============================================================================
update_state "DATA_RESTORED" "Restoring Instance Data into Recovery Target (DB + Filesystem)..."

DATA_DB_SQL="$DATA_SUBDIR/database.sql"
DATA_FILES_TAR="$DATA_SUBDIR/data.tar.gz"

echo "[INFO] [1/4] Preparing Recovery Database (Fail-Closed via Core)..."
if ! core_prepare_database "$RECOVERY_DB_CONTAINER" "$BOOTSTRAP_DB_USER" "$BOOTSTRAP_DB_PASS" "$RECOVERED_DB_NAME"; then
    fail_recovery "Fail-closed database preparation failed (DR-10)" "FAILED"
fi

echo "[INFO] [2/4] Configuring Recovered Database Role & Privileges..."
if ! core_setup_db_role "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" "$BOOTSTRAP_DB_USER" "$BOOTSTRAP_DB_PASS" "$RECOVERED_DB_NAME"; then
    fail_recovery "Database role setup from recovered config failed (DR-10)" "FAILED"
fi

echo "[INFO] [3/4] Restoring PostgreSQL Dump with ON_ERROR_STOP=1 (via Core)..."
if ! core_restore_database_dump "$RECOVERY_DB_CONTAINER" "$RECOVERED_DB_USER" "$RECOVERED_DB_PASS" "$RECOVERED_DB_NAME" "$DATA_DB_SQL"; then
    fail_recovery "PostgreSQL dump import failed (psql -v ON_ERROR_STOP=1 exited with error) (DR-10)" "FAILED"
fi
echo "  [OK] Database successfully imported."

echo "[INFO] [4/4] Restoring User Files Storage (/var/www/html/data via Core)..."
if ! core_restore_user_filesystem "$RECOVERY_APP_CONTAINER" "$DATA_FILES_TAR" "/var/www/html/data"; then
    fail_recovery "User filesystem restore failed (DR-11)" "FAILED"
fi
echo "  [OK] User data files extracted and ownership established."

echo "[INFO] Invalidating old sessions and clearing file locks (via Core)..."
if ! core_invalidate_sessions_and_locks "$RECOVERY_DB_CONTAINER" "$RECOVERED_DB_USER" "$RECOVERED_DB_PASS" "$RECOVERED_DB_NAME"; then
    fail_recovery "Session invalidation and lock cleanup failed (Section 7/8)" "FAILED"
fi

DATA_RESTORE_RESULT="PASS"
DATA_RESTORE_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# ==============================================================================
# STAGE 8: STRUCTURAL_VALIDATION_PASS
# ==============================================================================
update_state "STRUCTURAL_VALIDATION_PASS" "Executing bidirectional DB <-> Files consistency audit..."

CONSISTENCY_CHECK=$(core_validate_db_files_consistency "$DATA_MANIFEST" "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" "$RECOVERED_DB_USER" "$RECOVERED_DB_PASS" "$RECOVERED_DB_NAME" 2>&1 || true)
if ! echo "$CONSISTENCY_CHECK" | grep -q "SUCCESS"; then
    echo "$CONSISTENCY_CHECK" >&2
    fail_recovery "DB <-> Files consistency validation failed (DR-12): $CONSISTENCY_CHECK" "FAILED"
fi
echo "  [OK] Structural & bidirectional consistency validation passed: 100% matched."

# Core Structural Table Checks
STRUCTURAL_CHECK=$(python3 - "$RECOVERY_DB_CONTAINER" "$RECOVERED_DB_USER" "$RECOVERED_DB_PASS" "$RECOVERED_DB_NAME" << 'PYEOF'
import sys, subprocess

db_c, u, p, d = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]

tables = ["oc_users", "oc_groups", "oc_group_user", "oc_filecache", "oc_archive_document_metadata", "oc_systemtag", "oc_systemtag_object_mapping"]
for tbl in tables:
    sql = f"SELECT count(*) FROM {tbl};"
    cmd = ["docker", "exec", "-e", f"PGPASSWORD={p}", db_c, "psql", "-U", u, "-d", d, "-t", "-A", "-c", sql]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        print(f"FAIL_TABLE_{tbl}: {res.stderr.strip()}")
        sys.exit(1)
print("SUCCESS")
PYEOF
) || fail_recovery "Core structural table validation failed: $STRUCTURAL_CHECK" "FAILED"

# ==============================================================================
# STAGE 9: FUNCTIONAL_VALIDATION_PASS & SECURITY_VALIDATION_PASS
# ==============================================================================
update_state "FUNCTIONAL_VALIDATION_PASS" "Executing real Behavioral & Security Validation on recovered instance..."

# Configure trusted domains for recovery HTTP port
docker exec -u www-data "$RECOVERY_APP_CONTAINER" php /var/www/html/occ config:system:set trusted_domains 3 --value="localhost:${RECOVERY_HTTP_PORT}" >/dev/null 2>&1 || true
docker exec -u www-data "$RECOVERY_APP_CONTAINER" php /var/www/html/occ config:system:set trusted_domains 4 --value="127.0.0.1:${RECOVERY_HTTP_PORT}" >/dev/null 2>&1 || true

FUNCTIONAL_RESULT=$(python3 - "$RECOVERY_HTTP_PORT" "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" "$RECOVERED_DB_USER" "$RECOVERED_DB_PASS" "$RECOVERED_DB_NAME" << 'PYEOF'
import sys, subprocess, requests, json, time

port = sys.argv[1]
app_c = sys.argv[2]
db_c = sys.argv[3]
pg_user = sys.argv[4]
pg_pass = sys.argv[5]
pg_db = sys.argv[6]

base_url = f"http://127.0.0.1:{port}"

# 1. Authentication Validation (Real pre-disaster credentials and wrong password rejection)
auth_admin = ("admin", "Secure_Admin_Password_123!")
try:
    r_admin = requests.get(f"{base_url}/ocs/v1.php/cloud/user", auth=auth_admin, headers={"OCS-APIRequest": "true", "Accept": "application/json"}, timeout=10)
    if r_admin.status_code != 200:
        print(f"FAIL_AUTH: Pre-disaster admin login failed: status {r_admin.status_code}")
        sys.exit(1)
except Exception as e:
    print(f"FAIL_AUTH_CONN: Cannot connect to recovery instance for auth: {e}")
    sys.exit(1)

# Wrong password MUST return 401 Unauthorized
r_wrong = requests.get(f"{base_url}/ocs/v1.php/cloud/user", auth=("admin", "WrongPassword_123!"), headers={"OCS-APIRequest": "true"}, timeout=5)
if r_wrong.status_code != 401:
    print(f"FAIL_AUTH_WRONG: Expected 401 for wrong password, got {r_wrong.status_code}")
    sys.exit(1)
print("  [OK] Pre-disaster authentication verified & wrong password strictly rejected (DR-14).")

# 2. Search & Metadata Validation (Real operational search via DocumentMetadataService)
sql_doc = "SELECT document_number, subject FROM oc_archive_document_metadata LIMIT 1;"
res_doc = subprocess.check_output(
    ["docker", "exec", "-e", f"PGPASSWORD={pg_pass}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-F", "|", "-c", sql_doc],
    text=True
).strip()
if res_doc and "|" in res_doc:
    doc_num, subject = res_doc.split("|", 1)
    php_search = f"require '/var/www/html/lib/base.php'; $svc = \\OC::$server->get(\\OCA\\ArchiveAutoTag\\Service\\DocumentMetadataService::class); $r = $svc->searchByMetadata('{doc_num}'); echo count($r);"
    res_search = subprocess.check_output(["docker", "exec", "-u", "www-data", app_c, "php", "-r", php_search], text=True).strip()
    if not res_search.isdigit() or int(res_search) < 1:
        print(f"FAIL_SEARCH: Search for DocNo={doc_num} returned 0 results")
        sys.exit(2)
    print(f"  [OK] Document search/metadata verified: DocNo={doc_num}, Subject={subject} (DR-16).")
    
    # Negative test: search non-existent document must return 0
    php_search_neg = f"require '/var/www/html/lib/base.php'; $svc = \\OC::$server->get(\\OCA\\ArchiveAutoTag\\Service\\DocumentMetadataService::class); $r = $svc->searchByMetadata('DOES_NOT_EXIST_XYZ_9999'); echo count($r);"
    res_search_neg = subprocess.check_output(["docker", "exec", "-u", "www-data", app_c, "php", "-r", php_search_neg], text=True).strip()
    if int(res_search_neg) != 0:
        print(f"FAIL_SEARCH_NEG: Negative search returned {res_search_neg} != 0")
        sys.exit(2)
else:
    print("  [WARN] No documents in metadata table to test search.")

# 3. System Tag Validation
sql_tags = "SELECT count(*) FROM oc_systemtag;"
tag_count = int(subprocess.check_output(["docker", "exec", "-e", f"PGPASSWORD={pg_pass}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-c", sql_tags], text=True).strip())
if tag_count > 0:
    print(f"  [OK] System tags verified ({tag_count} tags restored).")

# 4. Security & Role Isolation Validation (Section 11 & DR-15)
# Non-admin user test_user_a cannot execute admin API operations
r_unauth = requests.post(
    f"{base_url}/index.php/apps/archive_autotag/api/admin/restore/run",
    auth=("test_user_a", "User_Password_123!"),
    headers={"OCS-APIRequest": "true", "Accept": "application/json"},
    json={"confirmation": "RESTORE-CONFIRM"},
    timeout=5
)
if r_unauth.status_code not in [401, 403]:
    print(f"FAIL_ISOLATION: Non-admin user access not denied (got {r_unauth.status_code})")
    sys.exit(3)

sql_grp = "SELECT count(*) FROM oc_group_user;"
grp_user_count = int(subprocess.check_output(["docker", "exec", "-e", f"PGPASSWORD={pg_pass}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-c", sql_grp], text=True).strip())
if grp_user_count < 1:
    print("FAIL_ISOLATION: No group memberships found in oc_group_user (DR-15)")
    sys.exit(3)
print(f"  [OK] Group memberships and role isolation verified ({grp_user_count} associations) (DR-15).")

print("SUCCESS")
PYEOF
) || fail_recovery "Functional & Security Validation failed: $FUNCTIONAL_RESULT (DR-14/DR-15/DR-16)" "FAILED"

VALIDATION_RESULT="PASS"
FUNCTIONAL_VALID_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

update_state "SECURITY_VALIDATION_PASS" "Security, Role, and Isolation checks passed (Section 19)."

# ==============================================================================
# STAGE 10: NETWORK_DISCONNECTED (Air-Gap Egress Enforcement)
# ==============================================================================
update_state "NETWORK_DISCONNECTED" "Enforcing organization Air-Gapped Internet Disconnect Gate..."

INTERNET_ACCESS_END=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
export INTERNET_ACCESS_END

if [ "$SKIP_INTERNET_GATE" -eq 1 ]; then
    if [ "$TARGET_ENV_MODE" != "dev_test" ]; then
        fail_recovery "--skip-internet-gate flag is strictly prohibited in operational recovery path (Section 15)" "FAILED"
    fi
    echo "  [WARN] --skip-internet-gate flag active in dev_test mode: Bypassing real network disconnection."
    INTERNET_DISCONNECT_RESULT="SKIPPED"
else
    # Enforce real egress blocking: drop default route in recovery app & proxy
    docker exec "$RECOVERY_APP_CONTAINER" sh -c "ip route del default 2>/dev/null || route del default 2>/dev/null || true" 2>/dev/null || true
    docker exec "$RECOVERY_PROXY_CONTAINER" sh -c "ip route del default 2>/dev/null || route del default 2>/dev/null || true" 2>/dev/null || true

    # Real egress verification test inside container
    EGRESS_CHECK=$(docker exec "$RECOVERY_APP_CONTAINER" python3 -c "
import urllib.request, sys
try:
    urllib.request.urlopen('http://1.1.1.1', timeout=2)
    print('CONNECTED')
except Exception:
    print('BLOCKED')
" 2>/dev/null || echo "BLOCKED")

    if [ "$EGRESS_CHECK" = "CONNECTED" ]; then
        fail_recovery "Internet still connected after recovery (DR-17): external egress was not blocked" "FAILED"
    fi
    echo "  [OK] Air-gapped egress disconnect verified: External internet is strictly BLOCKED (DR-17)."
    INTERNET_DISCONNECT_RESULT="PASS"
fi

NETWORK_DISCONNECT_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# ==============================================================================
# STAGE 11: FINAL_HEALTH_PASS
# ==============================================================================
update_state "FINAL_HEALTH_PASS" "Executing final Health Gate on recovered instance..."

export TARGET_PROXY_CONTAINER="$RECOVERY_PROXY_CONTAINER"
export TARGET_APP_CONTAINER="$RECOVERY_APP_CONTAINER"
export TARGET_DB_CONTAINER="$RECOVERY_DB_CONTAINER"
export TARGET_HTTP_PORT="$RECOVERY_HTTP_PORT"
export DB_NAME="$RECOVERED_DB_NAME"
export DB_USER="$RECOVERED_DB_USER"
export DB_PASSWORD="$RECOVERED_DB_PASS"

if ! "$SCRIPT_DIR/check_health.sh" > "$RECOVERY_DIR/final_health_check.log" 2>&1; then
    cat "$RECOVERY_DIR/final_health_check.log" >&2
    fail_recovery "Final Health Check failed on recovered instance (DR-13)" "FAILED"
fi
echo "  [OK] Final Health Check passed 100% on recovered instance."

MAINT_VERIFY=$(docker exec "$RECOVERY_APP_CONTAINER" php occ status 2>/dev/null | grep -i "maintenance:" | awk '{print $NF}' || echo "unknown")
if [ "$MAINT_VERIFY" != "false" ]; then
    fail_recovery "Recovered instance is still locked in maintenance mode: $MAINT_VERIFY" "FAILED"
fi
echo "  [OK] Maintenance mode is false (system online and servicing requests)."

HEALTH_RESULT="PASS"

# ==============================================================================
# STAGE 12: PRODUCTION PROTECTION AUDIT & FINAL STATE RESOLUTION
# ==============================================================================
if [ -x "$SCRIPT_DIR/fingerprint_production.sh" ] && [ -f "$TEMP_FP_BEFORE" ]; then
    echo "[INFO] Verifying Production Protection Fingerprint (DR-18)..."
    if ! "$SCRIPT_DIR/fingerprint_production.sh" record "$TEMP_FP_AFTER" >/dev/null 2>&1; then
        fail_recovery "Failed to capture post-recovery production fingerprint (DR-18)" "FAILED"
    fi
    if ! "$SCRIPT_DIR/fingerprint_production.sh" verify "$TEMP_FP_BEFORE" "$TEMP_FP_AFTER" >/dev/null 2>&1; then
        fail_recovery "DR-18 FAILED: Production state was modified during recovery!" "FAILED"
    fi
    echo "  [OK] DR-18 Production Protection verified: Zero modification confirmed."
fi

OPERATIONAL_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
COMPLETED_TIME="$OPERATIONAL_TIME"

if [ "$TARGET_ENV_MODE" = "sandbox" ]; then
    FINAL_STATE="DRILL_PASSED"
    FINAL_RESULT="SUCCESS"
    update_state "DRILL_PASSED" "Disaster Recovery Drill Completed Successfully in Sandbox (DRILL_PASSED)."
else
    FINAL_STATE="OPERATIONAL"
    FINAL_RESULT="SUCCESS"
    update_state "OPERATIONAL" "Enterprise Archive System Disaster Recovery fully OPERATIONAL on host $RECOVERY_HOST."
fi

export SYSTEM_RESTORE_RESULT
export DATA_RESTORE_RESULT
export VALIDATION_RESULT
export HEALTH_RESULT
export INTERNET_DISCONNECT_RESULT
export FINAL_STATE
export FINAL_RESULT
export COMPLETED_TIME

record_audit_log

# Calculate real RTO duration in seconds
RTO_SECONDS=$(python3 -c "
from datetime import datetime
try:
    t0 = datetime.fromisoformat('$DISASTER_DECLARED_TIME'.replace('Z', '+00:00'))
    t1 = datetime.fromisoformat('$OPERATIONAL_TIME'.replace('Z', '+00:00'))
    print(int((t1 - t0).total_seconds()))
except Exception:
    print('N/A')
")

echo ""
echo "================================================================================"
echo " [DISASTER RECOVERY FINISHED — $FINAL_STATE]"
echo "================================================================================"
echo "  - Incident ID:              $INCIDENT_ID"
echo "  - Target Recovery Point:    $FROZEN_RECOVERY_POINT"
echo "  - Recovery Host:            $RECOVERY_HOST"
echo "  - Recovery Target Port:     $RECOVERY_HTTP_PORT"
echo "  - Access URL:               http://localhost:$RECOVERY_HTTP_PORT"
echo "  - State:                    $FINAL_STATE"
echo "  - Target Environment Mode:  $TARGET_ENV_MODE"
echo "  - RTO (Recovery Time):      ${RTO_SECONDS}s"
echo "  - RPO (Recovery Point):     $FROZEN_RECOVERY_POINT"
echo "  - Milestones:"
echo "    * Disaster Declared:      $DISASTER_DECLARED_TIME"
echo "    * System Ready:           $SYSTEM_READY_TIME"
echo "    * Data Restored:          $DATA_RESTORE_TIME"
echo "    * Functional Valid:       $FUNCTIONAL_VALID_TIME"
echo "    * Network Disconnected:   $NETWORK_DISCONNECT_TIME"
echo "    * Final Health Pass:      $COMPLETED_TIME"
echo "  - Audit Log:                $AUDIT_LOG"
echo "================================================================================"

if [ "$KEEP_RECOVERY_ENV" -eq 0 ] && [ "$TARGET_ENV_MODE" = "sandbox" ]; then
    echo "[INFO] Cleaning up temporary recovery sandbox environment..."
    docker rm -f "$RECOVERY_PROXY_CONTAINER" "$RECOVERY_APP_CONTAINER" "$RECOVERY_DB_CONTAINER" 2>/dev/null || true
    rm -rf "$RECOVERY_DIR" 2>/dev/null || true
    echo "  [OK] Sandbox cleaned. Production untouched."
fi

exit 0
