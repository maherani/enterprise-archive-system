#!/usr/bin/env python3
"""
Test Suite: Independent System Backup (BR-01) Specification
Enterprise Archive System - Nextcloud

Verifies:
1. Strict authorization: Non-admin users are blocked with 403 Forbidden when creating/accessing system backups.
2. System Admin can trigger system backup via API (POST /api/admin/backup/run with {"backup_type": "system_only"}).
3. CLI interface: deploy/manage_backup.sh backup-system executes cleanly and generates system backup.
4. Archive structure: backup archive is a valid tar.gz containing all required system components.
5. Outer SHA-256 sidecar checksum matches the archive file.
6. Manifest validation: backup_type is 'system_only', git commit baseline matches git rev-parse HEAD.
7. Required system components present: config.tar.gz, config_keys.json, custom_apps.tar.gz, software_info.json.
8. Operational Database data ABSENT: database.sql does NOT exist anywhere inside system backup.
9. User file data ABSENT: data.tar.gz and user files do NOT exist anywhere inside system backup.
10. Deep inspection: Zero SQL dump files and zero user data files exist inside the extracted payload.
11. Admin API correctly classifies system_only in /backup/list and /backup/status endpoints.
12. CLI correctly displays system_only in manage_backup.sh list and status subcommands.
13. Sandbox verification tool deploy/test_system_backup.sh runs cleanly and confirms 100% integrity.
14. Regression check: Existing full_instance backup remains completely intact and operational.
"""

import os
import subprocess
import tarfile
import json
import hashlib
import re
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://127.0.0.1")
BASE_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin"

ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "Secure_Admin_Password_123!")
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

NON_ADMIN_USER = os.environ.get("NON_ADMIN_USER", "test_user_a")
NON_ADMIN_PASS = os.environ.get("NON_ADMIN_PASS", "User_Password_123!")
non_admin_auth = HTTPBasicAuth(NON_ADMIN_USER, NON_ADMIN_PASS)

HEADERS = {
    "OCS-APIRequest": "true",
    "Content-Type": "application/json",
    "Accept": "application/json"
}

REPO_DIR = "/home/alborz/enterprise-archive-system"
BACKUP_DIR = os.path.join(REPO_DIR, "deploy", "backups")


