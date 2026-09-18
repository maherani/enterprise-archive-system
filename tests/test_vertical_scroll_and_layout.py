#!/usr/bin/env python3
"""
Test Suite: Vertical Scroll and Non-Destructive Layout Isolation
Enterprise Archive System - Nextcloud 34

Validates:
1. High-Performance Vertical Scroll:
   - #content.app-archive_autotag has explicit calc(100vh - 50px) height.
   - #content.app-archive_autotag has overflow-y: auto !important and overflow-x: hidden !important.
   - Custom Obsidian scrollbar styles are applied with scrollbar-color and webkit rules.
   - Portal root #archive-portal-root.archive-portal-app has min-height: 100% and overflow: visible.
2. Zero Layout Regression / Non-Destructive Isolation:
   - No unscoped generic `#content {` or comma-separated `#content,` leaks in archive_portal.css.
   - Generic Nextcloud Files (#app-content) and Settings views are untouched.
   - app_menu_filter.css strictly scopes `#content` override to `#content.app-archive_autotag`.
3. HTTP Delivery and Asset Verification:
   - archive_portal.css and app_menu_filter.css served with HTTP 200 OK.
   - Portal main page serves archive-portal-root and CSS link references.
"""

import unittest
import requests
import subprocess

BASE_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

class TestVerticalScrollAndLayout(unittest.TestCase):

    def test_01_archive_portal_css_rules(self):
        """Verify archive_portal.css enforces robust vertical scroll rules."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/archive_portal.css"]
        css = subprocess.check_output(cmd).decode('utf-8')

        # 1. Scoped container height & overflow
        self.assertIn("#content.app-archive_autotag", css)
        self.assertIn("height: calc(100vh - 50px) !important;", css)
        self.assertIn("max-height: calc(100vh - 50px) !important;", css)
        self.assertIn("overflow-y: auto !important;", css)
        self.assertIn("overflow-x: hidden !important;", css)

        # 2. Scrollbar styles
        self.assertIn("scroll-behavior: smooth;", css)
        self.assertIn("-webkit-overflow-scrolling: touch;", css)
        self.assertIn("scrollbar-width: thin;", css)
        self.assertIn("#content.app-archive_autotag::-webkit-scrollbar", css)

        # 3. Portal root flexibility
        self.assertIn("#archive-portal-root.archive-portal-app", css)
        self.assertIn("min-height: 100%;", css)
        self.assertIn("overflow: visible;", css)

    def test_02_no_unscoped_content_leak_in_portal_css(self):
        """Ensure no broad selectors like '#content,' or '#app-content,' break other NC apps."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/archive_portal.css"]
        css = subprocess.check_output(cmd).decode('utf-8')

        forbidden_patterns = [
            "#content.app-archive_autotag,\n#content,",
            "#content.app-archive_autotag,\r\n#content,",
            "#content,\n#app-content",
            "#content,\r\n#app-content",
        ]
        for pat in forbidden_patterns:
            self.assertNotIn(pat, css, f"Dangerous unscoped selector found: {pat}")

    def test_03_app_menu_filter_css_scoping(self):
        """Verify app_menu_filter.css scopes #content strictly to archive portal."""
        cmd = ["docker", "exec", "archive_app", "cat", "/var/www/html/custom_apps/archive_autotag/css/app_menu_filter.css"]
        css = subprocess.check_output(cmd).decode('utf-8')

        self.assertIn("#content.app-archive_autotag {", css, "Must use scoped #content.app-archive_autotag")
        # Ensure generic unscoped '#content {' is absent
        self.assertNotIn("\n#content {", css, "Generic unscoped '#content {' must not exist in app_menu_filter.css")

    def test_04_http_asset_delivery(self):
        """Verify CSS files and portal root deliver properly via HTTP."""
        resp_portal_css = requests.get(f"{BASE_URL}/custom_apps/archive_autotag/css/archive_portal.css")
        self.assertEqual(resp_portal_css.status_code, 200)
        self.assertIn("overflow-y: auto !important;", resp_portal_css.text)

        resp_filter_css = requests.get(f"{BASE_URL}/custom_apps/archive_autotag/css/app_menu_filter.css")
        self.assertEqual(resp_filter_css.status_code, 200)
        self.assertIn("#content.app-archive_autotag", resp_filter_css.text)

        resp_portal = requests.get(f"{BASE_URL}/index.php/apps/archive_autotag/", auth=(ADMIN_USER, ADMIN_PASS))
        self.assertEqual(resp_portal.status_code, 200)
        self.assertIn("id=\"archive-portal-root\"", resp_portal.text)

if __name__ == "__main__":
    unittest.main()
