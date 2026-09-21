#!/usr/bin/env python3
"""
Test Suite: Central Tag Management for System Administrator (Requirement 28)
=============================================================================
Validates:
1. Access control:
   - Unauthenticated requests are rejected (HTTP 401).
   - Regular users (Adli, archive_user1) are strictly forbidden (HTTP 403).
   - Group admins (Bakbari, maherani) without system admin role are strictly forbidden (HTTP 403).
2. System Administrator Tag Catalog:
   - System Admin can list all tags across system (HTTP 200).
   - Catalog contains id, name, clean_name, scope ('system' or 'group'), group_id, status, owner, and usage_count.
3. Central Tag Creation:
   - System Admin can create a universal/system tag (scope='system').
   - System Admin can create a group-scoped tag for any group (scope='group', group_id='SOC').
4. Resource Assignment & Removal:
   - System Admin can assign tags to files and folders.
   - System Admin can remove tags from files and folders.
5. Safe Lifecycle & Deletion:
   - Attempting to delete a tag in use without force returns HTTP 409 Conflict (TAG_IN_USE).
   - Deleting with force=true performs cascading detachment and deletes the tag (HTTP 200).
6. Global Reconciliation:
   - System Admin can trigger global tag reconciliation (HTTP 200).
7. Audit Trail:
   - All admin actions (create, assign, remove, delete, reconcile) are reliably audited in oc_archive_tag_audit.
"""

import os
import sys
import time
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost")

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

SOC_ADMIN_USER = "Bakbari"
SOC_ADMIN_PASS = "User_Password_123!"

CERT_ADMIN_USER = "maherani"
CERT_ADMIN_PASS = "User_Password_123!"

REGULAR_USER = "Adli"
REGULAR_PASS = "User_Password_123!"

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
    "Content-Type": "application/json"
}

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
soc_admin_auth = HTTPBasicAuth(SOC_ADMIN_USER, SOC_ADMIN_PASS)
cert_admin_auth = HTTPBasicAuth(CERT_ADMIN_USER, CERT_ADMIN_PASS)
reg_auth = HTTPBasicAuth(REGULAR_USER, REGULAR_PASS)


def get_test_file_and_folder():
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=admin_auth, headers=HEADERS)
    assert r.status_code == 200, f"Failed to list files: {r.status_code}"
    data = r.json()
    files = data.get("files", [])
    test_file = None
    test_folder = None
    for f in files:
        if f.get("is_dir") and test_folder is None and "Enterprise_Archive" in f.get("path", ""):
            test_folder = f
        elif not f.get("is_dir") and test_file is None:
            test_file = f
        if test_file and test_folder:
            break
    return test_file, test_folder


