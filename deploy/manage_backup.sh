#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_DIR="$SCRIPT_DIR/backups"
CONFIG_FILE="$SCRIPT_DIR/backup_config.json"

cmd="${1:-help}"
shift || true

usage() {
    echo "================================================================================"
    echo " Enterprise Archive System - Unified Backup & Recovery CLI Tool"
    echo "================================================================================"
    echo "Usage: ./deploy/manage_backup.sh [command] [options...]"
    echo ""
    echo "Commands:"
    echo "  status               Show status of Docker containers and latest backup snapshots"
    echo "  list                 List all available backup archives (system, data, full)"
    echo "  backup-data          Create independent instance data backup (instance_data)"
    echo "  backup-system        Create independent system backup (system_only)"
    echo "  run [data|system]    Create backup (data: instance_data, system: system_only, default: full_instance)"
    echo "  backup [type]        Alias for run"
    echo "  restore [file]       Restore full instance from specified archive"
    echo "  test [file]          Perform sandbox verification (auto-detects full, data, or system)"
    echo "  test-data [file]     Perform sandbox verification of instance data backup"
    echo "  test-system [file]   Perform sandbox verification of system backup"
    echo "  prune [type]         Execute independent retention policy (data, system, full, or all)"
    echo "  config               Display current configuration from backup_config.json"
    echo "  help                 Display this help message"
    echo "================================================================================"
}

