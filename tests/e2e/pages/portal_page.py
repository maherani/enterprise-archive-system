"""
PortalPage Object Model for Archive Portal SPA
"""

from typing import List, Dict, Any
from playwright.sync_api import Page, Locator, expect
from tests.e2e.config import PORTAL_URL
from tests.e2e.pages.base_page import BasePage


class PortalPage(BasePage):
    CONTAINER = "#ea-document-container"
    SEARCH_INPUT = "#ea-search-input"
    SEARCH_CLEAR = "#ea-search-clear"
    SEARCH_COUNT = "#ea-search-count"
    REFRESH_BTN = "#ea-refresh-btn"
    UPLOAD_BTN = "#ea-upload-btn"
    FOLDER_REQ_BTN = "#ea-create-folder-req-btn"
    ADMIN_MANAGE_REQS_BTN = "#ea-admin-manage-reqs-btn"
    MANAGE_GROUP_TAGS_BTN = "#ea-manage-group-tags-btn"
    TAG_BAR_CONTAINER = "#ea-tag-bar-container"
    CLEAR_FILTERS_BTN = "#ea-clear-all-filters-btn"
    
    # Global Nav
    GLOBAL_NAV = "#archive-global-nav"
    NAV_BREADCRUMBS = ".archive-nav-breadcrumb-item, .ea-breadcrumb-item"

    # Table elements
    FILE_ROW = ".ea-file-row, tr.ea-row, .ea-item-card"
    FILE_NAME_CELL = ".ea-col-name, .ea-file-name"
    FILE_TAGS_CELL = ".ea-col-tags, .ea-file-tags"
    FILE_SIZE_CELL = ".ea-col-size, .ea-file-size"
    FILE_DATE_CELL = ".ea-col-date, .ea-file-date"

    # Drawer / Modals
    DRAWER_PANEL = "#ea-drawer-panel"
    DRAWER_BACKDROP = "#ea-drawer-backdrop"
    DRAWER_CLOSE_BTN = "#ea-drawer-close-btn"
    DRAWER_LOCATE_BTN = "#ea-drawer-locate-btn"

    def navigate(self) -> None:
        self.goto(PORTAL_URL)
        self.wait_until_loaded()

    def wait_until_loaded(self) -> None:
        self.wait_for_selector(self.CONTAINER, state="visible")
        self.wait_for_idle()

    def get_file_names(self) -> List[str]:
        rows = self.page.locator(f"{self.CONTAINER} {self.FILE_NAME_CELL}").all()
        return [r.text_content().strip() for r in rows if r.text_content()]

    def search(self, text: str) -> None:
        self.fill(self.SEARCH_INPUT, text)
        self.page.wait_for_timeout(400)  # Debounce
        self.wait_for_idle()

    def clear_search(self) -> None:
        if self.is_visible(self.SEARCH_CLEAR):
            self.click(self.SEARCH_CLEAR)
        else:
            self.fill(self.SEARCH_INPUT, "")
        self.wait_for_idle()

    def select_tag(self, tag_name: str) -> None:
        chip = self.page.locator(f"{self.TAG_BAR_CONTAINER} .ea-tag-chip:has-text('{tag_name}')")
        chip.click()
        self.page.wait_for_timeout(300)
        self.wait_for_idle()

    def clear_all_filters(self) -> None:
        if self.is_visible(self.CLEAR_FILTERS_BTN):
            self.click(self.CLEAR_FILTERS_BTN)
            self.page.wait_for_timeout(300)

    def open_folder(self, folder_name: str) -> None:
        folder_loc = self.page.locator(f"{self.CONTAINER} tr:has-text('{folder_name}'), {self.CONTAINER} .ea-item-card:has-text('{folder_name}')")
        folder_loc.click()
        self.wait_for_idle()

    def click_file_for_drawer(self, file_name: str) -> None:
        row = self.page.locator(f"{self.CONTAINER} tr:has-text('{file_name}')")
        row.click()
        self.wait_for_selector(self.DRAWER_PANEL, state="visible")

    def locate_in_folder(self) -> None:
        self.click(self.DRAWER_LOCATE_BTN)
        self.wait_for_idle()

    def get_breadcrumbs(self) -> List[str]:
        items = self.page.locator(self.NAV_BREADCRUMBS).all()
        return [item.text_content().strip() for item in items if item.text_content()]