class TestSystemBackup(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        # Ensure at least one system backup exists before running tests
        res = subprocess.run(
            [f"{REPO_DIR}/deploy/backup_system.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        assert res.returncode == 0, f"backup_system.sh failed during setup: {res.stderr}"

    def test_01_non_admin_forbidden_on_system_backup(self):
        """Non-admin users must be strictly blocked (403 Forbidden) from creating system backup."""
        url = f"{BASE_URL}/backup/run"
        res = requests.post(url, auth=non_admin_auth, headers=HEADERS, json={"backup_type": "system_only"})
        self.assertEqual(
            res.status_code, 403,
            f"Expected 403 Forbidden for non-admin on POST {url}, got {res.status_code}"
        )

    def test_02_admin_trigger_system_backup_via_api(self):
        """System Admin can queue a system backup via API."""
        url = f"{BASE_URL}/backup/run"
        res = requests.post(url, auth=admin_auth, headers=HEADERS, json={"backup_type": "system_only"})
        self.assertEqual(res.status_code, 200)
        data = res.json()
        self.assertEqual(data.get("status"), "success")
        self.assertEqual(data.get("backup_type"), "system_only")
        self.assertIn("task_id", data)

    def test_03_cli_backup_system_execution(self):
        """CLI tool deploy/manage_backup.sh backup-system executes successfully."""
        res = subprocess.run(
            [f"{REPO_DIR}/deploy/manage_backup.sh", "backup-system"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"manage_backup.sh backup-system failed: {res.stderr}")
        self.assertIn("System Backup Completed Successfully", res.stdout)
        self.assertIn("Zero Operational Data", res.stdout)

    def test_04_archive_structure_and_components(self):
        """System backup archive must be valid tar.gz containing all required system components."""
        target = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        self.assertTrue(os.path.isfile(target), "latest_system_backup.tar.gz missing")

        with tarfile.open(target, "r:gz") as tar:
            basenames = [os.path.basename(m.name) for m in tar.getmembers() if os.path.basename(m.name)]
            for comp in ["manifest.json", "manifest.txt", "config.tar.gz", "config_keys.json", "custom_apps.tar.gz", "software_info.json"]:
                self.assertIn(comp, basenames, f"Required component {comp} missing from system backup archive")

    def test_05_sha256_sidecar_validation(self):
        """Sidecar file latest_system_backup.tar.gz.sha256 must match archive checksum."""
        archive_path = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        sidecar_path = archive_path + ".sha256"
        self.assertTrue(os.path.isfile(sidecar_path), "Sidecar .sha256 file missing")

        with open(archive_path, "rb") as f:
            calc_sha = hashlib.sha256(f.read()).hexdigest()

        with open(sidecar_path, "r", encoding="utf-8") as f:
            sidecar_sha = f.read().split()[0].strip()

        self.assertEqual(calc_sha, sidecar_sha, "Sidecar SHA-256 does not match archive bytes")

    def test_06_manifest_schema_and_git_baseline(self):
        """manifest.json must have backup_type=system_only and capture correct Git baseline commit."""
        target = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        with tarfile.open(target, "r:gz") as tar:
            mf_member = [m for m in tar.getmembers() if m.name.endswith("manifest.json")][0]
            manifest = json.load(tar.extractfile(mf_member))

        self.assertEqual(manifest.get("backup_type"), "system_only")
        self.assertEqual(manifest.get("status"), "SUCCESS")

        baseline = manifest.get("software_baseline", {})
        git_commit = baseline.get("git_commit", "")
        self.assertTrue(bool(re.match(r"^[0-9a-f]{40}$", git_commit)), f"Invalid git commit SHA: {git_commit}")

        # Check git rev-parse HEAD in repo matches
        git_res = subprocess.run(["git", "rev-parse", "HEAD"], cwd=REPO_DIR, capture_output=True, text=True)
        self.assertEqual(git_commit, git_res.stdout.strip())
        self.assertTrue(bool(baseline.get("nextcloud_version")))
        self.assertTrue(bool(baseline.get("git_branch")))

        # Check components_digest_sha256
        comps = manifest.get("components", {})
        for k in ["config", "security_keys", "custom_apps", "software_info"]:
            self.assertIn(k, comps)
            self.assertEqual(len(comps[k].get("sha256", "")), 64)

        preimage = f"{comps['config']['sha256']}\n{comps['security_keys']['sha256']}\n{comps['custom_apps']['sha256']}\n{comps['software_info']['sha256']}"
        expected_digest = hashlib.sha256(preimage.encode("utf-8")).hexdigest()
        self.assertEqual(manifest.get("components_digest_sha256"), expected_digest)

    def test_07_required_system_components_integrity(self):
        """config.tar.gz, config_keys.json, and software_info.json must be valid."""
        target = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        with tarfile.open(target, "r:gz") as tar:
            members = {os.path.basename(m.name): m for m in tar.getmembers()}

            # 1. config_keys.json
            keys = json.load(tar.extractfile(members["config_keys.json"]))
            for k in ["instanceid", "passwordsalt", "secret"]:
                self.assertTrue(bool(keys.get(k)), f"Key {k} missing or empty in config_keys.json")

            # 2. software_info.json
            soft = json.load(tar.extractfile(members["software_info.json"]))
            self.assertIn("software_baseline", soft)
            self.assertIn("container_images", soft)
            self.assertIn("enabled_apps", soft)

            # 3. config.tar.gz contains config.php
            cfg_bytes = tar.extractfile(members["config.tar.gz"]).read()
            import io
            with tarfile.open(fileobj=io.BytesIO(cfg_bytes), mode="r:gz") as cfg_tar:
                cfg_names = [os.path.basename(n) for n in cfg_tar.getnames()]
                self.assertIn("config.php", cfg_names)

    def test_08_operational_database_absent(self):
        """STRICT ZERO-DATA CHECK: database.sql and SQL files must NOT exist inside system backup."""
        target = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        with tarfile.open(target, "r:gz") as tar:
            names = tar.getnames()
            self.assertFalse(any("database.sql" in n for n in names), "database.sql MUST NOT exist in system backup")
            self.assertFalse(any(n.endswith(".sql") for n in names), "SQL files MUST NOT exist in system backup")

    def test_09_user_file_data_absent(self):
        """STRICT ZERO-DATA CHECK: data.tar.gz and user files must NOT exist inside system backup."""
        target = os.path.join(BACKUP_DIR, "latest_system_backup.tar.gz")
        with tarfile.open(target, "r:gz") as tar:
            names = tar.getnames()
            self.assertFalse(any("data.tar.gz" in n for n in names), "data.tar.gz MUST NOT exist in system backup")
            self.assertFalse(any("/data/" in n for n in names), "User data directory MUST NOT exist in system backup")

    def test_10_admin_api_list_and_status_shows_system_only(self):
        """Admin API /backup/list and /backup/status must return system_only backups."""
        # 1. List endpoint
        res = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        backups = res.json().get("data", [])
        system_backups = [b for b in backups if b.get("type") == "system_only"]
        self.assertGreater(len(system_backups), 0, "No system_only backups returned in /backup/list")

        first_sys = system_backups[0]
        self.assertIn("filename", first_sys)
        self.assertIn("size_human", first_sys)
        self.assertTrue(first_sys.get("checksum_valid"))

        # 2. Status endpoint
        status_res = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(status_res.status_code, 200)
        st_data = status_res.json().get("data", {})
        self.assertIn("latest_system_backup", st_data)
        latest_sys = st_data.get("latest_system_backup")
        self.assertIsNotNone(latest_sys)
        self.assertEqual(latest_sys.get("type"), "system_only")

    def test_11_cli_list_and_status_shows_system_only(self):
        """CLI manage_backup.sh list and status commands display system_only archives."""
        # 1. CLI list
        res_list = subprocess.run(
            [f"{REPO_DIR}/deploy/manage_backup.sh", "list"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res_list.returncode, 0)
        self.assertIn("system_only", res_list.stdout)

        # 2. CLI status
        res_status = subprocess.run(
            [f"{REPO_DIR}/deploy/manage_backup.sh", "status"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res_status.returncode, 0)
        self.assertIn("System Only:", res_status.stdout)

    def test_12_verification_script_runs_cleanly(self):
        """deploy/test_system_backup.sh runs cleanly and confirms 100% integrity with zero operational data."""
        res = subprocess.run(
            [f"{REPO_DIR}/deploy/test_system_backup.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"test_system_backup.sh failed: {res.stderr}")
        self.assertIn("System Backup Verification PASSED", res.stdout)
        self.assertIn("Zero Operational Data Confirmed", res.stdout)

    def test_13_existing_backup_remains_operational(self):
        """Regression check: Existing full_instance backup remains intact and operational."""
        target = os.path.join(BACKUP_DIR, "latest_instance_backup.tar.gz")
        self.assertTrue(os.path.isfile(target), "latest_instance_backup.tar.gz must remain intact")

        res = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res.status_code, 200)
        backups = res.json().get("data", [])
        full_backups = [b for b in backups if b.get("type") == "full_instance"]
        self.assertGreater(len(full_backups), 0, "full_instance backups must still be present")


if __name__ == "__main__":
    unittest.main()
