#!/usr/bin/env python3
"""
Test Suite: Admin Group Sharing, File Management & Tag Isolation (Requirement 24)
Enterprise Archive System - Nextcloud 34

Validates:
 1. Admin shares file with group (SOC, Read-only).
 2. Admin shares folder with group (Compliance_Unit, Read+Create).
 3. Group member accesses shared folder via WebDAV PROPFIND.
 4. Dynamic membership: adding user to group grants immediate access.
 5. Dynamic membership: removing user from group revokes access.
 6. Read-only group member cannot upload to shared folder (HTTP 403 Forbidden).
 7. Authorized group member with Create permission uploads file (HTTP 201/204).
 8. Uploaded file receives group tag automatically.
 9. Subsequent uploads reuse existing group tag without duplicates.
10. Group Admin manages tags within their own group scope.
11. Group Admin cross-group tag operations strictly forbidden (HTTP 403).
12. System Admin membership does not bypass cross-group tag isolation without subadmin role.
13. Non-admin user cannot share resources (HTTP 403).
14. Non-authorized user tag operations rejected (HTTP 403).
15. Duplicate share updates permissions deterministically (no duplicates / 409).
16. Removing share removes grant and share record without deleting physical resource.
"""

import os
import sys
import time
import unittest
import requests
import subprocess
from requests.auth import HTTPBasicAuth

BASE_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost")
WEBDAV_BASE = f"{BASE_URL}/remote.php/dav/files"
API_BASE = f"{BASE_URL}/index.php/apps/archive_autotag/api"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

COMP_USER = "archive_user1"
COMP_PASS = "User_Password_123!"
comp_auth = HTTPBasicAuth(COMP_USER, COMP_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

DYN_USER = "Ftaheri"
DYN_PASS = "User_Password_123!"
dyn_auth = HTTPBasicAuth(DYN_USER, DYN_PASS)

HEADERS_JSON = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
    "Content-Type": "application/json"
}


def run_occ(cmd: str) -> str:
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    res = subprocess.run(full_cmd, shell=True, capture_output=True, text=True)
    return res.stdout.strip()


def run_sql(sql: str) -> str:
    res = subprocess.run(
        ["docker", "exec", "archive_db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", sql],
        capture_output=True, text=True
    )
    return res.stdout.strip()


def ensure_passwords():
    for u, p in [(ADMIN_USER, ADMIN_PASS), (SOC_USER, SOC_PASS), (COMP_USER, COMP_PASS), (CERT_USER, CERT_PASS), (DYN_USER, DYN_PASS)]:
        cmd = f"docker exec -u www-data -e OC_PASS='{p}' archive_app php occ user:resetpassword --password-from-env {u}"
        subprocess.run(cmd, shell=True, capture_output=True, text=True)


