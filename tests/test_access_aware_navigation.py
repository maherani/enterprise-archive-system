#!/usr/bin/env python3
"""
Test Suite: Access-Aware Global Navigation (Requirement 16)
Enterprise Archive System - Nextcloud 34

Validates:
1. Backend Resource API (/api/nav/resources):
   - Admin receives full department catalog under Enterprise_Archive.
   - SOC user (Bakbari) receives only authorized scopes (SOC, etc.) with ZERO LEAKAGE of CERT or other departments.
   - CERT user (maherani) receives only authorized scopes (CERT, etc.) with ZERO LEAKAGE of SOC or other departments.
   - Unauthenticated requests return 401 Unauthorized.
2. Frontend Assets and Global Inclusion:
   - global_archive_nav.css and global_archive_nav.js are served with HTTP 200 OK.
   - Included in both Archive Portal and Files app views.
3. DOM & Single-Row Navigation Specifications:
   - Contains authorized scope chips (همه اسناد, Enterprise_Archive, and user departments).
   - Dynamic active chip highlighting.
   - Current Path and Copy Path elements are strictly excluded per design refinement.
4. Layout Integrity & Vertical Scroll:
   - No breaking generic #content rules.
   - Preserves vertical scrolling.
"""

import unittest
import requests
import json
import subprocess

BASE_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"

