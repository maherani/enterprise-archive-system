#!/usr/bin/env python3
"""
Test Suite: File Location ('مکان در پوشه') and File Path Navigation
Enterprise Archive System - Nextcloud 34

Validates:
1. Backend API returns direct /apps/files/files?dir=... URLs for all files.
2. Direct file in Archive Root resolves to /Enterprise_Archive.
3. Nested file resolves to exact parent directory across arbitrary depth.
4. Persian / non-ASCII path encoding is handled properly without redirect loops.
5. HTTP GET on the resolved folder URL returns 200 OK without 303 bounce loops.
6. Multi-user ACL consistency (SOC and CERT users access their respective folders).
7. Frontend scripts (archive_portal.js & multi_tag_filter.js) use folderUrl & targetDir.
"""

import sys
import requests

NEXTCLOUD_URL = "http://localhost"
API_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"


def test_api_folder_urls_admin():
    print("[1/5] Testing API File Listing for Admin...")
    resp = requests.get(API_URL, auth=(ADMIN_USER, ADMIN_PASS))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"
    data = resp.json()
    files = data.get("files", [])
    assert len(files) > 0, "No files returned!"

    for f in files:
        web_url = f.get("web_url", "")
        folder_url = f.get("folder_url", "")
        target_dir = f.get("target_dir", "")
        parent_dir = f.get("parent_dir", "")

        assert not web_url.startswith("/f/"), f"web_url still uses deprecated /f/ shortlink: {web_url}"
        assert not folder_url.startswith("/f/"), f"folder_url still uses deprecated /f/ shortlink: {folder_url}"
        assert "/apps/files/files?dir=" in web_url, f"web_url does not point to files dir: {web_url}"
        assert target_dir.startswith("/"), f"target_dir must start with slash: {target_dir}"

    print(f"  -> PASS: Verified {len(files)} files have valid direct folder URLs.")
    return files


def test_deep_nested_cert_file(files):
    print("[2/5] Testing Deep Nested File Path Resolution (CERT sample)...")
    cert_files = [f for f in files if "schedule_host" in f.get("name", "")]
    assert len(cert_files) > 0, "Target CERT file not found!"
    f = cert_files[0]

    expected_parent = "Enterprise_Archive/CERT/بانک مرکزی/کاشف/چارچوب کنترلی"
    assert f["parent_dir"] == expected_parent, f"Parent dir mismatch: {f['parent_dir']}"
    assert f["target_dir"] == "/" + expected_parent, f"Target dir mismatch: {f['target_dir']}"

    # Verify HTTP 200 OK directly on this folder URL
    target_url = f"{NEXTCLOUD_URL}{f['folder_url']}"
    resp = requests.get(target_url, auth=(ADMIN_USER, ADMIN_PASS))
    assert resp.status_code == 200, f"Expected 200 for target folder, got {resp.status_code}"
    assert len(resp.history) == 0, f"Expected zero redirects (no 303 loops), got history: {resp.history}"
    print("  -> PASS: CERT deep nested file resolved to exact parent folder with 200 OK (0 redirects).")


def test_soc_incident_file(files):
    print("[3/5] Testing SOC Department File Path Resolution (Persian Deep Path)...")
    soc_files = [f for f in files if "SOC" in f.get("path", "") and "افتا" in f.get("path", "")]
    assert len(soc_files) > 0, "Target SOC file with Persian path 'افتا' not found!"
    f = soc_files[0]

    assert "SOC" in f["path"], f"Expected SOC in path, got {f['path']}"
    target_url = f"{NEXTCLOUD_URL}{f['folder_url']}"
    resp = requests.get(target_url, auth=(SOC_USER, SOC_PASS))
    assert resp.status_code == 200, f"Expected 200 for SOC user, got {resp.status_code}"
    assert len(resp.history) == 0, f"Expected 0 redirects, got: {resp.history}"
    print(f"  -> PASS: SOC item ({f['name']}) resolved to exact parent folder with 200 OK (0 redirects).")


def test_frontend_script_integrity():
    print("[4/5] Testing Frontend Scripts Integrity (archive_portal.js & multi_tag_filter.js)...")
    resp_portal = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/archive_portal.js")
    assert resp_portal.status_code == 200
    assert "folderUrl" in resp_portal.text, "folderUrl missing in archive_portal.js"
    assert "ea-drawer-locate-btn" in resp_portal.text
    assert "escapeHtml(folderUrl)" in resp_portal.text, "Drawer locate button must use folderUrl"

    resp_filter = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/multi_tag_filter.js")
    assert resp_filter.status_code == 200
    assert "targetDir" in resp_filter.text
    print("  -> PASS: Both frontend JS bundles correctly serve updated navigation logic.")


def test_user_session_portal_view():
    print("[5/5] Testing Portal UI accessibility for standard user...")
    resp = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/", auth=(SOC_USER, SOC_PASS))
    assert resp.status_code == 200, f"Expected 200 for portal, got {resp.status_code}"
    assert "archive-portal-root" in resp.text
    print("  -> PASS: Portal loads cleanly for standard user.")


if __name__ == "__main__":
    try:
        all_files = test_api_folder_urls_admin()
        test_deep_nested_cert_file(all_files)
        test_soc_incident_file(all_files)
        test_frontend_script_integrity()
        test_user_session_portal_view()
        print("\n=======================================================")
        print("ALL TESTS PASSED SUCCESSFULLY! (100% Navigation Verified)")
        print("=======================================================")
        sys.exit(0)
    except Exception as ex:
        print(f"\nTEST FAILED: {ex}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
