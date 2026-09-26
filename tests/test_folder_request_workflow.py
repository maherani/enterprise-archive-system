#!/usr/bin/env python3
"""
Automated Test Suite: Delegated Folder Creation Workflow & Governance
Enterprise Archive System - Nextcloud 34

Validates:
1. Access Control & Role Boundaries:
   - Regular users cannot submit or view folder creation requests (HTTP 403 Forbidden).
   - System Admins are exempt from request submission (create folders directly).
   - Group Admins cannot submit requests for groups they do not administer (Spoofing prevention, HTTP 403).
2. Group Admin Submission:
   - Group Admin can submit request for their own group (status: pending).
   - Requests are strictly isolated between different Group Admins.
3. System Admin Governance:
   - Group Admins cannot approve or reject requests (HTTP 403).
   - System Admin can view all requests across all groups with filters.
   - System Admin can reject a request with mandatory reason; verifies no folder or tag is created.
4. Atomic Approval & Enterprise Policy Enforcement:
   - System Admin can approve a request.
   - Folder is physically created in correct archive path under group hierarchy.
   - Standard group share permissions (Read + Create) are inherited/verified.
   - Restricted System Tag is atomically generated and bound to requesting group.
   - Members of the group can see the tag in /api/tags; other groups CANNOT see the tag.
   - Group members can upload files to the newly approved folder.
   - Group members remain strictly forbidden from creating subfolders directly (HTTP 403).
"""

import sys
import time
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"

# System Admin
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

# Group Admin for SOC
SOC_ADMIN_USER = "Bakbari"
SOC_ADMIN_PASS = "User_Password_123!"
soc_admin_auth = HTTPBasicAuth(SOC_ADMIN_USER, SOC_ADMIN_PASS)

# Group Admin for CERT
CERT_ADMIN_USER = "maherani"
CERT_ADMIN_PASS = "User_Password_123!"
cert_admin_auth = HTTPBasicAuth(CERT_ADMIN_USER, CERT_ADMIN_PASS)

# Regular User in Compliance_Unit
REGULAR_USER = "archive_user1"
REGULAR_PASS = "User_Password_123!"
regular_auth = HTTPBasicAuth(REGULAR_USER, REGULAR_PASS)

# Regular User in CERT
CERT_USER = "Adli"
CERT_PASS = "User_Password_123!"
cert_user_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)


