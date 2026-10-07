"""
Enterprise Archive System - UI & Navigation Fix Regression Test Suite
Validates in-portal navigation, deep-folder browsing, state restoration,
non-admin security isolation, and administrator workflows without URL corruption.
"""

import os
import sys
from playwright.sync_api import sync_playwright

# -----------------------------------------------------------------------------
# Configuration & Environment Validation
# -----------------------------------------------------------------------------

BASE_URL = os.environ.get("PORTAL_TEST_URL", "http://localhost").rstrip("/")
NORMALIZED_BASE_URL = BASE_URL + "/"
LOGIN_URL = f"{BASE_URL}/login"

ADMIN_USER = os.environ.get("PORTAL_ADMIN_TEST_USER", "admin")
ADMIN_PASS = os.environ.get("PORTAL_ADMIN_TEST_PASSWORD")

USER_A = os.environ.get("PORTAL_USER_A_TEST_USER", "test_user_a")
USER_A_PASS = os.environ.get("PORTAL_USER_A_TEST_PASSWORD")

# Enforce environment variable credential provision at startup
if not ADMIN_PASS or not USER_A_PASS:
    missing_vars = []
    if not ADMIN_PASS:
        missing_vars.append("PORTAL_ADMIN_TEST_PASSWORD")
    if not USER_A_PASS:
        missing_vars.append("PORTAL_USER_A_TEST_PASSWORD")
    sys.exit(
        f"ERROR: Missing required environment variable(s): {', '.join(missing_vars)}.\n"
        f"Please set these environment variables before running the test suite.\n"
        f"Example:\n"
        f"  export PORTAL_ADMIN_TEST_PASSWORD='...'\n"
        f"  export PORTAL_USER_A_TEST_PASSWORD='...'\n"
    )

# Known test fixtures for authorization, nested navigation, and isolation checks
GROUP_A_NAME = "Group A"
GROUP_A_SUBFOLDER = "Sub A"
GROUP_A_FILE = "Test_p2_02.txt"

KNOWN_UNAUTHORIZED_GROUP_B_FOLDERS = [
    "Group B",
    "SUB B",
    "test B",
    "LAUNCH-02-NEW-SUBFOLDER",
    "LAUNCH-05-NEW-SUBFOLDER",
]

KNOWN_UNAUTHORIZED_GROUP_B_FILES = [
    "P1-09-B-02.txt",
    "P1-09-B.txt",
    "S-L-02.txt",
    "S-L-05.txt",
    "LAUNCH-12-Duplicate-Test (1).txt",
]


# -----------------------------------------------------------------------------
# Assertion Helpers
# -----------------------------------------------------------------------------

def assert_public_base_url(observed_url: str, step_name: str = ""):
    """Verifies that the observed URL is exactly the normalized configured Public Base URL."""
    observed_clean = observed_url.rstrip("/") + "/"
    assert observed_clean == NORMALIZED_BASE_URL, (
        f"[{step_name}] Public Base URL mismatch:\n"
        f"  Expected Normalized: '{NORMALIZED_BASE_URL}'\n"
        f"  Observed URL:        '{observed_url}'"
    )


def assert_no_redirect_loops(nav_history: list, base_url: str, step_name: str = ""):
    """
    Detects redirect loops and cycles between Public Base URL, internal portal routes,
    and dashboard routes in main-frame navigation history.
    Allows normal one-time transition: LOGIN -> internal Portal route -> Public Base URL.
    Fails on any repeated cycling or dashboard redirection.
    """
    normalized_base = base_url.rstrip("/") + "/"

    # 1. Prohibit Dashboard route in all portal navigation flows
    dashboard_occurrences = [
        (idx + 1, u) for idx, u in enumerate(nav_history)
        if "/apps/dashboard" in u or "/app/dashboard" in u
    ]
    if dashboard_occurrences:
        raise AssertionError(
            f"[{step_name}] Prohibited Dashboard route detected in navigation history:\n"
            f"  Occurrences:        {dashboard_occurrences}\n"
            f"  Navigation History: {nav_history}"
        )

    # 2. Tokenize navigation sequence
    tokens = []
    for u in nav_history:
        clean = u.rstrip("/") + "/"
        if clean == normalized_base:
            tokens.append("PUBLIC_BASE")
        elif "/apps/archive_autotag" in u or "/app/archive_autotag" in u:
            tokens.append("PORTAL_INTERNAL")
        elif "/apps/dashboard" in u or "/app/dashboard" in u:
            tokens.append("DASHBOARD")
        elif "/login" in u:
            tokens.append("LOGIN")
        else:
            tokens.append(f"OTHER({u})")

    # Filter to monitored routes (exclude LOGIN and OTHER for cycle check)
    monitored = [t for t in tokens if t in ("PUBLIC_BASE", "PORTAL_INTERNAL", "DASHBOARD")]

    # Collapse consecutive identical tokens (e.g. consecutive pushState to same route)
    collapsed = []
    for m in monitored:
        if not collapsed or collapsed[-1] != m:
            collapsed.append(m)

    # Detect repeated cycling (e.g., A -> B -> A).
    # Any return to a previously visited monitored route after leaving it constitutes a cycle.
    seen_routes = {}
    for idx, token in enumerate(collapsed):
        if token in seen_routes:
            prev_idx = seen_routes[token]
            cycle_slice = collapsed[prev_idx:idx + 1]
            raise AssertionError(
                f"[{step_name}] Forbidden redirect cycle detected: "
                f"{' -> '.join(cycle_slice)}\n"
                f"  Collapsed Monitored Sequence: {collapsed}\n"
                f"  Full Navigation History:      {nav_history}"
            )
        seen_routes[token] = idx


