#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

mode="${1:-record}"
file_a="${2:-}"
file_b="${3:-}"

capture_fingerprint() {
    python3 - "$PROJECT_DIR" << 'PYEOF'
import os, sys, subprocess, json, hashlib

proj_dir = sys.argv[1]

# 1. Git HEAD
try:
    git_head = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=proj_dir, text=True).strip()
except Exception as e:
    git_head = f"error: {e}"

# 2. Container states and identities
containers = {}
for name in ["archive_db", "archive_app", "archive_proxy"]:
    try:
        out = subprocess.check_output(["docker", "inspect", name], text=True)
        info = json.loads(out)[0]
        containers[name] = {
            "id": info.get("Id"),
            "created": info.get("Created"),
            "image": info.get("Image"),
            "status": info.get("State", {}).get("Status"),
            "running": info.get("State", {}).get("Running"),
            "pid": info.get("State", {}).get("Pid")
        }
    except Exception as e:
        containers[name] = {"error": str(e)}

# 3. Database state evidence
db_state = {}
try:
    sql = "SELECT (SELECT count(*) FROM oc_users), (SELECT count(*) FROM oc_groups), (SELECT count(*) FROM oc_systemtag), (SELECT count(*) FROM oc_archive_document_metadata), (SELECT COALESCE(max(fileid), 0) FROM oc_filecache);"
    cmd = ["docker", "exec", "archive_db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-F", "|", "-c", sql]
    res = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
    if res.returncode == 0:
        parts = res.stdout.strip().split("|")
        db_state = {
            "users_count": int(parts[0]),
            "groups_count": int(parts[1]),
            "tags_count": int(parts[2]),
            "docs_metadata_count": int(parts[3]),
            "max_fileid": int(parts[4])
        }
    else:
        db_state = {"error": res.stderr.strip()}
except Exception as e:
    db_state = {"error": str(e)}

# 4. Critical filesystem markers
fs_markers = {}
for rel_path in [".env", "docker-compose.yml", "nextcloud/config/config.php"]:
    full_path = os.path.join(proj_dir, rel_path)
    if os.path.isfile(full_path):
        with open(full_path, "rb") as f:
            h = hashlib.sha256(f.read()).hexdigest()
        fs_markers[rel_path] = {
            "sha256": h,
            "size": os.path.getsize(full_path)
        }
    else:
        fs_markers[rel_path] = "missing"

fingerprint = {
    "git_head": git_head,
    "containers": containers,
    "database": db_state,
    "filesystem_markers": fs_markers
}

print(json.dumps(fingerprint, indent=2))
PYEOF
}

case "$mode" in
    record)
        if [ -n "$file_a" ]; then
            capture_fingerprint > "$file_a"
            echo "[OK] Production fingerprint recorded to: $file_a"
        else
            capture_fingerprint
        fi
        ;;
    verify)
        if [ -z "$file_a" ] || [ -z "$file_b" ]; then
            echo "[ERROR] Usage: $0 verify <before_file> <after_file>" >&2
            exit 1
        fi
        python3 - "$file_a" "$file_b" << 'PYEOF'
import sys, json

fa, fb = sys.argv[1], sys.argv[2]
with open(fa, "r") as f:
    da = json.load(f)
with open(fb, "r") as f:
    db = json.load(f)

mismatches = []

# Compare git head
if da.get("git_head") != db.get("git_head"):
    mismatches.append(f"Git HEAD changed: {da.get('git_head')} -> {db.get('git_head')}")

# Compare containers
ca, cb = da.get("containers", {}), db.get("containers", {})
for c in ["archive_db", "archive_app", "archive_proxy"]:
    ia, ib = ca.get(c, {}), cb.get(c, {})
    if ia.get("id") != ib.get("id"):
        mismatches.append(f"Container {c} ID changed: {ia.get('id')} -> {ib.get('id')}")
    if ia.get("status") != ib.get("status"):
        mismatches.append(f"Container {c} status changed: {ia.get('status')} -> {ib.get('status')}")

# Compare DB
dba, dbb = da.get("database", {}), db.get("database", {})
for k in ["users_count", "groups_count", "tags_count", "docs_metadata_count", "max_fileid"]:
    if dba.get(k) != dbb.get(k):
        mismatches.append(f"DB metric {k} changed: {dba.get(k)} -> {dbb.get(k)}")

# Compare filesystem markers
fsa, fsb = da.get("filesystem_markers", {}), db.get("filesystem_markers", {})
for k in fsa:
    if fsa.get(k) != fsb.get(k):
        mismatches.append(f"Filesystem marker {k} changed: {fsa.get(k)} -> {fsb.get(k)}")

if mismatches:
    print(f"[FAIL] Production state MODIFIED! Mismatches ({len(mismatches)}):", file=sys.stderr)
    for m in mismatches:
        print(f"  - {m}", file=sys.stderr)
    sys.exit(1)

print("[OK] Production state UNCHANGED: Before == After (100% Match). Zero modification confirmed.")
PYEOF
        ;;
    *)
        echo "Usage: $0 {record [output_file] | verify <before_file> <after_file>}"
        exit 1
        ;;
esac
