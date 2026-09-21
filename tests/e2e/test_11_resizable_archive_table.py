import unittest
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS, PROJECT_ROOT, ARTIFACTS_DIR
from tests.e2e.fixtures.users import get_authenticated_context

class TestResizableArchiveTable(unittest.TestCase):
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

    def test_01_table_colgroup_and_resize_handles_presence(self):
        """1. Verify colgroup structure, default column widths, and resize handles."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        # Switch to table view if in grid mode
        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # 1. Verify resizable table exists
        table = page.locator("#ea-resizable-table")
        self.assertTrue(table.is_visible(), "Table with #ea-resizable-table must be visible")

        # 2. Verify colgroup and 6 columns exist
        col_type = page.locator("#ea-col-type")
        col_title = page.locator("#ea-col-title")
        col_tags = page.locator("#ea-col-tags")
        col_size = page.locator("#ea-col-size")
        col_date = page.locator("#ea-col-date")
        col_actions = page.locator("#ea-col-actions")

        self.assertEqual(col_type.count(), 1, "col type must exist in colgroup")
        self.assertEqual(col_title.count(), 1, "col title must exist in colgroup")
        self.assertEqual(col_tags.count(), 1, "col tags must exist in colgroup")
        self.assertEqual(col_size.count(), 1, "col size must exist in colgroup")
        self.assertEqual(col_date.count(), 1, "col date must exist in colgroup")
        self.assertEqual(col_actions.count(), 1, "col actions must exist in colgroup")

        # 3. Verify resize handles exist on resizable columns
        handles = page.locator(".ea-resize-handle")
        self.assertGreaterEqual(handles.count(), 5, "Should have at least 5 resize handles")

        # 4. Verify table toolbar reset button exists
        reset_btn = page.locator("#ea-table-reset-widths")
        self.assertTrue(reset_btn.is_visible(), "Reset column widths button must be visible")

        # Save screenshot
        out_path = ARTIFACTS_DIR / "resizable_table_default.png"
        page.locator(".ea-table-container").screenshot(path=str(out_path))
        print("Default table screenshot saved to", str(out_path))
        page.close()

    def test_02_text_wrapping_and_three_line_clamping(self):
        """2. Verify long text cells have wrapping and 3-line clamping applied."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # Verify title wrappers exist and have clamping class
        title_wrappers = page.locator(".ea-cell-title-wrap")
        self.assertGreater(title_wrappers.count(), 0, "Expected .ea-cell-title-wrap on title cells")

        first_wrap = title_wrappers.first
        # Check computed style for webkit-line-clamp or overflow-wrap
        clamp_style = first_wrap.evaluate("el => window.getComputedStyle(el).webkitLineClamp")
        self.assertEqual(str(clamp_style), "3", "Expected 3-line clamping on title cell wrapper")

        overflow_wrap = first_wrap.evaluate("el => window.getComputedStyle(el).overflowWrap")
        self.assertIn(overflow_wrap, ["anywhere", "break-word"], "Expected overflow-wrap to prevent table overflow")

        page.close()

    def test_03_mouse_drag_resizing_persistence_and_reset(self):
        """3. Verify RTL mouse drag resizing, localStorage persistence, and reset button."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        page.goto(PORTAL_URL)
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        table_btn = page.locator("#ea-view-table-btn")
        if table_btn.is_visible():
            table_btn.click()
            page.wait_for_timeout(1000)

        # 1. Reset first to guarantee baseline
        reset_btn = page.locator("#ea-table-reset-widths")
        reset_btn.click()
        page.wait_for_timeout(500)

        # Baseline width of title column
        col_title = page.locator("#ea-col-title")
        initial_w = int(col_title.evaluate("el => parseInt(el.style.width, 10)"))
        self.assertEqual(initial_w, 380, f"Expected default title width 380px, got {initial_w}")

        # 2. Locate resize handle for title column
        title_handle = page.locator(".ea-resize-handle[data-col='title']")
        box = title_handle.bounding_box()
        self.assertIsNotNone(box, "Title resize handle must have a bounding box")

        # In RTL, dragging the left border 80px to the left (X - 80) increases the column width
        start_x = box["x"] + box["width"] / 2
        start_y = box["y"] + box["height"] / 2

        page.mouse.move(start_x, start_y)
        page.mouse.down()
        # Drag left by 80px
        page.mouse.move(start_x - 80, start_y, steps=10)
        page.mouse.up()
        page.wait_for_timeout(500)

        # 3. Verify width increased
        new_w = int(col_title.evaluate("el => parseInt(el.style.width, 10)"))
        self.assertGreater(new_w, initial_w + 50, f"Expected column width to increase significantly from {initial_w}, now {new_w}")

        # Save resized screenshot
        dragged_path = ARTIFACTS_DIR / "resizable_table_dragged.png"
        page.locator(".ea-table-container").screenshot(path=str(dragged_path))
        print(f"Resized table ({new_w}px) screenshot saved to {dragged_path}")

        # 4. Test Persistence: Reload page and verify width persists from localStorage
        page.reload()
        page.wait_for_selector("#ea-document-container", timeout=15000)
        page.wait_for_timeout(1500)

        persisted_w = int(page.locator("#ea-col-title").evaluate("el => parseInt(el.style.width, 10)"))
        self.assertEqual(persisted_w, new_w, f"Expected persisted width {new_w}px after reload, got {persisted_w}")

        # 5. Test Reset Button: Click reset and verify restoration to 380px
        page.locator("#ea-table-reset-widths").click()
        page.wait_for_timeout(500)

        restored_w = int(page.locator("#ea-col-title").evaluate("el => parseInt(el.style.width, 10)"))
        self.assertEqual(restored_w, 380, f"Expected restored width 380px after reset, got {restored_w}")

        # 6. Verify row interactions still work: click preview button to open drawer
        preview_btn = page.locator(".ea-table-preview").first
        if preview_btn.is_visible():
            preview_btn.click()
            page.wait_for_timeout(1000)
            drawer = page.locator("#ea-drawer-backdrop.open")
            self.assertTrue(drawer.is_visible(), "Drawer should open when clicking preview in resizable table")

        page.close()

if __name__ == "__main__":
    unittest.main(verbosity=2)
