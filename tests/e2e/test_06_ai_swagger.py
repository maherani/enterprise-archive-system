"""
Test Suite 6: Air-Gapped AI Swagger UI
Scenarios Covered:
- Scenario 20: AI Swagger UI (Air-gapped verification, OpenAPI spec delivery, endpoint rendering)
"""

import unittest
from playwright.sync_api import sync_playwright
from tests.e2e.config import DESKTOP_VIEWPORT, USERS
from tests.e2e.fixtures.users import UserManager
from tests.e2e.pages.swagger_page import SwaggerPage
from tests.e2e.helpers.failure_reporter import failure_diagnostics


class TestAISwagger(unittest.TestCase):
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

    def test_20_ai_swagger_ui_air_gapped(self):
        """20. AI Swagger UI: Renders air-gapped documentation with zero external CDN dependencies."""
        context = self.user_mgr.get_storage_context(self.browser, "admin")
        page = context.new_page()
        page.set_viewport_size(DESKTOP_VIEWPORT)
        swagger = SwaggerPage(page)

        with failure_diagnostics(page, "test_20_ai_swagger_ui_air_gapped"):
            swagger.navigate()

            # Verify Container & Title
            self.assertTrue(page.is_visible(swagger.CONTAINER), "Swagger container should be visible")
            title = swagger.get_api_title()
            self.assertIn("Enterprise Archive AI", title, f"Unexpected API Title: {title}")

            # Verify Authorize Button is present
            self.assertTrue(page.is_visible(swagger.AUTH_BTN), "Authorize button must be visible")

            # Verify Endpoints are listed
            ep_count = swagger.get_endpoints_count()
            self.assertGreaterEqual(ep_count, 1, f"Expected at least 1 API endpoint card, found: {ep_count}")

            # Air-Gapped Security Assertion: No external CDNs or unapproved domains
            is_air_gapped = swagger.is_air_gapped()
            self.assertTrue(is_air_gapped, "Swagger UI violated air-gapped isolation by loading external scripts/styles!")

        context.close()


if __name__ == "__main__":
    unittest.main()
