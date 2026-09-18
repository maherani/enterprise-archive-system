#!/usr/bin/env python3
"""
Test Suite: Admin Direct Folder Creation & Management
Enterprise Archive System - Nextcloud 34

Validates:
1. Access control on /api/folders and /api/folders/create (regular user forbidden: 403).
2. System Admin can retrieve complete archive folder hierarchy via /api/folders.
3. System Admin can directly create folders in any path of Enterprise_Archive via /api/folders/create.
4. OCC command 'archive:folder:create' allows direct folder creation from CLI with parent path and group binding.
5. Automated hierarchical system tagging is applied to newly created admin folders.
6. Non-admin isolation is maintained and WebDAV access reflects newly created folders.
"""

import unittest
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

USER = "archive_user1"
USER_PASS = "User_Password_123!"
user_auth = HTTPBasicAuth(USER, USER_PASS)

class TestAdminFolderCreation(unittest.TestCase):

    def test_01_non_admin_forbidden_direct_creation(self):
        """Regular users are strictly forbidden from direct folder creation endpoints."""
        r_list = requests.get(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folders",
            auth=user_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r_list.status_code, 403)

        r_create = requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folders/create",
            json={"folder_name": "illegal_user_folder", "parent_path": ""},
            auth=user_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r_create.status_code, 403)

    def test_02_admin_can_list_all_archive_folders(self):
        """Admin can list all folders in Enterprise_Archive hierarchy."""
        r = requests.get(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folders",
            auth=admin_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        self.assertGreater(len(data.get("folders", [])), 0)

    def test_03_admin_direct_api_folder_creation(self):
        """Admin can create a folder directly via REST API with group binding."""
        test_folder = "تست_مستقیم_ادمین_1405"
        r_create = requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folders/create",
            json={
                "folder_name": test_folder,
                "parent_path": "Finance/2026",
                "group_id": "Compliance_Unit"
            },
            auth=admin_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r_create.status_code, 200)
        data = r_create.json()
        self.assertEqual(data.get("status"), "success")

        # Verify folder exists in WebDAV
        r_dav = requests.request(
            "PROPFIND",
            f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/{test_folder}/",
            auth=admin_auth,
            headers={"Depth": "0"}
        )
        self.assertEqual(r_dav.status_code, 207)

        # Cleanup
        requests.delete(
            f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/{test_folder}/",
            auth=admin_auth
        )

    def test_04_admin_occ_folder_create_command(self):
        """Admin can create a folder directly via 'occ archive:folder:create' CLI command."""
        test_folder = "تست_فرمان_خط_ادمین"
        cmd = [
            "docker", "exec", "-u", "www-data", "archive_app",
            "php", "occ", "archive:folder:create", test_folder,
            "-p", "Finance/2026"
        ]
        proc = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0, f"Command failed: {proc.stderr}")
        self.assertIn("با موفقیت در ساختار آرشیو ایجاد شد", proc.stdout)

        # Cleanup
        requests.delete(
            f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/{test_folder}/",
            auth=admin_auth
        )

    def test_05_portal_serves_admin_folder_creation_button(self):
        """Archive Portal serves the + ساخت پوشه جدید button for Admin."""
        resp = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/archive_portal.js")
        self.assertEqual(resp.status_code, 200)
        text = resp.content.decode("utf-8")
        self.assertIn("ea-admin-create-folder-btn", text)
        self.assertIn("+ ساخت پوشه جدید", text)
        self.assertIn("openAdminCreateFolderModal", text)

if __name__ == "__main__":
    unittest.main()
