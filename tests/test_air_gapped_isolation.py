#!/usr/bin/env python3
"""
Test Suite: Air-Gapped Isolation & External Services Lockdown
Enterprise Archive System - Nextcloud 34

Validates:
1. Core air-gapped configurations:
   - has_internet_connection is false.
   - appstoreenabled is false.
   - updatechecker is false.
   - lookup_server is empty.
   - knowledgebaseenabled is false.
   - federation incoming/outgoing is false.
2. External/Internet-connected apps disabled:
   - federation, nextcloud_announcements, survey_client, updatenotification,
     weather_status, firstrunwizard, support, sharebymail.
3. App store route /settings/apps strictly 403 for non-admin users.
4. External storage (files_external) is completely disabled (404 on API & route).
5. Public links and federated sharing are blocked in capabilities and backend API.
6. External documentation and help route (/settings/help) is blocked with 403.
7. User menu does not contain external Help or About wizard entries.
8. Nginx reverse proxy enforces strict local-only Content-Security-Policy (CSP).
9. Frontend filter rulesets purge external links and buttons.
"""

import unittest
import subprocess
import requests
import json
import base64
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

USER = "archive_user1"
USER_PASS = "User_Password_123!"
user_auth = HTTPBasicAuth(USER, USER_PASS)

class TestAirGappedIsolation(unittest.TestCase):

    def test_01_core_air_gapped_system_configurations(self):
        """Verify core Nextcloud parameters are strictly locked for offline/air-gapped operation."""
        checks = [
            ("has_internet_connection", "false"),
            ("appstoreenabled", "false"),
            ("updatechecker", "false"),
            ("lookup_server", ""),
            ("knowledgebaseenabled", "false"),
            ("sharing.federation.allow_outgoing", "false"),
            ("sharing.federation.allow_incoming", "false"),
        ]
        for key, expected in checks:
            cmd = ["docker", "exec", "-u", "www-data", "archive_app", "php", "occ", "config:system:get", key]
            proc = subprocess.run(cmd, capture_output=True, text=True)
            val = proc.stdout.strip()
            self.assertEqual(val, expected, f"System config '{key}' must be '{expected}', got: '{val}'")

    def test_02_internet_facing_apps_disabled(self):
        """Verify all internet-facing and telemetry apps are disabled."""
        cmd = ["docker", "exec", "-u", "www-data", "archive_app", "php", "occ", "app:list"]
        proc = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0)
        output = proc.stdout

        # Extract enabled apps
        enabled_section = output.split("Disabled:")[0] if "Disabled:" in output else output
        
        forbidden_apps = [
            "federation",
            "nextcloud_announcements",
            "survey_client",
            "updatenotification",
            "weather_status",
            "firstrunwizard",
            "support",
            "sharebymail",
        ]
        for app in forbidden_apps:
            self.assertNotIn(f"- {app}:", enabled_section, f"App '{app}' must NOT be in enabled apps!")

    def test_03_appstore_and_apps_route_forbidden_for_regular_users(self):
        """Regular users are strictly forbidden from /settings/apps via direct URL."""
        r1 = requests.get(f"{NEXTCLOUD_URL}/settings/apps", auth=user_auth)
        self.assertEqual(r1.status_code, 403)

        r2 = requests.get(f"{NEXTCLOUD_URL}/index.php/settings/apps", auth=user_auth)
        self.assertEqual(r2.status_code, 403)

    def test_04_external_storage_strictly_disabled(self):
        """External storage (files_external) API and route return 404."""
        r_api = requests.get(
            f"{NEXTCLOUD_URL}/ocs/v2.php/apps/files_external/api/v1/mounts",
            auth=user_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r_api.status_code, 404)

        r_route = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/files_external", auth=user_auth)
        self.assertEqual(r_route.status_code, 404)

    def test_05_public_and_federated_sharing_blocked(self):
        """Public link sharing and federated sharing are completely disabled."""
        r = requests.get(
            f"{NEXTCLOUD_URL}/ocs/v2.php/cloud/capabilities?format=json",
            auth=user_auth,
            headers={"OCS-APIRequest": "true"}
        )
        self.assertEqual(r.status_code, 200)
        data = r.json()
        caps = data.get("ocs", {}).get("data", {}).get("capabilities", {})
        files_sharing = caps.get("files_sharing", {})

        # Public sharing must be disabled
        public_sharing = files_sharing.get("public", {})
        self.assertFalse(public_sharing.get("enabled", True), "Public link sharing must be disabled")

        # Federation sharing must be disabled
        federation = files_sharing.get("federation", {})
        self.assertFalse(federation.get("outgoing", True), "Federation outgoing must be disabled")
        self.assertFalse(federation.get("incoming", True), "Federation incoming must be disabled")

        # sharebymail must be disabled
        self.assertNotIn("sharebymail", files_sharing, "sharebymail must not be present in sharing capabilities")

    def test_06_help_and_documentation_route_blocked(self):
        """External documentation route /settings/help is blocked with 403."""
        r1 = requests.get(f"{NEXTCLOUD_URL}/settings/help", auth=user_auth)
        self.assertEqual(r1.status_code, 403)

        r2 = requests.get(f"{NEXTCLOUD_URL}/index.php/settings/help", auth=user_auth)
        self.assertEqual(r2.status_code, 403)

    def test_07_user_menu_excludes_help_and_about_wizards(self):
        """User avatar menu excludes external help and firstrunwizard about links."""
        resp = requests.get(f"{NEXTCLOUD_URL}/", auth=user_auth)
        self.assertEqual(resp.status_code, 200)
        for line in resp.text.splitlines():
            if 'id="initial-state-core-settingsNavEntries"' in line:
                val = line.split('value="')[1].split('"')[0]
                nav_entries = json.loads(base64.b64decode(val).decode("utf-8"))
                self.assertNotIn("help", nav_entries, "Help entry must not be present in user menu")
                self.assertNotIn("firstrunwizard_about", nav_entries, "Firstrunwizard entry must not be present in user menu")

    def test_08_nginx_air_gapped_csp_enforced(self):
        """Nginx reverse proxy enforces strict local-only Content-Security-Policy."""
        resp = requests.get(f"{NEXTCLOUD_URL}/", auth=user_auth)
        csp = resp.headers.get("Content-Security-Policy", "")
        self.assertIn("connect-src 'self'", csp)
        self.assertIn("font-src 'self'", csp)
        self.assertIn("object-src 'none'", csp)
        self.assertNotIn("openstreetmap.org", csp)

    def test_09_frontend_filter_contains_air_gap_rules(self):
        """Frontend CSS and JS contain explicit air-gapped isolation purging rules."""
        cmd_css = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/app_menu_filter.css"]
        css = subprocess.check_output(cmd_css).decode("utf-8")
        self.assertIn("docs.nextcloud.com", css)
        self.assertIn("help.nextcloud.com", css)
        self.assertIn("apps.nextcloud.com", css)
        self.assertIn("pointer-events: none !important;", css)

        cmd_js = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/js/app_menu_filter.js"]
        js = subprocess.check_output(cmd_js).decode("utf-8")
        self.assertIn("Air-Gapped Isolation: Purge External Links", js)

if __name__ == "__main__":
    unittest.main()
