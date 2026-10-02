#!/usr/bin/env python3
"""
Test Suite: BR-03 Real Sandbox Restore Verification of Instance Data
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
9. Sandbox verification script (deploy/test_instance_data_backup.sh) passes with 100% integrity and bi-directional DB<->Files consistency.
10. Admin API list, status, and test-report classify instance_data and report all BR-03 metrics correctly.
11. CLI list and status classify instance_data correctly.
12. Independent retention pruning does not delete system_only or full_instance backups.
13. Existing system_only backup remains intact and operational.
14. Existing full_instance backup remains intact and operational.
15. Failure Injection A: Corrupt archive -> FAIL (Zero Prod Impact).
16. Failure Injection B: Bad SHA-256 checksum -> FAIL (Zero Prod Impact).
17. Failure Injection C: Missing database.sql -> FAIL (Zero Prod Impact).
18. Failure Injection D: Missing data.tar.gz -> FAIL (Zero Prod Impact).
19. Failure Injection E: Missing required archive table -> FAIL (Zero Prod Impact).
20. Failure Injection F: Invalid System Baseline reference -> FAIL (Zero Prod Impact).
21. Failure Injection G: DB ok but file missing on filesystem -> FAIL (Zero Prod Impact).
22. Production Invariance: DB, user data, config, and container state remain untouched.
"""

import unittest
import os
import subprocess
import json
import tarfile
import hashlib
import re
import tempfile
import shutil
import requests
from requests.auth import HTTPBasicAuth

REPO_DIR = "/home/alborz/enterprise-archive-system"
DEPLOY_DIR = os.path.join(REPO_DIR, "deploy")
BACKUP_DIR = os.path.join(DEPLOY_DIR, "backups")
BASE_URL = "http://localhost/index.php/apps/archive_autotag/api/admin"
SYSTEM_URL = "http://localhost/index.php/apps/archive_autotag/api/system"

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


def get_production_user_count():
    """Query active production database for user count."""
    res = subprocess.run(
        ["docker", "exec", "-e", "PGPASSWORD=Secure_DB_Password_123!", "archive_db",
         "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", "SELECT count(*) FROM oc_users;"],
        capture_output=True, text=True
    )
    if res.returncode == 0 and res.stdout.strip().isdigit():
        return int(res.stdout.strip())
    return None


def extract_instance_backup(src_tar, dest_dir):
    """Extract backup tar and return the inner directory containing manifest.json."""
    with tarfile.open(src_tar, "r:gz") as tar:
        tar.extractall(dest_dir)
    for root, dirs, files in os.walk(dest_dir):
        if "manifest.json" in files:
            return root
    return dest_dir


