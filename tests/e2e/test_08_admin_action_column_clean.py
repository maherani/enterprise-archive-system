import unittest
from pathlib import Path
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import FILES_URL, USERS, PROJECT_ROOT
from tests.e2e.fixtures.users import get_authenticated_context

class TestAdminActionColumnClean(unittest.TestCase):
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

    def test_admin_files_table_no_locate_button(self):
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(FILES_URL, wait_until="networkidle")
        page.wait_for_selector(".archive-results-table tbody tr", timeout=20000)
        page.wait_for_timeout(1500)

        # 1. Assert .archive-locate-btn count is strictly 0
        locate_count = page.locator("a.archive-locate-btn, .archive-locate-btn").count()
        self.assertEqual(locate_count, 0, f"Expected 0 locate buttons in table, but found {locate_count}")

        # 2. Check inner text of all action cells for absence of locate / open folder phrases
        LOCATE_TXT = "مشاهده در پوشه"
        ENTER_TXT = "ورود به فولدر"
        OPEN_TXT = "باز کردن پوشه"
        DOWNLOAD_TXT = "دانلود"
        SHARE_TXT = "اشتراک با گروه"

        action_cells = page.locator(".archive-actions-cell")
        count = action_cells.count()
        self.assertGreater(count, 0, "Expected table to contain action cells")

        for idx in range(count):
            cell_text = action_cells.nth(idx).inner_text()
            self.assertNotIn(LOCATE_TXT, cell_text)
            self.assertNotIn(ENTER_TXT, cell_text)
            self.assertNotIn(OPEN_TXT, cell_text)

        # 3. Check file rows have download and share buttons
        file_rows = page.locator(".archive-results-table tbody tr.archive-file-row")
        if file_rows.count() > 0:
            first_file_actions = file_rows.first.locator(".archive-actions-cell")
            self.assertIn(DOWNLOAD_TXT, first_file_actions.inner_text())
            self.assertIn(SHARE_TXT, first_file_actions.inner_text())

        # 4. Check folder rows have share button and NOT download button
        folder_rows = page.locator(".archive-results-table tbody tr.archive-folder-row")
        if folder_rows.count() > 0:
            first_folder_actions = folder_rows.first.locator(".archive-actions-cell")
            self.assertIn(SHARE_TXT, first_folder_actions.inner_text())
            self.assertNotIn(DOWNLOAD_TXT, first_folder_actions.inner_text())

        out_path = PROJECT_ROOT / "artifacts" / "admin_table_no_locate_verified.png"
        page.locator(".archive-results-table").screenshot(path=str(out_path))
        print("SUCCESS: Screenshot saved to", str(out_path))

if __name__ == "__main__":
    unittest.main(verbosity=2)
