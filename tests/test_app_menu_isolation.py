#!/usr/bin/env python3
"""
Test App Menu and Navigation Isolation:
Verifies that for every non-admin group (SOC, CERT, Compliance_Unit, etc.),
only "بایگانی اسناد" (archive_autotag) is displayed in the navigation and app switcher.
Specifically ensures App store is removed for all non-admin groups and preserved only for admin.
"""

import unittest
import requests
import base64
import json

BASE_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

NON_ADMIN_USERS = [
    ("Bakbari", "User_Password_123!", "SOC"),
    ("Adli", "User_Password_123!", "CERT"),
    ("archive_user1", "User_Password_123!", "Compliance_Unit"),
]

class TestAppMenuIsolation(unittest.TestCase):
    def test_01_admin_user_sees_all_apps_and_appstore(self):
        """Admin user must see all enabled apps in core-apps and App store in settingsNavEntries."""
        session = requests.Session()
        session.auth = (ADMIN_USER, ADMIN_PASS)
        response = session.get(f"{BASE_URL}/")
        self.assertEqual(response.status_code, 200)

        apps = []
        is_admin = None
        settings_nav = {}
        for line in response.text.splitlines():
            if 'id="initial-state-core-apps"' in line:
                val = line.split('value="')[1].split('"')[0]
                apps = json.loads(base64.b64decode(val).decode('utf-8'))
            if 'id="initial-state-archive_autotag-is_admin"' in line:
                val = line.split('value="')[1].split('"')[0]
                is_admin = json.loads(base64.b64decode(val).decode('utf-8'))
            if 'id="initial-state-core-settingsNavEntries"' in line:
                val = line.split('value="')[1].split('"')[0]
                settings_nav = json.loads(base64.b64decode(val).decode('utf-8'))

        self.assertTrue(is_admin, "Admin user must have archive_autotag is_admin=True")
        app_ids = [a.get("id") for a in apps]
        print(f"Admin app IDs: {app_ids}")
        self.assertIn("archive_autotag", app_ids)
        self.assertIn("files", app_ids)
        self.assertIn("dashboard", app_ids)
        self.assertIn("office", app_ids)
        self.assertGreater(len(apps), 1, "Admin user must have multiple apps in core-apps")

        # Admin must have App store (core_apps) in settingsNavEntries
        self.assertIn("core_apps", settings_nav, "Admin must have core_apps (App store) available")
        self.assertEqual(settings_nav["core_apps"].get("href"), "/settings/apps")

    def test_02_non_admin_groups_only_see_archive_portal_and_no_appstore(self):
        """Non-admin users from SOC, CERT, Compliance_Unit must ONLY see archive_autotag and NO App store."""
        for username, password, group in NON_ADMIN_USERS:
            with self.subTest(user=username, group=group):
                session = requests.Session()
                session.auth = (username, password)
                response = session.get(f"{BASE_URL}/")
                self.assertEqual(response.status_code, 200)

                apps = []
                is_admin = None
                settings_nav = {}
                for line in response.text.splitlines():
                    if 'id="initial-state-core-apps"' in line:
                        val = line.split('value="')[1].split('"')[0]
                        apps = json.loads(base64.b64decode(val).decode('utf-8'))
                    if 'id="initial-state-archive_autotag-is_admin"' in line:
                        val = line.split('value="')[1].split('"')[0]
                        is_admin = json.loads(base64.b64decode(val).decode('utf-8'))
                    if 'id="initial-state-core-settingsNavEntries"' in line:
                        val = line.split('value="')[1].split('"')[0]
                        settings_nav = json.loads(base64.b64decode(val).decode('utf-8'))

                self.assertFalse(is_admin, f"User {username} in group {group} must have is_admin=False")
                app_ids = [a.get("id") for a in apps]
                print(f"User {username} ({group}) app IDs: {app_ids}")

                # Must contain ONLY archive_autotag
                self.assertEqual(len(apps), 1, f"Non-admin user {username} must have exactly 1 app in core-apps")
                self.assertEqual(apps[0].get("id"), "archive_autotag")
                self.assertEqual(apps[0].get("name"), "بایگانی اسناد")

                # App store / core_apps must NOT be present in settingsNavEntries for non-admins
                self.assertNotIn("core_apps", settings_nav, f"App store (core_apps) must NOT be present for {username}")
                self.assertNotIn("appstore", settings_nav, f"App store must NOT be present for {username}")

                # Forbidden apps must NOT be present
                for forbidden in ["files", "dashboard", "photos", "activity", "office", "appstore", "core_apps"]:
                    self.assertNotIn(forbidden, app_ids, f"App {forbidden} must NOT be visible to non-admin {username}")

    def test_03_non_admin_direct_files_page_still_isolated(self):
        """Visiting /apps/files/ directly as non-admin must still only show archive_autotag in navigation."""
        session = requests.Session()
        session.auth = ("Bakbari", "User_Password_123!")
        response = session.get(f"{BASE_URL}/apps/files/")
        self.assertEqual(response.status_code, 200)

        apps = []
        for line in response.text.splitlines():
            if 'id="initial-state-core-apps"' in line:
                val = line.split('value="')[1].split('"')[0]
                apps = json.loads(base64.b64decode(val).decode('utf-8'))

        app_ids = [a.get("id") for a in apps]
        self.assertEqual(len(apps), 1)
        self.assertEqual(apps[0].get("id"), "archive_autotag")

        # Verify app_menu_filter script and style are injected
        self.assertIn("app_menu_filter.js", response.text)
        self.assertIn("app_menu_filter.css", response.text)

    def test_04_webdav_remains_fully_functional(self):
        """WebDAV API (/remote.php/dav/files/) must remain 100% operational for non-admin users."""
        session = requests.Session()
        session.auth = ("Bakbari", "User_Password_123!")
        r = session.request("PROPFIND", f"{BASE_URL}/remote.php/dav/files/Bakbari/", headers={"Depth": "0"})
        self.assertEqual(r.status_code, 207, "WebDAV PROPFIND must return 207 Multi-Status")

if __name__ == "__main__":
    unittest.main()
