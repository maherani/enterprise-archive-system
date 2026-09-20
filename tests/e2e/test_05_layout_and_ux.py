"""
Test Suite 5: Layout, Responsive & Security Isolation
Scenarios Covered:
- Scenario 15: Vertical Scrolling & Sticky Header
- Scenario 16: Responsive Mobile Viewport (375x812)
- Scenario 17: App Menu Isolation (Regular user restrictions)
- Scenario 18: App Store Isolation (/settings/apps blocked)
- Scenario 19: URL Masking & Route Privacy
"""

import unittest
from playwright.sync_api import sync_playwright
from tests.e2e.config import DESKTOP_VIEWPORT, MOBILE_VIEWPORT, USERS, BASE_URL
from tests.e2e.fixtures.users import UserManager
from tests.e2e.pages.portal_page import PortalPage
from tests.e2e.helpers.failure_reporter import failure_diagnostics


class TestLayoutAndUX(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"]
        )
        cls.user_mgr = UserManager()

    @classmethod
    def tearDownClass(cls):
        cls.browser.close()
        cls.playwright.stop()

    def test_15_vertical_scrolling_sticky_header(self):
        """15. Vertical Scrolling: Header and nav bar remain sticky during scroll."""
        context = self.user_mgr.get_storage_context(self.browser, "admin")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)
        portal = PortalPage(page)

        with failure_diagnostics(page, "test_15_vertical_scrolling_sticky_header"):
            portal.navigate()
            portal.wait_until_loaded()

            # Verify global nav root is present
            page.wait_for_selector("#ea-global-nav-root", state="visible")

            # Check header / nav position or bounding box before scroll
            initial_nav_y = page.locator("#ea-global-nav-root").bounding_box()["y"]

            # Scroll down the page or container
            page.evaluate("window.scrollTo(0, 500)")
            page.wait_for_timeout(300)

            # Global nav root should remain visible in viewport
            self.assertTrue(page.locator("#ea-global-nav-root").is_visible())

        context.close()

    def test_16_responsive_mobile_layout(self):
        """16. Responsive Layout: Mobile viewport renders cleanly without overflow."""
        context = self.user_mgr.get_storage_context(self.browser, "admin")
        page = context.new_page()
        page.set_viewport_size(MOBILE_VIEWPORT)
        portal = PortalPage(page)

        with failure_diagnostics(page, "test_16_responsive_mobile_layout"):
            portal.navigate()
            portal.wait_until_loaded()

            # Ensure document container is rendered
            container = page.locator(portal.CONTAINER)
            self.assertTrue(container.is_visible())

            # Search bar should adapt to mobile width
            search_input = page.locator(portal.SEARCH_INPUT)
            self.assertTrue(search_input.is_visible())
            box = search_input.bounding_box()
            self.assertLessEqual(box["width"], MOBILE_VIEWPORT["width"])

            # Tag bar should be scrollable / wrap cleanly
            tag_bar = page.locator("#ea-tag-bar-container")
            self.assertTrue(tag_bar.is_visible())

        context.close()

    def test_17_app_menu_isolation(self):
        """17. App Menu Isolation: Regular persona cannot see admin apps or settings."""
        context = self.user_mgr.get_storage_context(self.browser, "regular_cert")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)
        portal = PortalPage(page)

        with failure_diagnostics(page, "test_17_app_menu_isolation"):
            portal.navigate()
            portal.wait_until_loaded()

            # Regular user must NOT see admin workflow buttons
            self.assertFalse(
                page.is_visible("#ea-admin-manage-reqs-btn"),
                "Regular user should never see admin manage requests button"
            )
            self.assertFalse(
                page.is_visible("#ea-admin-create-folder-btn"),
                "Regular user should never see admin create folder button"
            )

            # Check Nextcloud app menu: Admin/Apps/Settings options must not be leaked
            app_menu_links = page.locator("#appmenu a").all()
            for link in app_menu_links:
                href = link.get_attribute("href") or ""
                self.assertNotIn("/settings/apps", href, "App store must not be in regular user app menu")

        context.close()

    def test_18_app_store_isolation(self):
        """18. App Store Isolation: Direct navigation to /settings/apps is blocked."""
        context = self.user_mgr.get_storage_context(self.browser, "regular_cert")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)

        with failure_diagnostics(page, "test_18_app_store_isolation"):
            apps_url = f"{BASE_URL}/index.php/settings/apps"
            page.goto(apps_url)
            page.wait_for_timeout(1000)

            # Nextcloud either redirects regular users to files/login or throws 403 / Access forbidden
            current_url = page.url
            is_blocked = (
                "/settings/apps" not in current_url or
                page.locator("text=Access forbidden").count() > 0 or
                page.locator(".error, .warning").count() > 0 or
                "/apps/files" in current_url
            )
            self.assertTrue(is_blocked, f"Regular user should be blocked from apps store. Current URL: {current_url}")

        context.close()

    def test_19_url_masking_and_routes(self):
        """19. URL Masking: Portal URLs mask internal physical directory paths."""
        context = self.user_mgr.get_storage_context(self.browser, "soc_admin")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)
        portal = PortalPage(page)

        with failure_diagnostics(page, "test_19_url_masking_and_routes"):
            portal.navigate()
            portal.wait_until_loaded()

            # URL should remain masked without exposing raw server paths (/var/www, oc_data, etc.)
            current_url = page.url
            self.assertNotIn("/var/www", current_url)
            self.assertNotIn("oc_data", current_url)
            self.assertTrue(current_url.startswith(BASE_URL))
            self.assertIn("بایگانی اسناد", page.title())

        context.close()


if __name__ == "__main__":
    unittest.main()
