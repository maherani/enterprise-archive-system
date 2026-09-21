import unittest
import time
import requests
from requests.auth import HTTPBasicAuth
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS, BASE_URL
from tests.e2e.fixtures.users import get_authenticated_context

WEBDAV_BASE = f"{BASE_URL}/remote.php/dav/files"
ADMIN_AUTH = HTTPBasicAuth(USERS["admin"]["username"], USERS["admin"]["password"])

class TestSecureAdminDeletion(unittest.TestCase):
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

    def test_01_non_admin_cannot_see_delete_buttons(self):
        """1. Non-admin users must not see deletion buttons in table, grid, or drawer."""
        context = get_authenticated_context(self.browser, "compliance_user")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        # 1. Verify Table View has no delete buttons
        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        del_btns_table = page.locator(".ea-table-delete")
        self.assertEqual(del_btns_table.count(), 0, "Non-admin must not see .ea-table-delete buttons")

        # 2. Verify Grid View has no delete buttons
        grid_btn = page.locator("#ea-view-grid-btn")
        if grid_btn.is_visible():
            grid_btn.click()
            page.wait_for_timeout(1000)

        del_btns_card = page.locator(".ea-card-delete")
        self.assertEqual(del_btns_card.count(), 0, "Non-admin must not see .ea-card-delete buttons")

        # 3. Open drawer on first available card and verify drawer has no delete button
        cards = page.locator(".ea-card")
        if cards.count() > 0:
            cards.first.click()
            page.wait_for_timeout(1000)
            drawer_del_btn = page.locator("#ea-drawer-delete-btn")
            self.assertFalse(drawer_del_btn.is_visible(), "Non-admin must not see #ea-drawer-delete-btn in drawer")

        context.close()

    def test_02_admin_sees_delete_buttons_and_can_cancel_modal(self):
        """2. Admin sees delete buttons; clicking opens confirmation modal and cancel works."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        # Switch to table view
        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # Verify admin sees .ea-table-delete buttons
        del_btns = page.locator(".ea-table-delete")
        self.assertGreater(del_btns.count(), 0, "Admin must see .ea-table-delete buttons")

        # Click the first delete button
        del_btns.first.click()
        page.wait_for_selector("#ea-delete-confirm-modal", timeout=5000)

        modal = page.locator("#ea-delete-confirm-modal")
        self.assertTrue(modal.is_visible(), "Confirmation modal must appear upon clicking delete")

        # Verify modal warning text in Persian
        self.assertIn("تایید حذف دائمی", modal.text_content())
        self.assertIn("غیرقابل بازگشت", modal.text_content())

        # Click Cancel
        cancel_btn = modal.locator("#ea-cancel-delete-btn")
        cancel_btn.click()
        page.wait_for_timeout(500)

        # Verify modal is removed
        self.assertEqual(page.locator("#ea-delete-confirm-modal").count(), 0, "Modal must be removed on cancel")

        context.close()

    def test_03_admin_confirm_deletion_removes_resource(self):
        """3. Admin confirming deletion removes resource from table and storage permanently."""
        # 1. Create a dedicated test file via WebDAV
        ts = int(time.time())
        filename = f"e2e_del_test_{ts}.txt"
        dav_path = f"{WEBDAV_BASE}/{USERS['admin']['username']}/Enterprise_Archive/SOC/{filename}"
        up_res = requests.put(dav_path, auth=ADMIN_AUTH, data=f"E2E deletion test {ts}\n".encode("utf-8"))
        self.assertIn(up_res.status_code, [200, 201, 204], f"Upload failed: {up_res.status_code}")

        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        # Switch to table view
        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # Search for our created file
        search_input = page.locator("#ea-search-input")
        if search_input.is_visible():
            search_input.fill(filename)
            page.wait_for_timeout(1500)

        # Locate the row with our file
        file_row = page.locator(f"tr:has-text('{filename}')")
        self.assertTrue(file_row.is_visible(), f"Row for {filename} should be visible")

        # Click delete button in this specific row
        row_del_btn = file_row.locator(".ea-table-delete")
        row_del_btn.click()
        page.wait_for_selector("#ea-delete-confirm-modal", timeout=5000)

        # Click Confirm Delete
        confirm_btn = page.locator("#ea-confirm-delete-btn")
        confirm_btn.click()

        # Wait for deletion completion and toast
        page.wait_for_timeout(2000)

        # Verify row is no longer present
        self.assertEqual(page.locator(f"tr:has-text('{filename}')").count(), 0, "Deleted file row must not exist")

        # Verify WebDAV returns 404
        check_after = requests.get(dav_path, auth=ADMIN_AUTH)
        self.assertEqual(check_after.status_code, 404, "Physical file must return 404 after deletion")

        context.close()

if __name__ == "__main__":
    unittest.main()
