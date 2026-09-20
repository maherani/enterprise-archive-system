"""
SwaggerPage POM for AI Offline Documentation
"""

from typing import List
from playwright.sync_api import Page, expect
from tests.e2e.config import SWAGGER_URL
from tests.e2e.pages.base_page import BasePage


class SwaggerPage(BasePage):
    CONTAINER = ".container"
    TITLE = ".info-card h1"
    OPBLOCKS = ".endpoint-card"
    AUTH_BTN = "#btn-auth"
    TOPBAR_BRAND = ".topbar-brand"

    def navigate(self) -> None:
        self.goto(SWAGGER_URL)
        self.wait_for_selector(self.CONTAINER, state="visible")
        self.wait_for_idle()

    def get_api_title(self) -> str:
        return self.text_content(self.TITLE)

    def get_endpoints_count(self) -> int:
        return self.page.locator(self.OPBLOCKS).count()

    def is_air_gapped(self) -> bool:
        # Check that no external script or style resources were fetched
        scripts = self.page.locator("script").all()
        for s in scripts:
            src = s.get_attribute("src") or ""
            if src.startswith("http") and not ("127.0.0.1" in src or "localhost" in src):
                return False
        
        styles = self.page.locator("link[rel='stylesheet']").all()
        for st in styles:
            href = st.get_attribute("href") or ""
            if href.startswith("http") and not ("127.0.0.1" in href or "localhost" in href):
                return False
        return True
