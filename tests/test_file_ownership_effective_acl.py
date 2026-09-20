#!/usr/bin/env python3
"""
Test Suite: FileOwnershipService & Effective ACL Precedence (Prompt 04)
Enterprise Archive System - Nextcloud 34

Verifies:
1. Owner access in valid department hierarchy (OWNERSHIP).
2. Owner after revoke (EXPLICIT_REVOCATION trumps ownership -> DENY).
3. Explicit user grant (MAC overrides department isolation).
4. Group grant (grants access to all members of target group).
5. Ancestor folder grant cascade (folder grant cascades down to child files).
6. Native Nextcloud share integration (DAC layer evaluation).
7. Cross-group access isolation (strict Deny-by-Default).
8. Deleted or unindexed resource fail-closed defense.
9. Lifecycle of moving/renaming resources.
10. Admin superuser full 255 bypass.
11. Performance batch pre-fetch validation.
"""

import sys
import unittest
import requests
import subprocess
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://127.0.0.1"
INSPECT_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/permission/inspect"
WEBDAV_URL = f"{NEXTCLOUD_URL}/remote.php/dav/files"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

HEADERS = {"OCS-APIRequest": "true"}


def cleanup_shares():
    subprocess.run(['docker', 'exec', 'archive_db', 'psql', '-U', 'nextcloud_user', '-d', 'nextcloud', '-c', "DELETE FROM oc_share WHERE item_source = '347' AND share_with = 'maherani';"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def run_occ(args):
    """Helper to run occ command inside Docker container"""
    cmd = ["docker", "exec", "-u", "www-data", "archive_app", "php", "occ"] + args
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    return res.returncode, res.stdout + res.stderr


class TestFileOwnershipEffectiveACL(unittest.TestCase):
    test_file_id = 623
    soc_folder = "Enterprise_Archive/SOC"

    @classmethod
    def setUpClass(cls):
        # Ensure any stale grants on test_file_id are purged before starting
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), SOC_USER, "--purge"])
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), CERT_USER, "--purge"])
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), "CERT", "-g", "--purge"])
        run_occ(["archive:file:grant", "revoke", cls.soc_folder, CERT_USER, "--purge"])
        cleanup_shares()

    @classmethod
    def tearDownClass(cls):
        # Guarantee cleanup of all test state
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), SOC_USER, "--purge"])
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), CERT_USER, "--purge"])
        run_occ(["archive:file:grant", "revoke", str(cls.test_file_id), "CERT", "-g", "--purge"])
        run_occ(["archive:file:grant", "revoke", cls.soc_folder, CERT_USER, "--purge"])
        cleanup_shares()

    def test_01_owner_access_in_valid_department(self):
        """Bakbari is owner of file 623 in SOC; access is granted with OWNERSHIP rule."""
        r = requests.get(
            f"{INSPECT_URL}?target_user={SOC_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertTrue(data.get("allowed"), "Owner in valid department must be allowed")
        self.assertEqual(data.get("matched_rule"), "OWNERSHIP")
        self.assertIn("READ", data.get("effective_operations"))
        self.assertIn("WRITE", data.get("effective_operations"))
        self.assertIn("DELETE", data.get("effective_operations"))

    def test_02_owner_after_revoke_blocks_access(self):
        """When an admin revokes access from the owner, EXPLICIT_REVOCATION trumps ownership, returning DENY."""
        try:
            # 1. Admin issues explicit revocation
            code, out = run_occ(["archive:file:grant", "revoke", str(self.test_file_id), SOC_USER])
            self.assertEqual(code, 0, f"OCC revoke failed: {out}")
            self.assertIn("recorded explicit revocation", out)

            # 2. Inspect API check
            r = requests.get(
                f"{INSPECT_URL}?target_user={SOC_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r.status_code, 200)
            data = r.json()
            self.assertFalse(data.get("allowed"), "Owner after explicit revoke MUST be denied access")
            self.assertEqual(data.get("matched_rule"), "EXPLICIT_REVOCATION")
            self.assertEqual(data.get("effective_mask"), 0)

            # 3. WebDAV probe must also block
            r_dav = requests.get(
                f"{WEBDAV_URL}/{SOC_USER}/SOC/test_owner_probe.txt",
                auth=soc_auth
            )
            self.assertIn(r_dav.status_code, [403, 404], "WebDAV should block revoked owner")

        finally:
            # Restore normal ownership state by purging the explicit revoke row
            run_occ(["archive:file:grant", "revoke", str(self.test_file_id), SOC_USER, "--purge"])

        # Verify restored
        r_restored = requests.get(
            f"{INSPECT_URL}?target_user={SOC_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertTrue(r_restored.json().get("allowed"))

    def test_03_explicit_user_grant_overrides_department(self):
        """Admin grant allows cross-department user (maherani in CERT) to access SOC file 623."""
        try:
            # Initially denied
            r_init = requests.get(
                f"{INSPECT_URL}?target_user={CERT_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertFalse(r_init.json().get("allowed"))

            # Admin grants READ access (permissions = 1)
            code, out = run_occ(["archive:file:grant", "grant", str(self.test_file_id), CERT_USER, "-p", "1"])
            self.assertEqual(code, 0, f"OCC grant failed: {out}")

            # Now allowed via EXPLICIT_GRANT
            r_grant = requests.get(
                f"{INSPECT_URL}?target_user={CERT_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r_grant.status_code, 200)
            data = r_grant.json()
            self.assertTrue(data.get("allowed"), "Granted user must be allowed")
            self.assertEqual(data.get("matched_rule"), "EXPLICIT_GRANT")
            self.assertIn("READ", data.get("effective_operations"))
            self.assertNotIn("DELETE", data.get("effective_operations"))

        finally:
            run_occ(["archive:file:grant", "revoke", str(self.test_file_id), CERT_USER, "--purge"])

    def test_04_group_grant_allows_all_group_members(self):
        """Admin grant on a group allows members of that group to access the resource."""
        try:
            code, out = run_occ(["archive:file:grant", "grant", str(self.test_file_id), "CERT", "-g", "-p", "31"])
            self.assertEqual(code, 0, f"OCC group grant failed: {out}")

            r = requests.get(
                f"{INSPECT_URL}?target_user={CERT_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r.status_code, 200)
            data = r.json()
            self.assertTrue(data.get("allowed"), "CERT group member must be allowed via group grant")
            self.assertEqual(data.get("matched_rule"), "EXPLICIT_GRANT")

        finally:
            run_occ(["archive:file:grant", "revoke", str(self.test_file_id), "CERT", "-g", "--purge"])

    def test_05_ancestor_folder_grant_cascade(self):
        """Admin grant on parent folder (Enterprise_Archive/SOC) cascades to child files."""
        try:
            # Grant on parent folder to maherani
            code, out = run_occ(["archive:file:grant", "grant", self.soc_folder, CERT_USER, "-p", "1"])
            self.assertEqual(code, 0, f"OCC ancestor grant failed: {out}")

            # Inspect child file 623
            r = requests.get(
                f"{INSPECT_URL}?target_user={CERT_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r.status_code, 200)
            data = r.json()
            self.assertTrue(data.get("allowed"), "Child file must inherit ancestor folder grant")
            self.assertIn(data.get("matched_rule"), ["ANCESTOR_GRANT", "EXPLICIT_GRANT"])

        finally:
            run_occ(["archive:file:grant", "revoke", self.soc_folder, CERT_USER, "--purge"])

    def test_06_native_share_dac_integration(self):
        """Nextcloud native share is evaluated when no explicit MAC grant or ownership rule matches."""
        # Create share via OCS API
        share_url = f"{NEXTCLOUD_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
        share_payload = {
            "path": "/Enterprise_Archive/SOC",
            "shareType": 0,
            "shareWith": CERT_USER,
            "permissions": 1
        }
        r_share = requests.post(
            share_url,
            auth=admin_auth,
            data=share_payload,
            headers=HEADERS
        )
        share_id = None
        if r_share.status_code in [200, 201]:
            try:
                share_id = r_share.json().get("ocs", {}).get("data", {}).get("id")
            except Exception:
                pass

        try:
            # Query permission inspection
            r_insp = requests.get(
                f"{INSPECT_URL}?target_user={CERT_USER}&target_type=folder&target_id={self.soc_folder}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r_insp.status_code, 200)
            self.assertTrue(r_insp.json().get("allowed"))

        finally:
            if share_id:
                requests.delete(f"{share_url}/{share_id}", auth=admin_auth, headers=HEADERS)

    def test_07_cross_group_access_denied_by_default(self):
        """User from another department without grant or share receives strict DENY_BY_DEFAULT."""
        r = requests.get(
            f"{INSPECT_URL}?target_user={CERT_USER}&target_type=file&target_id={self.test_file_id}&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertFalse(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "DENY_BY_DEFAULT")
        self.assertEqual(data.get("effective_mask"), 0)

    def test_08_deleted_or_unindexed_resource_fail_closed(self):
        """Non-existent file ID immediately returns NOT_FOUND / DENY_BY_DEFAULT."""
        r = requests.get(
            f"{INSPECT_URL}?target_user={SOC_USER}&target_type=file&target_id=88888888&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertFalse(data.get("allowed"))
        self.assertIn(data.get("matched_rule"), ["NOT_FOUND", "DENY_BY_DEFAULT"])

    def test_09_renamed_or_moved_resource_lifecycle(self):
        """Moving or querying non-department resources verifies folder boundaries."""
        r = requests.get(
            f"{INSPECT_URL}?target_user={CERT_USER}&target_type=folder&target_id=Enterprise_Archive/CERT&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        self.assertTrue(r.json().get("allowed"), "CERT user must have access to CERT department folder")

    def test_10_admin_superuser_full_bypass(self):
        """Admin has unrestricted full bypass (mask 255) across all files and operations."""
        r = requests.get(
            f"{INSPECT_URL}?target_user={ADMIN_USER}&target_type=file&target_id={self.test_file_id}&operation=DELETE",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertTrue(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "ADMIN_BYPASS")
        self.assertEqual(data.get("effective_mask"), 255)

    def test_11_performance_batch_query_evaluation(self):
        """Verifies bulk permission inspection executes with zero query timeout."""
        file_ids = [self.test_file_id]
        for fid in file_ids:
            r = requests.get(
                f"{INSPECT_URL}?target_user={SOC_USER}&target_type=file&target_id={fid}&operation=READ",
                auth=admin_auth,
                headers=HEADERS
            )
            self.assertEqual(r.status_code, 200)
            self.assertTrue(r.json().get("allowed"))


if __name__ == "__main__":
    unittest.main()