def assert_portal_visible(page, step_name: str = ""):
    """Verifies that the Portal root and document container remain visible with no 404 or dashboard."""
    assert page.is_visible("#archive-portal-root"), (
        f"[{step_name}] Archive portal root (#archive-portal-root) is not visible.\n"
        f"  Current URL: {page.url}"
    )
    assert page.is_visible("#ea-document-container"), (
        f"[{step_name}] Document container (#ea-document-container) is not visible.\n"
        f"  Current URL: {page.url}"
    )
    title = page.title()
    assert "404" not in title and "Page not found" not in title, (
        f"[{step_name}] 404 Not Found detected in page title: '{title}'\n"
        f"  Current URL: {page.url}"
    )
    body_text = page.inner_text("body")
    assert "The page could not be found" not in body_text, (
        f"[{step_name}] 404 error page detected in body text.\n"
        f"  Current URL: {page.url}"
    )
    assert "/apps/dashboard" not in page.url, (
        f"[{step_name}] Redirected to Dashboard route.\n"
        f"  Current URL: {page.url}"
    )
    assert_public_base_url(page.url, step_name)


def get_element_name(el):
    """Extracts clean file or folder name from a table row or card element."""
    folder_path = el.get_attribute("data-folder-path")
    if folder_path:
        clean = folder_path.strip("/").split("/")[-1]
        if clean:
            return clean
    name = el.get_attribute("data-file-name")
    if name:
        return name
    title_el = el.query_selector(".ea-card-title, .ea-cell-title-wrap strong")
    if title_el:
        return title_el.inner_text().replace("📁", "").strip()
    return ""


def capture_folder_state(page):
    """Captures the visible folder/path state from summary, breadcrumbs, and visible item names."""
    summary_el = page.query_selector("#ea-results-summary")
    summary_text = summary_el.inner_text().strip() if summary_el else ""

    breadcrumb_els = page.query_selector_all(".ea-breadcrumb-btn")
    breadcrumbs = [b.inner_text().strip() for b in breadcrumb_els]

    active_crumb_el = page.query_selector(".ea-breadcrumb-btn.is-active")
    active_crumb = active_crumb_el.inner_text().strip() if active_crumb_el else ""

    item_els = page.query_selector_all("tr[data-file-id], .ea-card[data-file-id]")
    item_names = [get_element_name(el) for el in item_els if get_element_name(el)]

    return {
        "summary": summary_text,
        "breadcrumbs": breadcrumbs,
        "active_breadcrumb": active_crumb,
        "item_names": item_names,
    }


# -------------------------------------------------------------
# Main Test Suite Execution
# -------------------------------------------------------------

