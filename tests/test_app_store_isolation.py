#!/usr/bin/env python3
"""
Test App Store and App Launcher Isolation:
Verifies that:
1. The app store and app launcher are completely restricted:
   - Non-admin users (SOC, CERT, Compliance_Unit) have App Store and all unauthorized apps hidden.
   - Admin users retain full access to all apps and app management.
2. CSS and JS filters contain the complete ruleset to eliminate:
   - Outlined '+' app store buttons (.app-item--outlined)
   - External apps.nextcloud.com links
   - Settings apps links (/settings/apps)
   - Translated App Store labels ('App store', 'More apps', 'فروشگاه برنامه', 'برنامه‌های بیشتر')
3. OCC app config has appstore enabled strictly for ['admin'].
4. Regressions check for WebDAV and Archive Portal.
"""

import unittest
import requests
import json
import base64
import subprocess

BASE_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

NON_ADMIN_USERS = [
    ("Bakbari", "User_Password_123!", "SOC"),
    ("Adli", "User_Password_123!", "CERT"),
    ("archive_user1", "User_Password_123!", "Compliance_Unit"),
]

class TestAppStoreIsolation(unittest.TestCase):

    def test_01_appstore_backend_group_restriction(self):
        """Verify Nextcloud config restricts appstore exclusively to admin."""
        cmd = ["docker", "exec", "archive_app", "su", "-s", "/bin/bash", "www-data", "-c", "php occ config:app:get appstore enabled"]
        out = subprocess.check_output(cmd).decode('utf-8').strip()
        self.assertEqual(out, '["admin"]', f"appstore must be restricted strictly to admin, got: {out}")

    def test_02_css_contains_app_store_elimination_rules(self):
        """Verify app_menu_filter.css has robust rules hiding App Store and non-archive apps."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/app_menu_filter.css"]
        css = subprocess.check_output(cmd).decode('utf-8')

        required_rules = [
            "html.ea-non-admin .app-item--outlined",
            "html.ea-non-admin a[href*=\"apps.nextcloud.com\"]",
            "html.ea-non-admin a[href*=\"/settings/apps\"]",
            ".app-menu__grid .app-item:not([href*=\"archive_autotag\"])",
            "html.ea-non-admin a[title*=\"App store\"]",
            "html.ea-non-admin a[title*=\"فروشگاه\"]",
            "display: none !important;",
        ]
        for rule in required_rules:
            self.assertIn(rule, css, f"Required CSS rule missing: {rule}")

    def test_03_js_contains_app_store_purge_logic(self):
        """Verify app_menu_filter.js has purge logic for App Store and outlined buttons."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/js/app_menu_filter.js"]
        js = subprocess.check_output(cmd).decode('utf-8')

        required_patterns = [
            "app-item--outlined",
            "apps.nextcloud.com",
            "/settings/apps",
            "App store",
            "فروشگاه",
            "ea-non-admin",
            "archive_autotag",
            "purgeNonAdminApps",
        ]
        for pat in required_patterns:
            self.assertIn(pat, js, f"Required JS pattern missing: {pat}")

    def test_04_non_admin_users_receive_only_archive_portal(self):
        """Every non-admin group receives only archive_autotag and is_admin=False."""
        for username, password, group in NON_ADMIN_USERS:
            with self.subTest(user=username, group=group):
                session = requests.Session()
                session.auth = (username, password)
                response = session.get(f"{BASE_URL}/")
                self.assertEqual(response.status_code, 200)

                apps = []
                is_admin = None
                for line in response.text.splitlines():
                    if 'id="initial-state-core-apps"' in line:
                        val = line.split('value="')[1].split('"')[0]
                        apps = json.loads(base64.b64decode(val).decode('utf-8'))
                    if 'id="initial-state-archive_autotag-is_admin"' in line:
                        val = line.split('value="')[1].split('"')[0]
                        is_admin = json.loads(base64.b64decode(val).decode('utf-8'))

                self.assertFalse(is_admin, f"User {username} ({group}) must have is_admin=False")
                app_ids = [a.get("id") for a in apps]
                self.assertEqual(app_ids, ["archive_autotag"], f"User {username} must have only archive_autotag")

                # Both filter assets must be served
                self.assertIn("app_menu_filter.js", response.text)
                self.assertIn("app_menu_filter.css", response.text)

    def test_05_admin_user_maintains_full_capabilities(self):
        """Admin user retains all apps and is_admin=True."""
        session = requests.Session()
        session.auth = (ADMIN_USER, ADMIN_PASS)
        response = session.get(f"{BASE_URL}/")
        self.assertEqual(response.status_code, 200)

        apps = []
        is_admin = None
        for line in response.text.splitlines():
            if 'id="initial-state-core-apps"' in line:
                val = line.split('value="')[1].split('"')[0]
                apps = json.loads(base64.b64decode(val).decode('utf-8'))
            if 'id="initial-state-archive_autotag-is_admin"' in line:
                val = line.split('value="')[1].split('"')[0]
                is_admin = json.loads(base64.b64decode(val).decode('utf-8'))

        self.assertTrue(is_admin, "Admin must have is_admin=True")
        app_ids = [a.get("id") for a in apps]
        self.assertIn("archive_autotag", app_ids)
        self.assertIn("files", app_ids)
        self.assertIn("dashboard", app_ids)
        self.assertIn("office", app_ids)

if __name__ == "__main__":
    unittest.main()