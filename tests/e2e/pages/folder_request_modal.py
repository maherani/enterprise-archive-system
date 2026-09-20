"""
FolderRequestModal and AdminCartable POM
"""

from playwright.sync_api import Page, expect
from tests.e2e.pages.base_page import BasePage


class FolderRequestModal(BasePage):
    # Request Form Modal
    TRIGGER_BTN = "#ea-create-folder-req-btn"
    FOLDER_NAME_INPUT = "#ea-form-folder-name"
    TARGET_PATH_SELECT = "#ea-form-target-path"
    DESCRIPTION_INPUT = "#ea-form-description"
    SUBMIT_BTN = "#ea-form-submit-btn"
    CANCEL_BTN = "#ea-form-cancel-btn"
    ERROR_CONTAINER = "#ea-form-error"

    # Admin Cartable Modal
    ADMIN_CARTABLE_TRIGGER = "#ea-admin-manage-reqs-btn"
    ADMIN_REQS_CONTAINER = "#ea-admin-reqs-body"
    ADMIN_CLOSE_BTN = "#ea-admin-reqs-close-btn"

    def open_request_modal(self) -> None:
        self.click(self.TRIGGER_BTN)
        self.wait_for_selector(self.FOLDER_NAME_INPUT, state="visible")

    def submit_request(self, folder_name: str, parent_path: str = "", description: str = "") -> None:
        self.fill(self.FOLDER_NAME_INPUT, folder_name)
        if parent_path and self.is_visible(self.TARGET_PATH_SELECT):
            try:
                self.page.select_option(self.TARGET_PATH_SELECT, label=parent_path)
            except Exception:
                pass
        if description and self.is_visible(self.DESCRIPTION_INPUT):
            self.fill(self.DESCRIPTION_INPUT, description)
        
        self.click(self.SUBMIT_BTN)
        self.wait_for_idle()

    def open_admin_cartable(self) -> None:
        self.click(self.ADMIN_CARTABLE_TRIGGER)
        self.wait_for_selector(self.ADMIN_REQS_CONTAINER, state="visible")

    def approve_request(self, folder_name: str) -> bool:
        row = self.page.locator(f"{self.ADMIN_REQS_CONTAINER} tr:has-text('{folder_name}')")
        approve_btn = row.locator("button.ea-btn-approve")
        if approve_btn.count() > 0:
            self.page.once("dialog", lambda d: d.accept())
            approve_btn.first.click()
            self.wait_for_idle(timeout=6000)
            return True
        return False

    def reject_request(self, folder_name: str, reason: str = "Policy Violation") -> bool:
        row = self.page.locator(f"{self.ADMIN_REQS_CONTAINER} tr:has-text('{folder_name}')")
        reject_btn = row.locator("button.ea-btn-reject")
        if reject_btn.count() > 0:
            self.page.once("dialog", lambda d: d.accept(reason))
            reject_btn.first.click()
            self.wait_for_idle(timeout=6000)
            return True
        return False

    def close_cartable(self) -> None:
        if self.is_visible(self.ADMIN_CLOSE_BTN):
            self.click(self.ADMIN_CLOSE_BTN)
        self.wait_for_idle()
