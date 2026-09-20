"""
TagDrawer POM for Group Tag Governance and Reconciliation
"""

from typing import List, Dict
from playwright.sync_api import Page, expect
from tests.e2e.pages.base_page import BasePage


class TagDrawer(BasePage):
    TRIGGER_BTN = "#ea-manage-group-tags-btn"
    MODAL = "#ea-active-modal"
    NAME_INPUT = "#ea-gtag-name-input"
    GROUP_SELECT = "#ea-gtag-group-select"
    CREATE_BTN = "#ea-gtag-create-btn"
    RECONCILE_BTN = "#ea-gtag-reconcile-btn"
    LIST_CONTAINER = "#ea-gtag-list-container"
    CLOSE_BTN = "#ea-gtag-close-bottom-btn, #ea-modal-close-btn"
    MSG_CONTAINER = "#ea-gtag-create-msg"

    def open_drawer(self) -> None:
        self.click(self.TRIGGER_BTN)
        self.wait_for_selector(self.LIST_CONTAINER, state="visible")

    def create_tag(self, tag_name: str, group_id: str = "") -> None:
        self.fill(self.NAME_INPUT, tag_name)
        if group_id and self.is_visible(self.GROUP_SELECT):
            try:
                self.page.select_option(self.GROUP_SELECT, value=group_id)
            except Exception:
                pass
        self.click(self.CREATE_BTN)
        self.wait_for_idle()

    def reconcile_tags(self) -> None:
        self.click(self.RECONCILE_BTN)
        self.wait_for_idle()

    def get_tag_names(self) -> List[str]:
        pills = self.page.locator(f"{self.LIST_CONTAINER} .ea-mini-tag, {self.LIST_CONTAINER} tr td:first-child").all()
        return [p.text_content().strip() for p in pills if p.text_content()]

    def get_message(self) -> str:
        if self.is_visible(self.MSG_CONTAINER):
            return self.text_content(self.MSG_CONTAINER)
        return ""

    def close(self) -> None:
        if self.is_visible(self.CLOSE_BTN):
            self.click(self.CLOSE_BTN)
        self.wait_for_idle()
