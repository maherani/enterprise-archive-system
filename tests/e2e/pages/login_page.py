"""
LoginPage Object Model for Nextcloud Authentication
"""

from playwright.sync_api import Page, expect
from tests.e2e.config import LOGIN_URL, PORTAL_URL
from tests.e2e.pages.base_page import BasePage


class LoginPage(BasePage):
    USER_INPUT = "#user, input[name='user']"
    PASSWORD_INPUT = "#password, input[name='password']"
    SUBMIT_BUTTON = "button[type='submit'], #submit-form"
    ERROR_CONTAINER = ".input-field__helper-text-message--error"

    def navigate(self) -> None:
        self.goto(LOGIN_URL)

    def login(self, username: str, password: str) -> None:
        self.navigate()
        self.wait_for_selector(self.USER_INPUT)
        self.fill(self.USER_INPUT, username)
        self.fill(self.PASSWORD_INPUT, password)
        self.click(self.SUBMIT_BUTTON)
        
        # Wait until login inputs are gone or URL redirects away
        try:
            self.page.wait_for_selector(self.USER_INPUT, state="detached", timeout=8000)
        except Exception:
            self.page.wait_for_load_state("networkidle")

    def is_logged_in(self) -> bool:
        return not self.is_visible(self.USER_INPUT, timeout=2000)
