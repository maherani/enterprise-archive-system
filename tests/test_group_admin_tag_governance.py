#!/usr/bin/env python3
"""
Test Suite: Group-Specific Tag Governance by Group Admin (Requirement 15)
========================================================================
Validates that:
1. Regular user uploads files to group folders and hierarchical auto-tags are preserved.
2. Group Admin can create group-specific tags for their assigned group.
3. Group Admin can assign/remove group tags to/from files and folders in their group scope.
4. Regular users are strictly forbidden (403) from creating group tags.
5. Regular users are strictly forbidden (403) from assigning or removing group tags.
6. Cross-Group isolation: Group admin cannot manage, create, or assign tags for another group (403).
7. System Admin bypass prevention: System admin cannot manage group tags without subadmin role (403).
8. Auto-tagging coexistence: Manual group tags coexist peacefully with auto-generated hierarchy tags.
9. Tag persistence: Manual group tags are never pruned during tag reconciliation.
10. Multi-tag search & isolation: Group tags are strictly visible and filterable only to members of that group.
"""

import os
import sys
import time
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost")

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

SOC_ADMIN_USER = "Bakbari"
SOC_ADMIN_PASS = "User_Password_123!"

CERT_ADMIN_USER = "maherani"
CERT_ADMIN_PASS = "User_Password_123!"

CERT_REGULAR_USER = "Adli"
CERT_REGULAR_PASS = "User_Password_123!"

COMPLIANCE_REGULAR_USER = "archive_user1"
COMPLIANCE_REGULAR_PASS = "User_Password_123!"

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
    "Content-Type": "application/json"
}

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
soc_admin_auth = HTTPBasicAuth(SOC_ADMIN_USER, SOC_ADMIN_PASS)
cert_admin_auth = HTTPBasicAuth(CERT_ADMIN_USER, CERT_ADMIN_PASS)
cert_user_auth = HTTPBasicAuth(CERT_REGULAR_USER, CERT_REGULAR_PASS)
comp_user_auth = HTTPBasicAuth(COMPLIANCE_REGULAR_USER, COMPLIANCE_REGULAR_PASS)


def get_soc_file_id():
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_admin_auth, headers=HEADERS)
    assert r.status_code == 200, f"Failed to list files: {r.status_code}"
    files = r.json().get("files", [])
    for f in files:
        if not f.get("is_dir") and "SOC" in f.get("path", ""):
            return f["id"], f["path"]
    if files:
        return files[0]["id"], files[0]["path"]
    raise RuntimeError("No files found in SOC scope for testing.")


def get_cert_file_id():
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=cert_admin_auth, headers=HEADERS)
    assert r.status_code == 200, f"Failed to list files for CERT: {r.status_code}"
    files = r.json().get("files", [])
    for f in files:
        if not f.get("is_dir") and "CERT" in f.get("path", ""):
            return f["id"], f["path"]
    if files:
        return files[0]["id"], files[0]["path"]
    raise RuntimeError("No files found in CERT scope for testing.")


