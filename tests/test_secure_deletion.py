#!/usr/bin/env python3
"""
Test Suite: Secure System Administrator File & Folder Deletion (Requirement 26)
Enterprise Archive System - Nextcloud 34

Validates:
 1. Unauthenticated requests to /api/resource/delete are rejected (HTTP 401).
 2. Non-admin users attempting deletion are rejected (HTTP 403 Forbidden).
 3. Attempting to delete without resource identifier returns HTTP 400 Bad Request.
 4. Guard: Root archive paths ('/Enterprise_Archive') cannot be deleted (HTTP 400 Bad Request).
 5. System Administrator can permanently delete an archive file (HTTP 200 OK).
 6. Cascading cleanup: File deletion removes document metadata, ownership, and grants.
 7. Transactional Reliable Audit: FILE_DELETE event logged in archive_permission_audit with permissions=8.
 8. System Administrator can permanently delete an archive directory (HTTP 200 OK).
 9. Cascading cleanup: Folder deletion removes all descendant files, their metadata, and their grants.
10. Transactional Reliable Audit: FOLDER_DELETE event logged in archive_permission_audit with permissions=8.
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

HEADERS_JSON = {
    "Content-Type": "application/json",
    "OCS-APIRequest": "true",
    "Accept": "application/json",
}

def run_sql(query):
    cmd = [
        "docker", "exec", "archive_db",
        "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", query
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"SQL failed: {res.stderr}")
    return res.stdout.strip()


class TestSecureDeletion(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        # Verify connectivity
        res = requests.get(f"{API_BASE}/user-role", auth=admin_auth, headers=HEADERS_JSON)
        assert res.status_code == 200, f"Cannot connect to Nextcloud API: {res.status_code}"

    def test_01_unauthenticated_deletion_rejected(self):
        """Unauthenticated requests to delete resource must return 401."""
        url = f"{API_BASE}/resource/delete"
        res = requests.post(url, json={"file_id": 99999}, headers=HEADERS_JSON)
        self.assertEqual(res.status_code, 401, f"Expected 401, got {res.status_code}: {res.text}")

    def test_02_non_admin_deletion_forbidden(self):
        """Non-admin user (e.g. Bakbari) cannot delete archive files/folders (403 Forbidden)."""
        url = f"{API_BASE}/resource/delete"
        res = requests.post(url, auth=soc_auth, json={"file_id": 99999}, headers=HEADERS_JSON)
        self.assertEqual(res.status_code, 403, f"Expected 403, got {res.status_code}: {res.text}")
        data = res.json()
        self.assertEqual(data.get("code"), "FORBIDDEN")

    def test_03_missing_resource_parameters_rejected(self):
        """Request without file_id or folder_path returns 400 Bad Request."""
        url = f"{API_BASE}/resource/delete"
        res = requests.post(url, auth=admin_auth, json={}, headers=HEADERS_JSON)
        self.assertEqual(res.status_code, 400, f"Expected 400, got {res.status_code}: {res.text}")

    def test_04_root_archive_deletion_protected(self):
        """Root archive folder 'Enterprise_Archive' cannot be deleted."""
        url = f"{API_BASE}/resource/delete"
        res = requests.post(
            url,
            auth=admin_auth,
            json={"folder_path": "Enterprise_Archive", "is_dir": True},
            headers=HEADERS_JSON
        )
        self.assertEqual(res.status_code, 400, f"Expected 400, got {res.status_code}: {res.text}")
        self.assertIn("ریشه بایگانی", res.json().get("message", ""))

    def test_05_admin_delete_file_with_cascading_cleanup_and_audit(self):
        """System Admin can delete a file; metadata, ownership, grants and audit are handled."""
        ts = int(time.time())
        filename = f"test_del_file_{ts}.txt"
        dav_path = f"{WEBDAV_BASE}/{ADMIN_USER}/Enterprise_Archive/SOC/{filename}"
        
        # 1. Upload test file
        content = f"Test deletion content generated at {ts}\n"
        up_res = requests.put(dav_path, auth=admin_auth, data=content.encode("utf-8"))
        self.assertIn(up_res.status_code, [200, 201, 204], f"Upload failed: {up_res.status_code}")

        # 2. Get file ID
        fid_str = run_sql(f"SELECT fileid FROM oc_filecache WHERE path LIKE '%{filename}' LIMIT 1;")
        self.assertTrue(fid_str.isdigit(), f"File ID not found in filecache for {filename}")
        file_id = int(fid_str)

        # 3. Add document metadata via service/API
        meta_res = requests.post(
            f"{API_BASE}/metadata/{file_id}",
            auth=admin_auth,
            json={
                "subject": f"سند آزمایشی حذف {ts}",
                "document_number": f"DEL-{ts}",
                "confidentiality": "confidential"
            },
            headers=HEADERS_JSON
        )
        self.assertEqual(meta_res.status_code, 200, f"Metadata creation failed: {meta_res.text}")

        # Verify metadata exists in DB
        meta_cnt = run_sql(f"SELECT COUNT(*) FROM oc_archive_document_metadata WHERE file_id = {file_id};")
        self.assertEqual(meta_cnt, "1", "Metadata record was not inserted")

        # Verify file exists on WebDAV
        check_res = requests.get(dav_path, auth=admin_auth)
        self.assertEqual(check_res.status_code, 200, "File should exist before deletion")

        # 4. Perform Secure Deletion via API
        del_res = requests.post(
            f"{API_BASE}/resource/delete",
            auth=admin_auth,
            json={"file_id": file_id},
            headers=HEADERS_JSON
        )
        self.assertEqual(del_res.status_code, 200, f"Deletion failed: {del_res.status_code} {del_res.text}")
        del_data = del_res.json()
        self.assertEqual(del_data.get("status"), "success")
        self.assertEqual(del_data.get("deleted_id"), file_id)

        # 5. Verify physical file is gone
        check_after = requests.get(dav_path, auth=admin_auth)
        self.assertEqual(check_after.status_code, 404, "File must return 404 after deletion")

        # 6. Verify cascading cleanup: metadata record deleted
        meta_after = run_sql(f"SELECT COUNT(*) FROM oc_archive_document_metadata WHERE file_id = {file_id};")
        self.assertEqual(meta_after, "0", "Metadata record must be cleaned up")

        # 7. Verify cascading cleanup: ownership record deleted
        own_after = run_sql(f"SELECT COUNT(*) FROM oc_archive_file_ownership WHERE file_id = {file_id};")
        self.assertEqual(own_after, "0", "Ownership record must be cleaned up")

        # 8. Verify cascading cleanup: grants purged
        grants_after = run_sql(f"SELECT COUNT(*) FROM oc_archive_file_grants WHERE file_id = {file_id};")
        self.assertEqual(grants_after, "0", "Grants must be cleaned up")

        # 9. Verify Reliable Audit Trail
        audit_row = run_sql(
            f"SELECT action, permissions, result FROM oc_archive_permission_audit "
            f"WHERE file_id = {file_id} AND action = 'FILE_DELETE' ORDER BY id DESC LIMIT 1;"
        )
        self.assertTrue(audit_row, "Audit row for FILE_DELETE was not found")
        self.assertIn("FILE_DELETE", audit_row)
        self.assertIn("8", audit_row)
        self.assertIn("success", audit_row)

    def test_06_admin_delete_folder_with_recursive_cleanup_and_audit(self):
        """System Admin can delete a folder; recursive children, metadata and audit are handled."""
        ts = int(time.time())
        folder_name = f"test_del_folder_{ts}"
        dav_folder = f"{WEBDAV_BASE}/{ADMIN_USER}/Enterprise_Archive/SOC/{folder_name}"
        
        # 1. Create directory via MKCOL
        mk_res = requests.request("MKCOL", dav_folder, auth=admin_auth)
        self.assertIn(mk_res.status_code, [200, 201, 204], f"MKCOL failed: {mk_res.status_code}")

        # 2. Upload child file inside the directory
        child_filename = f"child_{ts}.txt"
        dav_child = f"{dav_folder}/{child_filename}"
        up_res = requests.put(dav_child, auth=admin_auth, data=b"Child content\n")
        self.assertIn(up_res.status_code, [200, 201, 204], f"Child upload failed: {up_res.status_code}")

        # 3. Get child file ID and folder ID
        child_id_str = run_sql(f"SELECT fileid FROM oc_filecache WHERE path LIKE '%{child_filename}' LIMIT 1;")
        self.assertTrue(child_id_str.isdigit(), f"Child ID not found for {child_filename}")
        child_id = int(child_id_str)

        folder_id_str = run_sql(f"SELECT fileid FROM oc_filecache WHERE path LIKE '%{folder_name}' LIMIT 1;")
        self.assertTrue(folder_id_str.isdigit(), f"Folder ID not found for {folder_name}")
        folder_id = int(folder_id_str)

        # 4. Attach metadata to child file
        meta_res = requests.post(
            f"{API_BASE}/metadata/{child_id}",
            auth=admin_auth,
            json={
                "subject": f"متادیتای فایل درون پوشه {ts}",
                "document_number": f"CH-{ts}",
                "confidentiality": "normal"
            },
            headers=HEADERS_JSON
        )
        self.assertEqual(meta_res.status_code, 200, f"Child metadata failed: {meta_res.text}")

        # 5. Delete folder via API
        del_res = requests.post(
            f"{API_BASE}/resource/delete",
            auth=admin_auth,
            json={"file_id": folder_id, "is_dir": True},
            headers=HEADERS_JSON
        )
        self.assertEqual(del_res.status_code, 200, f"Folder delete failed: {del_res.status_code} {del_res.text}")
        del_data = del_res.json()
        self.assertEqual(del_data.get("status"), "success")
        self.assertTrue(del_data.get("is_folder"))

        # 6. Verify folder is physically gone
        check_fld = requests.request("PROPFIND", dav_folder, auth=admin_auth)
        self.assertEqual(check_fld.status_code, 404, "Folder must return 404 after deletion")

        # 7. Verify child file is physically gone
        check_child = requests.get(dav_child, auth=admin_auth)
        self.assertEqual(check_child.status_code, 404, "Child file must return 404 after deletion")

        # 8. Verify child metadata was cleaned up
        child_meta_cnt = run_sql(f"SELECT COUNT(*) FROM oc_archive_document_metadata WHERE file_id = {child_id};")
        self.assertEqual(child_meta_cnt, "0", "Child metadata must be cleaned up")

        # 9. Verify folder audit trail
        audit_fld = run_sql(
            f"SELECT action, permissions, result FROM oc_archive_permission_audit "
            f"WHERE file_id = {folder_id} AND action = 'FOLDER_DELETE' ORDER BY id DESC LIMIT 1;"
        )
        self.assertTrue(audit_fld, "Audit row for FOLDER_DELETE was not found")
        self.assertIn("FOLDER_DELETE", audit_fld)
        self.assertIn("8", audit_fld)
        self.assertIn("success", audit_fld)


if __name__ == "__main__":
    unittest.main()
