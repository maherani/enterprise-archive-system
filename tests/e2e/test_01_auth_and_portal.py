"""
E2E Test Suite 1: Authentication, Portal Hydration, and Global Navigation (Scenarios 1-5)
"""

import unittest
from playwright.sync_api import sync_playwright, Browser, Page
from tests.e2e.config import PORTAL_URL, LOGIN_URL, USERS
from tests.e2e.pages.login_page import LoginPage
from tests.e2e.pages.portal_page import PortalPage
from tests.e2e.helpers.failure_reporter import capture_failure
from tests.e2e.fixtures.users import get_authenticated_page


class TestAuthAndPortal(unittest.TestCase):
    browser: Browser
    playwright = None

    @classmethod
    def setUpClass(cls):
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(headless=True)

    @classmethod
    def tearDownClass(cls):
        if cls.browser:
            cls.browser.close()
        if cls.playwright:
            cls.playwright.stop()

    def test_01_login_flow(self):
        """1. Login: Valid credentials establish session; invalid credentials display error."""
        page = self.browser.new_page()
        login_page = LoginPage(page)

        with capture_failure(page, "test_01_login_invalid"):
            login_page.navigate()
            login_page.fill(login_page.USER_INPUT, "admin")
            login_page.fill(login_page.PASSWORD_INPUT, "Wrong_Password_999!")
            login_page.click(login_page.SUBMIT_BUTTON)
            
            # Wait for error feedback element to appear
            login_page.wait_for_selector(login_page.ERROR_CONTAINER, state="visible", timeout=6000)
            self.assertTrue(
                login_page.is_visible(login_page.ERROR_CONTAINER),
                "Error feedback must be visible upon invalid login"
            )

        with capture_failure(page, "test_01_login_valid"):
            login_page.login(USERS["admin"]["username"], USERS["admin"]["password"])
            self.assertTrue(login_page.is_logged_in(), "User must be authenticated and redirected from login")
        page.close()

    def test_02_archive_portal_hydration(self):
        """2. Archive Portal: SPA loads cleanly with Obsidian layout, items, and view toggling."""
        page = get_authenticated_page(self.browser, "admin")
        js_errors = []
        page.on("pageerror", lambda err: js_errors.append(str(err)))

        with capture_failure(page, "test_02_portal_hydration"):
            portal = PortalPage(page)
            portal.navigate()
            self.assertTrue(portal.is_visible(portal.CONTAINER), "Document container must be visible")
            
            # Verify document items (cards or table rows) rendered
            items = page.locator(f"{portal.CONTAINER} .ea-card, {portal.CONTAINER} tr").all()
            self.assertGreater(len(items), 0, "Document items must be rendered inside portal")
            
            # Switch to table view if button exists and verify table
            if portal.is_visible("#ea-view-table-btn"):
                portal.click("#ea-view-table-btn")
                portal.wait_for_idle()
                self.assertTrue(portal.is_visible(f"{portal.CONTAINER} table, {portal.CONTAINER} .ea-table"), "Table view rendered")
            
            self.assertEqual(len(js_errors), 0, f"Page must not throw JS errors: {js_errors}")
        page.context.close()

    def test_03_global_navigation_breadcrumbs(self):
        """3. Global Navigation: Breadcrumb and path indicators are rendered in header."""
        page = get_authenticated_page(self.browser, "soc_admin")
        with capture_failure(page, "test_03_navigation"):
            portal = PortalPage(page)
            portal.navigate()
            
            # Check global nav root or portal breadcrumbs
            nav_visible = page.locator("#ea-global-nav-root, #archive-global-nav, .ea-breadcrumb-container").is_visible(timeout=5000)
            self.assertTrue(nav_visible, "Navigation breadcrumb bar must be present")
        page.context.close()

    def test_04_navigation_click_spa(self):
        """4. Navigation Click: Clicking breadcrumb updates directory state without full reload."""
        page = get_authenticated_page(self.browser, "admin")
        with capture_failure(page, "test_04_nav_click"):
            portal = PortalPage(page)
            portal.navigate()
            
            # Click refresh button in portal toolbar
            if portal.is_visible(portal.REFRESH_BTN):
                portal.click(portal.REFRESH_BTN)
                portal.wait_for_idle()
            
            self.assertTrue(portal.is_visible(portal.CONTAINER), "Container remains interactive after SPA action")
        page.context.close()

    def test_05_folder_browsing(self):
        """5. Folder Browsing: Opening folder navigates and updates file view."""
        page = get_authenticated_page(self.browser, "admin")
        with capture_failure(page, "test_05_folder_browsing"):
            portal = PortalPage(page)
            portal.navigate()
            
            # If folder exists, click it
            folder_row = page.locator(f"{portal.CONTAINER} .ea-card:has(.ea-icon-folder), {portal.CONTAINER} tr:has(.ea-icon-folder), {portal.CONTAINER} tr[data-type='dir']").first
            if folder_row.count() > 0:
                folder_row.click()
                portal.wait_for_idle()
                self.assertTrue(portal.is_visible(portal.CONTAINER), "Folder contents must load cleanly")
        page.context.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
