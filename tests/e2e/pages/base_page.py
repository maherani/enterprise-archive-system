"""
Base Page Object for Playwright E2E Tests
"""

import time
from typing import Optional
from playwright.sync_api import Page, Locator, Response, expect
from tests.e2e.config import DEFAULT_TIMEOUT, ACTION_TIMEOUT, SCREENSHOTS_DIR


class BasePage:
    def __init__(self, page: Page):
        self.page = page
        self.timeout = DEFAULT_TIMEOUT

    def goto(self, url: str, wait_until: str = "domcontentloaded") -> Optional[Response]:
        return self.page.goto(url, wait_until=wait_until, timeout=self.timeout)

    def wait_for_url(self, url_or_pattern: str, timeout: Optional[int] = None) -> None:
        self.page.wait_for_url(url_or_pattern, timeout=timeout or self.timeout)

    def wait_for_selector(self, selector: str, state: str = "visible", timeout: Optional[int] = None) -> Locator:
        loc = self.page.locator(selector)
        loc.wait_for(state=state, timeout=timeout or self.timeout)
        return loc

    def click(self, selector: str, timeout: Optional[int] = None) -> None:
        self.page.locator(selector).click(timeout=timeout or ACTION_TIMEOUT)

    def fill(self, selector: str, text: str, timeout: Optional[int] = None) -> None:
        self.page.locator(selector).fill(text, timeout=timeout or ACTION_TIMEOUT)

    def text_content(self, selector: str) -> str:
        return (self.page.locator(selector).text_content() or "").strip()

    def is_visible(self, selector: str, timeout: int = 3000) -> bool:
        try:
            return self.page.locator(selector).is_visible(timeout=timeout)
        except Exception:
            return False

    def wait_for_idle(self, timeout: int = 4000) -> None:
        try:
            self.page.wait_for_load_state("networkidle", timeout=timeout)
        except Exception:
            pass

    def take_screenshot(self, name: str) -> str:
        timestamp = int(time.time() * 1000)
        path = SCREENSHOTS_DIR / f"{name}_{timestamp}.png"
        self.page.screenshot(path=str(path), full_page=True)
        return str(path)
