#!/bin/bash
# ==============================================================================
# Enterprise Archive System - Shared Restore Core
#
# Target-independent restore core shared by:
#   - BR-04: Production Instance Data Restore (deploy/restore_instance_data.sh)
#   - BR-05: Full Disaster Recovery Orchestrator (deploy/orchestrate_disaster_recovery.sh)
#
# Covers:
#   1. Archive integrity & sidecar checksum validation
#   2. Manifest extraction & structural negative assertions
#   3. System baseline compatibility verification
#   4. Fail-closed database preparation (connection lock, termination, drop, create)
#   5. Database role configuration (oc_admin / target credentials)
#   6. Fail-closed database restore (psql -v ON_ERROR_STOP=1)
#   7. User filesystem restore & ownership enforcement
#   8. Session invalidation & file lock cleanup
#   9. Bidirectional DB <-> Files consistency validation (DB -> disk & disk -> DB)
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# 1. Archive & Checksum Validation
# ------------------------------------------------------------------------------
core_validate_archive_integrity() {
    local archive_file="$1"
    local sidecar_sha_file="${2:-${archive_file}.sha256}"

    if [ ! -f "$archive_file" ]; then
        echo "[ERROR] Archive file not found: $archive_file" >&2
        return 1
    fi

    # Check sidecar checksum if present
    if [ -f "$sidecar_sha_file" ]; then
        local expected_sha
        local actual_sha
        expected_sha=$(awk '{print $1}' "$sidecar_sha_file")
        actual_sha=$(sha256sum "$archive_file" | awk '{print $1}')
        if [ "$expected_sha" != "$actual_sha" ]; then
            echo "[ERROR] Checksum mismatch! Expected: $expected_sha, Got: $actual_sha" >&2
            return 2
        fi
    fi

    # Verify tar gzip stream integrity
    if ! tar -tzf "$archive_file" > /dev/null 2>&1; then
        echo "[ERROR] Archive is corrupted or not a valid gzip tarball: $archive_file" >&2
        return 3
    fi

    return 0
}

# ------------------------------------------------------------------------------
# 2. Extract & Validate Manifest
# ------------------------------------------------------------------------------
core_extract_manifest() {
    local archive_file="$1"
    local extract_dir="$2"
    mkdir -p "$extract_dir"
    tar -xzf "$archive_file" -C "$extract_dir"
    local manifest_file=""
    if [ -f "${extract_dir}/manifest.json" ]; then
        manifest_file="${extract_dir}/manifest.json"
    else
        manifest_file=$(find "$extract_dir" -maxdepth 2 -name "manifest.json" | head -n 1 || echo "")
    fi
    echo "$manifest_file"
}

core_extract_and_validate_manifest() {
    local archive_file="$1"
    local extract_dir="$2"
    local expected_type="$3" # "instance_data" or "system_only"

    mkdir -p "$extract_dir"
    tar -xzf "$archive_file" -C "$extract_dir"

    local manifest_file=""
    if [ -f "${extract_dir}/manifest.json" ]; then
        manifest_file="${extract_dir}/manifest.json"
    else
        manifest_file=$(find "$extract_dir" -maxdepth 2 -name "manifest.json" | head -n 1 || echo "")
    fi

    if [ -z "$manifest_file" ] || [ ! -f "$manifest_file" ]; then
        echo "[ERROR] manifest.json not found in archive $archive_file" >&2
        return 1
    fi

    # Check backup type
    local backup_type
    backup_type=$(python3 -c "import json; m=json.load(open('$manifest_file')); print(m.get('backup_type', ''))")
    if [ "$backup_type" != "$expected_type" ]; then
        echo "[ERROR] Manifest backup_type mismatch: expected '$expected_type', got '$backup_type'" >&2
        return 2
    fi

    # Structural Negative Assertions
    local listing_file="${extract_dir}/archive_listing.txt"
    tar -tzf "$archive_file" > "$listing_file"

    if [ "$expected_type" = "instance_data" ]; then
        if grep -Eq "(^|/)(config\.tar\.gz|custom_apps\.tar\.gz|docker-compose\.yml|config_keys\.json)$" "$listing_file"; then
            echo "[ERROR] Negative assertion failed: instance_data archive contains forbidden system files!" >&2
            return 3
        fi
        local comp_dir
        comp_dir=$(dirname "$manifest_file")
        if [ ! -s "${comp_dir}/database.sql" ]; then
            echo "[ERROR] Required component database.sql missing or empty in $archive_file" >&2
            return 4
        fi
        if [ ! -s "${comp_dir}/data.tar.gz" ]; then
            echo "[ERROR] Required component data.tar.gz missing or empty in $archive_file" >&2
            return 5
        fi
    elif [ "$expected_type" = "system_only" ]; then
        if grep -Eq "(^|/)(database\.sql|data\.tar\.gz)$" "$listing_file"; then
            echo "[ERROR] Negative assertion failed: system_only archive contains forbidden operational data files!" >&2
            return 3
        fi
        local comp_dir
        comp_dir=$(dirname "$manifest_file")
        for comp in config.tar.gz config_keys.json custom_apps.tar.gz software_info.json; do
            if [ ! -s "${comp_dir}/$comp" ]; then
                echo "[ERROR] Required component $comp missing or empty in $archive_file" >&2
                return 4
            fi
        done
    fi

    echo "$manifest_file"
    return 0
}

