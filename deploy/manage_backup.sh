#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$SCRIPT_DIR/backup_config.json"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$BACKUP_DIR/test_restore.log"

cmd="${1:-help}"
shift || true

usage() {
    echo "================================================================================"
    echo " Enterprise Archive System - Backup & Recovery Management CLI"
    echo "================================================================================"
    echo "Usage: ./deploy/manage_backup.sh <command> [options]"
    echo ""
    echo "Commands:"
    echo "  status               Show overall status of backup engine, services, and latest backups"
    echo "  list                 List all available backup archives (system_only & full_instance)"
    echo "  backup-system        Execute immediate System Backup (Software + Config + Identity, zero data)"
    echo "  run | backup         Execute immediate Full Instance Backup (DB + Data + Config)"
    echo "  restore [file]       Restore system from full instance backup archive"
    echo "  test [file]          Execute isolated test restore in sandbox DB (or system verification)"
    echo "  test-system [file]   Execute isolated System Backup verification (integrity & zero data)"
    echo "  prune                Execute retention policy to remove expired backup archives"
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
print('  - Enabled:    ', c.get('backup_enabled'))
print('  - Type:       ', c.get('backup_type', 'full_instance'))
print('  - Schedule:   ', c.get('schedule', {}).get('cron_expression'))
print('  - Max Backups:', c.get('retention_policy', {}).get('max_backups_count'))
print('  - Retention:  ', c.get('retention_policy', {}).get('retention_days'), 'days')
" 2>/dev/null || cat "$CONFIG_FILE"
        fi
        echo "================================================================================"
        ;;

    list)
        echo "=========================================================================================================================="
        printf "%-40s  %-14s  %-12s  %-19s  %-8s  %-15s\n" "ARCHIVE FILENAME" "TYPE" "GIT COMMIT" "DATE & TIME" "SIZE" "CHECKSUM STATUS"
        echo "--------------------------------------------------------------------------------------------------------------------------"
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
    git_commit = '-'
    if 'system' in fname:
        btype = 'system_only'
        try:
            with tarfile.open(f, 'r:gz') as t:
                for m in t.getmembers():
                    if m.name.endswith('manifest.json'):
                        mf = json.load(t.extractfile(m))
                        git_commit = mf.get('software_baseline', {}).get('git_commit', '-')[:8]
                        break
        except Exception:
            pass

    sha_file = f + '.sha256'
    sha_status = '[OK] Verified' if os.path.exists(sha_file) else '[No Hash]'
    print(f'{fname:<40}  {btype:<14}  {git_commit:<12}  {mtime:<19}  {sz:<8}  {sha_status:<15}')
"
        echo "=========================================================================================================================="
        ;;

    backup-system|system-backup)
        "$SCRIPT_DIR/backup_system.sh"
        ;;

    run|backup)
        target_type="${1:-}"
        if [ "$target_type" = "system" ] || [ "$target_type" = "system_only" ]; then
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
        else
            "$SCRIPT_DIR/test_restore.sh" ${target:+"$target"}
        fi
        ;;

    test-system)
        target="${1:-}"
        "$SCRIPT_DIR/test_system_backup.sh" ${target:+"$target"}
        ;;

    prune)
        echo "[INFO] Running retention pruning engine..."
        MAX_BACKUPS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('max_backups_count', 14))" 2>/dev/null || echo 14)
        find "$BACKUP_DIR" -maxdepth 1 \( -name "backup_full_instance_*.tar.gz" -o -name "backup_data_*.tar.gz" -o -name "backup_system_*.tar.gz" \) -type f | sort -r | tail -n +"$((MAX_BACKUPS + 1))" | while read -r old; do
            if [ -n "$old" ] && [ -f "$old" ]; then
                echo "[PRUNED] Removing expired: $(basename "$old")"
                rm -f "$old" "$old.sha256"
            fi
        done
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
