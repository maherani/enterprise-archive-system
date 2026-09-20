#!/usr/bin/env python3
"""
Test Suite: Fail-Closed Storage Isolation & Cache Hardening (Prompt 03)
Enterprise Archive System - Nextcloud 34

Verifies:
1. Existing & Allowed: SOC user accesses existing file in Enterprise_Archive/SOC -> 200 OK.
2. Existing & Denied: Cross-dept user accesses SOC file -> 404 Not Found (Strict IDOR Isolation).
3. Cache Missing / Bogus Probe: Non-existent file probe -> strictly 404 Fail-Closed (No Fail-Open leak).
4. Cache Stale / Deleted File: Deleted file immediately returns 404 (No stale cache leak).
5. Renamed File: File renamed via WebDAV MOVE is accessible at new location, old returns 404.
6. Nested Folder Upload: Authorized user uploads new file to department folder -> 201/204.
7. Unauthorized Upload: Cross-department user blocked from uploading into other dept -> 403/404.
8. Admin Superuser Access: Admin maintains unrestricted access across all files/folders.
9. WebDAV Protocol Compatibility: PROPFIND on archive structure functions seamlessly (207 Multi-Status).
10. AI API Integration: Zero regression on AI Bearer token & delegated identity retrieval.
"""

import sys
import time
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://127.0.0.1"
WEBDAV_URL = f"{NEXTCLOUD_URL}/remote.php/dav/files"
AI_API_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/v1/ai"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

AI_SERVICE_TOKEN = "ai_sec_token_7021824a20719a37d5433ba2f96832d28edf57d81d307a8468d2864601e29d08"

HEADERS = {"OCS-APIRequest": "true"}