class TestAccessAwareNavigation(unittest.TestCase):

    def test_01_api_unauthenticated_returns_401(self):
        """Verify unauthenticated access to /api/nav/resources is rejected with 401."""
        resp = requests.get(
            f"{BASE_URL}/index.php/apps/archive_autotag/api/nav/resources",
            headers={"OCS-APIREQUEST": "true", "Accept": "application/json"}
        )
        self.assertIn(resp.status_code, [401, 403])

    def test_02_api_admin_receives_all_resources(self):
        """Verify admin user receives all departments under Enterprise_Archive."""
        resp = requests.get(
            f"{BASE_URL}/index.php/apps/archive_autotag/api/nav/resources",
            auth=(ADMIN_USER, ADMIN_PASS),
            headers={"OCS-APIREQUEST": "true", "Accept": "application/json"}
        )
        self.assertEqual(resp.status_code, 200)
        data = resp.json()
        self.assertEqual(data.get("status"), "success")
        self.assertEqual(data.get("root_folder"), "Enterprise_Archive")

        item_ids = [item["id"] for item in data.get("items", [])]
        # Base items
        self.assertIn("all", item_ids)
        self.assertIn("root", item_ids)
        # Department folders
        self.assertIn("SOC", item_ids)
        self.assertIn("CERT", item_ids)

        # Admin user metadata
        user_meta = data.get("user", {})
        self.assertTrue(user_meta.get("is_admin"))
        self.assertEqual(user_meta.get("uid"), "admin")

    def test_03_api_soc_user_zero_leakage(self):
        """Verify SOC user (Bakbari) has access to SOC but CERT is strictly hidden."""
        resp = requests.get(
            f"{BASE_URL}/index.php/apps/archive_autotag/api/nav/resources",
            auth=(SOC_USER, SOC_PASS),
            headers={"OCS-APIREQUEST": "true", "Accept": "application/json"}
        )
        self.assertEqual(resp.status_code, 200)
        data = resp.json()
        self.assertEqual(data.get("status"), "success")

        item_ids = [item["id"] for item in data.get("items", [])]
        # Must have basic scopes
        self.assertIn("all", item_ids)
        self.assertIn("root", item_ids)
        self.assertIn("SOC", item_ids)

        # STRICT ZERO LEAKAGE: CERT must NOT be visible to SOC user
        self.assertNotIn("CERT", item_ids, "Security violation: CERT department leaked to SOC user!")
        self.assertNotIn("Network", item_ids, "Security violation: Network department leaked to SOC user!")

        user_meta = data.get("user", {})
        self.assertFalse(user_meta.get("is_admin"))
        self.assertIn("SOC", user_meta.get("groups", []))

    def test_04_api_cert_user_zero_leakage(self):
        """Verify CERT user (maherani) has access to CERT but SOC is strictly hidden."""
        resp = requests.get(
            f"{BASE_URL}/index.php/apps/archive_autotag/api/nav/resources",
            auth=(CERT_USER, CERT_PASS),
            headers={"OCS-APIREQUEST": "true", "Accept": "application/json"}
        )
        self.assertEqual(resp.status_code, 200)
        data = resp.json()
        self.assertEqual(data.get("status"), "success")

        item_ids = [item["id"] for item in data.get("items", [])]
        # Must have basic scopes
        self.assertIn("all", item_ids)
        self.assertIn("root", item_ids)
        self.assertIn("CERT", item_ids)

        # STRICT ZERO LEAKAGE: SOC must NOT be visible to CERT user
        self.assertNotIn("SOC", item_ids, "Security violation: SOC department leaked to CERT user!")

        user_meta = data.get("user", {})
        self.assertFalse(user_meta.get("is_admin"))
        self.assertIn("CERT", user_meta.get("groups", []))

    def test_05_frontend_assets_delivery_and_no_current_path(self):
        """Verify global_archive_nav assets are served and current path / copy path are omitted."""
        resp_css = requests.get(f"{BASE_URL}/custom_apps/archive_autotag/css/global_archive_nav.css")
        self.assertEqual(resp_css.status_code, 200)
        self.assertIn("#ea-global-nav-root", resp_css.text)
        self.assertIn("direction: rtl;", resp_css.text)
        self.assertIn(".ea-nav-items-track", resp_css.text)
        self.assertIn(".ea-nav-chip", resp_css.text)

        # Ensure current path & copy button classes are removed
        self.assertNotIn(".ea-breadcrumbs", resp_css.text)
        self.assertNotIn(".ea-btn-copy-path", resp_css.text)

        resp_js = requests.get(f"{BASE_URL}/custom_apps/archive_autotag/js/global_archive_nav.js")
        self.assertEqual(resp_js.status_code, 200)
        self.assertIn("Enterprise_Archive", resp_js.text)
        self.assertIn("ea-global-nav-root", resp_js.text)
        self.assertIn("updateActiveChip", resp_js.text)

        # Ensure breadcrumb rendering logic is removed
        self.assertNotIn("renderBreadcrumbs", resp_js.text)
        self.assertNotIn("ea-btn-copy-path", resp_js.text)

    def test_06_global_script_inclusion_in_pages(self):
        """Verify scripts are injected across both Portal and Files pages."""
        sess = requests.Session()
        sess.auth = (ADMIN_USER, ADMIN_PASS)

        resp_portal = sess.get(f"{BASE_URL}/index.php/apps/archive_autotag/")
        self.assertEqual(resp_portal.status_code, 200)
        self.assertIn("global_archive_nav.js", resp_portal.text)
        self.assertIn("global_archive_nav.css", resp_portal.text)

        resp_files = sess.get(f"{BASE_URL}/index.php/apps/files/files")
        self.assertEqual(resp_files.status_code, 200)
        self.assertIn("global_archive_nav.js", resp_files.text)
        self.assertIn("global_archive_nav.css", resp_files.text)

    def test_07_css_safety_and_non_destructive_layout(self):
        """Verify CSS contains no destructive global resets that would break scrolling."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/global_archive_nav.css"]
        css = subprocess.check_output(cmd).decode("utf-8")

        # Must not contain generic unscoped body { overflow: hidden } or #content { overflow: hidden }
        self.assertNotIn("overflow: hidden", css.lower())
        self.assertNotIn("overflow-y: hidden", css.lower())
        # All rules must be scoped to #ea-global-nav-root or .ea-
        for line in css.splitlines():
            line_str = line.strip()
            if line_str.endswith("{") and not line_str.startswith("@"):
                self.assertTrue(
                    line_str.startswith("#ea-global-nav-root") or line_str.startswith(".ea-"),
                    f"Found potentially leaking CSS selector: {line_str}"
                )

if __name__ == "__main__":
    unittest.main()
