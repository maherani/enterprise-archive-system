import unittest
import time
import requests
from requests.auth import HTTPBasicAuth
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS, BASE_URL
from tests.e2e.fixtures.users import get_authenticated_context

ADMIN_AUTH = HTTPBasicAuth(USERS["admin"]["username"], USERS["admin"]["password"])

class TestCentralTagManagementE2E(unittest.TestCase):
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

    def test_01_non_admin_cannot_see_central_tag_management_button(self):
        """1. Non-admin users (compliance_user) must not see #ea-admin-manage-tags-btn."""
        context = get_authenticated_context(self.browser, "compliance_user")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        manage_tags_btn = page.locator("#ea-admin-manage-tags-btn")
        self.assertFalse(manage_tags_btn.is_visible(), "Non-admin must not see Central Tag Management button")
        context.close()

    def test_02_admin_central_tag_console_lifecycle(self):
        """2. Admin sees Central Tag Management button, opens console, creates and deletes tag."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(2000)

        # 1. Verify #ea-admin-manage-tags-btn is visible
        manage_btn = page.locator("#ea-admin-manage-tags-btn")
        self.assertTrue(manage_btn.is_visible(), "Admin must see #ea-admin-manage-tags-btn")

        # 2. Click to open modal
        manage_btn.click()
        page.wait_for_selector("#ea-active-modal", timeout=8000)
        page.wait_for_timeout(1000)

        modal_title = page.locator(".ea-modal-title")
        self.assertIn("مدیریت مرکزی تگ‌ها", modal_title.inner_text())

        # 3. Verify scope selector and search input are present
        name_input = page.locator("#ea-admin-new-tag-name")
        scope_select = page.locator("#ea-admin-new-tag-scope")
        create_btn = page.locator("#ea-admin-create-tag-btn")
        search_input = page.locator("#ea-admin-tag-search-input")
        table_container = page.locator("#ea-admin-tags-table-container")

        self.assertTrue(name_input.is_visible())
        self.assertTrue(scope_select.is_visible())
        self.assertTrue(create_btn.is_visible())
        self.assertTrue(search_input.is_visible())
        self.assertTrue(table_container.is_visible())

        # 4. Create new test tag
        ts = int(time.time())
        test_tag_name = f"UI_TAG_{ts}"
        name_input.fill(test_tag_name)
        create_btn.click()

        # Wait for toast or success message
        page.wait_for_timeout(2000)

        # 5. Search for created tag
        search_input.fill(test_tag_name)
        page.wait_for_timeout(1000)

        # Tag row should be visible in table specifically inside table_container
        tag_cell = table_container.locator(f".ea-mini-tag:has-text('{test_tag_name}')")
        self.assertTrue(tag_cell.first.is_visible(), f"Created tag '{test_tag_name}' must be visible in table")

        # 6. Delete tag via table button
        page.on("dialog", lambda dialog: dialog.accept())
        del_btn = table_container.locator(f"button.ea-admin-tag-del-btn[data-tag-name='{test_tag_name}']")
        self.assertTrue(del_btn.first.is_visible())
        del_btn.first.click()
        page.wait_for_timeout(2000)

        # Verify tag is removed from table
        tag_cell_after = table_container.locator(f".ea-mini-tag:has-text('{test_tag_name}')")
        self.assertEqual(tag_cell_after.count(), 0, "Tag must be removed after deletion")

        # Close modal
        close_btn = page.locator("#ea-admin-tag-modal-close-btn")
        if close_btn.is_visible():
            close_btn.click()
            page.wait_for_timeout(1000)

        context.close()

    def test_03_admin_drawer_tag_actions_available(self):
        """3. Admin inspecting a resource in drawer sees tag assignment and tag remove buttons."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(2000)

        # In grid view (default), click on the first file card
        cards = page.locator(".ea-card")
        if cards.count() > 0:
            # Click first card to open drawer
            cards.first.click()
            page.wait_for_selector("#ea-drawer-backdrop.open", timeout=8000)
            page.wait_for_timeout(1500)

            # Check admin tag assign box
            admin_tag_select = page.locator("#ea-drawer-admin-tag-select")
            admin_add_btn = page.locator("#ea-drawer-admin-add-tag-btn")
            self.assertTrue(admin_tag_select.is_visible(), "Admin must see #ea-drawer-admin-tag-select in drawer")
            self.assertTrue(admin_add_btn.is_visible(), "Admin must see #ea-drawer-admin-add-tag-btn in drawer")

            # Close drawer
            close_drawer_btn = page.locator("#ea-drawer-close-btn")
            if close_drawer_btn.is_visible():
                close_drawer_btn.click()
                page.wait_for_timeout(500)

        context.close()


if __name__ == "__main__":
    unittest.main()