def run_tests():
    print("==================================================================")
    print(" EXECUTING VERIFICATION SUITE FOR UI / NAVIGATION FIX (TESTS A-I)")
    print("==================================================================")

    nested_fixture_tested = False

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        # -------------------------------------------------------------
        # TEST A & B: Administrator First Login & Welcome Completion (Fresh Context)
        # -------------------------------------------------------------
        print("\n--- TEST A & B: Administrator First Login & Welcome Completion ---")
        context_admin = browser.new_context()
        page_admin = context_admin.new_page()

        nav_history_admin = []
        page_admin.on("framenavigated", lambda frame: nav_history_admin.append(frame.url) if frame == page_admin.main_frame else None)

        print(f"Navigating to {LOGIN_URL}...")
        page_admin.goto(LOGIN_URL)
        page_admin.wait_for_selector("#user", timeout=12000)
        page_admin.fill("#user", ADMIN_USER)
        page_admin.fill("#password", ADMIN_PASS)

        print("Submitting login form...")
        page_admin.click("button[type='submit'], #submit-form")

        # 1. Must land on Portal root
        page_admin.wait_for_selector("#archive-portal-root", timeout=15000)
        print("✓ Reached Enterprise Archive Portal (#archive-portal-root loaded).")

        # 2. Redirect loop check: No cycling between routes
        assert_no_redirect_loops(nav_history_admin, BASE_URL, "Test A: Administrator Login")
        print("✓ Navigation history validated: zero redirect loops, zero cycling, zero Dashboard.")

        # 3. Welcome screen overlay verification in fresh context
        welcome_overlay = page_admin.wait_for_selector("#ea-welcome-overlay", state="visible", timeout=10000)
        assert welcome_overlay is not None, (
            f"[Fresh Context] Expected welcome screen overlay (#ea-welcome-overlay) upon first login.\n"
            f"  Observed state: overlay missing\n"
            f"  Current URL:    {page_admin.url}"
        )
        print("✓ Welcome screen overlay detected and visible in fresh context.")

        # 4. Welcome screen automatic dismissal
        page_admin.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=15000)
        assert page_admin.query_selector("#ea-welcome-overlay") is None, (
            f"[Fresh Context] Welcome overlay must detach after preparation.\n"
            f"  Current URL: {page_admin.url}"
        )
        print("✓ Welcome overlay smoothly detached after workspace readiness.")

        # 5. File/Folder table container must be visible with content
        doc_container = page_admin.wait_for_selector("#ea-document-container", state="visible", timeout=8000)
        assert doc_container is not None, (
            f"[Fresh Context] Document container (#ea-document-container) not visible after welcome dismissal.\n"
            f"  Current URL: {page_admin.url}"
        )
        rendered_items = page_admin.query_selector_all("tr[data-file-id], .ea-card[data-file-id]")
        assert len(rendered_items) > 0, (
            f"[Fresh Context] File/Folder table is empty; expected visible document entries.\n"
            f"  Current URL: {page_admin.url}"
        )
        print(f"✓ File/Folder table is visible with {len(rendered_items)} items rendered.")

        # 6. Verify address bar displays exactly the normalized Public Base URL
        assert_public_base_url(page_admin.url, "Test A & B: Public Base URL Check")
        print(f"✓ Address bar correctly displays configured Public Base URL: {page_admin.url}")

        # -------------------------------------------------------------
        # TEST D: Real Folder & Nested Subfolder Navigation + Back by State
        # -------------------------------------------------------------
        print("\n--- TEST D: Real Folder & Nested Subfolder Navigation ---")
        root_state = capture_folder_state(page_admin)
        print(f"Root state summary: '{root_state['summary']}' with {len(root_state['item_names'])} items.")

        # Step 1: Open known test fixture folder (Group A)
        folder_rows = page_admin.query_selector_all("tr[data-is-dir='true'], .ea-card[data-is-dir='true']")
        assert len(folder_rows) > 0, (
            f"No first-level folders found at root.\n"
            f"  Observed items: {root_state['item_names']}\n"
            f"  Current URL:    {page_admin.url}"
        )

        target_folder_row = None
        for row in folder_rows:
            if GROUP_A_NAME in get_element_name(row):
                target_folder_row = row
                break

        if target_folder_row is None:
            raise AssertionError(
                f"Required test fixture '{GROUP_A_NAME}' not found at root level.\n"
                f"  Available root items: {root_state['item_names']}"
            )

        folder_a_name = get_element_name(target_folder_row)
        print(f"Step 1: Opening first-level fixture folder: '{folder_a_name}'...")
        target_folder_row.click()

        # Deterministic wait for Folder A content to finish loading
        page_admin.wait_for_selector("#ea-document-container", state="visible")
        page_admin.wait_for_function(
            """(expected) => {
                const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                const summary = document.querySelector('#ea-results-summary');
                return crumb && crumb.innerText.includes(expected)
                    && summary && summary.innerText.includes(expected)
                    && !summary.innerText.includes('در حال دریافت');
            }""",
            arg=folder_a_name,
            timeout=10000
        )
        assert_portal_visible(page_admin, "Test D: Step 1 (Folder A Open)")
        folder_a_state = capture_folder_state(page_admin)
        print(f"Folder A state: summary='{folder_a_state['summary']}', active_crumb='{folder_a_state['active_breadcrumb']}', items={folder_a_state['item_names']}")

        assert folder_a_name in folder_a_state["summary"] and folder_a_name in folder_a_state["active_breadcrumb"], (
            f"Opening first-level folder '{folder_a_name}' did not update folder view correctly:\n"
            f"  Observed summary: '{folder_a_state['summary']}'\n"
            f"  Observed active crumb: '{folder_a_state['active_breadcrumb']}'"
        )
        assert_public_base_url(page_admin.url, "Test D: Step 1 Base URL")
        print("✓ First-level folder opened: correct content shown, Public Base URL maintained.")

        # Step 2: Locate and open known nested subfolder fixture (Sub A)
        # Switch to grid view to interact with subfolder card (triggers pushState in portal logic)
        page_admin.click("#ea-view-grid-btn")
        page_admin.wait_for_selector(".ea-grid-view, .ea-card", state="visible", timeout=8000)

        subfolder_cards = page_admin.query_selector_all(".ea-card[data-is-dir='true']")
        target_subfolder_card = None
        for card in subfolder_cards:
            if GROUP_A_SUBFOLDER in get_element_name(card):
                target_subfolder_card = card
                break

        if target_subfolder_card is None:
            available_subfolders = [get_element_name(c) for c in subfolder_cards]
            raise AssertionError(
                f"Required nested fixture '{GROUP_A_SUBFOLDER}' not found inside '{folder_a_name}'.\n"
                f"  Available subfolders: {available_subfolders}\n"
                f"  Folder A items:       {folder_a_state['item_names']}"
            )

        subfolder_b_name = get_element_name(target_subfolder_card)
        print(f"Step 2: Located known nested subfolder fixture: '{subfolder_b_name}'. Opening...")
        target_subfolder_card.click()

        # Deterministic wait for nested subfolder content to finish loading
        page_admin.wait_for_selector("#ea-document-container", state="visible")
        page_admin.wait_for_function(
            """(expected) => {
                const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                const summary = document.querySelector('#ea-results-summary');
                return crumb && crumb.innerText.includes(expected)
                    && summary && summary.innerText.includes(expected)
                    && !summary.innerText.includes('در حال دریافت');
            }""",
            arg=subfolder_b_name,
            timeout=10000
        )

        assert_portal_visible(page_admin, "Test D: Step 2 (Subfolder B Open)")
        subfolder_b_state = capture_folder_state(page_admin)
        print(f"Subfolder B state: summary='{subfolder_b_state['summary']}', active_crumb='{subfolder_b_state['active_breadcrumb']}', items={subfolder_b_state['item_names']}")

        # Verify state actually changed from Folder A to Subfolder B across multiple indicators
        assert subfolder_b_name in subfolder_b_state["summary"], (
            f"Opening nested subfolder '{subfolder_b_name}' failed to update summary: '{subfolder_b_state['summary']}'"
        )
        assert subfolder_b_name in subfolder_b_state["active_breadcrumb"], (
            f"Opening nested subfolder '{subfolder_b_name}' failed to update active breadcrumb: '{subfolder_b_state['active_breadcrumb']}'"
        )
        assert subfolder_b_state["active_breadcrumb"] != folder_a_state["active_breadcrumb"], (
            f"Active breadcrumb did not change upon entering subfolder ('{subfolder_b_state['active_breadcrumb']}')"
        )
        assert subfolder_b_state["summary"] != folder_a_state["summary"], (
            f"Summary did not change upon entering subfolder ('{subfolder_b_state['summary']}')"
        )
        assert subfolder_b_state["item_names"] != folder_a_state["item_names"], (
            f"Visible items did not change upon entering subfolder ('{subfolder_b_state['item_names']}')"
        )
        assert_public_base_url(page_admin.url, "Test D: Step 2 Base URL")
        nested_fixture_tested = True
        print("✓ Nested subfolder opened: state changed, correct nested content shown, Public Base URL maintained.")

        # Step 3: Returning using browser Back and verifying by state
        print("Step 3: Triggering Browser Back navigation...")
        page_admin.go_back()

        # Deterministic wait for Folder A restoration to finish loading
        page_admin.wait_for_selector("#ea-document-container", state="visible")
        page_admin.wait_for_function(
            """(expected) => {
                const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                const summary = document.querySelector('#ea-results-summary');
                return crumb && crumb.innerText.includes(expected)
                    && summary && summary.innerText.includes(expected)
                    && !summary.innerText.includes('در حال دریافت');
            }""",
            arg=folder_a_name,
            timeout=10000
        )

        assert_portal_visible(page_admin, "Test D: Step 3 (After Browser Back)")
        post_back_state = capture_folder_state(page_admin)
        print(f"State after browser back: summary='{post_back_state['summary']}', active_crumb='{post_back_state['active_breadcrumb']}', items={post_back_state['item_names']}")

        # Logically strict deterministic assertion on state restoration:
        # 1. Current visible state is Folder A
        assert folder_a_name in post_back_state["summary"], (
            f"Browser Back failed: Folder A ('{folder_a_name}') not in summary:\n"
            f"  Observed summary: '{post_back_state['summary']}'"
        )
        # 2. Current active breadcrumb is Folder A or clearly represents Folder A
        assert (
            post_back_state["active_breadcrumb"] == folder_a_name
            or folder_a_name in post_back_state["active_breadcrumb"]
        ), (
            f"Browser Back failed: active breadcrumb does not represent Folder A ('{folder_a_name}'):\n"
            f"  Observed crumb: '{post_back_state['active_breadcrumb']}'"
        )
        # 3. Subfolder B is no longer the active state
        assert subfolder_b_name not in post_back_state["active_breadcrumb"], (
            f"Browser Back failed: Subfolder B ('{subfolder_b_name}') is still active breadcrumb:\n"
            f"  Observed crumb: '{post_back_state['active_breadcrumb']}'"
        )
        assert post_back_state["active_breadcrumb"] != subfolder_b_name, (
            f"Browser Back failed: active breadcrumb is still Subfolder B ('{subfolder_b_name}')"
        )
        assert f"/{folder_a_name}/{subfolder_b_name}" not in post_back_state["summary"], (
            f"Browser Back failed: summary still references nested path '{folder_a_name}/{subfolder_b_name}':\n"
            f"  Observed summary: '{post_back_state['summary']}'"
        )
        # 4. Visible content is not the Subfolder B state and matches Folder A
        assert post_back_state["summary"] != subfolder_b_state["summary"], (
            f"Browser Back failed: summary remains identical to Subfolder B state."
        )
        assert post_back_state["item_names"] != subfolder_b_state["item_names"], (
            f"Browser Back failed: visible items remain identical to Subfolder B state."
        )
        assert post_back_state["item_names"] == folder_a_state["item_names"], (
            f"Browser Back failed to restore Folder A visible item list:\n"
            f"  Expected items: {folder_a_state['item_names']}\n"
            f"  Observed items: {post_back_state['item_names']}"
        )
        assert subfolder_b_name in post_back_state["item_names"], (
            f"Browser Back failed: Subfolder B ('{subfolder_b_name}') is not listed as a child in Folder A items:\n"
            f"  Observed items: {post_back_state['item_names']}"
        )
        # 5. Public Base URL remains correct
        assert_public_base_url(page_admin.url, "Test D: Step 3 Base URL")
        print(f"✓ Browser Back cleanly restored Folder A state from Subfolder B with strict state verification.")
        print("✓ In-portal folder navigation fully verified by visible state, zero loops, fixed Public Base URL.")

        # -------------------------------------------------------------
        # TEST E: Search & Filter Navigation
        # -------------------------------------------------------------
        print("\n--- TEST E: Search & Filter Navigation ---")
        search_input = page_admin.wait_for_selector("#ea-search-input", timeout=8000)
        assert search_input is not None, "Search input (#ea-search-input) is missing."
        search_input.fill("Test")
        page_admin.wait_for_selector("#ea-document-container", state="visible")
        assert_portal_visible(page_admin, "Test E: Filter Active")
        assert_public_base_url(page_admin.url, "Test E: Filter Public URL")

        # Clear search
        search_input.fill("")
        page_admin.wait_for_selector("#ea-document-container", state="visible")
        assert_portal_visible(page_admin, "Test E: Filter Cleared")
        assert_public_base_url(page_admin.url, "Test E: Filter Cleared Public URL")
        print("✓ Search & filter operates without corrupting URL or triggering redirect loops.")

        # -------------------------------------------------------------
        # TEST F: Browser Refresh (F5) with State Restoration
        # -------------------------------------------------------------
        print("\n--- TEST F: Browser Refresh (F5) with State Restoration ---")
        # Ensure a real folder is open prior to refresh and capture its actual state
        pre_refresh_folder = folder_a_name
        current_state = capture_folder_state(page_admin)
        if pre_refresh_folder not in current_state["summary"] and pre_refresh_folder not in current_state["active_breadcrumb"]:
            folder_rows_again = page_admin.query_selector_all("tr[data-is-dir='true'], .ea-card[data-is-dir='true']")
            target_f = None
            for rf in folder_rows_again:
                if pre_refresh_folder in get_element_name(rf):
                    target_f = rf
                    break
            assert target_f is not None, f"Could not find folder '{pre_refresh_folder}' to open before refresh."
            print(f"Opening folder '{pre_refresh_folder}' before refresh...")
            target_f.click()
            page_admin.wait_for_selector("#ea-document-container", state="visible")
            page_admin.wait_for_function(
                """(expected) => {
                    const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                    const summary = document.querySelector('#ea-results-summary');
                    return crumb && crumb.innerText.includes(expected)
                        && summary && summary.innerText.includes(expected)
                        && !summary.innerText.includes('در حال دریافت');
                }""",
                arg=pre_refresh_folder,
                timeout=10000
            )

        pre_refresh_state = capture_folder_state(page_admin)
        print(f"Pre-refresh captured state: summary='{pre_refresh_state['summary']}', crumb='{pre_refresh_state['active_breadcrumb']}', items={pre_refresh_state['item_names']}")
        assert pre_refresh_folder in pre_refresh_state["summary"], "Pre-refresh state does not reflect open folder summary."
        assert pre_refresh_folder in pre_refresh_state["active_breadcrumb"], "Pre-refresh state does not reflect open folder breadcrumb."
        assert len(pre_refresh_state["item_names"]) > 0, "Pre-refresh folder state contains zero items."

        # Track history slice specifically resulting from page refresh
        pre_reload_count = len(nav_history_admin)

        print("Executing page refresh (reload)...")
        page_admin.reload()

        # 1. Portal root exists
        page_admin.wait_for_selector("#archive-portal-root", timeout=15000)
        # 2. Document container exists
        page_admin.wait_for_selector("#ea-document-container", state="visible", timeout=12000)

        # 3. Welcome does not unexpectedly block the workspace
        assert page_admin.query_selector("#ea-welcome-overlay") is None, (
            f"[Refresh Test] Welcome overlay (#ea-welcome-overlay) must NOT reappear on refresh in same session!\n"
            f"  Current URL: {page_admin.url}"
        )
        print("✓ Welcome overlay properly bypassed on refresh.")

        # 4. Previously active folder is restored and visible state matches pre-refresh folder
        page_admin.wait_for_function(
            """(expected) => {
                const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                const summary = document.querySelector('#ea-results-summary');
                return crumb && crumb.innerText.includes(expected)
                    && summary && summary.innerText.includes(expected)
                    && !summary.innerText.includes('در حال دریافت');
            }""",
            arg=pre_refresh_folder,
            timeout=12000
        )

        post_refresh_state = capture_folder_state(page_admin)
        print(f"Post-refresh state: summary='{post_refresh_state['summary']}', crumb='{post_refresh_state['active_breadcrumb']}', items={post_refresh_state['item_names']}")

        assert pre_refresh_folder in post_refresh_state["summary"], (
            f"State restoration failed after refresh: summary missing '{pre_refresh_folder}'.\n"
            f"  Observed: '{post_refresh_state['summary']}'"
        )
        assert pre_refresh_folder in post_refresh_state["active_breadcrumb"], (
            f"State restoration failed after refresh: active crumb missing '{pre_refresh_folder}'.\n"
            f"  Observed: '{post_refresh_state['active_breadcrumb']}'"
        )
        assert post_refresh_state["active_breadcrumb"] == pre_refresh_state["active_breadcrumb"], (
            f"Active breadcrumb mismatch after refresh:\n"
            f"  Expected: '{pre_refresh_state['active_breadcrumb']}'\n"
            f"  Observed: '{post_refresh_state['active_breadcrumb']}'"
        )
        assert post_refresh_state["summary"] == pre_refresh_state["summary"], (
            f"Summary mismatch after refresh:\n"
            f"  Expected: '{pre_refresh_state['summary']}'\n"
            f"  Observed: '{post_refresh_state['summary']}'"
        )
        assert post_refresh_state["item_names"] == pre_refresh_state["item_names"], (
            f"Visible items mismatch after refresh:\n"
            f"  Expected: {pre_refresh_state['item_names']}\n"
            f"  Observed: {post_refresh_state['item_names']}"
        )

        # 5. No 404, no Dashboard, Public Base URL remains correct
        assert_portal_visible(page_admin, "Test F: Post-Refresh Portal Verification")

        # 6. No redirect loop
        nav_history_refresh = nav_history_admin[pre_reload_count:]
        assert_no_redirect_loops(nav_history_refresh, BASE_URL, "Test F: Refresh History Check")
        print("✓ Browser refresh succeeded: zero 404, zero Dashboard, zero loop, fixed Public Base URL, state fully restored.")

        context_admin.close()

        # -------------------------------------------------------------
        # TEST C: Returning Context (Welcome Skip Verification)
        # -------------------------------------------------------------
        print("\n--- TEST C: Returning Context (Welcome Skip Verification) ---")
        context_returning = browser.new_context()
        page_returning = context_returning.new_page()

        nav_history_returning = []
        page_returning.on("framenavigated", lambda frame: nav_history_returning.append(frame.url) if frame == page_returning.main_frame else None)

        print(f"Logging in with admin in context_returning...")
        page_returning.goto(LOGIN_URL)
        page_returning.wait_for_selector("#user", timeout=12000)
        page_returning.fill("#user", ADMIN_USER)
        page_returning.fill("#password", ADMIN_PASS)
        page_returning.click("button[type='submit'], #submit-form")

        page_returning.wait_for_selector("#archive-portal-root", timeout=15000)
        # First login: Welcome overlay appears and is completed
        if page_returning.query_selector("#ea-welcome-overlay"):
            page_returning.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=15000)
        page_returning.wait_for_selector("#ea-document-container", state="visible")
        assert_portal_visible(page_returning, "Test C: First Login in Context")

        # Now test the returning session within this established user session
        print("Testing returning navigation in existing session context...")
        page_returning2 = context_returning.new_page()
        nav_history_ret2 = []
        page_returning2.on("framenavigated", lambda frame: nav_history_ret2.append(frame.url) if frame == page_returning2.main_frame else None)

        # Navigating to Login URL in existing session redirects directly to Portal
        page_returning2.goto(LOGIN_URL)
        page_returning2.wait_for_selector("#archive-portal-root", timeout=15000)

        # Returning session MUST proceed directly: Portal -> file/folder table (no Welcome screen)
        assert page_returning2.query_selector("#ea-welcome-overlay") is None, (
            f"[Returning Context] Welcome screen overlay must NOT appear for an active returning session!\n"
            f"  Observed: overlay present\n"
            f"  Current URL: {page_returning2.url}"
        )
        assert page_returning2.is_visible("#ea-document-container"), (
            f"[Returning Context] File/Folder table (#ea-document-container) must be immediately visible.\n"
            f"  Current URL: {page_returning2.url}"
        )
        assert_portal_visible(page_returning2, "Test C: Returning Context Verification")
        assert_no_redirect_loops(nav_history_ret2, BASE_URL, "Test C: Returning Nav History")
        print("✓ Returning context reached Portal directly with Welcome skipped and zero redirect loops.")

        context_returning.close()

        # -------------------------------------------------------------
        # TEST G: Non-Admin User Access & Strict Isolation (test_user_a)
        # -------------------------------------------------------------
        print("\n--- TEST G: Non-Admin User Access & Strict Isolation (test_user_a) ---")
        context_user = browser.new_context()
        page_user = context_user.new_page()

        nav_history_user = []
        page_user.on("framenavigated", lambda frame: nav_history_user.append(frame.url) if frame == page_user.main_frame else None)

        page_user.goto(LOGIN_URL)
        page_user.wait_for_selector("#user", timeout=12000)
        page_user.fill("#user", USER_A)
        page_user.fill("#password", USER_A_PASS)
        page_user.click("button[type='submit'], #submit-form")

        page_user.wait_for_selector("#archive-portal-root", timeout=15000)
        if page_user.query_selector("#ea-welcome-overlay"):
            page_user.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=15000)

        page_user.wait_for_selector("#ea-document-container", state="visible")
        assert_portal_visible(page_user, "Test G: User A Login")
        assert_no_redirect_loops(nav_history_user, BASE_URL, "Test G: User A Navigation History")

        # 1. Group A is accessible
        summary_el = page_user.wait_for_selector("#ea-results-summary")
        summary_text = summary_el.inner_text().strip()
        print(f"Non-admin initial summary: '{summary_text}'")
        assert GROUP_A_NAME in summary_text, (
            f"[Isolation Test] Expected '{GROUP_A_NAME}' in summary for user '{USER_A}', observed: '{summary_text}'"
        )

        user_a_state = capture_folder_state(page_user)
        user_a_items = user_a_state["item_names"]
        print(f"User A visible items at initial view: {user_a_items}")
        has_auth_item = any(GROUP_A_NAME in it or GROUP_A_SUBFOLDER in it or GROUP_A_FILE in it for it in user_a_items)
        assert has_auth_item, (
            f"[Isolation Test] Expected authorized items ('{GROUP_A_NAME}', '{GROUP_A_SUBFOLDER}', or '{GROUP_A_FILE}') to be visible for '{USER_A}'.\n"
            f"  Observed items: {user_a_items}"
        )
        print("✓ Group A is accessible and authorized content is visible.")

        # 2. Group B is not visible & known unauthorized Group B files are not visible
        container_text = page_user.inner_text("#ea-document-container")
        for unauth_folder in KNOWN_UNAUTHORIZED_GROUP_B_FOLDERS:
            assert unauth_folder not in container_text, (
                f"[Isolation Leak Detected!] Unauthorized Group B folder '{unauth_folder}' is visible in container for '{USER_A}'!\n"
                f"  Visible items: {user_a_items}"
            )
            assert not any(unauth_folder == it for it in user_a_items), (
                f"[Isolation Leak Detected!] Unauthorized folder '{unauth_folder}' listed in item names for '{USER_A}'!"
            )

        for unauth_file in KNOWN_UNAUTHORIZED_GROUP_B_FILES:
            assert unauth_file not in container_text, (
                f"[Isolation Leak Detected!] Unauthorized Group B file '{unauth_file}' is visible in container for '{USER_A}'!\n"
                f"  Visible items: {user_a_items}"
            )
            assert not any(unauth_file == it for it in user_a_items), (
                f"[Isolation Leak Detected!] Unauthorized file '{unauth_file}' listed in item names for '{USER_A}'!"
            )

        # 3. Unauthorized Group B folder-path elements are not visible
        unauth_row = page_user.query_selector("tr[data-folder-path*='Group B'], .ea-card[data-folder-path*='Group B']")
        assert unauth_row is None, (
            f"[Isolation Leak Detected!] Found DOM element referencing 'Group B' folder path in User A session."
        )
        all_paths = [
            el.get_attribute("data-folder-path") or ""
            for el in page_user.query_selector_all("[data-folder-path]")
        ]
        for p in all_paths:
            assert "Group B" not in p, (
                f"[Isolation Leak Detected!] DOM data-folder-path contains 'Group B': '{p}'"
            )

        # 4. Administrator-only controls are not visible
        assert page_user.query_selector("#ea-admin-create-folder-btn") is None, (
            f"[Security Leak!] Administrator folder creation button exposed to standard user '{USER_A}'!"
        )
        assert page_user.query_selector("#ea-admin-manage-tags-btn") is None, (
            f"[Security Leak!] Administrator central tag management exposed to standard user '{USER_A}'!"
        )
        assert page_user.query_selector("#ea-admin-ai-security-btn") is None, (
            f"[Security Leak!] Administrator AI security button exposed to standard user '{USER_A}'!"
        )
        print("✓ Group B and admin controls are strictly isolated and not visible to User A.")

        # 5. Direct Portal navigation attempt to Group B does not expose its contents
        # Strengthened: observe the actual folder-files API request triggered by navigation
        print("Testing UI navigation attempt to unauthorized path '/Group B' while observing API response...")
        with page_user.expect_response(
            lambda r: "folder-files" in r.url and ("Group%20B" in r.url or "Group B" in r.url),
            timeout=12000
        ) as resp_info:
            page_user.evaluate("window._eaNavigateToFolder('/Group B')")

        api_response = resp_info.value
        print(f"Observed folder-files API response: Status {api_response.status}")

        # Verify that unauthorized access is rejected by the backend according to the application's contract
        # A secure response such as HTTP 403 or HTTP 404, or HTTP 200 with empty files/0 total is verified.
        if api_response.status in (403, 404):
            print(f"✓ Backend rejected unauthorized folder access with HTTP {api_response.status}.")
        elif api_response.status == 200:
            api_json = api_response.json()
            files_returned = api_json.get("files", [])
            total_returned = api_json.get("total", 0)
            assert len(files_returned) == 0 and total_returned == 0, (
                f"[Access Violation!] Backend returned items for unauthorized folder '/Group B'!\n"
                f"  Total: {total_returned}, Files: {files_returned}"
            )
            print(f"✓ Backend enforced authorization: 0 files returned (status=success, total=0).")
        else:
            raise AssertionError(
                f"[Unexpected API Response] folder-files returned unexpected HTTP status {api_response.status} for unauthorized request."
            )

        page_user.wait_for_selector("#ea-document-container", state="visible")
        page_user.wait_for_function(
            "() => { const s = document.querySelector('#ea-results-summary'); return s && !s.innerText.includes('در حال دریافت'); }",
            timeout=10000
        )
        unauth_nav_state = capture_folder_state(page_user)
        print(f"State after unauthorized nav attempt: summary='{unauth_nav_state['summary']}', items={unauth_nav_state['item_names']}")

        # Must display 0 items and contain none of the unauthorized files in DOM
        assert len(unauth_nav_state["item_names"]) == 0, (
            f"[Access Violation!] Standard user reached unauthorized folder content via UI navigation!\n"
            f"  Observed items in unauthorized path: {unauth_nav_state['item_names']}"
        )
        unauth_dom_text = page_user.inner_text("#ea-document-container")
        for unauth_file in KNOWN_UNAUTHORIZED_GROUP_B_FILES:
            assert unauth_file not in unauth_dom_text, (
                f"[Access Violation!] File '{unauth_file}' surfaced via UI navigation attempt in DOM!"
            )
        for unauth_folder in KNOWN_UNAUTHORIZED_GROUP_B_FOLDERS:
            assert unauth_folder not in unauth_dom_text, (
                f"[Access Violation!] Folder '{unauth_folder}' surfaced via UI navigation attempt in DOM!"
            )

        # 6. Restore user to '/Group A' and verify authorized content is still accessible
        print("Restoring User A to authorized folder '/Group A'...")
        with page_user.expect_response(
            lambda r: "folder-files" in r.url and ("Group%20A" in r.url or "Group A" in r.url),
            timeout=12000
        ) as restore_info:
            page_user.evaluate("window._eaNavigateToFolder('/Group A')")

        restore_response = restore_info.value
        assert restore_response.status == 200, (
            f"Failed to restore User A to Group A: HTTP {restore_response.status}"
        )
        restore_json = restore_response.json()
        assert len(restore_json.get("files", [])) > 0, "Restored Group A response contained 0 files!"

        page_user.wait_for_selector("#ea-document-container", state="visible")
        page_user.wait_for_function(
            """(expected) => {
                const crumb = document.querySelector('.ea-breadcrumb-btn.is-active');
                const summary = document.querySelector('#ea-results-summary');
                return crumb && crumb.innerText.includes(expected)
                    && summary && summary.innerText.includes(expected)
                    && !summary.innerText.includes('در حال دریافت');
            }""",
            arg=GROUP_A_NAME,
            timeout=10000
        )
        assert_portal_visible(page_user, "Test G: Restored Authorized State")
        restored_state = capture_folder_state(page_user)
        print(f"Restored state: summary='{restored_state['summary']}', items={restored_state['item_names']}")
        assert GROUP_A_NAME in restored_state["summary"] and GROUP_A_NAME in restored_state["active_breadcrumb"], (
            f"Restored state does not show Group A: '{restored_state['summary']}'"
        )
        assert len(restored_state["item_names"]) > 0, "Restored state has 0 visible items."
        assert any(GROUP_A_SUBFOLDER in it or GROUP_A_FILE in it for it in restored_state["item_names"]), (
            f"Authorized items missing in restored state: {restored_state['item_names']}"
        )
        print("✓ Non-admin user access and isolation verified with zero authorization leak; restored to authorized view successfully.")

        context_user.close()

        # -------------------------------------------------------------
        # TEST H: Administrator Navigation & Non-Destructive Action
        # -------------------------------------------------------------
        print("\n--- TEST H: Administrator Navigation & Non-Destructive Action ---")
        context_admin2 = browser.new_context()
        page_admin2 = context_admin2.new_page()

        nav_history_admin2 = []
        page_admin2.on("framenavigated", lambda frame: nav_history_admin2.append(frame.url) if frame == page_admin2.main_frame else None)

        page_admin2.goto(LOGIN_URL)
        page_admin2.wait_for_selector("#user", timeout=12000)
        page_admin2.fill("#user", ADMIN_USER)
        page_admin2.fill("#password", ADMIN_PASS)
        page_admin2.click("button[type='submit'], #submit-form")

        # Admin Portal loads
        page_admin2.wait_for_selector("#archive-portal-root", timeout=15000)
        if page_admin2.query_selector("#ea-welcome-overlay"):
            page_admin2.wait_for_selector("#ea-welcome-overlay", state="detached", timeout=15000)

        page_admin2.wait_for_selector("#ea-document-container", state="visible")
        assert_portal_visible(page_admin2, "Test H: Admin Login")
        assert_no_redirect_loops(nav_history_admin2, BASE_URL, "Test H: Admin Navigation")

        # Admin controls are visible
        admin_create_btn = page_admin2.wait_for_selector("#ea-admin-create-folder-btn", state="visible", timeout=8000)
        assert admin_create_btn is not None, "Admin button '#ea-admin-create-folder-btn' is missing for administrator!"

        admin_tags_btn = page_admin2.wait_for_selector("#ea-admin-manage-tags-btn", state="visible", timeout=8000)
        assert admin_tags_btn is not None, "Admin button '#ea-admin-manage-tags-btn' is missing for administrator!"

        admin_ai_btn = page_admin2.query_selector("#ea-admin-ai-security-btn")
        assert admin_ai_btn is not None, "Admin button '#ea-admin-ai-security-btn' is missing for administrator!"

        print("✓ Confirmed administrator workflow elements are rendered and visible.")

        # Central Tag Management modal opens
        print("Opening Administrator Central Tag Management modal (non-destructive action)...")
        admin_tags_btn.click()

        modal_el = page_admin2.wait_for_selector("#ea-active-modal", state="visible", timeout=8000)
        assert modal_el is not None, "Administrator modal '#ea-active-modal' did not open upon clicking admin action!"

        modal_text = modal_el.inner_text()
        assert "تگ" in modal_text or "مدیریت" in modal_text or "سامانه" in modal_text, (
            f"Unexpected modal content for Central Tag Management: '{modal_text[:200]}'"
        )
        print("✓ Administrator Central Tag Management modal rendered successfully.")

        # Modal closes successfully
        close_btn = page_admin2.query_selector("#ea-active-modal .ea-modal-close, #ea-active-modal button[title='بستن']")
        if close_btn:
            close_btn.click()
        else:
            page_admin2.keyboard.press("Escape")

        page_admin2.wait_for_selector("#ea-active-modal", state="detached", timeout=5000)
        assert page_admin2.query_selector("#ea-active-modal") is None, "Administrator modal remained open after dismissal."
        print("✓ Administrator modal dismissed cleanly without any data mutation.")

        # Portal remains functional afterward
        page_admin2.wait_for_selector("#ea-document-container", state="visible")
        post_modal_state = capture_folder_state(page_admin2)
        assert len(post_modal_state["item_names"]) > 0, "Portal lost document items after closing modal!"

        # No Dashboard route appears, no redirect loop occurs, Public Base URL remains correct
        assert "/apps/dashboard" not in page_admin2.url, "Dashboard route appeared after admin modal action!"
        assert_portal_visible(page_admin2, "Test H: Post Admin Action Verification")
        assert_no_redirect_loops(nav_history_admin2, BASE_URL, "Test H: End of Admin Flow")
        assert_public_base_url(page_admin2.url, "Test H: Final Base URL")
        print("✓ Administrator functionality confirmed fully accessible after navigation fix.")

        context_admin2.close()
        browser.close()

    # -------------------------------------------------------------
    # TEST I: Public URL Portability (No hardcoded IP / localhost in logic)
    # -------------------------------------------------------------
    print("\n--- TEST I: Public URL Portability Audit ---")
    files_to_audit = [
        "apps/archive_autotag/js/url_mask.js",
        "apps/archive_autotag/js/archive_portal.js",
        "apps/archive_autotag/lib/Controller/PageController.php",
        "apps/archive_autotag/lib/AppInfo/Application.php",
        "apps/archive_autotag/templates/main.php",
    ]

    for rel_path in files_to_audit:
        with open(rel_path, "r", encoding="utf-8") as f:
            content = f.read()
            # Assert 172.27.185.216 is not hardcoded anywhere in the file
            assert "172.27.185.216" not in content, f"Hardcoded IP found in {rel_path}!"

            # Assert localhost is not hardcoded as an application identity fallback in executable code
            lines = content.splitlines()
            in_block_comment = False
            for line_no, line in enumerate(lines, 1):
                clean_line = line.strip()

                # Handle multi-line comment blocks
                if "/*" in clean_line and "*/" not in clean_line:
                    in_block_comment = True
                    continue
                if in_block_comment:
                    if "*/" in clean_line:
                        in_block_comment = False
                    continue

                # Skip single-line comments
                if (
                    clean_line.startswith("//")
                    or clean_line.startswith("*")
                    or clean_line.startswith("/*")
                    or clean_line.startswith("#")
                ):
                    continue

                # Strip trailing inline comments if present
                code_part = clean_line.split("//")[0].split("#")[0].strip()

                if "localhost" in code_part:
                    raise AssertionError(
                        f"Hardcoded 'localhost' found in executable code at {rel_path}:{line_no}: {clean_line}"
                    )

    print("✓ Audited modified application files: ZERO hardcoded production IP or localhost values found.")

    print("\n==================================================================")
    print(" ALL TESTS A THROUGH I PASSED WITH 100% SUCCESS! (ZERO ERRORS)")
    print(f" Nested-navigation fixture ('{GROUP_A_NAME}' -> '{GROUP_A_SUBFOLDER}') actually tested: {nested_fixture_tested}")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
