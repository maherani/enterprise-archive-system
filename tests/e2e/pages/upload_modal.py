"""
UploadModal Object Model for Archive File Ingestion
"""

from pathlib import Path
from playwright.sync_api import Page, expect
from tests.e2e.pages.base_page import BasePage


class UploadModal(BasePage):
    MODAL = ".ea-modal-card:has(#ea-upload-file-input), #ea-upload-modal"
    FILE_INPUT = "#ea-upload-file-input"
    DROPZONE = "#ea-dropzone-box"
    TARGET_SELECT = "#ea-upload-target-select"
    SUBMIT_BTN = "#ea-upload-submit-btn"
    CANCEL_BTN = "#ea-upload-cancel-btn"
    CLOSE_BTN = "#ea-upload-modal-close-btn"
    FILE_NAME_DISPLAY = "#ea-upload-file-name"
    PROGRESS_BAR = "#ea-upload-progress-fill"

    def is_opened(self) -> bool:
        return self.is_visible(self.DROPZONE) or self.is_visible(self.SUBMIT_BTN)

    def select_file(self, file_path: str) -> None:
        self.page.set_input_files(self.FILE_INPUT, file_path)
        self.wait_for_selector(self.FILE_NAME_DISPLAY, state="visible")

    def select_target_folder(self, folder_path: str) -> None:
        if self.is_visible(self.TARGET_SELECT):
            self.page.select_option(self.TARGET_SELECT, label=folder_path)

    def submit_upload(self) -> None:
        self.click(self.SUBMIT_BTN)
        # Wait for upload to complete and modal to close
        self.wait_for_idle(timeout=6000)

    def close(self) -> None:
        if self.is_visible(self.CLOSE_BTN):
            self.click(self.CLOSE_BTN)
        elif self.is_visible(self.CANCEL_BTN):
            self.click(self.CANCEL_BTN)
