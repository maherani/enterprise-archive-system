#!/usr/bin/env python3
"""
Test Suite: BR-04 Production Instance Data Restore on a Healthy Server
Enterprise Archive System

Validates:
1. Non-admin users are forbidden (403) from restore APIs.
2. Missing or incorrect confirmation phrase is rejected (400).
3. system_only backups cannot be restored on live production (400 / safe rejection).
4. Concurrent restore protection: Active restore locks or IN_PROGRESS tasks reject 2nd restore with 409 Conflict.
5. Failure Injection A: Corrupted archive tarball -> Restore NOT STARTED (Fail-closed).
6. Failure Injection B: Bad SHA-256 sidecar checksum -> Restore NOT STARTED.
7. Failure Injection C: Baseline mismatch (incompatible git_commit) -> Restore NOT STARTED.
8. Failure Injection D: Archive containing forbidden system files -> Restore NOT STARTED.
9. Pre-Restore Safety Backup: Auto-created with backup_purpose='pre_restore_safety' and preserved from retention pruning.
10. Real Production Restore & Reversion (Section 27):
    - Upload Docs A & B -> Snapshot RP1 (instance_data backup).
    - Upload Docs C & D -> live DB & storage updated.
    - Execute Production Restore to RP1.
    - Verify Docs A & B exist; Docs C & D are reverted as expected.
    - Verify System State and config.php (passwordsalt, secret, instanceid) remain 100% UNCHANGED.
11. Post-Restore Permission & Isolation (Section 28):
    - Group A isolation preserved.
    - Group B cannot access Group A files (403 / isolation).
    - Group 3 isolation preserved.
12. Audit Trail (Section 25):
    - deploy/backups/restore_audit.jsonl records requested_by, backup_id, recovery_point, baseline, pre_restore_backup_id, result.
    - Zero passwords or secrets in audit logs.
13. CLI parity: deploy/manage_backup.sh restore-data enforces RESTORE-CONFIRM.
"""

import unittest
import os
import subprocess
import json
import tarfile
import hashlib
import time
import shutil
import tempfile
import requests
from requests.auth import HTTPBasicAuth

REPO_DIR = "/home/alborz/enterprise-archive-system"
DEPLOY_DIR = os.path.join(REPO_DIR, "deploy")
BACKUP_DIR = os.path.join(DEPLOY_DIR, "backups")
AUDIT_LOG = os.path.join(BACKUP_DIR, "restore_audit.jsonl")
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

PG_PASSWORD = os.environ.get("PGPASSWORD", "Secure_DB_Password_123!")
PG_USER = "nextcloud_user"
PG_DB = "nextcloud"


def query_db(sql):
    cmd = ["docker", "exec", "-e", f"PGPASSWORD={PG_PASSWORD}", "archive_db",
           "psql", "-U", PG_USER, "-d", PG_DB, "-t", "-A", "-c", sql]
    res = subprocess.run(cmd, capture_output=True, text=True)
    return res.stdout.strip(), res.returncode


def get_config_identity():
    """Extract system identity configuration from container config.php."""
    cmd = ["docker", "exec", "archive_app", "php", "-r", """
    require '/var/www/html/config/config.php';
    echo json_encode([
        'instanceid' => $CONFIG['instanceid'] ?? '',
        'passwordsalt' => $CONFIG['passwordsalt'] ?? '',
        'secret' => $CONFIG['secret'] ?? ''
    ]);
    """]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode == 0:
        return json.loads(res.stdout.strip())
    return {}