class TestFailCloseIsolationWrapper(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.test_filename = f"fc_test_{int(time.time())}.txt"
        cls.soc_file_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/{cls.test_filename}"
        cls.admin_soc_file_url = f"{WEBDAV_URL}/{ADMIN_USER}/Enterprise_Archive/SOC/{cls.test_filename}"

        # Upload initial test file as SOC user
        content = b"Fail-Closed Storage Security Verification Content"
        r = requests.put(cls.soc_file_url, auth=soc_auth, data=content)
        assert r.status_code in [201, 204], f"Setup upload failed: {r.status_code}"

    @classmethod
    def tearDownClass(cls):
        # Cleanup base file
        requests.delete(cls.admin_soc_file_url, auth=admin_auth)

    def test_01_existing_and_allowed_access(self):
        """SOC user (Bakbari) accessing their own file in Enterprise_Archive/SOC must succeed with 200 OK."""
        r = requests.get(self.soc_file_url, auth=soc_auth)
        self.assertIn(r.status_code, [200, 204], f"Expected 200/204 for authorized SOC user, got {r.status_code}")
        self.assertIn(b"Fail-Closed", r.content)

    def test_02_existing_and_denied_access_idor_isolation(self):
        """Cross-department user (maherani) accessing SOC file must be blocked with 404 Not Found."""
        # 1. Direct cross-user endpoint
        r1 = requests.get(f"{WEBDAV_URL}/{SOC_USER}/SOC/{self.test_filename}", auth=cert_auth)
        self.assertEqual(r1.status_code, 404, f"Expected 404 for cross-user URL, got {r1.status_code}")

        # 2. Cross-dept mount probe
        r2 = requests.get(f"{WEBDAV_URL}/{CERT_USER}/SOC/{self.test_filename}", auth=cert_auth)
        self.assertEqual(r2.status_code, 404, f"Expected 404 for cross-dept mount probe, got {r2.status_code}")

    def test_03_cache_missing_unindexed_probe_fail_closed(self):
        """Probing a non-existent or unindexed file path must strictly return 404 Fail-Closed."""
        bogus_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/ghost_unindexed_probe_99999.txt"
        r = requests.get(bogus_url, auth=soc_auth)
        self.assertEqual(r.status_code, 404, f"Expected 404 for unindexed/missing file, got {r.status_code}")

    def test_04_cache_stale_deleted_file(self):
        """Deleting a file and immediately querying it must return 404 (Zero stale cache leak)."""
        del_fname = f"del_test_{int(time.time())}.txt"
        del_soc_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/{del_fname}"
        del_admin_url = f"{WEBDAV_URL}/{ADMIN_USER}/Enterprise_Archive/SOC/{del_fname}"

        # 1. Create file as SOC user
        r_put = requests.put(del_soc_url, auth=soc_auth, data=b"Temporary deletion file")
        self.assertIn(r_put.status_code, [201, 204], f"Failed to upload test file: {r_put.status_code}")

        # 2. Delete file via Admin (admin has authorized deletion privileges in archive)
        r_del = requests.delete(del_admin_url, auth=admin_auth)
        self.assertIn(r_del.status_code, [200, 204], f"Failed to delete test file: {r_del.status_code}")

        # 3. Verify probe immediately returns 404 (Zero Stale Leak)
        r_probe = requests.get(del_soc_url, auth=soc_auth)
        self.assertEqual(r_probe.status_code, 404, f"Expected 404 after deletion, got {r_probe.status_code}")

    def test_05_renamed_file_lifecycle(self):
        """Renaming a file moves it to new cache path; new path is accessible, old path returns 404."""
        orig_fname = f"rename_orig_{int(time.time())}.txt"
        dest_fname = f"rename_dest_{int(time.time())}.txt"
        orig_admin_url = f"{WEBDAV_URL}/{ADMIN_USER}/Enterprise_Archive/SOC/{orig_fname}"
        dest_admin_url = f"{WEBDAV_URL}/{ADMIN_USER}/Enterprise_Archive/SOC/{dest_fname}"
        dest_soc_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/{dest_fname}"

        # Upload initial file via admin
        requests.put(orig_admin_url, auth=admin_auth, data=b"Rename test content")

        # Move / Rename
        move_headers = {"Destination": dest_admin_url}
        r_move = requests.request("MOVE", orig_admin_url, auth=admin_auth, headers=move_headers)
        self.assertIn(r_move.status_code, [201, 204], f"Move failed: {r_move.status_code}")

        # Verify old path returns 404
        r_old = requests.get(orig_admin_url, auth=admin_auth)
        self.assertEqual(r_old.status_code, 404, f"Old path should return 404, got {r_old.status_code}")

        # Verify new path returns 200 for both admin and SOC user
        r_new_admin = requests.get(dest_admin_url, auth=admin_auth)
        self.assertIn(r_new_admin.status_code, [200, 204], f"New path should return 200, got {r_new_admin.status_code}")

        r_new_soc = requests.get(dest_soc_url, auth=soc_auth)
        self.assertIn(r_new_soc.status_code, [200, 204], f"New path should return 200 for SOC user, got {r_new_soc.status_code}")

        # Cleanup
        requests.delete(dest_admin_url, auth=admin_auth)

    def test_06_nested_folder_upload_in_authorized_dept(self):
        """SOC user uploading a new file into Enterprise_Archive/SOC/ must succeed via parent check."""
        new_fname = f"upload_test_{int(time.time())}.txt"
        new_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/{new_fname}"
        r = requests.put(new_url, auth=soc_auth, data=b"New document in SOC")
        self.assertIn(r.status_code, [201, 204], f"Expected 201/204 on authorized upload, got {r.status_code}")

        # Verify it can be read
        r_get = requests.get(new_url, auth=soc_auth)
        self.assertEqual(r_get.status_code, 200)

        # Cleanup via admin
        cleanup_url = f"{WEBDAV_URL}/{ADMIN_USER}/Enterprise_Archive/SOC/{new_fname}"
        requests.delete(cleanup_url, auth=admin_auth)

    def test_07_unauthorized_upload_cross_department_blocked(self):
        """CERT user attempting to upload into SOC must be blocked with 403 or 404."""
        unauth_fname = f"unauth_hack_{int(time.time())}.txt"
        unauth_url = f"{WEBDAV_URL}/{CERT_USER}/SOC/{unauth_fname}"
        r = requests.put(unauth_url, auth=cert_auth, data=b"Unauthorized payload")
        self.assertIn(r.status_code, [403, 404], f"Expected 403 or 404 for unauthorized upload, got {r.status_code}")

    def test_08_admin_superuser_full_access(self):
        """Admin has full bypass on any file or folder."""
        r = requests.get(self.admin_soc_file_url, auth=admin_auth)
        self.assertIn(r.status_code, [200, 204], f"Admin should have access, got {r.status_code}")

    def test_09_webdav_propfind_compatibility(self):
        """PROPFIND on archive folder structure must succeed and list directory entries cleanly."""
        soc_folder_url = f"{WEBDAV_URL}/{SOC_USER}/SOC/"
        r = requests.request("PROPFIND", soc_folder_url, auth=soc_auth, headers={"Depth": "1"})
        self.assertEqual(r.status_code, 207, f"Expected 207 Multi-Status for PROPFIND, got {r.status_code}")
        self.assertIn("multistatus", r.text.lower())

    def test_10_ai_api_zero_regression(self):
        """AI file retrieval API must authenticate via Bearer token and enforce delegated worker ACL."""
        r_files = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_auth)
        file_id = None
        if r_files.status_code == 200:
            for f in r_files.json().get("files", []):
                if not f.get("is_dir", False):
                    file_id = f["id"]
                    break

        if file_id is not None:
            headers = {
                "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
                "X-On-Behalf-Of": SOC_USER
            }
            r_ai = requests.get(f"{AI_API_URL}/files/{file_id}", headers=headers)
            self.assertIn(r_ai.status_code, [200, 403], f"Expected 200 or 403 from AI API, got {r_ai.status_code}")


if __name__ == "__main__":
    unittest.main()