# ------------------------------------------------------------------------------
# 3. Baseline Compatibility Validation
# ------------------------------------------------------------------------------
core_validate_baseline_compatibility() {
    local manifest_file="$1"
    local current_git="$2"
    local current_nc="$3"

    python3 - "$manifest_file" "$current_git" "$current_nc" << 'PYEOF'
import sys, json

manifest_path = sys.argv[1]
curr_git = sys.argv[2]
curr_nc = sys.argv[3]

with open(manifest_path, "r", encoding="utf-8") as f:
    mf = json.load(f)

baseline = mf.get("system_baseline", {})
b_git = baseline.get("git_commit", "")
b_nc = baseline.get("nextcloud_version", "")

# Verify Git commit if both are valid 40-char hashes
if len(b_git) == 40 and len(curr_git) == 40:
    if b_git != curr_git:
        print(f"FAIL_GIT: Git commit mismatch! Backup: {b_git} != System: {curr_git}")
        sys.exit(1)

# Verify Nextcloud major/minor version matches
if b_nc and curr_nc != "unknown":
    if b_nc.split(".")[0] != curr_nc.split(".")[0]:
        print(f"FAIL_NC: Nextcloud version mismatch! Backup: {b_nc} != System: {curr_nc}")
        sys.exit(2)

print("SUCCESS")
PYEOF
}

# ------------------------------------------------------------------------------
# 4. Fail-Closed Database Preparation
# ------------------------------------------------------------------------------
core_prepare_database() {
    local db_container="$1"
    local admin_user="$2"
    local admin_password="$3"
    local target_db="$4"

    if [ "${TEST_SIMULATE_DB_PREP_FAIL:-0}" = "1" ]; then
        echo "[ERROR] Failed to disable incoming database connections on $target_db (Simulated DB prep failure DR-10)!" >&2
        return 1
    fi

    local db_exists
    db_exists=$(docker exec -e PGPASSWORD="$admin_password" "$db_container" psql -U "$admin_user" -d postgres -t -A -c         "SELECT count(*) FROM pg_database WHERE datname = '$target_db';" 2>/dev/null || echo "0")

    if [ "$db_exists" != "0" ]; then
        echo "  - [Core] Disallowing new connections on existing database '$target_db'..."
        if ! docker exec -e PGPASSWORD="$admin_password" "$db_container" psql -U "$admin_user" -d postgres -c             "ALTER DATABASE \"$target_db\" WITH ALLOW_CONNECTIONS false;" > /dev/null 2>&1; then
            echo "[ERROR] Failed to disable incoming database connections on $target_db!" >&2
            return 1
        fi

        echo "  - [Core] Terminating active connections on database '$target_db'..."
        if ! docker exec -e PGPASSWORD="$admin_password" "$db_container" psql -U "$admin_user" -d postgres -c             "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$target_db' AND pid <> pg_backend_pid();" > /dev/null 2>&1; then
            echo "[ERROR] Failed to terminate existing database connections on $target_db!" >&2
            return 2
        fi

        echo "  - [Core] Dropping database '$target_db' with FORCE..."
        if ! docker exec -e PGPASSWORD="$admin_password" "$db_container" psql -U "$admin_user" -d postgres -c             "DROP DATABASE IF EXISTS \"$target_db\" WITH (FORCE);" > /dev/null 2>&1; then
            echo "[ERROR] Failed to drop database $target_db!" >&2
            return 3
        fi
    fi

    echo "  - [Core] Creating fresh database '$target_db' owned by '$admin_user'..."
    if ! docker exec -e PGPASSWORD="$admin_password" "$db_container" psql -U "$admin_user" -d postgres -c         "CREATE DATABASE \"$target_db\" OWNER \"$admin_user\";" > /dev/null 2>&1; then
        echo "[ERROR] Failed to create database $target_db!" >&2
        return 4
    fi

    return 0
}