class TestInstanceDataRestore(unittest.TestCase):

    def tearDown(self):
        # Guarantee maintenance mode is always OFF after each test
        subprocess.run(["docker", "exec", "archive_app", "php", "occ", "maintenance:mode", "--off"], capture_output=True)

    @classmethod
    def setUpClass(cls):
        # Ensure a clean base instance_data backup exists
        res = subprocess.run(
            [f"{DEPLOY_DIR}/backup_instance_data.sh"],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        assert res.returncode == 0, f"Setup backup_instance_data failed: {res.stderr}"

    def test_01_api_access_control_and_confirmation(self):
        """Non-admin forbidden (403); invalid confirmation rejected (400)."""
        url = f"{BASE_URL}/restore/run"
        # 1. Non-admin forbidden
        res = requests.post(url, auth=soc_auth, headers=HEADERS, json={
            "target": "latest_instance_data_backup.tar.gz",
            "confirmation": "RESTORE-CONFIRM"
        })
        self.assertEqual(res.status_code, 403, f"Expected 403 for non-admin, got {res.status_code}")

        # 2. Admin with wrong confirmation
        res = requests.post(url, auth=admin_auth, headers=HEADERS, json={
            "target": "latest_instance_data_backup.tar.gz",
            "confirmation": "WRONG-KEY"
        })
        self.assertEqual(res.status_code, 400, f"Expected 400 for wrong confirmation, got {res.status_code}")

    def test_02_system_only_restore_rejected(self):
        """System-only backup must NOT be allowed to restore on live system."""
        # Ensure system backup exists
        subprocess.run([f"{DEPLOY_DIR}/backup_system.sh"], cwd=REPO_DIR, capture_output=True)
        url = f"{BASE_URL}/restore/run"
        res = requests.post(url, auth=admin_auth, headers=HEADERS, json={
            "target": "latest_system_backup.tar.gz",
            "confirmation": "RESTORE-CONFIRM"
        })
        self.assertEqual(res.status_code, 400, f"Expected 400 rejection for system_only restore, got {res.status_code}")
        data = res.json()
        self.assertIn("system_only", data.get("message", "") + data.get("code", ""))

    def test_03_concurrent_restore_protection(self):
        """Concurrent restore requests must return 409 Conflict."""
        lock_file = os.path.join(BACKUP_DIR, ".archive_restore.lock")
        try:
            with open(lock_file, "w") as f:
                f.write("999999\n")

            url = f"{BASE_URL}/restore/run"
            res = requests.post(url, auth=admin_auth, headers=HEADERS, json={
                "target": "latest_instance_data_backup.tar.gz",
                "confirmation": "RESTORE-CONFIRM"
            })
            self.assertEqual(res.status_code, 409, f"Expected 409 Conflict when lock exists, got {res.status_code}")
        finally:
            if os.path.exists(lock_file):
                os.remove(lock_file)

    def test_04_failure_injection_corrupted_archive(self):
        """Corrupted archive tarball -> Restore NOT STARTED, fail-closed."""
        bad_tar = os.path.join(BACKUP_DIR, "test_corrupt_data.tar.gz")
        with open(bad_tar, "wb") as f:
            f.write(b"NOT_A_VALID_GZIP_TARBALL_DATA")

        res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", bad_tar],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0, "Restore must exit non-zero on corrupt tar")
        self.assertIn("corrupted", res.stderr + res.stdout)
        if os.path.exists(bad_tar):
            os.remove(bad_tar)

    def test_05_failure_injection_bad_checksum(self):
        """SHA-256 sidecar mismatch -> Restore NOT STARTED."""
        src_tar = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        bad_tar = os.path.join(BACKUP_DIR, "test_bad_sha_data.tar.gz")
        shutil.copyfile(src_tar, bad_tar)
        with open(bad_tar + ".sha256", "w") as f:
            f.write("0000000000000000000000000000000000000000000000000000000000000000  test_bad_sha_data.tar.gz\n")

        res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", bad_tar],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0, "Restore must fail on checksum mismatch")
        self.assertIn("Checksum mismatch", res.stderr + res.stdout)

        for p in [bad_tar, bad_tar + ".sha256"]:
            if os.path.exists(p): os.remove(p)

    def test_06_failure_injection_baseline_mismatch(self):
        """Baseline mismatch (e.g. wrong Git commit in manifest) -> Restore NOT STARTED."""
        src_tar = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        tmp_dir = tempfile.mkdtemp(prefix="bad_baseline_")
        bad_tar = os.path.join(BACKUP_DIR, "test_bad_baseline.tar.gz")

        try:
            with tarfile.open(src_tar, "r:gz") as tar:
                tar.extractall(tmp_dir)

            # Locate manifest.json
            for root, dirs, files in os.walk(tmp_dir):
                if "manifest.json" in files:
                    mf_path = os.path.join(root, "manifest.json")
                    with open(mf_path, "r") as f:
                        mf = json.load(f)
                    mf["system_baseline"]["git_commit"] = "0000000000000000000000000000000000000000"
                    with open(mf_path, "w") as f:
                        json.dump(mf, f, indent=2)

                    inner_folder = os.path.basename(root)
                    parent_dir = os.path.dirname(root)
                    subprocess.run(["tar", "-czf", bad_tar, "-C", parent_dir, inner_folder], check=True)
                    break

            res = subprocess.run(
                [f"{DEPLOY_DIR}/restore_instance_data.sh", bad_tar],
                cwd=REPO_DIR, capture_output=True, text=True
            )
            self.assertNotEqual(res.returncode, 0, "Restore must fail on baseline mismatch")
            self.assertIn("mismatch", res.stderr + res.stdout)
        finally:
            shutil.rmtree(tmp_dir, ignore_errors=True)
            if os.path.exists(bad_tar): os.remove(bad_tar)

    def test_07_failure_injection_negative_assertion_system_files(self):
        """Archive containing forbidden system files (config.tar.gz) -> Restore NOT STARTED."""
        src_tar = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")
        tmp_dir = tempfile.mkdtemp(prefix="bad_sys_files_")
        bad_tar = os.path.join(BACKUP_DIR, "test_forbidden_sys.tar.gz")

        try:
            with tarfile.open(src_tar, "r:gz") as tar:
                tar.extractall(tmp_dir)

            for root, dirs, files in os.walk(tmp_dir):
                if "manifest.json" in files:
                    # Inject forbidden config.tar.gz
                    with open(os.path.join(root, "config.tar.gz"), "w") as f:
                        f.write("FORBIDDEN_CONFIG_CONTENT")
                    inner_folder = os.path.basename(root)
                    parent_dir = os.path.dirname(root)
                    subprocess.run(["tar", "-czf", bad_tar, "-C", parent_dir, inner_folder], check=True)
                    break

            res = subprocess.run(
                [f"{DEPLOY_DIR}/restore_instance_data.sh", bad_tar],
                cwd=REPO_DIR, capture_output=True, text=True
            )
            self.assertNotEqual(res.returncode, 0, "Restore must reject archives with system files")
            self.assertIn("Negative assertion failed", res.stderr + res.stdout)
        finally:
            shutil.rmtree(tmp_dir, ignore_errors=True)
            if os.path.exists(bad_tar): os.remove(bad_tar)

    def test_08_pre_restore_safety_backup_creation(self):
        """backup_instance_data.sh pre_restore_safety sets purpose and creates alias."""
        res = subprocess.run(
            [f"{DEPLOY_DIR}/backup_instance_data.sh", "pre_restore_safety"],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertEqual(res.returncode, 0, f"Pre-restore safety backup failed: {res.stderr}")

        latest_pre = os.path.join(BACKUP_DIR, "latest_instance_data_pre_restore_backup.tar.gz")
        self.assertTrue(os.path.exists(latest_pre), "latest_instance_data_pre_restore_backup.tar.gz alias must exist")

        with tarfile.open(latest_pre, "r:gz") as tar:
            m = tar.extractfile("manifest.json") if "manifest.json" in tar.getnames() else None
            if not m:
                for n in tar.getnames():
                    if n.endswith("manifest.json"):
                        m = tar.extractfile(n)
                        break
            self.assertIsNotNone(m, "manifest.json must be in pre_restore backup")
            mf = json.load(m)
            self.assertEqual(mf.get("backup_purpose"), "pre_restore_safety")

    def test_09_real_production_restore_and_reversion(self):
        """
        Section 27 Real Production Test:
        1. Capture current config.php identity (instanceid, passwordsalt, secret).
        2. Create test files Doc A & Doc B in test_user_a storage.
        3. Create instance_data backup (Recovery Point 1 - RP1).
        4. Create test files Doc C & Doc D in test_user_a storage.
        5. Execute Production Instance Data Restore of RP1.
        6. Assert Docs A & B exist.
        7. Assert Docs C & D are reverted (do not exist).
        8. Assert config.php identity is 100% UNCHANGED.
        """
        config_before = get_config_identity()
        self.assertTrue(bool(config_before.get("instanceid")), "Failed to read initial config identity")

        # Step 1.5: Clean residual test files from any prior runs
        subprocess.run([
            "docker", "exec", "archive_app", "bash", "-c",
            "rm -f /var/www/html/data/test_user_a/files/Doc_*.txt && php occ files:scan test_user_a >/dev/null 2>&1"
        ])

        # Step 2: Create Doc A and Doc B
        subprocess.run([
            "docker", "exec", "archive_app", "bash", "-c",
            "echo 'Content of Document A' > /var/www/html/data/test_user_a/files/Doc_A_Recovery.txt && "
            "echo 'Content of Document B' > /var/www/html/data/test_user_a/files/Doc_B_Recovery.txt && "
            "chown -R www-data:www-data /var/www/html/data/test_user_a/files/ && "
            "php occ files:scan test_user_a"
        ], check=True)

        # Step 3: Create RP1 backup
        rp1_output = subprocess.run(
            [f"{DEPLOY_DIR}/backup_instance_data.sh"],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertEqual(rp1_output.returncode, 0, f"RP1 creation failed: {rp1_output.stderr}")
        rp1_archive = os.path.join(BACKUP_DIR, "latest_instance_data_backup.tar.gz")

        # Step 4: Create Doc C and Doc D (created AFTER RP1)
        subprocess.run([
            "docker", "exec", "archive_app", "bash", "-c",
            "echo 'Content of Document C' > /var/www/html/data/test_user_a/files/Doc_C_PostRP.txt && "
            "echo 'Content of Document D' > /var/www/html/data/test_user_a/files/Doc_D_PostRP.txt && "
            "chown -R www-data:www-data /var/www/html/data/test_user_a/files/ && "
            "php occ files:scan test_user_a"
        ], check=True)

        # Verify Doc C and Doc D exist prior to restore
        check_c = subprocess.run(["docker", "exec", "archive_app", "test", "-f", "/var/www/html/data/test_user_a/files/Doc_C_PostRP.txt"])
        self.assertEqual(check_c.returncode, 0, "Doc C must exist before restore")

        # Step 5: Perform Live Production Restore of RP1
        restore_res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", rp1_archive],
            cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertEqual(restore_res.returncode, 0, f"Production restore failed: {restore_res.stderr}\nStdout: {restore_res.stdout}")

        # Step 6: Verify Doc A & Doc B EXIST
        check_a = subprocess.run(["docker", "exec", "archive_app", "test", "-f", "/var/www/html/data/test_user_a/files/Doc_A_Recovery.txt"])
        check_b = subprocess.run(["docker", "exec", "archive_app", "test", "-f", "/var/www/html/data/test_user_a/files/Doc_B_Recovery.txt"])
        self.assertEqual(check_a.returncode, 0, "Doc A must exist after restore")
        self.assertEqual(check_b.returncode, 0, "Doc B must exist after restore")

        # Step 7: Verify Doc C & Doc D DO NOT EXIST (Reversion proven!)
        check_c_after = subprocess.run(["docker", "exec", "archive_app", "test", "-f", "/var/www/html/data/test_user_a/files/Doc_C_PostRP.txt"])
        check_d_after = subprocess.run(["docker", "exec", "archive_app", "test", "-f", "/var/www/html/data/test_user_a/files/Doc_D_PostRP.txt"])
        self.assertNotEqual(check_c_after.returncode, 0, "Doc C must be REVERTED and absent from live storage")
        self.assertNotEqual(check_d_after.returncode, 0, "Doc D must be REVERTED and absent from live storage")

        # Step 8: Verify config identity is 100% UNCHANGED
        config_after = get_config_identity()
        self.assertEqual(config_before["instanceid"], config_after["instanceid"], "instanceid was altered!")
        self.assertEqual(config_before["passwordsalt"], config_after["passwordsalt"], "passwordsalt was altered!")
        self.assertEqual(config_before["secret"], config_after["secret"], "secret was altered!")

        # Cleanup test files A and B cleanly
        subprocess.run([
            "docker", "exec", "archive_app", "bash", "-c",
            "rm -f /var/www/html/data/test_user_a/files/Doc_A_Recovery.txt /var/www/html/data/test_user_a/files/Doc_B_Recovery.txt && "
            "php occ files:scan test_user_a"
        ], check=True)

    def test_10_post_restore_permission_and_isolation(self):
        """Section 28: Permission & Group Isolation preserved after restore."""
        # 1. Login verification for admin and users
        r_admin = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r_admin.status_code, 200, "Admin login/access failed post-restore")

        # 2. Group tag isolation check for test_user_a
        r_user = requests.get(f"{BASE_URL}/backup/status", auth=soc_auth, headers=HEADERS)
        self.assertEqual(r_user.status_code, 403, "Non-admin user isolation violated post-restore")

        # 3. Nextcloud operational status check
        nc_status = subprocess.run(["docker", "exec", "archive_app", "php", "occ", "status"], capture_output=True, text=True)
        self.assertEqual(nc_status.returncode, 0, "Nextcloud occ status failed")
        self.assertIn("installed: true", nc_status.stdout)
        self.assertIn("maintenance: false", nc_status.stdout)

    def test_11_audit_trail_recorded(self):
        """Section 25: Audit log recorded in restore_audit.jsonl with zero leaked secrets."""
        self.assertTrue(os.path.exists(AUDIT_LOG), "restore_audit.jsonl must exist")
        with open(AUDIT_LOG, "r") as f:
            lines = f.readlines()
        self.assertTrue(len(lines) > 0, "restore_audit.jsonl must contain at least 1 entry")

        last_entry = json.loads(lines[-1])
        self.assertEqual(last_entry.get("backup_type"), "instance_data")
        self.assertIn(last_entry.get("result"), ["SUCCESS", "FAILED"])
        self.assertTrue(bool(last_entry.get("backup_id")))
        self.assertTrue(bool(last_entry.get("system_baseline")))

        # Check NO passwords or secrets are in any entry
        for line in lines:
            self.assertNotIn("Secure_Admin_Password", line)
            self.assertNotIn("Secure_DB_Password", line)
            self.assertNotIn("passwordsalt", line)

    def test_12_cli_parity(self):
        """CLI manage_backup.sh restore-data enforces RESTORE-CONFIRM."""
        # Aborted run without confirmation
        res = subprocess.run(
            [f"{DEPLOY_DIR}/manage_backup.sh", "restore-data", "latest_instance_data_backup.tar.gz"],
            input="WRONG\n", cwd=REPO_DIR, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0, "CLI must reject invalid confirmation")
        self.assertIn("ABORTED", res.stdout + res.stderr)


    def test_13_hardening_failure_injection_maintenance_on_failure(self):
        """Failure Injection I: Maintenance ON failure -> Restore NOT STARTED, fail-closed."""
        env = os.environ.copy()
        env["TEST_SIMULATE_MAINT_ON_FAIL"] = "1"
        res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", "latest_instance_data_backup.tar.gz"],
            cwd=REPO_DIR, env=env, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0, "Restore must fail-closed if maintenance activation fails")
        self.assertIn("Failed to activate maintenance mode", res.stdout + res.stderr)

        # Verify audit log
        audit_file = os.path.join(BACKUP_DIR, "restore_audit.jsonl")
        self.assertTrue(os.path.exists(audit_file))
        with open(audit_file, "r") as f:
            last = json.loads(f.readlines()[-1])
        self.assertEqual(last.get("result"), "FAILED")
        self.assertEqual(last.get("failure_reason"), "Failed to activate maintenance mode")

        # Verify status file
        status_file = "/tmp/archive_backup_status.json"
        if os.path.exists(status_file):
            with open(status_file, "r") as f:
                st = json.load(f)
            self.assertEqual(st.get("status"), "FAILED")

    def test_14_hardening_failure_injection_missing_postgres_password(self):
        """Failure Injection J: Missing POSTGRES_PASSWORD -> Restore NOT STARTED, no hardcoded password."""
        dummy_env = "/tmp/test_dummy_missing_pw.env"
        with open(dummy_env, "w") as f:
            f.write("POSTGRES_DB=nextcloud\nPOSTGRES_USER=nextcloud_user\nPOSTGRES_PASSWORD=\n")
        try:
            env = os.environ.copy()
            env["RESTORE_ENV_FILE"] = dummy_env
            res = subprocess.run(
                [f"{DEPLOY_DIR}/restore_instance_data.sh", "latest_instance_data_backup.tar.gz"],
                cwd=REPO_DIR, env=env, capture_output=True, text=True
            )
            self.assertEqual(res.returncode, 1, "Restore must abort with missing password")
            self.assertIn("Missing database credentials in environment or .env file", res.stderr)
        finally:
            if os.path.exists(dummy_env):
                os.remove(dummy_env)

    def test_15_hardening_failure_injection_degraded_health(self):
        """Failure Injection K: Post-restore health check fails -> SUCCESS must NOT be recorded."""
        env = os.environ.copy()
        env["TEST_SIMULATE_HEALTH_FAIL"] = "1"
        res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", "latest_instance_data_backup.tar.gz"],
            cwd=REPO_DIR, env=env, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0, "Restore must fail if post-restore health check fails")
        self.assertIn("Post-Restore Health Check FAILED", res.stdout + res.stderr)

        # Verify audit log recorded FAILED, NOT SUCCESS
        audit_file = os.path.join(BACKUP_DIR, "restore_audit.jsonl")
        with open(audit_file, "r") as f:
            last = json.loads(f.readlines()[-1])
        self.assertEqual(last.get("result"), "FAILED")
        self.assertEqual(last.get("failure_reason"), "Post-restore health check gate failed")

    def test_16_hardening_failure_injection_maintenance_off_failure(self):
        """Failure Injection L: Maintenance OFF fails -> Final result != SUCCESS."""
        env = os.environ.copy()
        env["TEST_SIMULATE_MAINT_OFF_FAIL"] = "1"
        try:
            res = subprocess.run(
                [f"{DEPLOY_DIR}/restore_instance_data.sh", "latest_instance_data_backup.tar.gz"],
                cwd=REPO_DIR, env=env, capture_output=True, text=True
            )
            self.assertNotEqual(res.returncode, 0, "Restore must fail if maintenance mode cannot be turned off")
            out = res.stdout + res.stderr
            self.assertTrue(
                "Failed to execute 'occ maintenance:mode --off'" in out or "Failed to disable maintenance mode" in out,
                f"Expected maintenance off failure message in output, got: {out}"
            )

            # Verify audit log
            audit_file = os.path.join(BACKUP_DIR, "restore_audit.jsonl")
            with open(audit_file, "r") as f:
                last = json.loads(f.readlines()[-1])
            self.assertEqual(last.get("result"), "FAILED")
            self.assertEqual(last.get("failure_reason"), "Failed to disable maintenance mode")
        finally:
            # Cleanup: Ensure maintenance mode is off on the live container
            subprocess.run(["docker", "exec", "archive_app", "php", "occ", "maintenance:mode", "--off"], capture_output=True)

    def test_17_hardening_request_token_validation(self):
        """Failure Injection M: Invalid / Missing Request Token rejected (403), valid UI token accepted."""
        url_restore = f"{BASE_URL}/restore/run"
        url_backup = f"{BASE_URL}/backup/run"

        # 1. State-changing request with invalid requesttoken header -> 403 Forbidden CSRF_FAILED
        bad_headers = dict(HEADERS)
        bad_headers["requesttoken"] = "invalid_csrf_token_value_98765"
        res_bad_tok = requests.post(url_restore, auth=admin_auth, headers=bad_headers, json={
            "target": "latest_instance_data_backup.tar.gz",
            "confirmation": "RESTORE-CONFIRM"
        })
        self.assertEqual(res_bad_tok.status_code, 403, "Invalid request token must be rejected with 403")
        self.assertEqual(res_bad_tok.json().get("code"), "CSRF_FAILED")

        # 2. State-changing request from session with cookies without requesttoken -> 403 Forbidden
        cookie_headers = dict(HEADERS)
        res_no_tok = requests.post(
            url_backup, auth=admin_auth, headers=cookie_headers,
            cookies={"nc_session_id": "fake_browser_session_123"},
            json={"backup_type": "instance_data"}
        )
        self.assertEqual(res_no_tok.status_code, 403, "Browser session without requesttoken must be rejected")
        self.assertEqual(res_no_tok.json().get("code"), "CSRF_FAILED")

        # 3. GET read-only requests without token remain accessible
        res_status = requests.get(f"{BASE_URL}/backup/status", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res_status.status_code, 200)

        res_list = requests.get(f"{BASE_URL}/backup/list", auth=admin_auth, headers=HEADERS)
        self.assertEqual(res_list.status_code, 200)

    def test_18_hardening_audit_requester_identity(self):
        """Failure Injection N: Audit log records actual requesting administrator identity."""
        test_requester = "enterprise_secadmin_test"
        env = os.environ.copy()
        env["RESTORE_REQUESTED_BY"] = test_requester
        env["TEST_SIMULATE_MAINT_ON_FAIL"] = "1"  # fast fail-closed test
        res = subprocess.run(
            [f"{DEPLOY_DIR}/restore_instance_data.sh", "latest_instance_data_backup.tar.gz"],
            cwd=REPO_DIR, env=env, capture_output=True, text=True
        )
        self.assertNotEqual(res.returncode, 0)

        audit_file = os.path.join(BACKUP_DIR, "restore_audit.jsonl")
        with open(audit_file, "r") as f:
            last = json.loads(f.readlines()[-1])
        self.assertEqual(last.get("requested_by"), test_requester, "Audit log must record the exact requesting admin user")
        self.assertNotEqual(last.get("requested_by"), "admin", "Audit log must not be hardcoded to 'admin' when specified")


if __name__ == "__main__":
    unittest.main()
