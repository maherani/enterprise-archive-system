import unittest
from pathlib import Path
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS, PROJECT_ROOT
from tests.e2e.fixtures.users import get_authenticated_context

class TestCompactUILayout(unittest.TestCase):
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

    def test_compact_layout_and_collapsible_tags(self):
        """Verify high-density UI layout, reduced heights, and collapsible tag bar."""
        context = get_authenticated_context(self.browser, "cert_admin")
        page = context.new_page()

        page.goto(PORTAL_URL, wait_until="networkidle")
        page.wait_for_selector("#archive-portal-root", timeout=15000)
        page.wait_for_timeout(1500)

        # 1. Switch to table view
        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # 2. Check Global Nav Height
        global_nav = page.locator("#ea-global-nav-root")
        if global_nav.is_visible():
            g_box = global_nav.bounding_box()
            print(f"Global Nav Height: {g_box['height']}px")
            self.assertLessEqual(g_box["height"], 38, "Global nav height should be compact (<= 38px)")

        # 3. Check Sticky Top Section Height in normal compact mode
        sticky_top = page.locator("#ea-sticky-top-section")
        self.assertTrue(sticky_top.is_visible(), "Sticky top section should be visible")
        s_box = sticky_top.bounding_box()
        print(f"Sticky Top Section Height (expanded tags): {s_box['height']}px")
        self.assertLessEqual(s_box["height"], 215, "Sticky top section should be significantly compacted (<= 215px)")

        # 4. Check Table rows visibility and padding
        table_rows = page.locator(".ea-table tbody tr")
        row_count = table_rows.count()
        print(f"Table rows loaded: {row_count}")
        self.assertGreater(row_count, 0, "Table should have file/folder rows")

        # Capture screenshot with compact tags open
        out_normal = PROJECT_ROOT / "artifacts" / "compact_ui_normal_table.png"
        page.screenshot(path=str(out_normal), full_page=False)
        print(f"Screenshot saved: {out_normal}")

        # 5. Test Tag Collapse Toggle
        toggle_btn = page.locator("#ea-tag-toggle-btn")
        self.assertTrue(toggle_btn.is_visible(), "Tag collapse/expand toggle button should be visible")

        # Click collapse
        toggle_btn.click()
        page.wait_for_timeout(500)

        # Capture screenshot with tags collapsed (maximum table visibility)
        out_collapsed = PROJECT_ROOT / "artifacts" / "compact_ui_tags_collapsed_table.png"
        page.screenshot(path=str(out_collapsed), full_page=False)
        print(f"Screenshot saved: {out_collapsed}")

        chips_wrapper = page.locator("#ea-tag-chips-wrapper")
        self.assertTrue("is-collapsed" in (chips_wrapper.get_attribute("class") or ""))
        toggle_text = page.locator("#ea-tag-toggle-text").inner_text()
        self.assertIn("مشاهده برچسب‌ها", toggle_text)

        s_box_collapsed = sticky_top.bounding_box()
        print(f"Sticky Top Section Height (collapsed tags): {s_box_collapsed['height']}px")
        self.assertLessEqual(s_box_collapsed["height"], 155, "Collapsed sticky top height should be under 155px")

        # Re-expand tags
        toggle_btn.click()
        page.wait_for_timeout(500)
        self.assertFalse("is-collapsed" in (chips_wrapper.get_attribute("class") or ""))
        print("Compact UI and Collapsible Tags verified successfully!")

if __name__ == "__main__":
    unittest.main(verbosity=2)
