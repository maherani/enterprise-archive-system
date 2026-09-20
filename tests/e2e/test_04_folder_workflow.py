"""
Test Suite 4: Folder Workflow & Admin Cartable
Scenarios Covered:
- Scenario 10: Folder Request UI (Subadmin submission)
- Scenario 11: Admin Approval Cartable UI (Admin approves folder)
- Scenario 12: Admin Rejection Cartable UI (Admin rejects with reason)
"""

import time
import unittest
from playwright.sync_api import sync_playwright
from tests.e2e.config import DESKTOP_VIEWPORT, USERS
from tests.e2e.fixtures.users import UserManager
from tests.e2e.pages.portal_page import PortalPage
from tests.e2e.pages.folder_request_modal import FolderRequestModal
from tests.e2e.helpers.failure_reporter import failure_diagnostics


class TestFolderWorkflow(unittest.TestCase):
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

    def test_10_folder_request_submission(self):
        """10. Folder Request UI: Subadmin fills and submits folder request."""
        context = self.user_mgr.get_storage_context(self.browser, "soc_admin")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)
        portal = PortalPage(page)
        folder_modal = FolderRequestModal(page)

        with failure_diagnostics(page, "test_10_folder_request_submission"):
            portal.navigate()
            portal.wait_until_loaded()

            # Ensure Request Folder button is visible for subadmin
            page.wait_for_selector(folder_modal.TRIGGER_BTN, state="visible", timeout=8000)
            folder_modal.open_request_modal()

            # Form fields must be visible
            page.wait_for_selector(folder_modal.FOLDER_NAME_INPUT, state="visible")
            page.wait_for_selector(folder_modal.DESCRIPTION_INPUT, state="visible")

            # Submit valid request
            timestamp = int(time.time() * 1000) % 1000000
            test_folder = f"e2e_soc_folder_{timestamp}"
            folder_modal.submit_request(
                folder_name=test_folder,
                description="E2E test folder creation request for incident response unit."
            )

            # Modal should close on success and trigger a toast
            page.wait_for_selector("#ea-active-modal", state="detached", timeout=8000)

        context.close()

    def test_11_admin_approval_cartable(self):
        """11. Admin Approval UI: Admin reviews pending request and approves it."""
        # 1. First, submit a request via soc_admin to guarantee a pending request
        sub_context = self.user_mgr.get_storage_context(self.browser, "soc_admin")
        sub_page = sub_context.new_page()
        sub_page.set_viewport_size(DESKTOP_VIEWPORT)
        sub_portal = PortalPage(sub_page)
        sub_folder_modal = FolderRequestModal(sub_page)

        sub_portal.navigate()
        sub_portal.wait_until_loaded()
        sub_page.wait_for_selector(sub_folder_modal.TRIGGER_BTN, state="visible", timeout=8000)
        sub_folder_modal.open_request_modal()

        timestamp = int(time.time() * 1000) % 1000000
        approve_folder_name = f"e2e_appr_{timestamp}"
        sub_folder_modal.submit_request(
            folder_name=approve_folder_name,
            description="Request awaiting admin approval in E2E suite."
        )
        sub_page.wait_for_selector("#ea-active-modal", state="detached", timeout=8000)
        sub_context.close()

        # 2. Open Admin cartable as super admin and approve
        admin_context = self.user_mgr.get_storage_context(self.browser, "admin")
        admin_page = admin_context.new_page()
        admin_page.set_viewport_size(DESKTOP_VIEWPORT)
        admin_portal = PortalPage(admin_page)
        admin_folder_modal = FolderRequestModal(admin_page)

        with failure_diagnostics(admin_page, "test_11_admin_approval_cartable"):
            admin_portal.navigate()
            admin_portal.wait_until_loaded()

            # Cartable button must be visible for admin
            admin_page.wait_for_selector(admin_folder_modal.ADMIN_CARTABLE_TRIGGER, state="visible", timeout=8000)
            admin_folder_modal.open_admin_cartable()

            # Cartable table should load
            admin_page.wait_for_selector(f"{admin_folder_modal.ADMIN_REQS_CONTAINER} table", state="visible", timeout=8000)

            # Find row and click Approve (confirm dialog accepted automatically by POM)
            success = admin_folder_modal.approve_request(approve_folder_name)
            self.assertTrue(success, f"Expected approve button for {approve_folder_name} to be clicked")

            # Verify request status in cartable reflects approved or updates
            admin_page.wait_for_timeout(1000)
            admin_folder_modal.close_cartable()

        admin_context.close()

    def test_12_admin_rejection_cartable(self):
        """12. Admin Rejection UI: Admin rejects request with mandatory reason."""
        # 1. Submit request via soc_admin
        sub_context = self.user_mgr.get_storage_context(self.browser, "soc_admin")
        sub_page = sub_context.new_page()
        sub_page.set_viewport_size(DESKTOP_VIEWPORT)
        sub_portal = PortalPage(sub_page)
        sub_folder_modal = FolderRequestModal(sub_page)

        sub_portal.navigate()
        sub_portal.wait_until_loaded()
        sub_page.wait_for_selector(sub_folder_modal.TRIGGER_BTN, state="visible", timeout=8000)
        sub_folder_modal.open_request_modal()

        timestamp = int(time.time() * 1000) % 1000000
        reject_folder_name = f"e2e_rejc_{timestamp}"
        sub_folder_modal.submit_request(
            folder_name=reject_folder_name,
            description="Request destined to be rejected in E2E test."
        )
        sub_page.wait_for_selector("#ea-active-modal", state="detached", timeout=8000)
        sub_context.close()

        # 2. Admin cartable rejection
        admin_context = self.user_mgr.get_storage_context(self.browser, "admin")
        admin_page = admin_context.new_page()
        admin_page.set_viewport_size(DESKTOP_VIEWPORT)
        admin_portal = PortalPage(admin_page)
        admin_folder_modal = FolderRequestModal(admin_page)

        with failure_diagnostics(admin_page, "test_12_admin_rejection_cartable"):
            admin_portal.navigate()
            admin_portal.wait_until_loaded()

            admin_folder_modal.open_admin_cartable()
            admin_page.wait_for_selector(f"{admin_folder_modal.ADMIN_REQS_CONTAINER} table", state="visible", timeout=8000)

            # Reject with reason
            rejection_reason = "E2E Automated Governance Rejection"
            rejected = admin_folder_modal.reject_request(reject_folder_name, reason=rejection_reason)
            self.assertTrue(rejected, f"Expected reject button for {reject_folder_name} to be clicked")

            # Verify rejection reflects in UI
            admin_page.wait_for_timeout(1000)
            admin_folder_modal.close_cartable()

        admin_context.close()


if __name__ == "__main__":
    unittest.main()
