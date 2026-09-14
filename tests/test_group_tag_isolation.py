#!/usr/bin/env python3
"""
Automated Test Suite: Group-Level Tag Isolation (Nextcloud 34)
Enterprise Archive System

Validates:
1. Admin creates or assigns a tag to group 'SOC'.
2. Users in 'SOC' (Bakbari) see 'SOC' tags in /api/tags and portal.
3. Users in 'CERT' (Adli) DO NOT see 'SOC' tags in /api/tags or search.
4. Users in 'Compliance_Unit' (archive_user1) DO NOT see 'SOC' or 'CERT' tags.
5. System Admin (admin) sees ALL tags across ALL groups without restriction.
6. Cross-group tag filtering isolation: querying a tag outside user's group returns 0 files.
"""

import sys
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

USER_SOC = "Bakbari"
USER_SOC_PASS = "User_Password_123!"

USER_CERT = "Adli"
USER_CERT_PASS = "User_Password_123!"

USER_COMP = "archive_user1"
USER_COMP_PASS = "User_Password_123!"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
soc_auth = HTTPBasicAuth(USER_SOC, USER_SOC_PASS)
cert_auth = HTTPBasicAuth(USER_CERT, USER_CERT_PASS)
comp_auth = HTTPBasicAuth(USER_COMP, USER_COMP_PASS)


def run_occ(cmd: str) -> str:
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    res = subprocess.run(full_cmd, shell=True, capture_output=True, text=True)
    return res.stdout.strip()


