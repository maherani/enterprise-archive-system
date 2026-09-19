#!/usr/bin/env python3
"""
Test Suite: Unified Folder & Tag Results Table Verification
Enterprise Archive System - Nextcloud 34

Validates:
1. /api/folder-files returns exact directory contents for user 'maherani' at '/CERT/بانک مرکزی/کاشف/چارچوب کنترلی' (3 files).
2. /api/folder-files returns child folders for user 'maherani' at '/CERT' (3 subfolders).
3. /api/folder-files returns accessible root items for 'admin' at '/Enterprise_Archive'.
4. Multi-tag intersection filter endpoint (/api/filter) remains 100% operational.
5. Frontend script bundles (multi_tag_filter.js & multi_tag_filter.css) contain unified table logic and table hide CSS.
"""

import sys
import requests

NEXTCLOUD_URL = "http://localhost"
CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"


def test_cert_folder_files():
    print("[1/5] Testing /api/folder-files for CERT user at '/CERT/بانک مرکزی/کاشف/چارچوب کنترلی'...")
    folder_path = "/CERT/بانک مرکزی/کاشف/چارچوب کنترلی"
    url = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-files"
    resp = requests.get(url, params={"dir": folder_path}, auth=(CERT_USER, CERT_PASS))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert data.get("status") == "success", f"Status not success: {data}"
    assert data.get("total") == 3, f"Expected 3 files, got {data.get('total')}"
    
    file_names = [f["name"] for f in data.get("files", [])]
    expected_files = [
        "schedule_host=litmus_scheduler=P-RES_trace=bigBori_trace_cpu=0.bi.pdf",
        "schedule_host=litmus_scheduler=P-RES_trace=bigBori_trace_cpu=0.bin",
        "schedule_host=litmus_scheduler=P-RES_trace=bori_trace_cpu=0.bi.pdf"
    ]
    for ef in expected_files:
        assert ef in file_names, f"Expected file {ef} not found in {file_names}"

    for f in data.get("files", []):
        assert f["is_dir"] is False
        assert f["human_size"] != ""
        assert "download_url" in f
        assert "web_url" in f
        assert "target_dir" in f
        assert f["parent_dir"] != ""

    print("  -> PASS: All 3 files in target CERT directory verified with full metadata.")


def test_cert_subfolders():
    print("[2/5] Testing /api/folder-files for CERT subfolders at '/CERT'...")
    url = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-files"
    resp = requests.get(url, params={"dir": "/CERT"}, auth=(CERT_USER, CERT_PASS))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert data.get("status") == "success"
    assert data.get("total") >= 3, f"Expected at least 3 subfolders, got {data.get('total')}"

    folder_names = [f["name"] for f in data.get("files", []) if f["is_dir"]]
    for expected_dir in ["افتا", "بانک مرکزی", "وزارت اقتصاد"]:
        assert expected_dir in folder_names, f"Expected folder {expected_dir} not found in {folder_names}"

    print(f"  -> PASS: Verified subfolders {folder_names} at '/CERT'.")


def test_admin_folder_listing():
    print("[3/5] Testing /api/folder-files for Admin at '/Enterprise_Archive'...")
    url = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-files"
    resp = requests.get(url, params={"dir": "/Enterprise_Archive"}, auth=(ADMIN_USER, ADMIN_PASS))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert data.get("status") == "success"
    assert data.get("total") > 0, "Expected non-empty directory listing for admin"
    print(f"  -> PASS: Admin folder listing returned {data.get('total')} items.")


def test_multi_tag_filter_intact():
    print("[4/5] Verifying multi-tag filter endpoint (/api/filter) remains operational...")
    url = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter"
    resp = requests.get(url, auth=(ADMIN_USER, ADMIN_PASS))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert data.get("status") == "success"
    assert "files" in data
    print(f"  -> PASS: Multi-tag filter API returned {data.get('total')} files.")


def test_frontend_assets():
    print("[5/5] Testing frontend JS & CSS bundle contents...")
    r_js = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/multi_tag_filter.js")
    r_js.encoding = "utf-8"
    assert r_js.status_code == 200
    assert "fetchCurrentFolderFiles" in r_js.text, "fetchCurrentFolderFiles missing from JS bundle"
    assert "data-cy-files-list" in r_js.text, "Selector missing in JS bundle"
    assert "تعداد اسناد: " in r_js.text, "count_label missing in JS bundle"

    r_css = requests.get(f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/css/multi_tag_filter.css")
    r_css.encoding = "utf-8"
    assert r_css.status_code == 200
    assert "table[data-cy-files-list]" in r_css.text, "CSS rule hiding standard table missing"
    assert "archive-tag-results-container" in r_css.text
    assert "archive-file-path-badge" in r_css.text
    print("  -> PASS: Frontend JS and CSS bundles correctly deployed and serving new logic.")


if __name__ == "__main__":
    try:
        test_cert_folder_files()
        test_cert_subfolders()
        test_admin_folder_listing()
        test_multi_tag_filter_intact()
        test_frontend_assets()
        print("\n=======================================================")
        print("ALL TESTS PASSED SUCCESSFULLY! (100% Unified Table Verified)")
        print("=======================================================")
        sys.exit(0)
    except Exception as e:
        print(f"\nTEST FAILED: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
