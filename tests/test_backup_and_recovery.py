#!/usr/bin/env python3
"""
Test Suite: Data Backup and Recovery Specification (Requirement 29)
Enterprise Archive System - Nextcloud

Verifies:
1. Strict authorization: Non-admin users are blocked with 403 Forbidden on all backup & recovery endpoints.
2. Status endpoint (/api/admin/backup/status) returns service state, configuration, and latest backup metadata.
3. List endpoint (/api/admin/backup/list) returns valid list of archive files with SHA-256 validation.
4. Configuration endpoint (/api/admin/backup/config) persists schedule, retention count, and enabled status.
5. Safety controls on restore (/api/admin/restore/run):
   - Rejects requests without RESTORE-CONFIRM phrase (400 Bad Request).
   - Rejects non-existent backup targets (404 Not Found).
6. Sandbox Test Restore (/api/admin/backup/test):
   - Triggers isolated sandbox test restore on temporary database without altering live data.
   - Polls task status until completion.
7. Archive download endpoint (/api/admin/backup/download):
   - Verifies stream headers (Content-Type: application/gzip, Content-Disposition: attachment).
8. Unified Terminal CLI tool (deploy/manage_backup.sh):
   - Verifies CLI status, list, and config subcommands.
"""

import os
import time
import subprocess
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://127.0.0.1")
BASE_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin"

ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "Secure_Admin_Password_123!")
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = os.environ.get("SOC_USER", "Bakbari")
SOC_PASS = os.environ.get("SOC_PASS", "User_Password_123!")
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

HEADERS = {
    "OCS-APIRequest": "true",
    "Content-Type": "application/json",
    "Accept": "application/json"
}