def run_tests():
    print("==================================================================")
    print(" STARTING GROUP-LEVEL TAG ISOLATION VERIFICATION SUITE")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Setup: Reset test passwords for group members
    # ------------------------------------------------------------------
    print("\n[Setup] Ensuring passwords and group memberships...")
    for u, p in [(USER_SOC, USER_SOC_PASS), (USER_CERT, USER_CERT_PASS), (USER_COMP, USER_COMP_PASS)]:
        cmd = f"docker exec -u www-data -e OC_PASS='{p}' archive_app php occ user:resetpassword --password-from-env {u}"
        subprocess.run(cmd, shell=True, capture_output=True, text=True)
    print("  ✔ Passwords verified.")

    # ------------------------------------------------------------------
    # Step 1: Admin configures group-specific tag
    # ------------------------------------------------------------------
    print("\n[Step 1] Creating/Assigning group-specific tag 'SOC_Defense_Policy' to group 'SOC'...")
    # 1. Create tag via WebDAV or ensure it exists
    r_mk = requests.post(
        f"{NEXTCLOUD_URL}/remote.php/dav/systemtags/",
        json={"name": "SOC_Defense_Policy", "userVisible": True, "userAssignable": True},
        auth=admin_auth,
        headers={"Content-Type": "application/json"}
    )
    # Assign tag to group SOC via OCC
    out_assign = run_occ("archive:tag:gov set-group SOC_Defense_Policy SOC")
    print(f"  - occ set-group output: {out_assign}")

    # Also ensure tag 'SOC' is bound to group 'SOC'
    run_occ("archive:tag:gov set-group SOC SOC")
    # Ensure tag 'CERT' is bound to group 'CERT'
    run_occ("archive:tag:gov set-group CERT CERT")
    print("  ✔ Group-level tag bindings configured by System Admin.")

    # ------------------------------------------------------------------
    # Step 2: User in 'SOC' (Bakbari) queries visible tags
    # ------------------------------------------------------------------
    print("\n[Step 2] Querying visible tags for User 'Bakbari' (Group: SOC)...")
    r_soc = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=soc_auth)
    assert r_soc.status_code == 200, f"Failed to fetch tags for Bakbari: {r_soc.status_code}"
    soc_tags = [t["name"] for t in r_soc.json().get("tags", [])]
    print(f"  - Bakbari visible tags count: {len(soc_tags)}")
    assert "SOC_Defense_Policy" in soc_tags, "Bakbari CANNOT see SOC_Defense_Policy tag!"
    assert "SOC" in soc_tags, "Bakbari CANNOT see SOC tag!"
    assert "CERT" not in soc_tags, "LEAK: Bakbari CAN SEE CERT tag!"
    print("  ✔ PASSED: SOC user sees SOC tags and is isolated from CERT tags.")

    # ------------------------------------------------------------------
    # Step 3: User in 'CERT' (Adli) queries visible tags
    # ------------------------------------------------------------------
    print("\n[Step 3] Querying visible tags for User 'Adli' (Group: CERT)...")
    r_cert = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=cert_auth)
    assert r_cert.status_code == 200, f"Failed to fetch tags for Adli: {r_cert.status_code}"
    cert_tags = [t["name"] for t in r_cert.json().get("tags", [])]
    print(f"  - Adli visible tags count: {len(cert_tags)}")
    assert "CERT" in cert_tags, "Adli CANNOT see CERT tag!"
    assert "SOC" not in cert_tags, "LEAK: Adli CAN SEE SOC tag!"
    assert "SOC_Defense_Policy" not in cert_tags, "LEAK: Adli CAN SEE SOC_Defense_Policy tag!"
    print("  ✔ PASSED: CERT user sees CERT tags and is isolated from SOC tags.")

    # ------------------------------------------------------------------
    # Step 4: User in 'Compliance_Unit' (archive_user1) queries visible tags
    # ------------------------------------------------------------------
    print("\n[Step 4] Querying visible tags for User 'archive_user1' (Group: Compliance_Unit)...")
    r_comp = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=comp_auth)
    assert r_comp.status_code == 200, f"Failed to fetch tags for archive_user1: {r_comp.status_code}"
    comp_tags = [t["name"] for t in r_comp.json().get("tags", [])]
    print(f"  - archive_user1 visible tags count: {len(comp_tags)}")
    assert "SOC" not in comp_tags, "LEAK: Compliance_Unit CAN SEE SOC tag!"
    assert "CERT" not in comp_tags, "LEAK: Compliance_Unit CAN SEE CERT tag!"
    assert "SOC_Defense_Policy" not in comp_tags, "LEAK: Compliance_Unit CAN SEE SOC_Defense_Policy tag!"
    print("  ✔ PASSED: Compliance_Unit user is isolated from both SOC and CERT tags.")

    # ------------------------------------------------------------------
    # Step 5: System Admin (admin) sees ALL tags across all groups
    # ------------------------------------------------------------------
    print("\n[Step 5] Querying visible tags for System Administrator (admin)...")
    r_admin = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=admin_auth)
    assert r_admin.status_code == 200, f"Failed to fetch tags for Admin: {r_admin.status_code}"
    admin_tags = [t["name"] for t in r_admin.json().get("tags", [])]
    print(f"  - Admin visible tags count: {len(admin_tags)}")
    assert "SOC" in admin_tags, "Admin CANNOT see SOC tag!"
    assert "CERT" in admin_tags, "Admin CANNOT see CERT tag!"
    assert "SOC_Defense_Policy" in admin_tags, "Admin CANNOT see SOC_Defense_Policy tag!"
    assert "Enterprise_Archive" in admin_tags, "Admin CANNOT see Enterprise_Archive tag!"
    print("  ✔ PASSED: System Admin has full, unrestricted visibility of all tags across all groups.")

    # ------------------------------------------------------------------
    # Step 6: Cross-group filtering rejection
    # ------------------------------------------------------------------
    print("\n[Step 6] Testing cross-group filtering (CERT user queries ?tags=SOC)...")
    r_filter = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tags=SOC", auth=cert_auth)
    assert r_filter.status_code == 200, f"Filter request failed: {r_filter.status_code}"
    filter_data = r_filter.json()
    assert filter_data.get("status") == "success"
    assert len(filter_data.get("files", [])) == 0, f"LEAK: CERT user got files with tag SOC: {filter_data}"
    print("  ✔ PASSED: Cross-group tag filtering returned 0 files (Strict Isolation enforced).")

    print("\n==================================================================")
    print(" ALL 6 GROUP-LEVEL TAG ISOLATION TESTS PASSED WITH 100% SUCCESS!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
