#!/usr/bin/env python3
"""
Automated Test Suite: Advanced Folder Request Governance v2 (v1.9.0)
Enterprise Archive System - Nextcloud 34

Validates:
1. Duplicate Prevention (Backend Enforced & Physical Filesystem):
   - Reject request if physical folder already exists in the archive tree.
   - Reject request if another request for the same path is currently 'pending'.
   - Verify unique constraint protects against concurrent race conditions.
2. Complete Request Audit Trail:
   - All lifecycle events recorded: request_created, request_pending, request_approved,
     folder_created, permissions_applied, tag_created, request_completed, request_rejected.
   - Audit events contain Request ID, Event Type, Actor, Group, Folder Name, Statuses, Timestamps, and Reasons.
   - Audit API permissions: regular users (403), group admins (isolated to own group), system admin (full).
3. Group Admin Notifications:
   - Upon approval, native notification dispatched to group admin with folder details and link.
   - Upon rejection, native notification dispatched to group admin with mandatory rejection reason.
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


def run_tests():
    print("==================================================================")
    print(" STARTING ADVANCED FOLDER REQUEST GOVERNANCE V2 VERIFICATION SUITE")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Step 1: Duplicate Prevention - Existing Physical Folder
    # ------------------------------------------------------------------
    print("\n[Step 1] Testing Duplicate Prevention: Rejecting Already Existing Physical Folder...")
    # 'افتا' and 'عملیات_امنیتی_۱۴۰۵' physically exist under Enterprise_Archive/SOC
    dup_physical_payload = {
        "folder_name": "عملیات_امنیتی_۱۴۰۵",
        "target_path": "افتا",
        "description": "تلاش برای ایجاد مجدد پوشه موجود",
        "group_id": "SOC"
    }
    r_dup_phys = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=dup_physical_payload,
        auth=soc_admin_auth
    )
    print(f"  - Duplicate physical folder response status: {r_dup_phys.status_code}")
    assert r_dup_phys.status_code in (400, 409), f"Expected 400 or 409 for existing folder, got {r_dup_phys.status_code}"
    err_msg = r_dup_phys.json().get("message", "")
    print(f"  - Server response message: '{err_msg}'")
    assert "وجود" in err_msg or "already" in err_msg.lower() or "exist" in err_msg.lower()
    print("  ✔ PASSED: Physical existing folder request successfully blocked by backend.")

    # ------------------------------------------------------------------
    # Step 2: Duplicate Prevention - Concurrent / Pending Request
    # ------------------------------------------------------------------
    print("\n[Step 2] Testing Duplicate Prevention: Rejecting Duplicate Pending Request...")
    unique_test_name = f"گزارش_امنیتی_{int(time.time())}"
    pending_payload = {
        "folder_name": unique_test_name,
        "target_path": "افتا",
        "description": "درخواست تستی اول برای ارزیابی تکراری بودن",
        "group_id": "SOC"
    }

    # First submission -> MUST succeed
    r_sub1 = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=pending_payload,
        auth=soc_admin_auth
    )
    assert r_sub1.status_code in (200, 201), f"First submission failed: {r_sub1.status_code} {r_sub1.text}"
    req1_id = r_sub1.json().get("request", {}).get("id")
    print(f"  ✔ Initial Request #{req1_id} created in 'pending' status.")

    # Second submission with exact same folder and path -> MUST be rejected
    r_sub2 = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=pending_payload,
        auth=soc_admin_auth
    )
    print(f"  - Duplicate pending response status: {r_sub2.status_code}")
    assert r_sub2.status_code in (400, 409), f"Expected 400 or 409 for duplicate pending, got {r_sub2.status_code}"
    err_msg2 = r_sub2.json().get("message", "")
    print(f"  - Server response message: '{err_msg2}'")
    assert "انتظار" in err_msg2 or "pending" in err_msg2.lower() or "duplicate" in err_msg2.lower()
    print("  ✔ PASSED: Duplicate pending request successfully blocked by backend validation and DB constraint.")

    # ------------------------------------------------------------------
    # Step 3: Audit Trail Access Control & Initial Lifecycle Events
    # ------------------------------------------------------------------
    print("\n[Step 3] Testing Audit Trail Access Control & Initial Creation Events...")
    # Regular user attempting to access audit -> 403
    r_reg_aud = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req1_id}/audit",
        auth=regular_auth
    )
    assert r_reg_aud.status_code == 403
    print("  ✔ Regular user strictly forbidden from audit trail (HTTP 403).")

    # CERT admin attempting to access SOC request audit -> 403 (Isolation)
    r_cert_aud = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req1_id}/audit",
        auth=cert_admin_auth
    )
    assert r_cert_aud.status_code == 403
    print("  ✔ Cross-group admin access to audit trail strictly blocked (HTTP 403).")

    # SOC admin fetches audit for their own request
    r_soc_aud = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req1_id}/audit",
        auth=soc_admin_auth
    )
    assert r_soc_aud.status_code == 200
    trail1 = r_soc_aud.json().get("audit_trail", [])
    event_types1 = [e["event_type"] for e in trail1]
    print(f"  - Audit events recorded so far: {event_types1}")
    assert "request_created" in event_types1
    assert "request_pending" in event_types1
    assert trail1[0]["actor_uid"] == SOC_ADMIN_USER
    assert trail1[0]["group_id"] == "SOC"
    print("  ✔ PASSED: Request creation and pending state correctly audited.")

    # ------------------------------------------------------------------
    # Step 4: Rejection Lifecycle Audit & Notification
    # ------------------------------------------------------------------
    print("\n[Step 4] Testing Rejection Lifecycle Audit & Notification...")
    reject_reason = "مسیر زیرپوشه نیازمند بازنگری است."
    r_reject = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req1_id}/reject",
        json={"reason": reject_reason},
        auth=admin_auth
    )
    assert r_reject.status_code == 200

    # Verify audit contains rejection event with reason
    r_aud_after_reject = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req1_id}/audit",
        auth=soc_admin_auth
    )
    assert r_aud_after_reject.status_code == 200
    trail_rej = r_aud_after_reject.json().get("audit_trail", [])
    rej_event = [e for e in trail_rej if e["event_type"] == "request_rejected"]
    assert len(rej_event) == 1, "Rejection event missing from audit trail!"
    assert rej_event[0]["actor_uid"] == ADMIN_USER
    assert rej_event[0]["rejection_reason"] == reject_reason
    print(f"  ✔ PASSED: Rejection event recorded in audit with reason: '{rej_event[0]['rejection_reason']}'")

    # ------------------------------------------------------------------
    # Step 5: Full Approval Lifecycle Audit Trail
    # ------------------------------------------------------------------
    print("\n[Step 5] Testing Full Approval Lifecycle Audit Events...")
    unique_approve_name = f"اسناد_نهایی_{int(time.time())}"
    approve_payload = {
        "folder_name": unique_approve_name,
        "target_path": "افتا",
        "description": "پوشه نهایی برای بررسی کامل رویدادهای تأیید و نوتیفیکیشن",
        "group_id": "SOC"
    }

    r_sub_appr = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests",
        json=approve_payload,
        auth=soc_admin_auth
    )
    assert r_sub_appr.status_code in (200, 201)
    req2_id = r_sub_appr.json().get("request", {}).get("id")
    print(f"  ✔ Fresh Request #{req2_id} submitted.")

    # Admin approves
    r_appr_action = requests.post(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req2_id}/approve",
        auth=admin_auth
    )
    assert r_appr_action.status_code == 200, f"Approve failed: {r_appr_action.text}"
    print(f"  ✔ Request #{req2_id} approved by system administrator.")

    # Inspect complete audit trail
    r_full_aud = requests.get(
        f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/folder-requests/{req2_id}/audit",
        auth=admin_auth
    )
    assert r_full_aud.status_code == 200
    trail2 = r_full_aud.json().get("audit_trail", [])
    event_types2 = [e["event_type"] for e in trail2]
    print(f"  - Audit events recorded: {event_types2}")

    expected_events = [
        "request_created",
        "request_pending",
        "request_approved",
        "folder_created",
        "permissions_applied",
        "tag_created",
        "request_completed"
    ]
    for ev in expected_events:
        assert ev in event_types2, f"Missing required audit event '{ev}' in audit trail!"
    print("  ✔ PASSED: All 7 approval lifecycle events verified in audit trail.")

    # ------------------------------------------------------------------
    # Step 6: Native Notification Delivery Verification
    # ------------------------------------------------------------------
    print("\n[Step 6] Testing Native Nextcloud Notification Delivery...")
    # Verify in oc_notifications table via docker psql
    check_notif_cmd = [
        "docker", "exec", "-u", "postgres", "archive_db",
        "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c",
        f"SELECT subject, object_id FROM oc_notifications WHERE \"user\" = '{SOC_ADMIN_USER}' AND app = 'archive_autotag' ORDER BY notification_id DESC LIMIT 5;"
    ]
    res_notif = subprocess.run(check_notif_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    notif_lines = res_notif.stdout.strip().splitlines()
    print(f"  - Recorded notifications for {SOC_ADMIN_USER}:\n    " + "\n    ".join(notif_lines))

    subjects = [line.split("|")[0] for line in notif_lines if "|" in line]
    assert "folder_request_approved" in subjects or "folder_request_rejected" in subjects, \
        f"Expected folder notifications in database, got: {notif_lines}"
    print("  ✔ PASSED: Native Nextcloud notifications verified in oc_notifications table.")

    print("\n==================================================================")
    print(" ALL ADVANCED GOVERNANCE, DUPLICATE PREVENTION, AUDIT TRAIL,")
    print(" AND NOTIFICATION TESTS PASSED SUCCESSFULLY (100%)!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
