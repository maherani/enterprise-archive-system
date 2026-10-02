#!/usr/bin/env python3
"""
Test Suite: BR-02 Instance Data Backup (instance_data)
Enterprise Archive System

Validates:
1. Non-admin users are forbidden (403) from backup APIs.
2. Admin can trigger instance_data backup via API.
3. CLI manage_backup.sh backup-data creates instance_data backup.
4. Archive contains database.sql and data.tar.gz.
5. System software and configuration (config.tar.gz, custom_apps.tar.gz, docker-compose.yml) are strictly excluded.
6. Manifest records backup_type = instance_data and recovery_point.
7. Manifest binds to System Baseline reference (system_backup_id, git_commit, nextcloud_version).
8. SHA-256 checksum sidecar and components_digest_sha256 are valid.
9. Sandbox verification script (deploy/test_instance_data_backup.sh) passes with 100% integrity.
10. Admin API list and status classify instance_data correctly.
11. CLI list and status classify instance_data correctly.
12. Independent retention pruning does not delete system_only or full_instance backups.
13. Existing system_only backup remains intact and operational.
14. Existing full_instance backup remains intact and operational.
"""

import unittest
import os
import subprocess
import json
import tarfile
import hashlib
import re
import requests
from requests.auth import HTTPBasicAuth

REPO_DIR = "/home/alborz/enterprise-archive-system"
DEPLOY_DIR = os.path.join(REPO_DIR, "deploy")
BACKUP_DIR = os.path.join(DEPLOY_DIR, "backups")
BASE_URL = "http://localhost/index.php/apps/archive_autotag/api/admin"

ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "Secure_Admin_Password_123!")
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = os.environ.get("SOC_USER", "test_user_a")
SOC_PASS = os.environ.get("SOC_PASS", "User_Password_123!")
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
    "Content-Type": "application/json"
}


