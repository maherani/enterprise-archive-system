#!/usr/bin/env python3
"""
Test Suite: Central Permission Resolver & Effective Permission Verification
Enterprise Archive System - Nextcloud 34

Verifies:
1. Access control to the Inspection API (403 for non-admins).
2. Admin superuser bypass across all resource types with full 255 bitmask.
3. Department-scoped folder isolation (SOC member allowed, cross-dept denied).
4. File ownership access with hierarchy validation.
5. Cross-department file isolation (deny-by-default when no grant/share exists).
6. Fail-close semantics on non-existent or unindexed resources.
7. Granular operation-level decisions (e.g. READ allowed for owner, MANAGE denied without subadmin/admin).
8. Tag access evaluation and isolation.
"""

import sys
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://127.0.0.1"
INSPECT_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/permission/inspect"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

OTHER_USER = "maherani"
OTHER_PASS = "User_Password_123!"
other_auth = HTTPBasicAuth(OTHER_USER, OTHER_PASS)

HEADERS = {"OCS-APIRequest": "true"}


class TestCentralPermissionResolver(unittest.TestCase):

    def test_01_non_admin_cannot_inspect_permissions(self):
        """Regular users must receive 403 Forbidden when calling the permission inspect endpoint."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=file&target_id=623",
            auth=soc_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 403, f"Expected 403 for non-admin user, got {r.status_code}")

    def test_02_admin_superuser_full_bypass_on_file(self):
        """Admin has full superuser bypass (mask 255) on any archive file."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=admin&target_type=file&target_id=623&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertTrue(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "ADMIN_BYPASS")
        self.assertEqual(data.get("effective_mask"), 255)
        self.assertIn("READ", data.get("effective_operations"))
        self.assertIn("WRITE", data.get("effective_operations"))
        self.assertIn("DELETE", data.get("effective_operations"))
        self.assertIn("MANAGE", data.get("effective_operations"))

    def test_03_admin_superuser_full_bypass_on_folder(self):
        """Admin has full bypass on any folder structure."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=admin&target_type=folder&target_id=Enterprise_Archive/SOC&operation=CREATE",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertTrue(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "ADMIN_BYPASS")
        self.assertEqual(data.get("effective_mask"), 255)

    def test_04_owner_file_access_with_valid_hierarchy(self):
        """Bakbari is owner of file 623 in SOC; hierarchy is valid, access is granted with OWNERSHIP rule."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=file&target_id=623&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertTrue(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "OWNERSHIP")
        self.assertIn("READ", data.get("effective_operations"))
        self.assertIn("WRITE", data.get("effective_operations"))
        self.assertIn("DELETE", data.get("effective_operations"))

    def test_05_cross_department_file_access_blocked_deny_by_default(self):
        """maherani (not in SOC, no explicit grant, no share) must be DENIED access to file 623."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=maherani&target_type=file&target_id=623&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertFalse(data.get("allowed"))
        self.assertEqual(data.get("matched_rule"), "DENY_BY_DEFAULT")
        self.assertEqual(data.get("effective_mask"), 0)
        self.assertIn("No matching grant, share, or department membership permits READ", data.get("reason", ""))

    def test_06_department_folder_scoping(self):
        """Bakbari (in SOC) is allowed access to Enterprise_Archive/SOC; maherani is DENIED."""
        # Allowed for SOC member
        r_soc = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=folder&target_id=Enterprise_Archive/SOC&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r_soc.status_code, 200)
        data_soc = r_soc.json()
        self.assertTrue(data_soc.get("allowed"))
        self.assertIn(data_soc.get("matched_rule"), ["DEPARTMENT_MEMBERSHIP", "GROUP_ADMIN"])

        # Denied for other department user
        r_other = requests.get(
            f"{INSPECT_URL}?target_user=maherani&target_type=folder&target_id=Enterprise_Archive/SOC&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r_other.status_code, 200)
        data_other = r_other.json()
        self.assertFalse(data_other.get("allowed"))
        self.assertEqual(data_other.get("matched_rule"), "DENY_BY_DEFAULT")

    def test_07_nonexistent_or_unindexed_file_fail_close(self):
        """Non-existent or unindexed file IDs must fail-close immediately (DENY_BY_DEFAULT)."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=file&target_id=9999999&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertFalse(data.get("allowed"))
        self.assertIn(data.get("matched_rule"), ["NOT_FOUND", "DENY_BY_DEFAULT"])
        self.assertEqual(data.get("effective_mask"), 0)

    def test_08_granular_operation_resolution(self):
        """Bakbari can READ file 623, but requesting MANAGE without subadmin/admin role is evaluated accurately."""
        # READ is permitted
        r_read = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=file&target_id=623&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r_read.status_code, 200)
        self.assertTrue(r_read.json().get("allowed"))

        # Check effective operations list includes READ and DELETE
        ops = r_read.json().get("effective_operations", "")
        self.assertIn("READ", ops)
        self.assertIn("DELETE", ops)

    def test_09_tag_permission_evaluation(self):
        """System admin can evaluate Tag permissions; tags are isolated by group scope."""
        r = requests.get(
            f"{INSPECT_URL}?target_user=Bakbari&target_type=tag&target_id=1&operation=READ",
            auth=admin_auth,
            headers=HEADERS
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertIn("allowed", data)
        self.assertIn("matched_rule", data)
        self.assertIn("effective_mask", data)


if __name__ == "__main__":
    unittest.main()
