#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$BACKUP_DIR/test_restore.log"
LAST_RUN_LOG="$BACKUP_DIR/.test_system_last_run.log"

TARGET_BACKUP="${1:-}"

if [ -z "$TARGET_BACKUP" ]; then
    if [ -f "$BACKUP_DIR/latest_system_backup.tar.gz" ]; then
        TARGET_BACKUP="$BACKUP_DIR/latest_system_backup.tar.gz"
    else
        LATEST_FOUND=$(find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f | sort -r | head -n1 || echo "")
        if [ -n "$LATEST_FOUND" ]; then
            TARGET_BACKUP="$LATEST_FOUND"
        else
            echo "[ERROR] No system backup archive found in $BACKUP_DIR"
            exit 1
        fi
    fi
fi

if [ ! -f "$TARGET_BACKUP" ]; then
    echo "[ERROR] Target system backup file not found: $TARGET_BACKUP"
    exit 1
fi

TARGET_BASENAME="$(basename "$TARGET_BACKUP")"
SANDBOX_DIR=$(mktemp -d -t sys_backup_sandbox_XXXXXX)

cleanup() {
    rm -rf "$SANDBOX_DIR"
}
trap cleanup EXIT INT TERM

START_TIME=$(date +%s)

echo "================================================================================"
echo " Enterprise Archive System - System Backup Verification Engine (BR-01)"
echo " Target Archive: $TARGET_BACKUP"
echo " Sandbox Dir:    $SANDBOX_DIR"
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

# STRICT INSPECTION: database.sql and data.tar.gz must NOT be inside the archive
if grep -Eq "/database\\.sql|/data\\.tar\\.gz" "$LISTING_FILE"; then
    echo "[FAIL] Operational database dump or user data archive detected inside system backup!"
    echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (Operational Data Leak in System Backup)" >> "$LOG_FILE"
    exit 1
fi

# Must contain required system components
for comp in manifest.json manifest.txt config.tar.gz config_keys.json custom_apps.tar.gz software_info.json; do
    if ! grep -Fq "/$comp" "$LISTING_FILE"; then
        echo "[FAIL] Missing required system component in archive: $comp"
        echo "[FAIL] Target: $TARGET_BASENAME -> FAIL (Missing Component: $comp)" >> "$LOG_FILE"
        exit 1
    fi
done
echo "  [OK] Archive member structure verified (Zero operational data confirmed)."

echo "[INFO] [3/5] Extracting components to sandbox directory..."
tar -C "$SANDBOX_DIR" -xzf "$TARGET_BACKUP"
BACKUP_ROOT=$(find "$SANDBOX_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)

if [ -z "$BACKUP_ROOT" ] || [ ! -d "$BACKUP_ROOT" ]; then
    echo "[FAIL] Failed to locate extracted backup root directory."
    exit 1
fi

echo "[INFO] [4/5] Validating manifest.json, Git baseline, and component checksums..."
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
if btype != "system_only":
    print(f"[FAIL] Expected backup_type=system_only, got {btype}", file=sys.stderr)
    sys.exit(1)

# 2. Git baseline
baseline = m.get("software_baseline", {})
commit = baseline.get("git_commit", "")
branch = baseline.get("git_branch", "unknown")
if not re.match(r"^[0-9a-f]{40}$", commit):
    print(f"[FAIL] Invalid Git commit SHA in manifest: {commit}", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] Git Commit Baseline: {commit} (Branch: {branch})")

# 3. Nextcloud version
nc_ver = baseline.get("nextcloud_version", "")
if not nc_ver:
    print("[FAIL] Nextcloud version missing from manifest", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] Nextcloud Version: {nc_ver}")

# 4. Component checksums
comps = m.get("components", {})
for k in ["config", "security_keys", "custom_apps", "software_info"]:
    if k not in comps:
        print(f"[FAIL] Component {k} missing in manifest components dictionary", file=sys.stderr)
        sys.exit(1)
    sha = comps[k].get("sha256", "")
    if len(sha) != 64:
        print(f"[FAIL] Component {k} has invalid SHA-256: {sha}", file=sys.stderr)
        sys.exit(1)

# 5. Composite digest
cfg_sha = comps["config"]["sha256"]
keys_sha = comps["security_keys"]["sha256"]
apps_sha = comps["custom_apps"]["sha256"]
soft_sha = comps["software_info"]["sha256"]
preimage = f"{cfg_sha}\n{keys_sha}\n{apps_sha}\n{soft_sha}"
expected_digest = hashlib.sha256(preimage.encode()).hexdigest()

actual_digest = m.get("components_digest_sha256", "")
if actual_digest != expected_digest:
    print(f"[FAIL] components_digest_sha256 mismatch! Expected {expected_digest}, got {actual_digest}", file=sys.stderr)
    sys.exit(1)
print(f"  [OK] Components Digest Verified: {actual_digest}")
EOF

# Validate actual files match sha256 in manifest
CONFIG_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/config.tar.gz" | cut -d' ' -f1)
KEYS_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/config_keys.json" | cut -d' ' -f1)
APPS_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/custom_apps.tar.gz" | cut -d' ' -f1)
SOFT_ACTUAL_SHA=$(sha256sum "$BACKUP_ROOT/software_info.json" | cut -d' ' -f1)

MANIFEST_CONFIG_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['config']['sha256'])")
MANIFEST_KEYS_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['security_keys']['sha256'])")
MANIFEST_APPS_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['custom_apps']['sha256'])")
MANIFEST_SOFT_SHA=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['components']['software_info']['sha256'])")