class TestInstanceDataBackup(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        # Ensure at least one instance_data backup exists before running tests
        res = subprocess.run(
            [f"{DEPLOY_DIR}/backup_instance_data.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        assert res.returncode == 0, f"backup_instance_data.sh failed during setup: {res.stderr}"

    def test_01_non_admin_forbidden(self):
        """Non-admin users must receive 403 Forbidden on all backup endpoints."""
        endpoints = [
            ("GET", f"{BASE_URL}/backup/status", None),
            ("GET", f"{BASE_URL}/backup/list", None),
            ("POST", f"{BASE_URL}/backup/run", {"backup_type": "instance_data"}),
            ("POST", f"{BASE_URL}/backup/test", {"target": "latest_instance_data_backup.tar.gz"}),
            ("GET", f"{BASE_URL}/backup/test-report?filename=latest_instance_data_backup.tar.gz", None),
        ]
        for method, url, body in endpoints:
            if method == "GET":
                res = requests.get(url, auth=soc_auth, headers=HEADERS)
            else:
                res = requests.post(url, auth=soc_auth, headers=HEADERS, json=body)
            self.assertEqual(res.status_code, 403, f"Endpoint {url} should be 403 for non-admin, got {res.status_code}")

    def test_02_admin_trigger_instance_data_backup_via_api(self):
        """Admin can trigger instance_data backup via API."""
        url = f"{BASE_URL}/backup/run"
        payload = {"backup_type": "instance_data"}
        res = requests.post(url, auth=admin_auth, headers=HEADERS, json=payload)
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        self.assertEqual(data.get("backup_type"), "instance_data")
        self.assertIn("task_id", data)
        self.assertTrue(data.get("task_id", "").startswith("req-"))

        # Verify status records backup_data
        status_file = os.path.join(BACKUP_DIR, ".backup_status.json")
        if os.path.isfile(status_file):
            with open(status_file, "r") as f:
                st = json.load(f)
            self.assertIn(st.get("action"), ["backup_data", "backup"])

    def test_03_cli_backup_data_creates_archive(self):
        """CLI manage_backup.sh backup-data executes backup_instance_data.sh successfully."""
        res = subprocess.run(
            [f"{DEPLOY_DIR}/manage_backup.sh", "backup-data"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"backup-data failed: {res.stderr}")
        self.assertIn("Instance Data Backup Completed Successfully", res.stdout)
        self.assertIn("Backup Type:     instance_data", res.stdout)

        # Check canonical latest instance data file
        latest_file = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        self.assertTrue(os.path.isfile(latest_file))
        self.assertTrue(os.path.isfile(latest_file + ".sha256"))

    def test_04_archive_contents_and_negative_assertions(self):
        """Archive must contain database.sql and data.tar.gz, and strictly exclude system state."""
        latest_file = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        self.assertTrue(os.path.isfile(latest_file))

        with tarfile.open(latest_file, "r:gz") as tar:
            members = [os.path.basename(m.name) for m in tar.getmembers()]

            # Must contain operational data components
            self.assertIn("manifest.json", members)
            self.assertIn("manifest.txt", members)
            self.assertIn("database.sql", members)
            self.assertIn("data.tar.gz", members)

            # STRICT NEGATIVE ASSERTION: Must NOT contain system software or configuration
            self.assertNotIn("config.tar.gz", members, "config.tar.gz must be excluded from instance_data")
            self.assertNotIn("custom_apps.tar.gz", members, "custom_apps.tar.gz must be excluded from instance_data")
            self.assertNotIn("docker-compose.yml", members, "docker-compose.yml must be excluded from instance_data")
            self.assertNotIn("config_keys.json", members, "config_keys.json must be excluded from instance_data")

    def test_05_manifest_structure_and_recovery_point(self):
        """manifest.json must have backup_type = instance_data and valid recovery_point."""
        latest_file = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        with tarfile.open(latest_file, "r:gz") as tar:
            manifest_member = next(m for m in tar.getmembers() if m.name.endswith("manifest.json"))
            manifest = json.load(tar.extractfile(manifest_member))

            self.assertEqual(manifest.get("backup_type"), "instance_data")
            self.assertEqual(manifest.get("status"), "SUCCESS")
            self.assertTrue(manifest.get("backup_id", "").startswith("bk-data-"))
            self.assertIsNotNone(manifest.get("created_at"))
            self.assertIsNotNone(manifest.get("recovery_point"))

            # Excluded data documentation
            excluded = manifest.get("excluded_data", {})
            self.assertIn("system_configuration", excluded)
            self.assertIn("custom_companion_apps", excluded)

    def test_06_system_baseline_binding(self):
        """Manifest must bind to System Baseline reference without copying system files."""
        latest_file = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        with tarfile.open(latest_file, "r:gz") as tar:
            manifest_member = next(m for m in tar.getmembers() if m.name.endswith("manifest.json"))
            manifest = json.load(tar.extractfile(manifest_member))

            baseline = manifest.get("system_baseline", {})
            self.assertIsNotNone(baseline.get("system_backup_id"), "system_backup_id reference missing")
            commit = baseline.get("git_commit", "")
            self.assertTrue(bool(re.match(r"^[0-9a-f]{40}$", commit)), f"Invalid git commit SHA: {commit}")
            self.assertIsNotNone(baseline.get("nextcloud_version"))
            self.assertIsNotNone(baseline.get("archive_app_version"))

    def test_07_sidecar_checksum_and_component_digests(self):
        """Sidecar SHA-256 and components_digest_sha256 must be mathematically exact."""
        latest_file = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        sidecar_file = latest_file + ".sha256"
        self.assertTrue(os.path.isfile(sidecar_file))

        with open(sidecar_file, "r") as f:
            sidecar_sha = f.read().split()[0].strip()

        with open(latest_file, "rb") as f:
            calc_sha = hashlib.sha256(f.read()).hexdigest()
        self.assertEqual(sidecar_sha, calc_sha, "Outer sidecar SHA-256 mismatch")

        with tarfile.open(latest_file, "r:gz") as tar:
            manifest_member = next(m for m in tar.getmembers() if m.name.endswith("manifest.json"))
            manifest = json.load(tar.extractfile(manifest_member))

            comps = manifest.get("components", {})
            self.assertIn("database", comps)
            self.assertIn("user_data", comps)

            db_sha = comps["database"]["sha256"]
            data_sha = comps["user_data"]["sha256"]
            self.assertEqual(len(db_sha), 64)
            self.assertEqual(len(data_sha), 64)

            # Verify composite digest
            preimage = f"{db_sha}\n{data_sha}"
            expected_digest = hashlib.sha256(preimage.encode()).hexdigest()
            self.assertEqual(manifest.get("components_digest_sha256"), expected_digest)

    def test_08_sandbox_verification_script(self):
        """deploy/test_instance_data_backup.sh runs cleanly and confirms 100% integrity."""
        res = subprocess.run(
            [f"{DEPLOY_DIR}/test_instance_data_backup.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"test_instance_data_backup.sh failed: {res.stderr}")
        self.assertIn("Instance Data Backup Verification PASSED", res.stdout)
        self.assertIn("100% Integrity - Sandbox Validated", res.stdout)
        self.assertIn("Verified Users:", res.stdout)
        self.assertIn("Verified Groups:", res.stdout)

    def test_09_admin_api_list_and_status(self):
        """Admin API list and status classify instance_data correctly."""
        # 1. List
        list_res = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(list_res.status_code, 200)
        items = list_res.json().get("data", [])
        data_backups = [b for b in items if b.get("type") == "instance_data"]
        self.assertGreater(len(data_backups), 0, "No instance_data backups found in API list")

        first_data = data_backups[0]
        self.assertIn("filename", first_data)
        self.assertIn("size_human", first_data)
        self.assertTrue(first_data.get("checksum_valid"))
        self.assertEqual(first_data.get("test_status"), "PASS")

        # 2. Status
        status_res = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(status_res.status_code, 200)
        st_data = status_res.json().get("data", {})
        self.assertIn("latest_instance_data_backup", st_data)
        latest_data = st_data.get("latest_instance_data_backup")
        self.assertIsNotNone(latest_data)
        self.assertEqual(latest_data.get("type"), "instance_data")

    def test_10_cli_list_and_status(self):
        """CLI manage_backup.sh list and status display instance_data archives."""
        res_list = subprocess.run(
            [f"{DEPLOY_DIR}/manage_backup.sh", "list"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res_list.returncode, 0)
        self.assertIn("instance_data", res_list.stdout)

        res_status = subprocess.run(
            [f"{DEPLOY_DIR}/manage_backup.sh", "status"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res_status.returncode, 0)
        self.assertIn("Instance Data:", res_status.stdout)

    def test_11_independent_retention_pruning(self):
        """Pruning instance_data backups must never delete system_only or full_instance backups."""
        # Verify system_only backup exists before prune
        latest_sys = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        latest_full = os.path.join(BACKUP_DIR, "latest_instance_backup.tar.gz")
        self.assertTrue(os.path.isfile(latest_sys), "latest_system_backup.tar.gz must exist")
        self.assertTrue(os.path.isfile(latest_full), "latest_instance_backup.tar.gz must exist")

        res_prune = subprocess.run(
            [f"{DEPLOY_DIR}/manage_backup.sh", "prune", "data"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res_prune.returncode, 0)
        self.assertIn("[PRUNE:instance_data]", res_prune.stdout)

        # Confirm system_only and full_instance still exist
        self.assertTrue(os.path.isfile(latest_sys), "system_only backup was deleted by data prune!")
        self.assertTrue(os.path.isfile(latest_full), "full_instance backup was deleted by data prune!")

    def test_12_existing_system_backup_remains_operational(self):
        """Existing system_only backup verification and API listing remain 100% operational."""
        res = subprocess.run(
            [f"{DEPLOY_DIR}/test_system_backup.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"test_system_backup.sh failed: {res.stderr}")
        self.assertIn("System Backup Verification PASSED", res.stdout)

    def test_13_existing_full_instance_backup_remains_operational(self):
        """Existing full_instance backup remains 100% operational."""
        res = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        backups = res.json().get("data", [])
        full_backups = [b for b in backups if b.get("type") == "full_instance"]
        self.assertGreater(len(full_backups), 0, "full_instance backups must still be present")


if __name__ == "__main__":
    unittest.main()
