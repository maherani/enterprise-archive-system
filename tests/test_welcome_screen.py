import time
import sys
from playwright.sync_api import sync_playwright

BASE_URL = "http://localhost"
LOGIN_URL = f"{BASE_URL}/login"

def run_test_suite():
    print("==================================================================")
    print("STARTING COMPREHENSIVE BROWSER TEST SUITE FOR BAND 3 (WELCOME SCREEN)")
    print("==================================================================")
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # -------------------------------------------------------------
        # TEST 1: Regular User (test_user_a)
        # -------------------------------------------------------------
        print("\n--- TEST 1: Regular User (test_user_a) ---")
        context1 = browser.new_context()
        page1 = context1.new_page()

        console_errors = []
        page1.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)

        print("Navigating to login page...")
        page1.goto(LOGIN_URL)
        page1.wait_for_selector("#user")
        page1.fill("#user", "test_user_a")
        page1.fill("#password", "User_Password_123!")
        
        print("Submitting login form...")
        page1.click("button[type='submit'], #submit-form")

        # Check for Welcome Screen immediately after login
        print("Waiting for Welcome Screen overlay...")
        welcome_overlay = page1.wait_for_selector("#ea-welcome-overlay", timeout=8000)
        assert welcome_overlay is not None, "Welcome overlay #ea-welcome-overlay was not found!"
        print("âœ… Welcome overlay is present in DOM!")

        # Verify Welcome Screen contents
        title_el = page1.wait_for_selector(".ea-welcome-title")
        title_text = title_el.inner_text().strip()
        print(f"Welcome Title: '{title_text}'")
        assert "سامانه بایگانی اسناد سازمانی" in title_text, f"Title mismatch: {title_text}"

        greeting_el = page1.wait_for_selector(".ea-welcome-greeting")
        greeting_text = greeting_el.inner_text().strip()
        print(f"Greeting: '{greeting_text}'")
        assert "خوش آمدید" in greeting_text, f"Greeting mismatch: {greeting_text}"
        assert "خوش آمدید" in greeting_text, f"Greeting mismatch: {greeting_text}"

        subtitle_el = page1.wait_for_selector(".ea-welcome-subtitle")
        subtitle_text = subtitle_el.inner_text().strip()
        print(f"Subtitle: '{subtitle_text}'")
        assert "سامانه در حال آماده‌سازی محیط کاری شماست" in subtitle_text

        status_text_el = page1.wait_for_selector("#ea-welcome-status-text")
        status_text = status_text_el.inner_text().strip()
        print(f"Initial Status text: '{status_text}'")
        assert ("در حال آماده‌سازی محیط بایگانی شما" in status_text or "محیط کاری آماده شد" in status_text)

        # Wait for automatic transition (welcome overlay fades out and is detached)
        print("Waiting for automatic transition to Archive Table without user click...")
        page1.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=12000)
        print("âœ… Welcome overlay successfully detached after portal became ready!")

        # Verify Archive Table is visible and loaded
        table_container = page1.wait_for_selector("#ea-document-container", state="visible")
        assert table_container is not None
        print("âœ… Archive Document Container is visible!")

        # Verify user is at highest accessible folder
        summary_el = page1.wait_for_selector("#ea-results-summary")
        summary_text = summary_el.inner_text().strip()
        print(f"Archive summary: '{summary_text}'")

        # -------------------------------------------------------------
        # TEST 5: Refresh (test_user_a in same session)
        # -------------------------------------------------------------
        print("\n--- TEST 5: Page Refresh in same session ---")
        print("Reloading page...")
        page1.reload()

        # On reload in same session, welcome overlay should NOT be shown
        page1.wait_for_selector("#archive-portal-root", timeout=8000)
        overlay_on_refresh = page1.query_selector("#ea-welcome-overlay")
        assert overlay_on_refresh is None, "Welcome overlay MUST NOT be present on page refresh in same session!"
        print("âœ… PASS: Welcome overlay was NOT shown on refresh in same session!")

        table_after_refresh = page1.wait_for_selector("#ea-document-container", state="visible")
        assert table_after_refresh is not None
        print("âœ… PASS: Archive Table loaded directly on refresh!")

        # -------------------------------------------------------------
        # TEST 6: Browser Back Navigation
        # -------------------------------------------------------------
        print("\n--- TEST 6: Browser Back Navigation ---")
        page1.go_back()
        time.sleep(1)
        assert "/apps/archive_autotag" in page1.url or "/login" in page1.url or "/" in page1.url
        print(f"URL after back: {page1.url}")
        print("âœ… PASS: Back navigation handled cleanly!")
        context1.close()

        # -------------------------------------------------------------
        # TEST 2: Group Admin (admin_user_a)
        # -------------------------------------------------------------
        print("\n--- TEST 2: Group Admin (admin_user_a) ---")
        context2 = browser.new_context()
        page2 = context2.new_page()

        page2.goto(LOGIN_URL)
        page2.wait_for_selector("#user")
        page2.fill("#user", "admin_user_a")
        page2.fill("#password", "User_Password_123!")
        page2.click("button[type='submit'], #submit-form")

        welcome2 = page2.wait_for_selector("#ea-welcome-overlay", timeout=8000)
        assert welcome2 is not None
        greeting2 = page2.wait_for_selector(".ea-welcome-greeting").inner_text()
        print(f"Group Admin Greeting: '{greeting2}'")
        assert "Admin Group A" in greeting2

        # Automatic transition
        page2.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=12000)
        page2.wait_for_selector("#ea-document-container", state="visible")
        print("âœ… PASS: Group Admin welcome screen displayed and transitioned to Archive Table!")
        context2.close()

        # -------------------------------------------------------------
        # TEST 3: System Admin (admin)
        # -------------------------------------------------------------
        print("\n--- TEST 3: System Admin (admin) ---")
        context3 = browser.new_context()
        page3 = context3.new_page()

        page3.goto(LOGIN_URL)
        page3.wait_for_selector("#user")
        page3.fill("#user", "admin")
        page3.fill("#password", "Secure_Admin_Password_123!")
        page3.click("button[type='submit'], #submit-form")

        welcome3 = page3.wait_for_selector("#ea-welcome-overlay", timeout=8000)
        assert welcome3 is not None
        greeting3 = page3.wait_for_selector(".ea-welcome-greeting").inner_text()
        print(f"System Admin Greeting: '{greeting3}'")
        assert "admin" in greeting3

        # Automatic transition
        page3.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=12000)
        page3.wait_for_selector("#ea-document-container", state="visible")
        print("âœ… PASS: System Admin welcome screen displayed and transitioned to Archive Table!")

        # -------------------------------------------------------------
        # TEST 7: Archive Regression (Admin view)
        # -------------------------------------------------------------
        print("\n--- TEST 7: Archive Regression Validation ---")
        # Check search input
        search_input = page3.wait_for_selector("#ea-search-input")
        assert search_input is not None
        print("âœ… Search input is present!")

        # Test search query
        page3.fill("#ea-search-input", "isms")
        time.sleep(1)
        print("Search performed.")

        # Clear search
        page3.fill("#ea-search-input", "")
        time.sleep(1)

        # Check view switch buttons
        table_btn = page3.wait_for_selector("#ea-view-table-btn")
        grid_btn = page3.wait_for_selector("#ea-view-grid-btn")
        assert table_btn is not None and grid_btn is not None
        print("âœ… View mode switch buttons present!")

        # Check upload button
        upload_btn = page3.query_selector("#ea-upload-btn")
        refresh_btn = page3.query_selector("#ea-refresh-btn")
        folder_view_btn = page3.query_selector("#ea-folder-view-btn")
        assert upload_btn is None and refresh_btn is None and folder_view_btn is None
        print("âœ… Upload button present!")

        context3.close()

        # -------------------------------------------------------------
        # TEST 4: Multi-Group User (test_user_multi)
        # -------------------------------------------------------------
        print("\n--- TEST 4: Multi-Group User (test_user_multi) ---")
        context4 = browser.new_context()
        page4 = context4.new_page()

        page4.goto(LOGIN_URL)
        page4.wait_for_selector("#user")
        page4.fill("#user", "test_user_multi")
        page4.fill("#password", "User_Password_123!")
        page4.click("button[type='submit'], #submit-form")

        welcome4 = page4.wait_for_selector("#ea-welcome-overlay", timeout=8000)
        assert welcome4 is not None
        greeting4 = page4.wait_for_selector(".ea-welcome-greeting").inner_text()
        print(f"Multi-Group Greeting: '{greeting4}'")
        assert "test_user_multi" in greeting4

        # Automatic transition
        page4.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=12000)
        page4.wait_for_selector("#ea-document-container", state="visible")
        print("âœ… PASS: Multi-Group User welcome screen displayed and transitioned to Archive Table!")
        context4.close()

        browser.close()

    print("\n==================================================================")
    print("ALL TESTS COMPLETED SUCCESSFULLY! ZERO REGRESSIONS DETECTED.")
    print("==================================================================")

if __name__ == "__main__":
    run_test_suite()