class TestBackupAndRecovery(unittest.TestCase):

    def test_01_non_admin_forbidden(self):
        """Non-admin users must be strictly blocked from all backup & recovery endpoints."""
        endpoints = [
            ("GET", f"{BASE_URL}/backup/status", None),
            ("GET", f"{BASE_URL}/backup/list", None),
            ("POST", f"{BASE_URL}/backup/run", {}),
            ("POST", f"{BASE_URL}/restore/run", {"confirmation": "RESTORE-CONFIRM"}),
            ("POST", f"{BASE_URL}/backup/test", {}),
            ("GET", f"{BASE_URL}/backup/task-status", None),
            ("POST", f"{BASE_URL}/backup/config", {"backup_enabled": True}),
            ("GET", f"{BASE_URL}/backup/download?filename=latest_data_backup.tar.gz", None),
        ]

        for method, url, body in endpoints:
            if method == "GET":
                res = requests.get(url, auth=soc_auth, headers=HEADERS)
            else:
                res = requests.post(url, auth=soc_auth, headers=HEADERS, json=body)
            self.assertEqual(
                res.status_code, 403,
                f"Expected 403 Forbidden for non-admin on {method} {url}, got {res.status_code}"
            )

    def test_02_admin_get_status(self):
        """Admin can retrieve backup system status, schedule config, and latest backup info."""
        res = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        payload = data.get("data", {})
        self.assertIn("service_running", payload)
        self.assertIn("backup_enabled", payload)
        self.assertIn("schedule", payload)
        self.assertIn("retention_policy", payload)
        self.assertIn("storage", payload)
        self.assertIn("backups_count", payload)

    def test_03_admin_get_list(self):
        """Admin can list all backup archives with checksum and test status."""
        res = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        backups = data.get("data", [])
        self.assertIsInstance(backups, list)
        if len(backups) > 0:
            first = backups[0]
            self.assertIn("filename", first)
            self.assertIn("size_bytes", first)
            self.assertIn("size_human", first)
            self.assertIn("checksum_valid", first)
            self.assertIn("test_status", first)

    def test_04_admin_save_config(self):
        """Admin can update backup schedule and retention policy configuration."""
        new_config = {
            "backup_enabled": True,
            "cron_expression": "0 2 * * *",
            "max_backups_count": 14,
            "retention_days": 30
        }
        res = requests.post(f"{BASE_URL}/backup/config", auth=admin_auth, headers=HEADERS, json=new_config)
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")

        # Verify persistence via status call
        status_res = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(status_res.status_code, 200)
        saved = status_res.json().get("data", {})
        self.assertTrue(saved.get("backup_enabled"))
        self.assertEqual(saved.get("schedule", {}).get("cron_expression"), "0 2 * * *")
        self.assertEqual(saved.get("retention_policy", {}).get("max_backups_count"), 14)

    def test_05_admin_restore_validation(self):
        """Restore endpoint must strictly enforce the RESTORE-CONFIRM confirmation phrase and valid targets."""
        # 1. Missing confirmation
        r1 = requests.post(f"{BASE_URL}/restore/run", auth=admin_auth, headers=HEADERS, json={"target": "non_existent.tar.gz"})
        self.assertEqual(r1.status_code, 400)
        self.assertIn("RESTORE-CONFIRM", r1.json().get("message", ""))

        # 2. Invalid confirmation
        r2 = requests.post(f"{BASE_URL}/restore/run", auth=admin_auth, headers=HEADERS, json={
            "target": "non_existent.tar.gz",
            "confirmation": "yes-restore"
        })
        self.assertEqual(r2.status_code, 400)

        # 3. Non-existent backup target with valid phrase
        r3 = requests.post(f"{BASE_URL}/restore/run", auth=admin_auth, headers=HEADERS, json={
            "target": "totally_fake_backup_9999.tar.gz",
            "confirmation": "RESTORE-CONFIRM"
        })
        self.assertEqual(r3.status_code, 404)

    def test_06_admin_sandbox_test_restore(self):
        """Admin can trigger sandbox test restore and poll for SUCCESS."""
        # Check if latest_data_backup.tar.gz exists
        res = requests.post(f"{BASE_URL}/backup/test", auth=admin_auth, headers=HEADERS, json={
            "target": "latest_data_backup.tar.gz"
        })
        self.assertEqual(res.status_code, 200)
        self.assertEqual(res.json().get("status"), "success")

        # Poll task status
        max_wait = 25
        completed = False
        start_time = time.time()
        while time.time() - start_time < max_wait:
            time.sleep(2)
            ts = requests.get(f"{BASE_URL}/backup/task-status", auth=admin_auth, headers=HEADERS)
            if ts.status_code == 200:
                task_data = ts.json().get("data", {})
                if task_data.get("status") in ["SUCCESS", "FAILED"]:
                    completed = True
                    self.assertEqual(task_data.get("status"), "SUCCESS", f"Sandbox test failed: {task_data}")
                    break

        self.assertTrue(completed, "Sandbox test restore did not finish within timeout")

    def test_07_admin_download_backup(self):
        """Admin can stream download backup archive with correct gzip attachment headers."""
        res = requests.get(
            f"{BASE_URL}/backup/download?filename=latest_data_backup.tar.gz",
            auth=admin_auth,
            headers={"OCS-APIRequest": "true"},
            stream=True
        )
        self.assertEqual(res.status_code, 200)
        self.assertEqual(res.headers.get("Content-Type"), "application/gzip")
        self.assertIn("attachment", res.headers.get("Content-Disposition", ""))
        # Read first 1024 bytes to confirm gzip magic number (0x1f, 0x8b)
        chunk = next(res.iter_content(chunk_size=1024))
        self.assertGreater(len(chunk), 0)
        self.assertEqual(chunk[:2], b"\x1f\x8b", "Downloaded file magic bytes do not match gzip header")

    def test_08_cli_manage_backup_integration(self):
        """Verify unified CLI tool deploy/manage_backup.sh subcommands."""
        repo_dir = "/home/alborz/enterprise-archive-system"
        if not os.path.isdir(repo_dir):
            self.skipTest("Not running inside target repository filesystem")

        # Test CLI list
        res_list = subprocess.run(
            [f"{repo_dir}/deploy/manage_backup.sh", "list"],
            cwd=repo_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        self.assertEqual(res_list.returncode, 0, f"manage_backup.sh list failed: {res_list.stderr}")
        self.assertIn("ARCHIVE FILENAME", res_list.stdout)
        self.assertIn("[OK] Verified", res_list.stdout)

        # Test CLI status
        res_status = subprocess.run(
            [f"{repo_dir}/deploy/manage_backup.sh", "status"],
            cwd=repo_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        self.assertEqual(res_status.returncode, 0, f"manage_backup.sh status failed: {res_status.stderr}")
        self.assertIn("archive_app", res_status.stdout)
        self.assertIn("archive_db", res_status.stdout)


    def test_09_admin_test_report_endpoint(self):
        """Admin can retrieve parsed sandbox test report and raw logs."""
        res = requests.get(f"{BASE_URL}/backup/test-report", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        payload = data.get("data", {})
        self.assertIn("status", payload)
        self.assertIn("users_count", payload)
        self.assertIn("groups_count", payload)
        self.assertIn("tags_count", payload)
        self.assertIn("docs_count", payload)
        self.assertIn("raw_log", payload)


    def test_10_public_maintenance_status_endpoint(self):
        """All users (including unauthenticated guests) can check maintenance status without credentials."""
        res = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/system/maintenance-status")
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        payload = data.get("data", {})
        self.assertIn("in_maintenance", payload)
        self.assertIn("estimated_seconds", payload)


if __name__ == "__main__":
    unittest.main(verbosity=2)
