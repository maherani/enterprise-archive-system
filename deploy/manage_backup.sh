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
    echo "  status               Show overall status of backup engine, services, and latest backup"
    echo "  list                 List all available backup archives with integrity and test status"
    echo "  run | backup         Execute immediate point-in-time consistent full-instance backup"
    echo "  restore [file]       Restore system from latest backup or specified archive"
    echo "  test [file]          Execute isolated test restore in sandbox DB without downtime"
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
        echo "[2] Latest Backup Archive:"
        LATEST_TARGET=""
        if [ -f "$BACKUP_DIR/latest_instance_backup.tar.gz" ]; then
            LATEST_TARGET="$BACKUP_DIR/latest_instance_backup.tar.gz"
        elif [ -f "$BACKUP_DIR/latest_data_backup.tar.gz" ]; then
            LATEST_TARGET="$BACKUP_DIR/latest_data_backup.tar.gz"
        elif [ -f "$BACKUP_DIR/latest_nextcloud_backup.tar.gz" ]; then
            LATEST_TARGET="$BACKUP_DIR/latest_nextcloud_backup.tar.gz"
        fi

        if [ -n "$LATEST_TARGET" ] && [ -f "$LATEST_TARGET" ]; then
            sz=$(du -h "$LATEST_TARGET" | cut -f1)
            dt=$(date -r "$LATEST_TARGET" -Iseconds 2>/dev/null || stat -c%y "$LATEST_TARGET" 2>/dev/null || echo "N/A")
            sha="N/A"
            if [ -f "$LATEST_TARGET.sha256" ]; then
                sha=$(cut -d' ' -f1 < "$LATEST_TARGET.sha256")
            fi
            echo "  - File:       $(basename "$LATEST_TARGET") ($sz)"
            echo "  - Date:       $dt"
            echo "  - SHA256:     $sha"
        else
            echo "  - No latest backup found."
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
        echo "===================================================================================================="
        printf "%-38s  %-14s  %-19s  %-8s  %-15s\n" "ARCHIVE FILENAME" "TYPE" "DATE & TIME" "SIZE" "CHECKSUM STATUS"
        echo "----------------------------------------------------------------------------------------------------"
        python3 -c "
import os, glob, json, datetime

backup_dir = '$BACKUP_DIR'
files = sorted(glob.glob(os.path.join(backup_dir, '*.tar.gz')), key=os.path.getmtime, reverse=True)

for f in files:
    fname = os.path.basename(f)
    if fname.startswith('latest_'):
        continue
    sz = f'{os.path.getsize(f) / (1024*1024):.1f}M'
    mtime = datetime.datetime.fromtimestamp(os.path.getmtime(f)).strftime('%Y-%m-%d %H:%M:%S')
    btype = 'full_instance'
    sha_file = f + '.sha256'
    sha_status = '[OK] Verified' if os.path.exists(sha_file) else '[No Hash]'
    print(f'{fname:<38}  {btype:<14}  {mtime:<19}  {sz:<8}  {sha_status:<15}')
"
        echo "===================================================================================================="
        ;;

    run|backup)
        "$SCRIPT_DIR/backup_db.sh"
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
        "$SCRIPT_DIR/test_restore.sh" ${target:+"$target"}
        ;;

    prune)
        echo "[INFO] Running retention pruning engine..."
        MAX_BACKUPS=$(python3 -c "import json; c=json.load(open('$CONFIG_FILE')); print(c.get('retention_policy', {}).get('max_backups_count', 14))" 2>/dev/null || echo 14)
        find "$BACKUP_DIR" -maxdepth 1 \( -name "backup_full_instance_*.tar.gz" -o -name "backup_data_*.tar.gz" \) -type f | sort -r | tail -n +"$((MAX_BACKUPS + 1))" | while read -r old; do
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