case "$cmd" in
    status)
        echo "================================================================================"
        echo " Backup & Recovery Engine Status"
        echo "================================================================================"
        echo "[1] Docker Container Status:"
        for c in archive_db archive_app archive_proxy; do
            st=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
            printf "  - %-15s : %s\n" "$c" "$st"
        done
        echo ""
        echo "[2] Latest Backups:"
        
        # Latest Full Instance Backup
        LATEST_FULL=""
        if [ -f "$BACKUP_DIR/latest_instance_backup.tar.gz" ]; then
            LATEST_FULL="$BACKUP_DIR/latest_instance_backup.tar.gz"
        elif [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
            LATEST_FULL="$BACKUP_DIR/latest_data_backup.tar.gz"
        fi

        if [ -n "$LATEST_FULL" ] && [ -f "$LATEST_FULL" ]; then
            sz=$(du -h "$LATEST_FULL" | cut -f1)
            dt=$(date -r "$LATEST_FULL" -Iseconds 2>/dev/null || stat -c%y "$LATEST_FULL" 2>/dev/null || echo "N/A")
            sha="N/A"
            if [ -f "$LATEST_FULL.sha256" ]; then
                sha=$(cut -d' ' -f1 < "$LATEST_FULL.sha256")
            fi
            echo "  - Full Instance: $(basename "$LATEST_FULL") ($sz)"
            echo "    * Date:        $dt"
            echo "    * SHA256:      $sha"
        else
            echo "  - Full Instance: None found."
        fi

        # Latest Instance Data Backup
        LATEST_DATA=""
        if [ -f "$BACKUP_DIR/latest_instance_data_backup.tar.gz" ]; then
            LATEST_DATA="$BACKUP_DIR/latest_instance_data_backup.tar.gz"
        fi

        if [ -n "$LATEST_DATA" ] && [ -f "$LATEST_DATA" ]; then
            sz_data=$(du -h "$LATEST_DATA" | cut -f1)
            dt_data=$(date -r "$LATEST_DATA" -Iseconds 2>/dev/null || stat -c%y "$LATEST_DATA" 2>/dev/null || echo "N/A")
            sha_data="N/A"
            if [ -f "$LATEST_DATA.sha256" ]; then
                sha_data=$(cut -d' ' -f1 < "$LATEST_DATA.sha256")
            fi
            baseline_data=$(python3 -c "
import tarfile, json
try:
    with tarfile.open('$LATEST_DATA', 'r:gz') as t:
        for m in t.getmembers():
            if m.name.endswith('manifest.json'):
                mf = json.load(t.extractfile(m))
                b = mf.get('system_baseline', {})
                print(b.get('system_backup_id', 'N/A')[:18] + ' (' + b.get('git_commit', 'N/A')[:8] + ')')
                break
except Exception:
    print('N/A')
" 2>/dev/null || echo "N/A")
            echo "  - Instance Data: $(basename "$LATEST_DATA") ($sz_data)"
            echo "    * Baseline:    $baseline_data"
            echo "    * Date:        $dt_data"
            echo "    * SHA256:      $sha_data"
        else
            echo "  - Instance Data: None found."
        fi

        # Latest System Backup
        LATEST_SYS=""
        if [ -f "$BACKUP_DIR/latest_system_backup.tar.gz" ]; then
            LATEST_SYS="$BACKUP_DIR/latest_system_backup.tar.gz"
        fi

        if [ -n "$LATEST_SYS" ] && [ -f "$LATEST_SYS" ]; then
            sz_sys=$(du -h "$LATEST_SYS" | cut -f1)
            dt_sys=$(date -r "$LATEST_SYS" -Iseconds 2>/dev/null || stat -c%y "$LATEST_SYS" 2>/dev/null || echo "N/A")
            sha_sys="N/A"
            if [ -f "$LATEST_SYS.sha256" ]; then
                sha_sys=$(cut -d' ' -f1 < "$LATEST_SYS.sha256")
            fi
            git_commit_sys=$(python3 -c "
import tarfile, json
try:
    with tarfile.open('$LATEST_SYS', 'r:gz') as t:
        for m in t.getmembers():
            if m.name.endswith('manifest.json'):
                mf = json.load(t.extractfile(m))
                print(mf.get('software_baseline', {}).get('git_commit', 'N/A')[:8])
                break
except Exception:
    print('N/A')
" 2>/dev/null || echo "N/A")
            echo "  - System Only:   $(basename "$LATEST_SYS") ($sz_sys)"
            echo "    * Git Commit:  $git_commit_sys"
            echo "    * Date:        $dt_sys"
            echo "    * SHA256:      $sha_sys"
        else
            echo "  - System Only:   None found."
        fi

        echo ""
        echo "[3] Configuration ($CONFIG_FILE):"
        if [ -f "$CONFIG_FILE" ]; then
            python3 -c "
import json
c = json.load(open('$CONFIG_FILE'))
print('  - Master Enabled:      ', c.get('backup_enabled'))
print('  - Storage Location:    ', c.get('storage_location'))
print('  - Full Instance Cron:  ', c.get('schedule', {}).get('cron_expression'))
sys_b = c.get('system_backup', {})
print('  - System Backup Cron:  ', sys_b.get('cron_expression', 'N/A'), '(keep: ' + str(sys_b.get('max_backups_count', 14)) + ')')
data_b = c.get('instance_data_backup', {})
print('  - Instance Data Cron:  ', data_b.get('cron_expression', 'N/A'), '(keep: ' + str(data_b.get('max_backups_count', 14)) + ')')
" 2>/dev/null || cat "$CONFIG_FILE"
        fi
        echo "================================================================================"
        ;;

    list)
        echo "=============================================================================================================================="
        printf "%-42s  %-15s  %-16s  %-19s  %-8s  %-15s\n" "ARCHIVE FILENAME" "TYPE" "BASELINE/COMMIT" "DATE & TIME" "SIZE" "CHECKSUM STATUS"
        echo "------------------------------------------------------------------------------------------------------------------------------"
        python3 -c "
import os, glob, json, datetime, tarfile

backup_dir = '$BACKUP_DIR'
files = sorted(glob.glob(os.path.join(backup_dir, '*.tar.gz')), key=os.path.getmtime, reverse=True)

for f in files:
    fname = os.path.basename(f)
    if fname.startswith('latest_'):
        continue
    sz = f'{os.path.getsize(f) / (1024*1024):.1f}M'
    if os.path.getsize(f) < 1024 * 1024:
        sz = f'{os.path.getsize(f) / 1024:.0f}K'
    mtime = datetime.datetime.fromtimestamp(os.path.getmtime(f)).strftime('%Y-%m-%d %H:%M:%S')
    
    btype = 'full_instance'
    baseline_info = '-'
    if 'system' in fname:
        btype = 'system_only'
        try:
            with tarfile.open(f, 'r:gz') as t:
                for m in t.getmembers():
                    if m.name.endswith('manifest.json'):
                        mf = json.load(t.extractfile(m))
                        baseline_info = mf.get('software_baseline', {}).get('git_commit', '-')[:8]
                        break
        except Exception:
            pass
    elif 'instance_data' in fname or 'backup_data' in fname:
        btype = 'instance_data'
        try:
            with tarfile.open(f, 'r:gz') as t:
                for m in t.getmembers():
                    if m.name.endswith('manifest.json'):
                        mf = json.load(t.extractfile(m))
                        b = mf.get('system_baseline', {})
                        sys_id = b.get('system_backup_id', '')
                        commit = b.get('git_commit', '')[:8]
                        if sys_id:
                            baseline_info = sys_id[:16]
                        elif commit:
                            baseline_info = commit
                        break
        except Exception:
            pass

    sha_file = f + '.sha256'
    sha_status = '[OK] Verified' if os.path.exists(sha_file) else '[No Hash]'
    print(f'{fname:<42}  {btype:<15}  {baseline_info:<16}  {mtime:<19}  {sz:<8}  {sha_status:<15}')
"
        echo "=============================================================================================================================="
        ;;

    backup-data|data-backup|backup-instance-data)
        "$SCRIPT_DIR/backup_instance_data.sh"
        ;;

    backup-system|system-backup)
        "$SCRIPT_DIR/backup_system.sh"
        ;;

    run|backup)
        target_type="${1:-}"
        if [ "$target_type" = "data" ] || [ "$target_type" = "instance_data" ]; then
            "$SCRIPT_DIR/backup_instance_data.sh"
        elif [ "$target_type" = "system" ] || [ "$target_type" = "system_only" ]; then
            "$SCRIPT_DIR/backup_system.sh"
        else
            "$SCRIPT_DIR/backup_db.sh"
        fi
        ;;

    restore)
        target="${1:-}"
        if [ -z "$target" ]; then
            default_restore="latest_instance_backup.tar.gz"
            if [ ! -f "$BACKUP_DIR/$default_restore" ] && [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
                default_restore="latest_data_backup.tar.gz"
            fi
            echo "[PROMPT] Restoring from latest full instance backup: $default_restore"
            read -r -p "Are you sure you want to restore the entire system? [y/N]: " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                "$SCRIPT_DIR/restore_db.sh" "$BACKUP_DIR/$default_restore"
            else
                echo "[ABORTED] Restore cancelled by user."
            fi
        else
            "$SCRIPT_DIR/restore_db.sh" "$target"
        fi
        ;;

    test)
        target="${1:-}"
        if [ -n "$target" ] && [[ "$target" == *"system"* ]]; then
            "$SCRIPT_DIR/test_system_backup.sh" "$target"
        elif [ -n "$target" ] && ([[ "$target" == *"instance_data"* ]] || [[ "$target" == *"backup_data"* ]]); then
            "$SCRIPT_DIR/test_instance_data_backup.sh" "$target"
        else
            "$SCRIPT_DIR/test_restore.sh" ${target:+"$target"}
        fi
        ;;

    test-data|test-instance-data)
        target="${1:-}"
        "$SCRIPT_DIR/test_instance_data_backup.sh" ${target:+"$target"}
        ;;

    test-system)
        target="${1:-}"
        "$SCRIPT_DIR/test_system_backup.sh" ${target:+"$target"}
        ;;

    prune)
        target_type="${1:-all}"
        echo "[INFO] Running independent retention pruning engine (Target: $target_type)..."
        
        # 1. Prune Instance Data Backups
        if [ "$target_type" = "all" ] || [ "$target_type" = "data" ] || [ "$target_type" = "instance_data" ]; then
            MAX_DATA=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('instance_data_backup', c.get('retention_policy', {})).get('max_backups_count', 14))" 2>/dev/null || echo 14)
            DAYS_DATA=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('instance_data_backup', c.get('retention_policy', {})).get('retention_days', 30))" 2>/dev/null || echo 30)
            echo "[PRUNE:instance_data] Enforcing limit (Count: $MAX_DATA, Days: $DAYS_DATA)..."
            find "$BACKUP_DIR" -maxdepth 1 -name "backup_instance_data_*.tar.gz" -type f | sort -r | tail -n +"$((MAX_DATA + 1))" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing expired data backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
            find "$BACKUP_DIR" -maxdepth 1 -name "backup_instance_data_*.tar.gz" -type f -mtime +"$DAYS_DATA" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing aged data backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
        fi

        # 2. Prune System Backups
        if [ "$target_type" = "all" ] || [ "$target_type" = "system" ] || [ "$target_type" = "system_only" ]; then
            MAX_SYS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('system_backup', c.get('retention_policy', {})).get('max_backups_count', 14))" 2>/dev/null || echo 14)
            DAYS_SYS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('system_backup', c.get('retention_policy', {})).get('retention_days', 30))" 2>/dev/null || echo 30)
            echo "[PRUNE:system_only] Enforcing limit (Count: $MAX_SYS, Days: $DAYS_SYS)..."
            find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f | sort -r | tail -n +"$((MAX_SYS + 1))" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing expired system backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
            find "$BACKUP_DIR" -maxdepth 1 -name "backup_system_*.tar.gz" -type f -mtime +"$DAYS_SYS" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing aged system backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
        fi

        # 3. Prune Full Instance Backups
        if [ "$target_type" = "all" ] || [ "$target_type" = "full" ] || [ "$target_type" = "full_instance" ]; then
            MAX_FULL=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('max_backups_count', 14))" 2>/dev/null || echo 14)
            DAYS_FULL=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('retention_days', 30))" 2>/dev/null || echo 30)
            echo "[PRUNE:full_instance] Enforcing limit (Count: $MAX_FULL, Days: $DAYS_FULL)..."
            find "$BACKUP_DIR" -maxdepth 1 \( -name "backup_full_instance_*.tar.gz" -o -name "backup_data_*.tar.gz" \) -type f | sort -r | tail -n +"$((MAX_FULL + 1))" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing expired full backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
            find "$BACKUP_DIR" -maxdepth 1 \( -name "backup_full_instance_*.tar.gz" -o -name "backup_data_*.tar.gz" \) -type f -mtime +"$DAYS_FULL" | while read -r old; do
                if [ -n "$old" ] && [ -f "$old" ]; then
                    echo "  [PRUNED] Removing aged full backup: $(basename "$old")"
                    rm -f "$old" "$old.sha256"
                fi
            done
        fi

        echo "[SUCCESS] Pruning complete."
        ;;

    config)
        if [ -f "$CONFIG_FILE" ]; then
            cat "$CONFIG_FILE"
        else
            echo "[ERROR] $CONFIG_FILE not found."
            exit 1
        fi
        ;;

    *)
        usage
        ;;
esac