class TestAdminGroupSharing(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        print("\n" + "=" * 70)
        print(" INITIALIZING REQUIREMENT 24: ADMIN GROUP SHARING TEST SUITE")
        print("=" * 70)
        ensure_passwords()

        # Create test folder in admin's root via WebDAV MKCOL
        cls.test_folder_name = "Test_Admin_Share_Folder"
        r_mk = requests.request("MKCOL", f"{WEBDAV_BASE}/{ADMIN_USER}/{cls.test_folder_name}", auth=admin_auth)
        # 201 Created or 405 (if exists)
        cls.test_ro_folder = "Test_Admin_RO_Folder"
        requests.request("MKCOL", f"{WEBDAV_BASE}/{ADMIN_USER}/{cls.test_ro_folder}", auth=admin_auth)

        # Upload a test file into admin's root
        cls.test_file_name = "test_share_doc.txt"
        r_put = requests.put(
            f"{WEBDAV_BASE}/{ADMIN_USER}/{cls.test_file_name}",
            data="Enterprise confidential document for group sharing tests.",
            auth=admin_auth
        )
        assert r_put.status_code in [201, 204], f"Failed to upload test file: {r_put.status_code}"

        # Rescan admin files
        run_occ("files:scan admin")

        # Get file IDs from DB
        cls.file_id = int(run_sql(f"SELECT fileid FROM oc_filecache WHERE path = 'files/{cls.test_file_name}';") or 0)
        cls.folder_id = int(run_sql(f"SELECT fileid FROM oc_filecache WHERE path = 'files/{cls.test_folder_name}';") or 0)
        cls.ro_folder_id = int(run_sql(f"SELECT fileid FROM oc_filecache WHERE path = 'files/{cls.test_ro_folder}';") or 0)

        print(f"  Test File ID: {cls.file_id}")
        print(f"  Test Folder ID: {cls.folder_id}")
        print(f"  Test RO Folder ID: {cls.ro_folder_id}")

    @classmethod
    def tearDownClass(cls):
        print("\n" + "=" * 70)
        print(" CLEANING UP TEST ARTIFACTS")
        print("=" * 70)
        # Delete shares
        run_sql(f"DELETE FROM oc_share WHERE item_source IN ('{cls.file_id}', '{cls.folder_id}', '{cls.ro_folder_id}');")
        run_sql(f"DELETE FROM oc_archive_file_grants WHERE file_id IN ({cls.file_id}, {cls.folder_id}, {cls.ro_folder_id});")
        # Ensure DYN_USER is removed from Compliance_Unit
        run_occ(f"group:removeuser Compliance_Unit {DYN_USER}")

    def test_01_admin_shares_file_with_group(self):
        """1. Admin shares file with group SOC (Read-only = 1)."""
        print("\n[Test 1] Admin shares file with group 'SOC' (Read-only)...")
        r = requests.post(
            f"{API_BASE}/share/group",
            json={"resource_id": self.file_id, "group_id": "SOC", "permissions": 1},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 200, f"Share failed: {r.text}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        self.assertIn("share_id", data)

        # Verify oc_share entry
        share_type = run_sql(f"SELECT share_type FROM oc_share WHERE id = {data['share_id']};")
        self.assertEqual(share_type, "1", "Share type in oc_share must be 1 (group share)")

        # Verify oc_archive_file_grants entry
        grant = run_sql(f"SELECT grantee_id FROM oc_archive_file_grants WHERE file_id = {self.file_id} AND grantee_id = 'SOC';")
        self.assertEqual(grant, "SOC", "Grant not found in oc_archive_file_grants")
        print("  ✔ File successfully shared with group SOC.")

    def test_02_admin_shares_folder_with_group(self):
        """2. Admin shares folder with group Compliance_Unit (permissions=31 All)."""
        print("\n[Test 2] Admin shares folder with group 'Compliance_Unit' (Read+Create+Update=31)...")
        r = requests.post(
            f"{API_BASE}/share/group",
            json={"resource_id": self.folder_id, "group_id": "Compliance_Unit", "permissions": 31},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 200, f"Folder share failed: {r.text}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        print("  ✔ Folder successfully shared with group Compliance_Unit.")

    def test_03_group_member_accesses_shared_folder(self):
        """3. Group member (archive_user1) accesses shared folder via WebDAV PROPFIND."""
        print("\n[Test 3] Group member accesses shared folder via WebDAV...")
        # Check listing of user's root or shared folder
        r = requests.request(
            "PROPFIND",
            f"{WEBDAV_BASE}/{COMP_USER}/{self.test_folder_name}",
            auth=comp_auth,
            headers={"Depth": "1"}
        )
        self.assertEqual(r.status_code, 207, f"Expected 207 Multi-Status, got {r.status_code}")
        print("  ✔ Group member successfully accessed shared folder (HTTP 207 Multi-Status).")

    def test_04_dynamic_membership_add_user_gains_access(self):
        """4. Adding user to group grants immediate access without permission duplication."""
        print("\n[Test 4] Dynamic membership: adding user to group grants access...")
        run_occ(f"group:adduser Compliance_Unit {DYN_USER}")

        r = requests.request(
            "PROPFIND",
            f"{WEBDAV_BASE}/{DYN_USER}/{self.test_folder_name}",
            auth=dyn_auth,
            headers={"Depth": "0"}
        )
        self.assertEqual(r.status_code, 207, f"Newly added user failed to access folder: {r.status_code}")
        print("  ✔ Dynamic membership addition immediately granted access (HTTP 207).")

    def test_05_dynamic_membership_remove_user_loses_access(self):
        """5. Removing user from group revokes access."""
        print("\n[Test 5] Dynamic membership: removing user from group revokes access...")
        run_occ(f"group:removeuser Compliance_Unit {DYN_USER}")

        r = requests.request(
            "PROPFIND",
            f"{WEBDAV_BASE}/{DYN_USER}/{self.test_folder_name}",
            auth=dyn_auth,
            headers={"Depth": "0"}
        )
        self.assertIn(r.status_code, [403, 404], f"Removed user should not access folder, got {r.status_code}")
        print(f"  ✔ Removed user access correctly rejected with HTTP {r.status_code}.")

    def test_06_readonly_group_cannot_upload(self):
        """6. Read-only group member cannot upload to shared folder (HTTP 403 Forbidden)."""
        print("\n[Test 6] Read-only group member attempts upload (Fail-Closed)...")
        # Share RO folder with group SOC (perms = 1)
        r_share = requests.post(
            f"{API_BASE}/share/group",
            json={"resource_id": self.ro_folder_id, "group_id": "SOC", "permissions": 1},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r_share.status_code, 200)

        # Bakbari (in SOC) attempts upload
        r_put = requests.put(
            f"{WEBDAV_BASE}/{SOC_USER}/{self.test_ro_folder}/illegal_file.txt",
            data="Should be rejected",
            auth=soc_auth
        )
        self.assertEqual(r_put.status_code, 403, f"Expected 403 Forbidden, got {r_put.status_code}")
        print("  ✔ Read-only group upload strictly forbidden (HTTP 403).")

    def test_07_create_perm_group_can_upload(self):
        """7. Authorized group member with Create permission uploads file."""
        print("\n[Test 7] Group member with Create permission uploads file...")
        upload_content = "File uploaded by Compliance_Unit member."
        r_put = requests.put(
            f"{WEBDAV_BASE}/{COMP_USER}/{self.test_folder_name}/compliance_report.txt",
            data=upload_content,
            auth=comp_auth
        )
        self.assertIn(r_put.status_code, [201, 204], f"Expected 201/204, got {r_put.status_code}")
        print(f"  ✔ File uploaded successfully with HTTP {r_put.status_code}.")

    def test_08_uploaded_file_receives_group_tag(self):
        """8. Uploaded file receives group tag automatically."""
        print("\n[Test 8] Verifying automatic group tag assignment...")
        # Get uploaded file id from DB
        uploaded_fid = run_sql("SELECT fileid FROM oc_filecache WHERE path LIKE '%/compliance_report.txt';")
        self.assertTrue(uploaded_fid, "Uploaded file not found in filecache")

        # Get system tags for this file
        tag_names = run_sql(f"""
            SELECT t.name FROM oc_systemtag t 
            JOIN oc_systemtag_object_mapping m ON t.id = m.systemtagid 
            WHERE m.objectid = '{uploaded_fid}';
        """).splitlines()

        print(f"  Tags assigned to file {uploaded_fid}: {tag_names}")
        # Must have Compliance_Unit or parent folder tag
        has_group_tag = any("Compliance_Unit" in t for t in tag_names)
        self.assertTrue(has_group_tag, f"Expected group tag 'Compliance_Unit' in tags: {tag_names}")
        print("  ✔ Automatic group tagging verified on uploaded file.")

    def test_09_existing_tag_reused_no_duplicates(self):
        """9. Subsequent uploads reuse existing group tag without duplicates."""
        print("\n[Test 9] Uploading second file to verify tag reuse...")
        r_put = requests.put(
            f"{WEBDAV_BASE}/{COMP_USER}/{self.test_folder_name}/compliance_report_2.txt",
            data="Second compliance document",
            auth=comp_auth
        )
        self.assertIn(r_put.status_code, [201, 204])

        # Check count of systemtags named 'Compliance_Unit'
        count = run_sql("SELECT count(*) FROM oc_systemtag WHERE name = 'Compliance_Unit';")
        self.assertEqual(int(count), 1, f"Expected exactly 1 tag named 'Compliance_Unit', found {count}")
        print("  ✔ Tag reused deterministically without duplicate tag entries.")

    def test_10_group_admin_manages_own_tags(self):
        """10. Group Admin manages tags within their own group scope."""
        print("\n[Test 10] Group Admin (Bakbari) creates tag for group 'SOC'...")
        tag_name = f"SOC_Test_Tag_{int(time.time())}"
        r = requests.post(
            f"{API_BASE}/group-tags/create",
            json={"group_id": "SOC", "name": tag_name},
            auth=soc_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 200, f"Expected 200 OK, got {r.status_code}: {r.text}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        print(f"  ✔ Group Admin successfully created tag '{tag_name}'.")

    def test_11_cross_group_tag_operation_forbidden(self):
        """11. Group Admin cross-group tag operations strictly forbidden (HTTP 403)."""
        print("\n[Test 11] Group Admin attempts cross-group tag creation...")
        r = requests.post(
            f"{API_BASE}/group-tags/create",
            json={"group_id": "CERT", "name": "Illegal_SOC_In_CERT"},
            auth=soc_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 403, f"Expected 403 Forbidden, got {r.status_code}")
        print("  ✔ Cross-group tag creation blocked with HTTP 403 Forbidden.")

    def test_12_admin_membership_does_not_leak_cross_group_tag(self):
        """12. System Admin without subadmin role cannot manage group tags (no implicit bypass)."""
        print("\n[Test 12] System Admin attempts group tag creation without subadmin role...")
        r = requests.post(
            f"{API_BASE}/group-tags/create",
            json={"group_id": "SOC", "name": "Admin_Bypass_Tag"},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 403, f"Expected 403 Forbidden, got {r.status_code}")
        print("  ✔ Implicit system admin bypass strictly blocked with HTTP 403 Forbidden.")

    def test_13_unauthorized_user_cannot_share_resources(self):
        """13. Non-admin user cannot share resources (HTTP 403 Forbidden)."""
        print("\n[Test 13] Non-admin user attempts to share resource...")
        r = requests.post(
            f"{API_BASE}/share/group",
            json={"resource_id": self.file_id, "group_id": "Compliance_Unit", "permissions": 1},
            auth=soc_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 403, f"Expected 403 Forbidden, got {r.status_code}")
        print("  ✔ Non-admin resource sharing blocked with HTTP 403 Forbidden.")

    def test_14_unauthorized_tag_operations_rejected(self):
        """14. Non-authorized user tag operations rejected (HTTP 403 Forbidden)."""
        print("\n[Test 14] Regular user attempts to delete a group tag...")
        r = requests.post(
            f"{API_BASE}/group-tags/delete",
            json={"group_id": "SOC", "tag_id": 99999},
            auth=comp_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r.status_code, 403, f"Expected 403 Forbidden, got {r.status_code}")
        print("  ✔ Unauthorized tag operation rejected with HTTP 403 Forbidden.")

    def test_15_duplicate_share_updates_permissions_deterministically(self):
        """15. Duplicate share updates permissions deterministically without errors or extra rows."""
        print("\n[Test 15] Duplicate share update test...")
        # Update file share permissions from 1 to 3
        r_update = requests.post(
            f"{API_BASE}/share/group",
            json={"resource_id": self.file_id, "group_id": "SOC", "permissions": 3},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r_update.status_code, 200, f"Expected 200, got {r_update.status_code}")
        data = r_update.json()
        self.assertIn(data.get("action"), ["updated", "GROUP_SHARE_UPDATED"])

        # Verify only 1 share entry exists for this file and group
        count = run_sql(f"SELECT count(*) FROM oc_share WHERE item_source = '{self.file_id}' AND share_type = 1 AND share_with = 'SOC';")
        self.assertEqual(int(count), 1, f"Expected 1 share entry, found {count}")

        # Verify permissions updated in oc_share
        perms = run_sql(f"SELECT permissions FROM oc_share WHERE item_source = '{self.file_id}' AND share_type = 1 AND share_with = 'SOC';")
        self.assertEqual(int(perms), 3, f"Expected permissions 3, found {perms}")
        print("  ✔ Deterministic duplicate share update verified (no duplicates, permissions updated).")

    def test_16_removing_share_does_not_delete_physical_resource(self):
        """16. Removing share removes grant and share record without deleting physical resource."""
        print("\n[Test 16] Removing share and verifying physical resource retention...")
        r_del = requests.post(
            f"{API_BASE}/share/group/delete",
            json={"resource_id": self.file_id, "group_id": "SOC"},
            auth=admin_auth,
            headers=HEADERS_JSON
        )
        self.assertEqual(r_del.status_code, 200, f"Expected 200, got {r_del.status_code}")

        # Verify share entry deleted
        count = run_sql(f"SELECT count(*) FROM oc_share WHERE item_source = '{self.file_id}' AND share_type = 1 AND share_with = 'SOC';")
        self.assertEqual(int(count), 0, "Share entry was not removed from oc_share")

        # Verify file physically still exists in filecache and storage
        file_check = run_sql(f"SELECT fileid FROM oc_filecache WHERE fileid = {self.file_id};")
        self.assertEqual(str(self.file_id), file_check, "Physical resource filecache entry was improperly deleted!")

        # Verify admin can still download file via WebDAV
        r_get = requests.get(f"{WEBDAV_BASE}/{ADMIN_USER}/{self.test_file_name}", auth=admin_auth)
        self.assertEqual(r_get.status_code, 200, "Admin cannot access physical file after share removal!")
        print("  ✔ Share removed successfully while preserving physical resource.")


if __name__ == "__main__":
    unittest.main(verbosity=2)