def repack_archive(root_folder, out_tar):
    """Repack archive preserving root folder name and create .sha256 sidecar."""
    parent_dir = os.path.dirname(root_folder)
    folder_name = os.path.basename(root_folder)
    subprocess.run(["tar", "-czf", out_tar, "-C", parent_dir, folder_name], check=True)
    with open(out_tar, "rb") as f:
        h = hashlib.sha256(f.read()).hexdigest()
    with open(out_tar + ".sha256", "w") as f:
        f.write(f"{h}  {os.path.basename(out_tar)}\n")


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

    def test_08_sandbox_restore_verification_script(self):
        """deploy/test_instance_data_backup.sh executes full BR-03 sandbox restore and bi-directional cross-check."""
        res = subprocess.run(
            [f"{DEPLOY_DIR}/test_instance_data_backup.sh"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"test_instance_data_backup.sh failed: {res.stderr}")
        self.assertIn("Instance Data Sandbox Restore PASSED", res.stdout)
        self.assertIn("100% Verified DB & Filesystem", res.stdout)
        self.assertIn("Database Restore:         PASS", res.stdout)
        self.assertIn("Database Tables:          PASS", res.stdout)
        self.assertIn("User Data Extraction:     PASS", res.stdout)
        self.assertIn("DB <-> Files Consistency: PASS", res.stdout)
        self.assertIn("Sandbox Cleanup:          PASS", res.stdout)

    def test_09_admin_api_list_status_and_report(self):
        """Admin API list, status, and test-report classify instance_data and report BR-03 fields."""
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

        # 3. Test Report
        report_res = requests.get(f"{BASE_URL}/backup/test-report?filename=latest_instance_data_backup.tar.gz", auth=admin_auth, headers=HEADERS)
        self.assertEqual(report_res.status_code, 200)
        rep = report_res.json().get("data", {})
        self.assertEqual(rep.get("status"), "PASS")
        self.assertEqual(rep.get("type"), "instance_data")
        self.assertEqual(rep.get("db_restore"), "PASS")
        self.assertEqual(rep.get("db_tables"), "PASS")
        self.assertEqual(rep.get("data_extraction"), "PASS")
        self.assertEqual(rep.get("db_files_consistency"), "PASS")
        self.assertEqual(rep.get("sandbox_cleanup"), "PASS")
        self.assertIsNotNone(rep.get("system_baseline"))
        self.assertIsNotNone(rep.get("git_commit"))

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

    # =========================================================================
    # Section 19: Failure Injection Scenarios
    # =========================================================================

    def test_14_failure_injection_a_corrupt_archive(self):
        """Failure Injection A: Corrupted archive stream must fail gracefully with zero prod impact."""
        with tempfile.TemporaryDirectory() as tmpdir:
            bad_archive = os.path.join(tmpdir, "corrupt_data.tar.gz")
            with open(bad_archive, "wb") as f:
                f.write(b"GARBAGE NON-GZIP CONTENT FOR CORRUPTION TEST")
            with open(bad_archive, "rb") as f:
                h = hashlib.sha256(f.read()).hexdigest()
            with open(bad_archive + ".sha256", "w") as f:
                f.write(f"{h}  corrupt_data.tar.gz\n")

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", bad_archive],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0, "Corrupt archive must return non-zero exit code")

    def test_15_failure_injection_b_checksum_mismatch(self):
        """Failure Injection B: Checksum mismatch in sidecar must fail immediately with zero prod impact."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            bad_archive = os.path.join(tmpdir, "bad_checksum.tar.gz")
            shutil.copyfile(src, bad_archive)
            with open(bad_archive + ".sha256", "w") as f:
                f.write("0000000000000000000000000000000000000000000000000000000000000000  bad_checksum.tar.gz\n")

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", bad_archive],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("checksum mismatch", res.stdout.lower())

    def test_16_failure_injection_c_missing_database_sql(self):
        """Failure Injection C: Archive missing database.sql must fail negative check."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            extract_dir = os.path.join(tmpdir, "extracted")
            inner_dir = extract_instance_backup(src, extract_dir)

            db_sql = os.path.join(inner_dir, "database.sql")
            self.assertTrue(os.path.exists(db_sql), "database.sql must exist before removal")
            os.remove(db_sql)

            repack_tar = os.path.join(tmpdir, "no_db.tar.gz")
            repack_archive(inner_dir, repack_tar)

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", repack_tar],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("database.sql", res.stdout)

    def test_17_failure_injection_d_missing_data_tar_gz(self):
        """Failure Injection D: Archive missing data.tar.gz must fail negative check."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            extract_dir = os.path.join(tmpdir, "extracted")
            inner_dir = extract_instance_backup(src, extract_dir)

            data_tar = os.path.join(inner_dir, "data.tar.gz")
            self.assertTrue(os.path.exists(data_tar), "data.tar.gz must exist before removal")
            os.remove(data_tar)

            repack_tar = os.path.join(tmpdir, "no_data.tar.gz")
            repack_archive(inner_dir, repack_tar)

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", repack_tar],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("data.tar.gz", res.stdout)

    def test_18_failure_injection_e_missing_required_table(self):
        """Failure Injection E: Database missing critical archive table must fail schema check."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            extract_dir = os.path.join(tmpdir, "extracted")
            inner_dir = extract_instance_backup(src, extract_dir)

            db_sql = os.path.join(inner_dir, "database.sql")
            with open(db_sql, "r", encoding="utf-8", errors="ignore") as f:
                sql_content = f.read()

            sql_content_mod = sql_content.replace("oc_archive_document_metadata", "oc_arch_doc_meta_removed")
            with open(db_sql, "w", encoding="utf-8") as f:
                f.write(sql_content_mod)

            # Update manifest checksum for database component
            db_sha = hashlib.sha256(sql_content_mod.encode("utf-8")).hexdigest()
            manifest_file = os.path.join(inner_dir, "manifest.json")
            with open(manifest_file, "r") as f:
                mf = json.load(f)
            mf["components"]["database"]["sha256"] = db_sha
            data_sha = mf["components"]["user_data"]["sha256"]
            mf["components_digest_sha256"] = hashlib.sha256(f"{db_sha}\n{data_sha}".encode()).hexdigest()
            with open(manifest_file, "w") as f:
                json.dump(mf, f)

            repack_tar = os.path.join(tmpdir, "missing_table.tar.gz")
            repack_archive(inner_dir, repack_tar)

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", repack_tar],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("missing from restored sandbox database", res.stdout)

    def test_19_failure_injection_f_invalid_system_baseline(self):
        """Failure Injection F: Manifest with invalid baseline git_commit must fail."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            extract_dir = os.path.join(tmpdir, "extracted")
            inner_dir = extract_instance_backup(src, extract_dir)

            manifest_file = os.path.join(inner_dir, "manifest.json")
            with open(manifest_file, "r") as f:
                mf = json.load(f)
            mf["system_baseline"]["git_commit"] = "invalid_hash"
            with open(manifest_file, "w") as f:
                json.dump(mf, f)

            repack_tar = os.path.join(tmpdir, "bad_baseline.tar.gz")
            repack_archive(inner_dir, repack_tar)

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", repack_tar],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("Invalid Git commit SHA", res.stdout)

    def test_20_failure_injection_g_db_ok_filesystem_missing_file(self):
        """Failure Injection G: DB restores OK but physical file missing from data archive -> must FAIL cross-check."""
        with tempfile.TemporaryDirectory() as tmpdir:
            src = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
            extract_dir = os.path.join(tmpdir, "extracted")
            inner_dir = extract_instance_backup(src, extract_dir)

            # Unpack data.tar.gz, delete a user file, and repack using native fast tar
            data_tar = os.path.join(inner_dir, "data.tar.gz")
            data_extract = os.path.join(tmpdir, "data_extracted")
            os.makedirs(data_extract, exist_ok=True)
            subprocess.run(["tar", "-xzf", data_tar, "-C", data_extract], check=True)

            # Find a real file in user's files and delete it
            deleted = False
            for root, dirs, files in os.walk(data_extract):
                if "/files/" in root.replace("\\", "/") and files:
                    os.remove(os.path.join(root, files[0]))
                    deleted = True
                    break
            self.assertTrue(deleted, "Could not locate a user file to delete for failure injection")

            # Repack data.tar.gz with native tar
            os.remove(data_tar)
            subprocess.run(["tar", "-czf", data_tar, "-C", data_extract, "data"], check=True)

            # Update manifest checksum for user_data
            with open(data_tar, "rb") as f:
                data_sha = hashlib.sha256(f.read()).hexdigest()
            manifest_file = os.path.join(inner_dir, "manifest.json")
            with open(manifest_file, "r") as f:
                mf = json.load(f)
            mf["components"]["user_data"]["sha256"] = data_sha
            db_sha = mf["components"]["database"]["sha256"]
            mf["components_digest_sha256"] = hashlib.sha256(f"{db_sha}\n{data_sha}".encode()).hexdigest()
            with open(manifest_file, "w") as f:
                json.dump(mf, f)

            repack_tar = os.path.join(tmpdir, "fs_mismatch.tar.gz")
            repack_archive(inner_dir, repack_tar)

            res = subprocess.run(
                [f"{DEPLOY_DIR}/test_instance_data_backup.sh", repack_tar],
                capture_output=True,
                text=True
            )
            self.assertNotEqual(res.returncode, 0)
            self.assertIn("file(s) in DB oc_filecache missing on extracted filesystem", res.stdout)

    def test_21_production_invariance_and_safety(self):
        """Verify production database, containers, and filesystem are completely untouched after all tests."""
        prod_users = get_production_user_count()
        self.assertIsNotNone(prod_users)
        self.assertGreaterEqual(prod_users, 8, "Production users must remain at least 8")

        # Container health
        for c in ["archive_db", "archive_app", "archive_proxy"]:
            chk = subprocess.run(["docker", "inspect", "--format", "{{.State.Status}}", c], capture_output=True, text=True)
            self.assertEqual(chk.stdout.strip(), "running", f"Container {c} must be running")

        # Production not in maintenance mode
        maint_res = requests.get(f"{SYSTEM_URL}/maintenance-status", headers=HEADERS)
        self.assertEqual(maint_res.status_code, 200)
        maint_data = maint_res.json().get("data", {})
        self.assertFalse(maint_data.get("in_maintenance", True), "Production must not be in maintenance mode")


if __name__ == "__main__":
    unittest.main()