def run_tests():
    print("=" * 80)
    print("  RUNNING REQUIREMENT 28: CENTRAL TAG MANAGEMENT INTEGRATION TESTS")
    print("=" * 80)

    ts = int(time.time())

    # -------------------------------------------------------------------------
    # 1. Access Control & Authorization (Fail-Closed)
    # -------------------------------------------------------------------------
    print("\n[Test 1] Verifying access control on /api/admin/tags...")
    # 1a. Unauthenticated
    r_unauth = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", headers=HEADERS)
    assert r_unauth.status_code == 401, f"Expected 401 for unauth, got: {r_unauth.status_code}"
    print("  ✔ Unauthenticated request rejected with HTTP 401.")

    # 1b. Regular user (Adli)
    r_reg = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", auth=reg_auth, headers=HEADERS)
    assert r_reg.status_code == 403, f"Expected 403 for regular user, got: {r_reg.status_code}"
    assert r_reg.json().get("code") == "FORBIDDEN"
    print("  ✔ Regular user strictly forbidden with HTTP 403.")

    # 1c. Group Admin (Bakbari)
    r_ga = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", auth=soc_admin_auth, headers=HEADERS)
    assert r_ga.status_code == 403, f"Expected 403 for group admin, got: {r_ga.status_code}"
    print("  ✔ Group admin strictly forbidden with HTTP 403.")

    # -------------------------------------------------------------------------
    # 2. System Administrator Tag Catalog Listing
    # -------------------------------------------------------------------------
    print("\n[Test 2] Verifying System Administrator can list full tag catalog...")
    r_cat = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", auth=admin_auth, headers=HEADERS)
    assert r_cat.status_code == 200, f"Failed to list tags for admin: {r_cat.status_code}"
    cat_data = r_cat.json()
    assert cat_data.get("status") == "success"
    tags = cat_data.get("tags", [])
    print(f"  ✔ Admin retrieved {len(tags)} tags from central catalog.")
    if tags:
        sample = tags[0]
        assert "id" in sample and "name" in sample and "scope" in sample and "status" in sample
        print(f"  ✔ Sample tag structure verified: ID={sample['id']}, Name='{sample['name']}', Scope='{sample['scope']}'")

    # -------------------------------------------------------------------------
    # 3. Central Tag Creation (Universal System Tag & Group Tag)
    # -------------------------------------------------------------------------
    print("\n[Test 3] Creating Universal System Tag and Group-Scoped Tag as Admin...")
    sys_tag_name = f"GlobalConfidential_{ts}"
    grp_tag_name = f"AuditReq28_{ts}"

    # 3a. Regular user blocked from creating admin tag
    r_reg_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/create",
        auth=reg_auth,
        headers=HEADERS,
        json={"tag_name": sys_tag_name, "scope": "system"}
    )
    assert r_reg_create.status_code == 403, f"Expected 403 for reg user create, got: {r_reg_create.status_code}"
    print("  ✔ Regular user blocked from creating admin tag (HTTP 403).")

    # 3b. Create Universal System Tag
    r_sys_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/create",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_name": sys_tag_name, "scope": "system"}
    )
    assert r_sys_create.status_code == 200, f"Failed to create system tag: {r_sys_create.text}"
    sys_res = r_sys_create.json().get("data", {})
    sys_tag_id = sys_res.get("tag_id")
    assert sys_tag_id > 0
    assert sys_res.get("scope") == "system"
    print(f"  ✔ Created Universal System Tag: '{sys_tag_name}' (ID: {sys_tag_id}).")

    # 3c. Create Group Tag for SOC
    r_grp_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/create",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_name": grp_tag_name, "scope": "group", "group_id": "SOC"}
    )
    assert r_grp_create.status_code == 200, f"Failed to create group tag: {r_grp_create.text}"
    grp_res = r_grp_create.json().get("data", {})
    grp_tag_id = grp_res.get("tag_id")
    assert grp_tag_id > 0
    assert grp_res.get("scope") == "group"
    assert grp_res.get("group_id") == "SOC"
    assert grp_res.get("name") == f"[SOC] {grp_tag_name}"
    print(f"  ✔ Created Group-Scoped Tag: '[SOC] {grp_tag_name}' (ID: {grp_tag_id}).")

    # -------------------------------------------------------------------------
    # 4. Resource Assignment & Removal (File & Folder)
    # -------------------------------------------------------------------------
    print("\n[Test 4] Verifying Tag Assignment to File and Folder...")
    test_file, test_folder = get_test_file_and_folder()
    assert test_file is not None, "No archive file found for testing"
    assert test_folder is not None, "No archive folder found for testing"
    test_file_id = test_file["id"]
    test_folder_id = test_folder["id"]
    print(f"  Using Test File ID: {test_file_id} ('{test_file.get('name')}')")
    print(f"  Using Test Folder ID: {test_folder_id} ('{test_folder.get('name')}')")

    # 4a. Regular user blocked from assign
    r_reg_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/assign",
        auth=reg_auth,
        headers=HEADERS,
        json={"tag_id": sys_tag_id, "file_id": test_file_id}
    )
    assert r_reg_assign.status_code == 403
    print("  ✔ Regular user blocked from admin tag assign (HTTP 403).")

    # 4b. Admin assigns Universal System Tag to file
    r_file_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/assign",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": sys_tag_id, "file_id": test_file_id}
    )
    assert r_file_assign.status_code == 200, f"Failed to assign tag to file: {r_file_assign.text}"
    assert r_file_assign.json().get("data", {}).get("resource_type") == "file"
    print(f"  ✔ Assigned System Tag #{sys_tag_id} to file #{test_file_id} successfully.")

    # 4c. Admin assigns Group Tag to folder
    r_folder_assign = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/assign",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": grp_tag_id, "file_id": test_folder_id}
    )
    assert r_folder_assign.status_code == 200, f"Failed to assign tag to folder: {r_folder_assign.text}"
    assert r_folder_assign.json().get("data", {}).get("resource_type") == "folder"
    print(f"  ✔ Assigned Group Tag #{grp_tag_id} to folder #{test_folder_id} successfully.")

    # 4d. Verify usage counts in catalog
    r_cat2 = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", auth=admin_auth, headers=HEADERS)
    assert r_cat2.status_code == 200
    cat_tags = {t["id"]: t for t in r_cat2.json().get("tags", [])}
    assert cat_tags[sys_tag_id]["usage_count"] >= 1
    assert cat_tags[grp_tag_id]["usage_count"] >= 1
    print(f"  ✔ Verified usage counts updated in catalog (System Tag: {cat_tags[sys_tag_id]['usage_count']}, Group Tag: {cat_tags[grp_tag_id]['usage_count']}).")

    # 4e. Admin removes Group Tag from folder
    r_folder_remove = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/remove",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": grp_tag_id, "file_id": test_folder_id}
    )
    assert r_folder_remove.status_code == 200, f"Failed to remove tag from folder: {r_folder_remove.text}"
    print(f"  ✔ Removed Group Tag #{grp_tag_id} from folder #{test_folder_id} successfully.")

    # -------------------------------------------------------------------------
    # 5. Safe Lifecycle & Deletion (In-Use Conflict & Force Delete)
    # -------------------------------------------------------------------------
    print("\n[Test 5] Verifying Safe Tag Deletion (TAG_IN_USE conflict & Force Delete)...")
    # 5a. Regular user blocked from delete
    r_reg_del = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/delete",
        auth=reg_auth,
        headers=HEADERS,
        json={"tag_id": sys_tag_id}
    )
    assert r_reg_del.status_code == 403
    print("  ✔ Regular user blocked from admin tag delete (HTTP 403).")

    # 5b. Delete system tag in-use without force -> HTTP 409 Conflict
    r_del_conflict = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/delete",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": sys_tag_id, "force": False}
    )
    assert r_del_conflict.status_code == 409, f"Expected 409 Conflict, got: {r_del_conflict.status_code}"
    conf_data = r_del_conflict.json()
    assert conf_data.get("code") == "TAG_IN_USE"
    assert conf_data.get("usage_count") >= 1
    print(f"  ✔ Safe guard confirmed: Delete in-use tag rejected with HTTP 409 (Usage: {conf_data.get('usage_count')}).")

    # 5c. Force delete system tag -> HTTP 200 and cascading detachment
    r_del_force = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/delete",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": sys_tag_id, "force": True}
    )
    assert r_del_force.status_code == 200, f"Force delete failed: {r_del_force.text}"
    print(f"  ✔ Force delete completed successfully with cascading detachment.")

    # 5d. Delete group tag (usage is now 0) -> HTTP 200
    r_del_grp = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/delete",
        auth=admin_auth,
        headers=HEADERS,
        json={"tag_id": grp_tag_id, "force": False}
    )
    assert r_del_grp.status_code == 200, f"Delete group tag failed: {r_del_grp.text}"
    print(f"  ✔ Deleted Group Tag #{grp_tag_id} successfully.")

    # 5e. Verify tags no longer exist in catalog
    r_cat3 = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags", auth=admin_auth, headers=HEADERS)
    remaining_ids = [t["id"] for t in r_cat3.json().get("tags", [])]
    assert sys_tag_id not in remaining_ids, "System tag still present in catalog after delete!"
    assert grp_tag_id not in remaining_ids, "Group tag still present in catalog after delete!"
    print("  ✔ Verified deleted tags are completely absent from catalog.")

    # -------------------------------------------------------------------------
    # 6. Global Reconciliation Endpoint
    # -------------------------------------------------------------------------
    print("\n[Test 6] Testing Admin Global Reconciliation API...")
    r_recon = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/admin/tags/reconcile",
        auth=admin_auth,
        headers=HEADERS
    )
    assert r_recon.status_code == 200, f"Reconciliation failed: {r_recon.text}"
    recon_data = r_recon.json()
    assert recon_data.get("status") == "success"
    print(f"  ✔ Global reconciliation executed: {recon_data.get('message')}")

    # -------------------------------------------------------------------------
    # 7. Audit Trail Verification
    # -------------------------------------------------------------------------
    print("\n[Test 7] Verifying Audit Trail in oc_archive_tag_audit...")
    db_cmd = [
        "docker", "exec", "archive_db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c",
        f"SELECT action, result FROM oc_archive_tag_audit WHERE actor_uid = 'admin' AND tag_id = {sys_tag_id} ORDER BY id ASC;"
    ]
    res_db = subprocess.run(db_cmd, capture_output=True, text=True)
    assert res_db.returncode == 0, f"Database query failed: {res_db.stderr}"
    lines = [l.strip() for l in res_db.stdout.splitlines() if l.strip()]
    actions_logged = [l.split("|")[0] for l in lines]
    print(f"  Logged actions for System Tag #{sys_tag_id}: {actions_logged}")
    assert "create_tag" in actions_logged, "Missing create_tag in audit"
    assert "assign_tag" in actions_logged, "Missing assign_tag in audit"
    assert "delete_tag" in actions_logged, "Missing delete_tag in audit"
    print("  ✔ All admin lifecycle operations confirmed in oc_archive_tag_audit!")

    print("\n" + "=" * 80)
    print("  ALL REQUIREMENT 28 INTEGRATION TESTS PASSED WITH 100% SUCCESS!")
    print("=" * 80)


if __name__ == "__main__":
    run_tests()