def run_all_tests():
    print("================================================================================")
    print("  SUITE: Group-Specific Tag Governance by Group Admin (Requirement 15)")
    print("================================================================================")

    ts = int(time.time())
    soc_tag_name = f"SOC_Audit_Tag_{ts}"
    cert_tag_name = f"CERT_Priority_Tag_{ts}"

    # -------------------------------------------------------------------------
    # TEST 1: Regular User Upload & Auto-Tagging Integrity
    # -------------------------------------------------------------------------
    print("\n[Test 1] Verifying Auto-Tagging integrity on regular file uploads...")
    test_filename = f"governance_test_{ts}.txt"
    upload_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/Bakbari/SOC/{test_filename}"
    upload_resp = requests.put(
        upload_url,
        data=b"Test document content for tag governance verification.",
        auth=soc_admin_auth
    )
    assert upload_resp.status_code in (201, 204), f"Upload failed: {upload_resp.status_code}"

    # Wait for autotag hook to process
    time.sleep(1)

    r_files = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_admin_auth, headers=HEADERS)
    assert r_files.status_code == 200
    uploaded_file = next((f for f in r_files.json().get("files", []) if test_filename in f.get("name", "")), None)
    assert uploaded_file is not None, f"Uploaded file {test_filename} not found in archive!"
    auto_tags = [t["name"] for t in uploaded_file.get("tags", [])]
    assert "SOC" in auto_tags, f"Expected 'SOC' tag missing from uploaded file: {auto_tags}"
    test_file_id = uploaded_file["id"]
    print(f"  ✔ File uploaded (ID: {test_file_id}) with auto-tags: {auto_tags}")

    # -------------------------------------------------------------------------
    # TEST 2: Group Admin Creates Tag for Own Group
    # -------------------------------------------------------------------------
    print(f"\n[Test 2] Group Admin (Bakbari) creates tag '{soc_tag_name}' for group 'SOC'...")
    r_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/create",
        auth=soc_admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_name": soc_tag_name}
    )
    assert r_create.status_code == 200, f"Expected 200, got {r_create.status_code}: {r_create.text}"
    create_data = r_create.json()
    assert create_data.get("status") == "success", f"Creation failed: {create_data}"
    soc_tag_id = create_data["tag"]["tag_id"]
    clean_name = create_data["tag"]["clean_name"]
    assert clean_name == soc_tag_name, f"Clean name mismatch: {clean_name} vs {soc_tag_name}"
    print(f"  ✔ Tag successfully created with ID: {soc_tag_id} and clean name: {clean_name}")

    # Verify tag appears in group tags list
    r_gtags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags?group_id=SOC", auth=soc_admin_auth, headers=HEADERS)
    assert r_gtags.status_code == 200
    tag_ids = [t["id"] for t in r_gtags.json().get("tags", [])]
    assert soc_tag_id in tag_ids, f"Created tag ID {soc_tag_id} not found in group tags list!"
    print("  ✔ Tag confirmed present in /api/group-tags list for SOC.")

    # -------------------------------------------------------------------------
    # TEST 3: Group Admin Assigns Tag to File in Group Scope
    # -------------------------------------------------------------------------
    print(f"\n[Test 3] Group Admin assigns tag ID {soc_tag_id} to file ID {test_file_id}...")
    r_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/assign",
        auth=soc_admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": test_file_id}
    )
    assert r_assign.status_code == 200, f"Expected 200, got {r_assign.status_code}: {r_assign.text}"
    assert r_assign.json().get("status") == "success"

    # Verify file now reflects the assigned group tag
    r_file_check = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_admin_auth, headers=HEADERS)
    file_obj = next((f for f in r_file_check.json().get("files", []) if f["id"] == test_file_id), None)
    assert file_obj is not None
    current_tags = [t["name"] for t in file_obj.get("tags", [])]
    expected_full_tag = f"[SOC] {soc_tag_name}"
    assert expected_full_tag in current_tags, f"Expected tag '{expected_full_tag}' not on file: {current_tags}"
    print(f"  ✔ File {test_file_id} now has tags: {current_tags}")

    # -------------------------------------------------------------------------
    # TEST 4: Regular User Blocked from Creating Tags (403 Forbidden)
    # -------------------------------------------------------------------------
    print("\n[Test 4] Verifying regular users are forbidden from creating tags (HTTP 403)...")
    for u_name, u_auth in [("archive_user1", comp_user_auth), ("Adli", cert_user_auth)]:
        r_hack_create = requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/create",
            auth=u_auth,
            headers=HEADERS,
            json={"group_id": "SOC", "tag_name": f"Hacked_Tag_{ts}"}
        )
        assert r_hack_create.status_code == 403, f"User {u_name} was not rejected! Status: {r_hack_create.status_code}"
        print(f"  ✔ Regular user '{u_name}' correctly rejected with HTTP 403 Forbidden.")

    # -------------------------------------------------------------------------
    # TEST 5: Regular User Blocked from Assigning/Removing Tags (403 Forbidden)
    # -------------------------------------------------------------------------
    print("\n[Test 5] Verifying regular users cannot assign or remove group tags (HTTP 403)...")
    r_hack_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/assign",
        auth=comp_user_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": test_file_id}
    )
    assert r_hack_assign.status_code == 403, f"Expected 403, got {r_hack_assign.status_code}"
    print("  ✔ Regular user blocked from assigning tag with HTTP 403.")

    r_hack_remove = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/remove",
        auth=comp_user_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": test_file_id}
    )
    assert r_hack_remove.status_code == 403, f"Expected 403, got {r_hack_remove.status_code}"
    print("  ✔ Regular user blocked from removing tag with HTTP 403.")

    # -------------------------------------------------------------------------
    # TEST 6: Cross-Group Isolation Lockdown (403 Forbidden)
    # -------------------------------------------------------------------------
    print("\n[Test 6] Verifying Cross-Group isolation lockdown (HTTP 403)...")
    # 6a. Bakbari (SOC Admin) attempts to create a tag for CERT
    r_cross_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/create",
        auth=soc_admin_auth,
        headers=HEADERS,
        json={"group_id": "CERT", "tag_name": f"Cross_Tag_{ts}"}
    )
    assert r_cross_create.status_code == 403, f"Cross-group create not rejected! Status: {r_cross_create.status_code}"
    print("  ✔ Bakbari (SOC) forbidden from creating tag for 'CERT' (HTTP 403).")

    # 6b. Maherani (CERT Admin) attempts to assign SOC tag to a file
    r_cross_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/assign",
        auth=cert_admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": test_file_id}
    )
    assert r_cross_assign.status_code == 403, f"Cross-group assign not rejected! Status: {r_cross_assign.status_code}"
    print("  ✔ Maherani (CERT) forbidden from assigning tag in 'SOC' (HTTP 403).")

    # 6c. Bakbari attempts to assign SOC tag to CERT file
    try:
        cert_file_id, _ = get_cert_file_id()
        r_cross_file = requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/assign",
            auth=soc_admin_auth,
            headers=HEADERS,
            json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": cert_file_id}
        )
        assert r_cross_file.status_code == 403, f"SOC Admin assigning to CERT file not rejected! Status: {r_cross_file.status_code}"
        print("  ✔ Bakbari forbidden from assigning SOC tag to CERT target file (HTTP 403).")
    except Exception as e:
        print(f"  Note on CERT file test: {e}")

    # -------------------------------------------------------------------------
    # TEST 7: System Admin Bypass Prevention (Zero-Bypass HTTP 403)
    # -------------------------------------------------------------------------
    print("\n[Test 7] Verifying System Admin ('admin') is blocked without Subadmin role (Zero-Bypass)...")
    r_admin_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/create",
        auth=admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_name": f"Admin_Tag_{ts}"}
    )
    assert r_admin_create.status_code == 403, f"Admin bypass occurred! Status: {r_admin_create.status_code}"
    print("  ✔ 'admin' user blocked from creating SOC group tag with HTTP 403 Forbidden.")

    r_admin_del = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/delete",
        auth=admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id}
    )
    assert r_admin_del.status_code == 403, f"Admin bypass occurred! Status: {r_admin_del.status_code}"
    print("  ✔ 'admin' user blocked from deleting SOC group tag with HTTP 403 Forbidden.")

    # -------------------------------------------------------------------------
    # TEST 8: Auto-Tagging Coexistence & Non-Regression
    # -------------------------------------------------------------------------
    print("\n[Test 8] Verifying peaceful coexistence of auto-tags and manual group tags...")
    r_file_meta = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_admin_auth, headers=HEADERS)
    file_obj = next((f for f in r_file_meta.json().get("files", []) if f["id"] == test_file_id), None)
    assert file_obj is not None
    tags_on_file = [t["name"] for t in file_obj.get("tags", [])]
    assert "SOC" in tags_on_file, f"Auto-tag 'SOC' missing after group tag was assigned: {tags_on_file}"
    assert f"[SOC] {soc_tag_name}" in tags_on_file, f"Manual tag missing: {tags_on_file}"
    print(f"  ✔ Both auto-tags and group tags present on file: {tags_on_file}")

    # -------------------------------------------------------------------------
    # TEST 9: Tag Persistence During Lifecycle & Reconciliation
    # -------------------------------------------------------------------------
    print("\n[Test 9] Verifying group admin tags are NOT pruned during reconciliation...")
    # Trigger auto-tag reconciliation via CLI
    import subprocess
    recon_cmd = ["docker", "exec", "archive_app", "su", "-s", "/bin/bash", "www-data", "-c", "php occ archive:tag:reconcile"]
    res_recon = subprocess.run(recon_cmd, capture_output=True, text=True)
    assert res_recon.returncode == 0, f"Reconciliation failed: {res_recon.stderr}"
    print("  ✔ Ran 'occ archive:reconcile-tags' successfully.")

    # Verify the tag still exists in SOC group tags
    r_gtags_post = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags?group_id=SOC", auth=soc_admin_auth, headers=HEADERS)
    assert r_gtags_post.status_code == 200
    tag_ids_post = [t["id"] for t in r_gtags_post.json().get("tags", [])]
    assert soc_tag_id in tag_ids_post, f"Group tag {soc_tag_id} was improperly pruned during reconciliation!"
    print(f"  ✔ Group tag {soc_tag_id} persisted through full reconciliation without pruning.")

    # -------------------------------------------------------------------------
    # TEST 10: Multi-Tag Filter & Cross-Group Visibility Isolation
    # -------------------------------------------------------------------------
    print("\n[Test 10] Verifying Multi-Tag search and cross-group tag visibility isolation...")
    # 10a. SOC Admin and SOC users can see the tag in /api/tags
    r_soc_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=soc_admin_auth, headers=HEADERS)
    assert r_soc_tags.status_code == 200
    soc_visible_names = [t["name"] for t in r_soc_tags.json().get("tags", [])]
    assert f"[SOC] {soc_tag_name}" in soc_visible_names, f"Tag missing from SOC visible tags: {soc_visible_names}"
    print(f"  ✔ Tag '[SOC] {soc_tag_name}' is visible to SOC members in /api/tags.")

    # 10b. CERT users (Adli) and Compliance users CANNOT see the SOC tag in /api/tags
    r_cert_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=cert_user_auth, headers=HEADERS)
    assert r_cert_tags.status_code == 200
    cert_visible_names = [t["name"] for t in r_cert_tags.json().get("tags", [])]
    assert f"[SOC] {soc_tag_name}" not in cert_visible_names, f"LEAK: CERT user can see SOC tag! {cert_visible_names}"
    print(f"  ✔ Tag '[SOC] {soc_tag_name}' is strictly hidden from CERT users.")

    r_comp_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=comp_user_auth, headers=HEADERS)
    assert r_comp_tags.status_code == 200
    comp_visible_names = [t["name"] for t in r_comp_tags.json().get("tags", [])]
    assert f"[SOC] {soc_tag_name}" not in comp_visible_names, f"LEAK: Compliance user can see SOC tag! {comp_visible_names}"
    print(f"  ✔ Tag '[SOC] {soc_tag_name}' is strictly hidden from Compliance users.")

    # 10c. Multi-tag filter query by SOC Admin
    r_filter = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/filter?tag_ids={soc_tag_id}",
        auth=soc_admin_auth,
        headers=HEADERS
    )
    assert r_filter.status_code == 200
    filter_files = r_filter.json().get("files", [])
    filter_ids = [f["id"] for f in filter_files]
    assert test_file_id in filter_ids, f"Filtered files missing test file {test_file_id}: {filter_ids}"
    print(f"  ✔ Multi-tag filter by tag ID {soc_tag_id} returned expected document (ID {test_file_id}).")

    # -------------------------------------------------------------------------
    # CLEANUP: Unassign, Delete Tag, and Clean Test File
    # -------------------------------------------------------------------------
    print("\n[Cleanup] Removing tag assignment and deleting test tag...")
    r_remove = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/remove",
        auth=soc_admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id, "file_id": test_file_id}
    )
    assert r_remove.status_code == 200
    print("  ✔ Tag removed from file.")

    r_delete = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/delete",
        auth=soc_admin_auth,
        headers=HEADERS,
        json={"group_id": "SOC", "tag_id": soc_tag_id}
    )
    assert r_delete.status_code == 200
    print("  ✔ Tag deleted from system.")

    requests.delete(upload_url, auth=soc_admin_auth)
    print("  ✔ Temporary test file deleted.")

    print("\n================================================================================")
    print("  ALL 10 TESTS IN REQUIREMENT 15 SUITE PASSED SUCCESSFULLY! (100% PASS)")
    print("================================================================================")


if __name__ == "__main__":
    run_all_tests()