# ------------------------------------------------------------------------------
# 5. Setup Database Role from Nextcloud config.php
# ------------------------------------------------------------------------------
core_setup_db_role() {
    local app_container="$1"
    local db_container="$2"
    local admin_user="$3"
    local admin_password="$4"
    local target_db="$5"

    python3 - "$app_container" "$db_container" "$admin_user" "$admin_password" "$target_db" << 'PYEOF'
import sys, subprocess

app_c = sys.argv[1]
db_c = sys.argv[2]
admin_u = sys.argv[3]
admin_p = sys.argv[4]
target_db = sys.argv[5]

try:
    cmd_php = ["docker", "exec", app_c, "php", "-r", "require '/var/www/html/config/config.php'; echo ($CONFIG['dbuser']??'') . '|' . ($CONFIG['dbpassword']??'');"]
    res = subprocess.check_output(cmd_php, text=True).strip()
    if "|" in res:
        dbuser, dbpass = res.split("|", 1)
        if dbuser and dbuser != admin_u:
            dbpass_escaped = dbpass.replace("'", "''")
            sql = f"""
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{dbuser}') THEN
                    CREATE ROLE "{dbuser}" WITH LOGIN PASSWORD '{dbpass_escaped}';
                ELSE
                    ALTER ROLE "{dbuser}" WITH LOGIN PASSWORD '{dbpass_escaped}';
                END IF;
                GRANT ALL PRIVILEGES ON DATABASE "{target_db}" TO "{dbuser}";
                ALTER DATABASE "{target_db}" OWNER TO "{dbuser}";
            END $$;
            """
            subprocess.run(
                ["docker", "exec", "-e", f"PGPASSWORD={admin_p}", db_c, "psql", "-U", admin_u, "-d", "postgres", "-c", sql],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE
            )
            # Ensure schema public grants
            sql_schema = f'GRANT ALL ON SCHEMA public TO "{dbuser}";'
            subprocess.run(
                ["docker", "exec", "-e", f"PGPASSWORD={admin_p}", db_c, "psql", "-U", admin_u, "-d", target_db, "-c", sql_schema],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE
            )
except Exception as e:
    print(f"[ERROR] core_setup_db_role failed: {e}", file=sys.stderr)
    sys.exit(1)
PYEOF
}

# ------------------------------------------------------------------------------
# 6. Database Restore (psql -v ON_ERROR_STOP=1)
# ------------------------------------------------------------------------------
core_restore_database_dump() {
    local db_container="$1"
    local db_user="$2"
    local db_password="$3"
    local target_db="$4"
    local sql_file="$5"

    if [ ! -s "$sql_file" ]; then
        echo "[ERROR] SQL dump file is missing or empty: $sql_file" >&2
        return 1
    fi

    echo "  - [Core] Importing SQL dump with ON_ERROR_STOP=1..."
    if ! docker exec -i -e PGPASSWORD="$db_password" "$db_container" \
        psql -v ON_ERROR_STOP=1 -U "$db_user" -d "$target_db" < "$sql_file" > /dev/null 2>&1; then
        echo "[ERROR] Database SQL import failed (psql exited with error)." >&2
        return 2
    fi

    # Verify core archive tables exist
    local core_tables_count
    core_tables_count=$(docker exec -e PGPASSWORD="$db_password" "$db_container" psql -U "$db_user" -d "$target_db" -t -A -c \
        "SELECT count(*) FROM information_schema.tables WHERE table_name IN ('oc_users', 'oc_groups', 'oc_filecache', 'oc_archive_document_metadata', 'oc_systemtag');" 2>/dev/null || echo "0")

    if [ "$core_tables_count" -lt 5 ]; then
        echo "[ERROR] Database restore verification failed: found $core_tables_count / 5 core tables." >&2
        return 3
    fi

    # Ensure schema permissions for oc_admin role if exists
    docker exec -e PGPASSWORD="$db_password" "$db_container" psql -U "$db_user" -d "$target_db" -c \
        "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'oc_admin') THEN GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO oc_admin; GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO oc_admin; GRANT ALL ON SCHEMA public TO oc_admin; END IF; END \$\$;" >/dev/null 2>&1 || true

    return 0
}