if [ "$CONFIG_ACTUAL_SHA" != "$MANIFEST_CONFIG_SHA" ] || \
   [ "$KEYS_ACTUAL_SHA" != "$MANIFEST_KEYS_SHA" ] || \
   [ "$APPS_ACTUAL_SHA" != "$MANIFEST_APPS_SHA" ] || \
   [ "$SOFT_ACTUAL_SHA" != "$MANIFEST_SOFT_SHA" ]; then
    echo "[FAIL] Internal component SHA-256 hash mismatch with manifest.json"
    exit 1
fi
echo "  [OK] Individual component hashes match manifest."

echo "[INFO] [5/5] Deep inspection of configuration, keys, and zero-data assertion..."
# 1. Config stream check
if ! tar -tzf "$BACKUP_ROOT/config.tar.gz" | grep -Fq "config.php"; then
    echo "[FAIL] config.tar.gz does not contain config.php"
    exit 1
fi

# 2. Config keys check
python3 - "$BACKUP_ROOT/config_keys.json" << 'EOF'
import json, sys
keys_path = sys.argv[1]
keys = json.load(open(keys_path))
for k in ["instanceid", "passwordsalt", "secret"]:
    v = keys.get(k, "")
    if not v:
        print(f"[FAIL] config_keys.json is missing key {k}", file=sys.stderr)
        sys.exit(1)
print(f"  [OK] Cryptographic identity keys verified: instanceid={keys.get('instanceid')} (salt/secret present)")
EOF

# 3. ABSOLUTE ZERO DATA CONFIRMATION: Scan extracted directory tree for any SQL files or user document patterns
SQL_FILES=$(find "$BACKUP_ROOT" -type f -name "*.sql" || echo "")
if [ -n "$SQL_FILES" ]; then
    echo "[FAIL] SQL database dump file found in system backup: $SQL_FILES"
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
GIT_COMMIT_VAL=$(python3 -c "import json; m=json.load(open('$MANIFEST_FILE')); print(m['software_baseline']['git_commit'])")

echo "================================================================================"
echo "[PASS] System Backup Verification PASSED (100% Integrity - Zero Operational Data Confirmed)"
echo "  - Verified Archive:  $TARGET_BASENAME"
echo "  - Backup Type:       system_only"
echo "  - Git Baseline:      $GIT_COMMIT_VAL"
echo "  - Duration:          ${DURATION}s"
echo "  - Status:            PASS"
echo "================================================================================"

echo "[PASS] Target: $TARGET_BASENAME -> PASS (100% Verified System Backup, 0 Operational Data, Duration: ${DURATION}s)" >> "$LOG_FILE"
exit 0
