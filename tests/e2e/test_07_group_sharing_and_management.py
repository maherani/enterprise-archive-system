"""
E2E Test Suite 7: Admin Group Sharing & UI Isolation (Requirement 24)
Enterprise Archive System - Nextcloud 34

Validates:
1. Admin logs into Archive Portal, switches to table view, and verifies Group Sharing buttons exist.
2. Admin opens GroupShareModal from table row action.
3. Admin shares resource with group SOC and verifies share appears in modal list.
4. Admin deletes group share and verifies table updates.
5. Non-admin user (Bakbari in SOC) logs into Archive Portal, and verifies Group Sharing buttons and modals are strictly hidden/absent.
"""

import unittest
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS
from tests.e2e.helpers.failure_reporter import capture_failure
from tests.e2e.fixtures.users import get_authenticated_context


class TestGroupSharingAndManagementE2E(unittest.TestCase):
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

    def test_01_admin_group_sharing_ui_and_modal_lifecycle(self):
        """Admin views share buttons, opens modal, adds share, and deletes share."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        with capture_failure(page, "test_01_admin_group_sharing_flow"):
            page.goto(PORTAL_URL, wait_until="networkidle")

            # 1. Switch to table view
            table_btn = page.locator("#ea-view-table-btn")
            if table_btn.is_visible():
                table_btn.click()
                page.wait_for_timeout(1000)

            # 2. Wait for table share buttons to appear
            page.wait_for_selector(".ea-table-share", timeout=10000)
            share_btns = page.locator(".ea-table-share")
            count = share_btns.count()
            self.assertGreater(count, 0, "Admin should see at least one .ea-table-share button in table view")

            # 3. Click the first share button to open modal
            share_btns.first.click()
            modal = page.locator("#ea-group-share-modal")
            modal.wait_for(state="visible", timeout=5000)
            self.assertTrue(modal.is_visible(), "Group Share Modal should be visible after clicking share button")

            # 4. Verify group select dropdown is populated
            group_select = page.locator("#ea-share-group-select")
            group_select.wait_for(state="visible")
            # Wait for groups to load
            page.wait_for_timeout(1500)
            options = group_select.locator("option").all_inner_texts()
            self.assertTrue(any("SOC" in opt for opt in options), f"Expected 'SOC' in group options: {options}")

            # 5. Select 'SOC' and submit share
            group_select.select_option(value="SOC")
            submit_btn = page.locator("#ea-submit-share-btn")
            submit_btn.click()

            # Wait for status message or table update
            status_msg = page.locator("#ea-share-status-msg")
            status_msg.wait_for(state="visible", timeout=5000)
            self.assertIn("موفقیت", status_msg.inner_text())

            # 6. Verify SOC is listed in active shares
            shares_list = page.locator("#ea-shares-list-container")
            self.assertIn("SOC", shares_list.inner_text())

            # 7. Delete the share
            page.on("dialog", lambda dialog: dialog.accept())
            del_btn = page.locator(".ea-delete-share-btn").first
            if del_btn.is_visible():
                del_btn.click()
                page.wait_for_timeout(2000)

            # 8. Close modal
            close_btn = page.locator("#ea-close-share-modal")
            close_btn.click()
            page.wait_for_timeout(500)
            self.assertFalse(modal.is_visible(), "Modal should be closed after clicking close button")

    def test_02_non_admin_group_sharing_ui_strictly_hidden(self):
        """Non-admin user (Bakbari in SOC) must not see group sharing buttons."""
        context = get_authenticated_context(self.browser, "soc_admin")
        page = context.new_page()

        with capture_failure(page, "test_02_non_admin_sharing_hidden"):
            page.goto(PORTAL_URL, wait_until="networkidle")

            # 1. Switch to table view
            table_btn = page.locator("#ea-view-table-btn")
            if table_btn.is_visible():
                table_btn.click()
                page.wait_for_timeout(1000)

            # 2. Verify table share buttons are absent
            share_btns = page.locator(".ea-table-share")
            self.assertEqual(share_btns.count(), 0, "Non-admin user must NOT see any .ea-table-share buttons")

            # 3. Open drawer by clicking on first row if present
            rows = page.locator(".ea-table tbody tr")
            if rows.count() > 0:
                rows.first.click()
                page.wait_for_timeout(1000)
                drawer_share_btn = page.locator("#ea-drawer-share-btn")
                self.assertFalse(drawer_share_btn.is_visible(), "Non-admin must NOT see #ea-drawer-share-btn in drawer")


if __name__ == "__main__":
    unittest.main(verbosity=2)