# ------------------------------------------------------------------------------
# 7. Filesystem Restore
# ------------------------------------------------------------------------------
core_restore_user_filesystem() {
    local app_container="$1"
    local data_tar_file="$2"
    local target_data_path="${3:-/var/www/html/data}"

    if [ ! -s "$data_tar_file" ]; then
        echo "[ERROR] User data tarball is missing or empty: $data_tar_file" >&2
        return 1
    fi

    echo "  - [Core] Cleaning existing files in $target_data_path..."
    docker exec "$app_container" bash -c "rm -rf ${target_data_path}/*"

    echo "  - [Core] Extracting user files archive..."
    if ! docker exec -i "$app_container" tar -xzf - -C /var/www/html < "$data_tar_file" 2>/dev/null; then
        echo "[ERROR] Failed to extract user files archive into $app_container!" >&2
        return 2
    fi

    echo "  - [Core] Setting .ocdata marker and ownership www-data:www-data..."
    docker exec "$app_container" bash -c "touch ${target_data_path}/.ocdata && chown -R www-data:www-data ${target_data_path}"
    return 0
}

# ------------------------------------------------------------------------------
# 8. Session Invalidation & File Lock Cleanup
# ------------------------------------------------------------------------------
core_invalidate_sessions_and_locks() {
    local db_container="$1"
    local db_user="$2"
    local db_password="$3"
    local target_db="$4"

    echo "  - [Core] Invalidating old authtokens and clearing bruteforce attempts..."
    if ! docker exec -e PGPASSWORD="$db_password" "$db_container" psql -v ON_ERROR_STOP=1 -U "$db_user" -d "$target_db" \
        -c "TRUNCATE TABLE oc_authtoken; TRUNCATE TABLE oc_bruteforce_attempts;" > /dev/null 2>&1; then
        echo "[ERROR] Failed to truncate authtokens and bruteforce attempts in $target_db!" >&2
        return 1
    fi

    echo "  - [Core] Clearing stale file locks..."
    if ! docker exec -e PGPASSWORD="$db_password" "$db_container" psql -v ON_ERROR_STOP=1 -U "$db_user" -d "$target_db" \
        -c "DO \$\$ BEGIN IF to_regclass('oc_file_locks') IS NOT NULL THEN TRUNCATE TABLE oc_file_locks; END IF; END \$\$;" > /dev/null 2>&1; then
        echo "[ERROR] Failed to clear file locks in $target_db!" >&2
        return 2
    fi
    return 0
}

