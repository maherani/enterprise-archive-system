"""
Test Suite 2: Tags & Faceted Search
Scenarios Covered:
- Scenario 06: Multi-tag Filtering (AND logic)
- Scenario 13: Group Tag Management (Drawer, Badges, Validation Guard)
- Scenario 14: Locate in Folder Navigation
"""

import time
import unittest
from playwright.sync_api import sync_playwright
from tests.e2e.config import DESKTOP_VIEWPORT, USERS, ARTIFACTS_DIR
from tests.e2e.fixtures.users import UserManager
from tests.e2e.pages.portal_page import PortalPage
from tests.e2e.pages.tag_drawer import TagDrawer
from tests.e2e.helpers.failure_reporter import failure_diagnostics


class TestTagsAndFilter(unittest.TestCase):
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

    def setUp(self):
        self.context = self.user_mgr.get_storage_context(self.browser, "soc_admin")
        self.page = self.context.new_page()
        self.page.set_viewport_size(DESKTOP_VIEWPORT)
        self.portal = PortalPage(self.page)
        self.tag_drawer = TagDrawer(self.page)

    def tearDown(self):
        self.context.close()

    def test_06_multi_tag_and_filtering(self):
        """6. Multi-tag Filtering: Applying multiple tags enforces AND logic."""
        with failure_diagnostics(self.page, "test_06_multi_tag_and_filtering"):
            self.portal.navigate()
            self.portal.wait_until_loaded()

            # Ensure tag bar is visible
            self.page.wait_for_selector("#ea-tag-bar-container", state="visible")
            tag_chips = self.page.locator("#ea-tag-bar-container .ea-tag-chip:not([data-tag-all='true'])")
            chip_count = tag_chips.count()

            if chip_count >= 2:
                # Click first tag
                tag1_text = tag_chips.nth(0).locator("span").first.text_content().strip()
                tag_chips.nth(0).click()
                self.page.wait_for_timeout(400)

                # Click second tag
                tag2_text = tag_chips.nth(1).locator("span").first.text_content().strip()
                tag_chips.nth(1).click()
                self.page.wait_for_timeout(400)

                # Verify active filters ribbon contains both tags
                ribbon = self.page.locator("#ea-active-ribbon-container")
                self.assertTrue(ribbon.is_visible(), "Expected active ribbon to be visible")
                ribbon_text = ribbon.text_content()
                self.assertIn(tag1_text, ribbon_text, f"Expected {tag1_text} in active ribbon")
                self.assertIn(tag2_text, ribbon_text, f"Expected {tag2_text} in active ribbon")

                # Clear all filters
                clear_btn = self.page.locator("#ea-clear-all-filters-btn")
                self.assertTrue(clear_btn.is_visible(), "Clear all filters button should be visible")
                clear_btn.click()
                self.page.wait_for_timeout(400)
                self.assertEqual(ribbon.text_content().strip(), "", "Ribbon should be empty after clearing filters")
            else:
                # Single tag or empty tag pool verification
                all_chip = self.page.locator("#ea-tag-bar-container .ea-tag-chip[data-tag-all='true']")
                self.assertTrue(all_chip.is_visible(), "All documents chip must always be present")

    def test_13_group_tag_management_governance(self):
        """13. Group Tag Management: Subadmin tag creation, validation guard, and reconciliation."""
        with failure_diagnostics(self.page, "test_13_group_tag_management_governance"):
            self.portal.navigate()
            self.portal.wait_until_loaded()

            # Subadmin must see the manage group tags button
            self.page.wait_for_selector(self.tag_drawer.TRIGGER_BTN, state="visible", timeout=8000)
            self.tag_drawer.open_drawer()

            # Verify modal opened
            self.assertTrue(self.page.is_visible(self.tag_drawer.MODAL))

            # Guard 1: Empty tag name triggers validation guard
            self.tag_drawer.create_tag("")
            val_msg = self.tag_drawer.get_message()
            self.assertIn("نام تگ را وارد نمایید", val_msg, f"Expected validation error on empty tag, got: {val_msg}")

            # Create unique test tag
            timestamp = int(time.time() * 1000) % 1000000
            test_tag_name = f"e2e_soc_{timestamp}"
            self.tag_drawer.create_tag(test_tag_name)

            # Assert success message
            self.page.wait_for_selector(self.tag_drawer.MSG_CONTAINER, state="visible", timeout=6000)
            msg = self.tag_drawer.get_message()
            self.assertIn("با موفقیت", msg, f"Expected success message, got: {msg}")

            # Verify created tag appears in the list table
            self.page.wait_for_timeout(500)
            tag_list = self.tag_drawer.get_tag_names()
            self.assertTrue(
                any(test_tag_name in t for t in tag_list),
                f"Tag '{test_tag_name}' should appear in group tags list: {tag_list}"
            )

            # Trigger tag reconciliation
            reconcile_btn = self.page.locator(self.tag_drawer.RECONCILE_BTN)
            self.assertTrue(reconcile_btn.is_visible())
            reconcile_btn.click()
            self.page.wait_for_timeout(1000)

            # Close drawer
            self.tag_drawer.close()
            self.portal.wait_for_idle()

    def test_14_locate_in_folder(self):
        """14. Locate in Folder: File detail drawer reveals file location in hierarchy."""
        with failure_diagnostics(self.page, "test_14_locate_in_folder"):
            self.portal.navigate()
            self.portal.wait_until_loaded()

            # Ensure table view is enabled
            table_btn = self.page.locator("#ea-view-table-btn")
            if table_btn.is_visible():
                table_btn.click()
                self.page.wait_for_timeout(400)

            # Check if any file row is present
            file_rows = self.page.locator("#ea-document-container tr.ea-row, #ea-document-container .ea-item-card")
            if file_rows.count() > 0:
                # Click the first row to open detail drawer
                file_rows.first.click()
                self.page.wait_for_selector(self.portal.DRAWER_PANEL, state="visible", timeout=6000)

                # Locate in folder button must be present
                locate_btn = self.page.locator(self.portal.DRAWER_LOCATE_BTN)
                self.assertTrue(locate_btn.is_visible(), "Locate in folder button must be present in drawer")

                # Verify target dir is saved to session storage on click
                locate_btn.click()
                target_dir = self.page.evaluate("() => sessionStorage.getItem('ea_target_dir')")
                self.assertIsNotNone(target_dir, "Target directory should be tracked in sessionStorage")

                # Close drawer
                close_btn = self.page.locator(self.portal.DRAWER_CLOSE_BTN)
                if close_btn.is_visible():
                    close_btn.click()


if __name__ == "__main__":
    unittest.main()