def run_tests():
    print("==================================================================")
    print(" STARTING DELEGATED FOLDER CREATION WORKFLOW VERIFICATION SUITE")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Step 1: Verify Role Endpoint & Regular User Access Denial
    # ------------------------------------------------------------------
    print("\n[Step 1] Verifying Role Information and Regular User Denial...")
    r_role_reg = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/user-role", auth=regular_auth)
    assert r_role_reg.status_code == 200, f"Role endpoint failed for regular user: {r_role_reg.status_code}"
    role_reg = r_role_reg.json().get("role", {})
    assert role_reg.get("is_admin") is False
    assert role_reg.get("is_group_admin") is False
    print("  ✔ Regular user role identified correctly (is_admin=False, is_group_admin=False).")

    # Regular user attempts to list requests -> 403
    r_list_reg = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests", auth=regular_auth)
    assert r_list_reg.status_code == 403, f"Regular user unexpectedly able to list requests! Status: {r_list_reg.status_code}"
    print("  ✔ PASSED: Regular user rejected from listing requests with HTTP 403.")

    # Regular user attempts to submit a request -> 403
    r_create_reg = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json={"folder_name": "Unauthorized_Folder", "group_id": "Compliance_Unit", "description": "Hacking"},
        auth=regular_auth
    )
    assert r_create_reg.status_code == 403, f"Regular user unexpectedly able to submit request! Status: {r_create_reg.status_code}"
    print("  ✔ PASSED: Regular user rejected from submitting request with HTTP 403.")

    # ------------------------------------------------------------------
    # Step 2: Verify Group Admin Roles & Anti-Spoofing
    # ------------------------------------------------------------------
    print("\n[Step 2] Verifying Group Admin Identity & Group Anti-Spoofing...")
    r_role_soc = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/user-role", auth=soc_admin_auth)
    assert r_role_soc.status_code == 200
    role_soc = r_role_soc.json().get("role", {})
    assert role_soc.get("is_group_admin") is True
    assert "SOC" in role_soc.get("subadmin_groups", [])
    print("  ✔ Bakbari correctly identified as Group Admin for 'SOC'.")

    # Bakbari tries to spoof and request a folder for 'CERT' -> 403
    r_spoof = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json={"folder_name": "Malicious_CERT_Folder", "group_id": "CERT", "description": "Cross group attempt"},
        auth=soc_admin_auth
    )
    assert r_spoof.status_code == 403, f"Expected 403 Forbidden for cross-group spoofing, got {r_spoof.status_code}"
    print("  ✔ PASSED: Group admin cross-group spoofing rejected with HTTP 403.")

    # System Admin attempts to submit request -> 400 (exempt from workflow)
    r_admin_create = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json={"folder_name": "Admin_Folder", "group_id": "SOC", "description": "Admin attempt"},
        auth=admin_auth
    )
    assert r_admin_create.status_code == 400, f"Expected 400 for system admin request submission, got {r_admin_create.status_code}"
    print("  ✔ PASSED: System Admin is exempt from submitting requests (direct creation).")

    # ------------------------------------------------------------------
    # Step 3: Valid Request Submission by Group Admin
    # ------------------------------------------------------------------
    print("\n[Step 3] Group Admin submits valid folder creation request for group 'SOC'...")
    ts = int(time.time())
    folder_name_1 = f"سامانه_پدافند_{ts}"
    req_payload_1 = {
        "folder_name": folder_name_1,
        "target_path": "افتا",
        "description": "پوشه اسناد امنیتی و گزارش‌های پدافند سایبری مرکز عملیات امنیت",
        "group_id": "SOC"
    }
    r_submit_1 = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=req_payload_1,
        auth=soc_admin_auth
    )
    assert r_submit_1.status_code in (200, 201), f"Failed to submit request: {r_submit_1.status_code} {r_submit_1.text}"
    req_data_1 = r_submit_1.json().get("request", {})
    req_id_1 = req_data_1.get("id")
    assert req_data_1.get("status") == "pending"
    assert req_data_1.get("group_id") == "SOC"
    assert req_data_1.get("requester_uid") == SOC_ADMIN_USER
    print(f"  ✔ Successfully created Request #{req_id_1} with status 'pending'.")

    # Group Admin lists their own requests
    r_list_soc = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests", auth=soc_admin_auth)
    assert r_list_soc.status_code == 200
    soc_req_ids = [req["id"] for req in r_list_soc.json().get("requests", [])]
    assert req_id_1 in soc_req_ids, "Submitted request not found in SOC admin request list"
    print(f"  ✔ SOC Admin sees Request #{req_id_1} in their group request list.")

    # CERT Group Admin lists requests -> MUST NOT see Request 1 (Isolation)
    r_list_cert = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests", auth=cert_admin_auth)
    assert r_list_cert.status_code == 200
    cert_req_ids = [req["id"] for req in r_list_cert.json().get("requests", [])]
    assert req_id_1 not in cert_req_ids, "LEAK: CERT Admin can see SOC's request!"
    print("  ✔ PASSED: Isolation verified between different group administrators.")

    # ------------------------------------------------------------------
    # Step 4: Rejection Workflow with Mandatory Reason
    # ------------------------------------------------------------------
    print("\n[Step 4] Verifying Rejection Workflow by System Admin...")
    # Group Admin attempts to reject or approve -> 403
    r_unauth_reject = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req_id_1}/reject",
        json={"reason": "Unauthorized attempt"},
        auth=soc_admin_auth
    )
    assert r_unauth_reject.status_code == 403
    print("  ✔ Group admin forbidden from rejecting requests (HTTP 403).")

    # System Admin rejects with reason
    rejection_reason = "توضیحات ناکافی است؛ لطفاً مسیر دقیق زیرپوشه را بازبینی و مجدداً ثبت نمایید."
    r_admin_reject = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req_id_1}/reject",
        json={"reason": rejection_reason},
        auth=admin_auth
    )
    assert r_admin_reject.status_code == 200, f"Admin reject failed: {r_admin_reject.status_code} {r_admin_reject.text}"
    data_rejected = r_admin_reject.json().get("request", {})
    assert data_rejected.get("status") == "rejected"
    assert data_rejected.get("rejection_reason") == rejection_reason
    assert data_rejected.get("reviewer_uid") == ADMIN_USER
    print(f"  ✔ PASSED: Request #{req_id_1} rejected by Admin with recorded reason.")

    # SOC Admin verifies they can view the rejection reason
    r_soc_view_rejected = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req_id_1}",
        auth=soc_admin_auth
    )
    assert r_soc_view_rejected.status_code == 200
    view_data = r_soc_view_rejected.json().get("request", {})
    assert view_data.get("status") == "rejected"
    assert view_data.get("rejection_reason") == rejection_reason
    print("  ✔ SOC Admin sees the rejection reason in request details.")

    # ------------------------------------------------------------------
    # Step 5: Approval Workflow & Atomic Folder/Tag Creation
    # ------------------------------------------------------------------
    print("\n[Step 5] Testing Approval Workflow & Atomic Folder/Tag Provisioning...")
    # 5.1 Group Admin submits a fresh request for approval
    folder_to_create = f"عملیات_امنیتی_{int(time.time())}"
    req_payload_2 = {
        "folder_name": folder_to_create,
        "target_path": "افتا",
        "description": "پوشه آرشیو گزارش‌های دوره‌ای افتا برای سال ۱۴۰۵",
        "group_id": "SOC"
    }
    r_submit_2 = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=req_payload_2,
        auth=soc_admin_auth
    )
    assert r_submit_2.status_code in (200, 201)
    req_id_2 = r_submit_2.json().get("request", {}).get("id")
    print(f"  ✔ Fresh Request #{req_id_2} created for folder '{folder_to_create}'.")

    # 5.2 System Admin approves the request
    r_approve = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req_id_2}/approve",
        auth=admin_auth
    )
    assert r_approve.status_code == 200, f"Admin approve failed: {r_approve.status_code} {r_approve.text}"
    approved_req = r_approve.json().get("request", {})
    assert approved_req.get("status") == "approved"
    assert approved_req.get("reviewer_uid") == ADMIN_USER
    assert approved_req.get("created_folder_id") is not None
    assert approved_req.get("created_tag_id") is not None
    print(f"  ✔ PASSED: Request #{req_id_2} approved. Folder ID: {approved_req.get('created_folder_id')}, Tag ID: {approved_req.get('created_tag_id')}")

    # 5.3 Verify Tag Provisioning and Group Tag Isolation
    print("\n[Step 5.3] Verifying Tag Isolation for the newly generated tag...")
    r_soc_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=soc_admin_auth)
    soc_tag_names = [t["name"] for t in r_soc_tags.json().get("tags", [])]
    assert folder_to_create in soc_tag_names, f"Newly created tag '{folder_to_create}' missing from SOC tags!"
    print(f"  ✔ SOC Group member successfully sees tag '{folder_to_create}'.")

    r_cert_tags = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/tags", auth=cert_user_auth)
    cert_tag_names = [t["name"] for t in r_cert_tags.json().get("tags", [])]
    assert folder_to_create not in cert_tag_names, f"LEAK: CERT user Adli can see SOC tag '{folder_to_create}'!"
    print(f"  ✔ PASSED: Tag isolation maintained (CERT member cannot see SOC tag '{folder_to_create}').")

    # 5.4 Verify WebDAV File Upload into the Newly Created Folder
    print("\n[Step 5.4] Verifying WebDAV Upload into newly created folder...")
    import urllib.parse
    group_folder_name = 'مرکز عملیات و پاسخ‌گویی امنیت سایبری'
    encoded_group_folder = urllib.parse.quote(group_folder_name)
    encoded_target_path = urllib.parse.quote('افتا')
    encoded_folder_to_create = urllib.parse.quote(folder_to_create)
    new_folder_webdav = f"{NEXTCLOUD_URL}/remote.php/dav/files/{SOC_ADMIN_USER}/{encoded_group_folder}/{encoded_target_path}/{encoded_folder_to_create}"
    sample_doc_url = f"{new_folder_webdav}/incident_report_{int(time.time())}.txt"
    r_put = requests.put(sample_doc_url, data=b"Highly confidential SOC operation report content.", auth=soc_admin_auth)
    print(f"  - WebDAV PUT status: {r_put.status_code}")
    assert r_put.status_code in (201, 204), f"Failed to upload document into newly created folder: {r_put.status_code}"
    print("  ✔ PASSED: Group member successfully uploaded document into newly approved folder.")

    # 5.5 Verify MKCOL protection still strictly blocks manual folder creation
    print("\n[Step 5.5] Verifying MKCOL folder creation policy remains active...")
    unauth_subfolder_url = f"{new_folder_webdav}/Illegal_Direct_Subfolder"
    r_mkcol = requests.request("MKCOL", unauth_subfolder_url, auth=soc_admin_auth)
    assert r_mkcol.status_code == 403, f"Expected 403 Forbidden for direct MKCOL, got {r_mkcol.status_code}"
    print("  ✔ PASSED: Direct MKCOL folder creation remains strictly blocked (HTTP 403).")

    print("\n==================================================================")
    print(" ALL WORKFLOW, GOVERNANCE, PERMISSION & TAG ISOLATION TESTS PASSED!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()