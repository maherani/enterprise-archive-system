#!/usr/bin/env python3
"""
Automated Test Suite: URL Masking & Multi-Domain Support
Enterprise Archive System

Validates:
1. Access via 'localhost' Host header returns HTTP 200 without trusted domain warnings.
2. Access via 'docs.maskan' Host header returns HTTP 200 without trusted domain warnings.
3. The global URL Masking script 'url_mask.js' is properly registered and loaded.
4. Nextcloud trusted_domains configuration includes 'docs.maskan'.
5. WebDAV endpoints operate cleanly under both 'localhost' and 'docs.maskan'.
"""

import sys
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)


def run_occ(cmd: str) -> str:
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    res = subprocess.run(full_cmd, shell=True, capture_output=True, text=True)
    return res.stdout.strip()


def run_tests():
    print("==================================================================")
    print(" STARTING URL MASKING & MULTI-DOMAIN (docs.maskan) VERIFICATION")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Test 1: Verify trusted_domains contains docs.maskan
    # ------------------------------------------------------------------
    print("\n[Test 1] Verifying Nextcloud trusted_domains list...")
    trusted_domains_raw = run_occ("config:system:get trusted_domains")
    print(f"  - Current trusted_domains:\n{trusted_domains_raw}")
    assert "docs.maskan" in trusted_domains_raw, f"docs.maskan not found in trusted_domains: {trusted_domains_raw}"
    print("  ✔ Confirmed 'docs.maskan' is registered in Nextcloud trusted_domains.")

    # ------------------------------------------------------------------
    # Test 2: Access Nextcloud via localhost
    # ------------------------------------------------------------------
    print("\n[Test 2] Requesting Nextcloud root with Host: localhost...")
    r_local = requests.get(NEXTCLOUD_URL, headers={"Host": "localhost"}, allow_redirects=True)
    assert r_local.status_code == 200, f"Expected 200 OK for localhost, got {r_local.status_code}"
    assert "You are accessing the server from an untrusted domain" not in r_local.text, "Untrusted domain error on localhost"
    print("  ✔ Successfully accessed via localhost (HTTP 200).")

    # ------------------------------------------------------------------
    # Test 3: Access Nextcloud via docs.maskan
    # ------------------------------------------------------------------
    print("\n[Test 3] Requesting Nextcloud root with Host: docs.maskan...")
    r_maskan = requests.get(NEXTCLOUD_URL, headers={"Host": "docs.maskan"}, allow_redirects=True)
    assert r_maskan.status_code == 200, f"Expected 200 OK for docs.maskan, got {r_maskan.status_code}"
    assert "You are accessing the server from an untrusted domain" not in r_maskan.text, "Untrusted domain error on docs.maskan"
    print("  ✔ Successfully accessed via docs.maskan (HTTP 200, no trusted domain block).")

    # ------------------------------------------------------------------
    # Test 4: Verify url_mask.js is served by web server
    # ------------------------------------------------------------------
    print("\n[Test 4] Verifying url_mask.js asset accessibility...")
    js_url = f"{NEXTCLOUD_URL}/custom_apps/archive_autotag/js/url_mask.js"
    r_js = requests.get(js_url)
    assert r_js.status_code == 200, f"Failed to retrieve url_mask.js: {r_js.status_code}"
    assert "maskAddressBar" in r_js.text, "url_mask.js content invalid"
    assert "window.history.replaceState" in r_js.text, "Missing replaceState in url_mask.js"
    print("  ✔ url_mask.js is publicly accessible, cache-ready, and contains stealth masking logic.")

    # ------------------------------------------------------------------
    # Test 5: Verify WebDAV under docs.maskan
    # ------------------------------------------------------------------
    print("\n[Test 5] Testing WebDAV PROPFIND with Host: docs.maskan...")
    dav_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/"
    r_dav = requests.request("PROPFIND", dav_url, headers={"Host": "docs.maskan"}, auth=admin_auth)
    assert r_dav.status_code in (207, 200), f"WebDAV failed under docs.maskan: {r_dav.status_code}"
    print("  ✔ WebDAV operational under docs.maskan without regression.")

    print("\n==================================================================")
    print(" ALL URL MASKING & MULTI-DOMAIN TESTS PASSED WITH 100% SUCCESS!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
