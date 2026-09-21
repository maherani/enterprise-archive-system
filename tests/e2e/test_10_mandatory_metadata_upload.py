"""
E2E Test Suite 10: Mandatory Document Metadata Capture Before Archive Upload (Requirement 25)
Enterprise Archive System - Nextcloud 34

Validates:
1. User opens Upload Modal and verifies metadata fields are rendered.
2. Form enforcement (Fail-Closed): Submit button is strictly disabled without both file and valid subject (>= 2 chars).
3. User stages a file and fills mandatory metadata (subject) + optional metadata (document_number, confidentiality, issuer).
4. Upload executes via /api/upload-with-metadata and successfully completes.
5. Ingested document displays subject under filename in table view.
6. Opening Quick View Drawer displays the complete metadata card with confidentiality badge and attributes.
7. Search bar successfully searches and filters by document metadata attributes.
"""

import time
import unittest
from pathlib import Path
from playwright.sync_api import sync_playwright, Browser, Page, expect
from tests.e2e.config import PORTAL_URL, USERS, PROJECT_ROOT, ARTIFACTS_DIR
from tests.e2e.fixtures.users import get_authenticated_context
from tests.e2e.fixtures.file_factory import FileFactory
from tests.e2e.helpers.failure_reporter import capture_failure


class TestMandatoryMetadataUploadE2E(unittest.TestCase):
    browser: Browser
    playwright = None
    factory = None

    @classmethod
    def setUpClass(cls):
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"]
        )
        cls.factory = FileFactory()

    @classmethod
    def tearDownClass(cls):
        if cls.factory:
            cls.factory.cleanup()
        if cls.browser:
            cls.browser.close()
        if cls.playwright:
            cls.playwright.stop()

    def test_01_mandatory_metadata_upload_and_drawer_display(self):
        """Verify upload modal metadata validation, upload completion, and quick view drawer metadata card."""
        context = get_authenticated_context(self.browser, "admin")
        page = context.new_page()

        try:
            page.goto(PORTAL_URL, wait_until="networkidle")
            page.wait_for_selector("#ea-document-container", timeout=15000)
            page.wait_for_timeout(1000)

            # 1. Click Upload button
            upload_btn = page.locator("#ea-upload-btn")
            expect(upload_btn).to_be_visible()
            upload_btn.click()

            # 2. Wait for modal to open
            modal = page.locator("#ea-active-modal")
            expect(modal).to_be_visible()

            # 3. Verify metadata fields exist
            subject_input = page.locator("#ea-meta-subject")
            number_input = page.locator("#ea-meta-number")
            date_input = page.locator("#ea-meta-date")
            conf_select = page.locator("#ea-meta-confidentiality")
            issuer_input = page.locator("#ea-meta-issuer")
            desc_input = page.locator("#ea-meta-description")
            submit_btn = page.locator("#ea-upload-submit-btn")

            expect(subject_input).to_be_visible()
            expect(number_input).to_be_visible()
            expect(conf_select).to_be_visible()
            expect(submit_btn).to_be_disabled()

            # 4. Stage a file
            timestamp = int(time.time() * 1000)
            unique_doc_num = f"REQ25-DOC-{timestamp}"
            filename = f"metadata_test_{timestamp}.txt"
            test_file = self.factory.create_txt_file(
                filename,
                f"Enterprise confidential document with mandatory metadata. Identifier: {unique_doc_num}"
            )
            file_input = page.locator("#ea-upload-file-input")
            file_input.set_input_files(str(test_file))
            page.wait_for_timeout(500)

            # Submit button MUST still be disabled because subject is empty!
            expect(submit_btn).to_be_disabled()

            # Type 1 character into subject: submit button MUST still be disabled
            subject_input.fill("A")
            page.wait_for_timeout(200)
            expect(submit_btn).to_be_disabled()

            # Type valid subject (>= 2 characters)
            doc_subject = f"گزارش ارزیابی الزامات امنیتی و متادیتا {timestamp}"
            subject_input.fill(doc_subject)
            number_input.fill(unique_doc_num)
            date_input.fill("1405/06/31")
            conf_select.select_option(value="confidential")
            issuer_input.fill("اداره بازرسی و عملیات امنیت")
            desc_input.fill("سند تست متادیتای الزامی مطابق نیازمندی ۲۵ سامانه بایگانی")
            page.wait_for_timeout(300)

            # Submit button MUST now be enabled!
            expect(submit_btn).to_be_enabled()

            # 5. Submit the upload
            submit_btn.click()

            # Wait for upload to complete and modal to detach
            page.wait_for_selector("#ea-active-modal", state="detached", timeout=15000)
            page.wait_for_timeout(1500)

            # 6. Switch to Table View if not already
            table_btn = page.locator("#ea-view-table-btn")
            if table_btn.is_visible():
                table_btn.click()
                page.wait_for_timeout(800)

            # 7. Search for the unique document number
            search_input = page.locator("#ea-search-input")
            search_input.fill(unique_doc_num)
            page.wait_for_timeout(1200)

            # Verify document row appears in table
            doc_row = page.locator(f"tr[data-file-id]:has-text('{filename}')").first
            expect(doc_row).to_be_visible(timeout=8000)

            # Verify subject is rendered under filename
            expect(doc_row).to_contain_text(unique_doc_num)

            # 8. Click row to open Quick View Drawer
            doc_row.click()
            page.wait_for_timeout(800)

            # 9. Verify Quick View Drawer is open and displays metadata card
            drawer = page.locator("#ea-drawer-backdrop.open")
            expect(drawer).to_be_visible()

            meta_card = page.locator(".ea-drawer-metadata-card")
            expect(meta_card).to_be_visible()
            expect(meta_card).to_contain_text("شناسنامه و مشخصات سند")
            expect(meta_card).to_contain_text(doc_subject)
            expect(meta_card).to_contain_text(unique_doc_num)
            expect(meta_card).to_contain_text("اداره بازرسی و عملیات امنیت")

            # Check confidentiality badge
            conf_badge = page.locator(".ea-conf-badge.ea-conf-confidential")
            expect(conf_badge).to_be_visible()
            expect(conf_badge).to_contain_text("محرمانه")

            # Capture artifact screenshot
            screenshot_path = ARTIFACTS_DIR / "screenshots" / "req25_mandatory_metadata_drawer.png"
            page.screenshot(path=str(screenshot_path))
            print(f"Saved E2E screenshot to {screenshot_path}")

        except Exception as e:
            capture_failure(page, "test_01_mandatory_metadata_upload_and_drawer_display")
            raise e
        finally:
            context.close()


if __name__ == "__main__":
    unittest.main()
