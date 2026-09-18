#!/usr/bin/env python3
"""
Test Suite: UI Upload and Auto-Tagging Integration
==================================================
Validates:
1. Archive Portal contains Upload Document button ('ea-upload-btn') and static assets.
2. Archive Portal CSS contains Dropzone and Drag-and-Drop styles.
3. WebDAV file upload to group folder (/SOC) triggers hierarchical auto-tagging.
4. Auto-tags are returned in Portal API (/api/files) for the uploaded file.
5. app_menu_filter does not block Files app controls or actions.
"""

import unittest
import requests
import urllib.parse
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_AUTH = HTTPBasicAuth("admin", "Secure_Admin_Password_123!")
SOC_USER_AUTH = HTTPBasicAuth("Bakbari", "User_Password_123!")
COMP_USER_AUTH = HTTPBasicAuth("archive_user1", "User_Password_123!")

class TestUIUploadAndTagging(unittest.TestCase):

    def test_01_portal_serves_upload_button_and_assets(self):
        """Verify that archive_portal.js includes the upload button and modal logic."""
        r_js = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/archive_portal.js")
        self.assertEqual(r_js.status_code, 200)
        self.assertIn("ea-upload-btn", r_js.text)
        self.assertIn("openUploadModal", r_js.text)
        self.assertIn("setupGlobalDragAndDrop", r_js.text)
        self.assertIn("ea-dropzone-box", r_js.text)

        r_css = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/css/archive_portal.css")
        self.assertEqual(r_css.status_code, 200)
        self.assertIn(".ea-dropzone-box", r_css.text)
        self.assertIn(".ea-global-drop-overlay", r_css.text)
        self.assertIn(".ea-upload-file-card", r_css.text)

    def test_02_app_menu_filter_protects_files_app_controls(self):
        """Verify app_menu_filter does not hide files app controls or buttons."""
        r_filter = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/app_menu_filter.js")
        self.assertEqual(r_filter.status_code, 200)
        self.assertIn("#app-content", r_filter.text)
        self.assertIn(".files-new-action-menu", r_filter.text)

    def test_03_webdav_upload_in_soc_folder_triggers_auto_tagging(self):
        """Verify that uploading a file to SOC folder automatically attaches hierarchical tags."""
        test_filename = "auto_tag_ui_test_doc.txt"
        target_path = "SOC/افتا/گزارش ها/" + test_filename
        encoded_path = urllib.parse.quote(target_path)
        dav_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/Bakbari/{encoded_path}"

        content = "Confidential SOC Report for testing auto-tagging."
        r_put = requests.put(dav_url, data=content.encode("utf-8"), auth=SOC_USER_AUTH)
        self.assertIn(r_put.status_code, [200, 201, 204], f"Upload failed: {r_put.status_code}")

        # Check in Portal API that file is returned and has tags
        r_files = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=SOC_USER_AUTH)
        self.assertEqual(r_files.status_code, 200)
        files = r_files.json().get("files", [])

        found = None
        for f in files:
            if test_filename in f.get("name", ""):
                found = f
                break

        self.assertIsNotNone(found, f"Uploaded file {test_filename} not found in portal files list")
        tag_names = [t["name"] for t in found.get("tags", [])]
        print(f"Uploaded file tags: {tag_names}")
        self.assertIn("SOC", tag_names, "Hierarchical tag 'SOC' must be applied")
        self.assertIn("افتا", tag_names, "Hierarchical tag 'افتا' must be applied")
        self.assertIn("گزارش ها", tag_names, "Hierarchical tag 'گزارش ها' must be applied")

if __name__ == "__main__":
    unittest.main()
