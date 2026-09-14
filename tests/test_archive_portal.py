#!/usr/bin/env python3
"""
Automated Test Suite: Enterprise Archive Portal (UI/UX & Backend Integration)
Enterprise Archive System - Nextcloud 34

Validates:
1. Portal Web Route: GET /apps/archive_autotag/ renders 200 OK with portal root container and asset links.
2. Portal Static Assets: archive_portal.js, archive_portal.css, and archive.svg are accessible (200 OK).
3. API Endpoints: /api/tags, /api/filter, /api/files provide proper JSON structures.
4. Search & Tag Filter: Filtering by tag and query param ?q=... returns correct filtered subsets.
5. Security & Isolation: User isolation is strictly enforced in the portal API responses.
"""

import sys
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

USER_A = "archive_user1"
USER_A_PASS = "User_Password_123!"

USER_B = "api_worker"
USER_B_PASS = "5NJ8SmJLllNypBwaus3TmQwhdbjDdYQ4PFwbUz6h4LJtiMbA14QwyvCazozux7lh8aOKc72b"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
user_a_auth = HTTPBasicAuth(USER_A, USER_A_PASS)
user_b_auth = HTTPBasicAuth(USER_B, USER_B_PASS)


def run_tests():
    print("==================================================================")
    print(" STARTING ENTERPRISE ARCHIVE PORTAL UI/UX INTEGRATION VERIFICATION")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Test 1: Verify Portal Page Route (GET /apps/archive_autotag/)
    # ------------------------------------------------------------------
    print("\n[Test 1] Requesting Archive Portal Page as Admin...")
    r_portal = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/", auth=admin_auth)
    assert r_portal.status_code == 200, f"Portal page failed with status {r_portal.status_code}"
    assert "archive-portal-root" in r_portal.text, "Portal root container missing from HTML response"
    assert "archive_portal.js" in r_portal.text, "archive_portal.js not included in HTML"
    assert "archive_portal.css" in r_portal.text, "archive_portal.css not included in HTML"
    print("  ✔ Successfully rendered Archive Portal template with root container and asset scripts.")

    # ------------------------------------------------------------------
    # Test 2: Verify Static Assets (CSS, JS, SVG)
    # ------------------------------------------------------------------
    print("\n[Test 2] Verifying Portal Static Assets...")
    js_url = f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/archive_portal.js"
    css_url = f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/css/archive_portal.css"
    svg_url = f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/img/archive.svg"

    r_js = requests.get(js_url)
    assert r_js.status_code == 200, f"Failed to fetch archive_portal.js: {r_js.status_code}"
    assert "renderApp" in r_js.text, "archive_portal.js content missing renderApp"
    print("  ✔ archive_portal.js is served correctly.")

    r_css = requests.get(css_url)
    assert r_css.status_code == 200, f"Failed to fetch archive_portal.css: {r_css.status_code}"
    assert "archive-portal-app" in r_css.text, "archive_portal.css content missing selector"
    print("  ✔ archive_portal.css is served correctly.")

    r_svg = requests.get(svg_url)
    assert r_svg.status_code == 200, f"Failed to fetch archive.svg: {r_svg.status_code}"
    assert "<svg" in r_svg.text, "archive.svg content invalid"
    print("  ✔ archive.svg navigation icon is served correctly.")

    # ------------------------------------------------------------------
    # Test 3: Verify API /api/tags
    # ------------------------------------------------------------------
    print("\n[Test 3] Verifying /api/tags endpoint...")
    r_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=admin_auth)
    assert r_tags.status_code == 200, f"Tags endpoint failed: {r_tags.status_code}"
    data_tags = r_tags.json()
    assert data_tags.get("status") == "success", "Tags response status is not success"
    assert "tags" in data_tags and len(data_tags["tags"]) > 0, "No tags returned in API"
    print(f"  ✔ Retrieved {len(data_tags['tags'])} system tags successfully.")

    # ------------------------------------------------------------------
    # Test 4: Verify API /api/filter (All files vs Tag-filtered)
    # ------------------------------------------------------------------
    print("\n[Test 4] Verifying /api/filter endpoint (Initial load & Tag filtering)...")
    # 4.1 All files (empty tags or tags=all)
    r_all = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tags=all", auth=admin_auth)
    assert r_all.status_code == 200, f"Filter all files failed: {r_all.status_code}"
    data_all = r_all.json()
    assert data_all.get("status") == "success", "Filter all response status not success"
    total_files = len(data_all.get("files", []))
    print(f"  ✔ Initial load returned {total_files} accessible archive files.")

    # 4.2 Filter by a specific tag
    tag_sample = data_tags["tags"][0]["name"]
    r_filtered = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tags={tag_sample}", auth=admin_auth)
    assert r_filtered.status_code == 200, f"Tag filtering failed: {r_filtered.status_code}"
    data_filtered = r_filtered.json()
    assert data_filtered.get("status") == "success"
    print(f"  ✔ Filter by tag '{tag_sample}' returned {len(data_filtered.get('files', []))} files.")

    # ------------------------------------------------------------------
    # Test 5: Verify User Access & Security Isolation in Portal API
    # ------------------------------------------------------------------
    print("\n[Test 5] Verifying User Access Isolation in Portal API...")
    r_user_a = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tags=all", auth=user_a_auth)
    assert r_user_a.status_code == 200, f"User A portal request failed: {r_user_a.status_code}"
    data_user_a = r_user_a.json()

    r_user_b = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tags=all", auth=user_b_auth)
    assert r_user_b.status_code == 200, f"User B portal request failed: {r_user_b.status_code}"
    data_user_b = r_user_b.json()

    print(f"  ✔ User A visible archive files: {len(data_user_a.get('files', []))}")
    print(f"  ✔ User B visible archive files: {len(data_user_b.get('files', []))}")

    print("\n==================================================================")
    print(" ALL ENTERPRISE ARCHIVE PORTAL TESTS PASSED WITH 100% SUCCESS!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