# ------------------------------------------------------------------------------
# 9. Bidirectional DB <-> Files Consistency Validation
# ------------------------------------------------------------------------------
core_validate_db_files_consistency() {
    local manifest_file="$1"
    local app_container="$2"
    local db_container="$3"
    local db_user="$4"
    local db_password="$5"
    local target_db="$6"

    python3 - "$manifest_file" "$app_container" "$db_container" "$db_user" "$db_password" "$target_db" << 'PYEOF'
import sys, json, subprocess

manifest_file = sys.argv[1]
app_c = sys.argv[2]
db_c = sys.argv[3]
pg_user = sys.argv[4]
pg_password = sys.argv[5]
pg_db = sys.argv[6]

with open(manifest_file, "r", encoding="utf-8") as f:
    mf = json.load(f)

# Step A: Check component counts against manifest expectations
exp_counts = mf.get("components", {}).get("database", {})
exp_u = exp_counts.get("users_count")
exp_g = exp_counts.get("groups_count")
exp_t = exp_counts.get("tags_count")
exp_d = exp_counts.get("documents_metadata_count")

sql = "SELECT (SELECT count(*) FROM oc_users), (SELECT count(*) FROM oc_groups), (SELECT count(*) FROM oc_systemtag), (SELECT count(*) FROM oc_archive_document_metadata);"
cmd = ["docker", "exec", "-e", f"PGPASSWORD={pg_password}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-F", "|", "-c", sql]
res = subprocess.run(cmd, capture_output=True, text=True)
if res.returncode != 0:
    print(f"FAIL_COUNTS_QUERY: {res.stderr}")
    sys.exit(1)

parts = res.stdout.strip().split("|")
if len(parts) == 4:
    u_cnt, g_cnt, t_cnt, d_cnt = [int(x) if x.isdigit() else 0 for x in parts]
    if exp_u is not None and u_cnt != exp_u:
        print(f"FAIL_USERS_COUNT: Users mismatch: DB={u_cnt} != Manifest={exp_u}")
        sys.exit(1)
    if exp_g is not None and g_cnt != exp_g:
        print(f"FAIL_GROUPS_COUNT: Groups mismatch: DB={g_cnt} != Manifest={exp_g}")
        sys.exit(1)
    if exp_t is not None and t_cnt != exp_t:
        print(f"FAIL_TAGS_COUNT: Tags mismatch: DB={t_cnt} != Manifest={exp_t}")
        sys.exit(1)
    if exp_d is not None and d_cnt != exp_d:
        print(f"FAIL_DOCS_COUNT: Docs metadata mismatch: DB={d_cnt} != Manifest={exp_d}")
        sys.exit(1)

# Step B: Direction 1: DB oc_filecache -> Physical files on disk + sample size verification
sql_fc = "SELECT s.id, f.path, f.size FROM oc_filecache f JOIN oc_storages s ON f.storage = s.numeric_id WHERE f.path LIKE 'files/%' AND f.mimetype != 2;"
cmd_fc = ["docker", "exec", "-e", f"PGPASSWORD={pg_password}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-F", "|", "-c", sql_fc]
res_fc = subprocess.run(cmd_fc, capture_output=True, text=True)
if res_fc.returncode != 0:
    print(f"FAIL_FC_QUERY: {res_fc.stderr}")
    sys.exit(1)

missing_files = []
sample_files = []
for line in res_fc.stdout.strip().splitlines():
    line = line.strip()
    if not line or "|" not in line:
        continue
    fc_parts = line.split("|")
    if len(fc_parts) < 3:
        continue
    storage_id, fpath, db_sz_str = fc_parts[0], fc_parts[1], fc_parts[2]
    db_size = int(db_sz_str) if db_sz_str.isdigit() else 0

    if storage_id.startswith("local::"):
        disk_path = storage_id.replace("local::", "") + fpath
    elif storage_id.startswith("home::"):
        user_name = storage_id.replace("home::", "")
        disk_path = f"/var/www/html/data/{user_name}/{fpath}"
    else:
        continue

    c = ["docker", "exec", app_c, "test", "-f", disk_path]
    if subprocess.run(c).returncode != 0:
        missing_files.append(disk_path)
    elif len(sample_files) < 10:
        sample_files.append((disk_path, db_size))

if missing_files:
    print(f"FAIL_MISSING_FILES: {len(missing_files)} DB records missing physical files on disk! Sample: {missing_files[:3]}")
    sys.exit(2)

# Verify sample file sizes
for sp, exp_sz in sample_files:
    try:
        act_sz = int(subprocess.check_output(["docker", "exec", app_c, "stat", "-c%s", sp], text=True).strip())
        if act_sz != exp_sz:
            print(f"FAIL_SIZE_MISMATCH: Sample file {sp} has disk size {act_sz} bytes but DB size {exp_sz} bytes")
            sys.exit(3)
    except Exception as e:
        print(f"FAIL_STAT: {sp}: {e}")
        sys.exit(3)

# Step C: Direction 2: Physical files on disk -> DB record exists in oc_filecache (Orphan detection)
# Check admin files and any other user files
find_cmd = ["docker", "exec", app_c, "bash", "-c", "find /var/www/html/data -maxdepth 4 -path '*/files/*' -type f"]
res_find = subprocess.run(find_cmd, capture_output=True, text=True)
if res_find.returncode != 0:
    print(f"FAIL_FIND_EXECUTION: find command failed: {res_find.stderr.strip()}", file=sys.stderr)
    sys.exit(5)
if res_find.stdout.strip():
    orphan_files = []
    for disk_f in res_find.stdout.strip().splitlines():
        disk_f = disk_f.strip()
        if not disk_f or ".ocTransfer" in disk_f or ".part" in disk_f:
            continue
        rel_parts = disk_f.split("/var/www/html/data/")
        if len(rel_parts) < 2:
            continue
        user_and_path = rel_parts[1].split("/", 1)
        if len(user_and_path) < 2:
            continue
        username, rel_path = user_and_path[0], user_and_path[1]
        
        sql_chk = f"SELECT count(*) FROM oc_filecache f JOIN oc_storages s ON f.storage = s.numeric_id WHERE s.id = 'home::{username}' AND f.path = '{rel_path}';"
        chk_res = subprocess.check_output(
            ["docker", "exec", "-e", f"PGPASSWORD={pg_password}", db_c, "psql", "-U", pg_user, "-d", pg_db, "-t", "-A", "-c", sql_chk],
            text=True
        ).strip()
        if chk_res.isdigit() and int(chk_res) == 0:
            orphan_files.append(disk_f)

    if orphan_files:
        print(f"FAIL_ORPHAN_FILES: {len(orphan_files)} Physical files have no DB record in oc_filecache! Sample: {orphan_files[:3]}")
        sys.exit(4)

print("SUCCESS")
PYEOF
}
