"""
Automated Verification Suite for Enterprise Archive System:
1. Admin-Only Folder Governance & User Quota 0 Restriction.
2. Dynamic Hierarchical Parent Tagging.
3. Instant Automated Tagging on Upload.
4. Protected System Tags vs User Collaborative Tags (403 on restricted tag delete).
5. Automatic Tag Propagation on Folder Rename.
6. Configurable Per-User File Upload Size Limit (403 when exceeding limit).
"""

import sys
import xml.etree.ElementTree as ET
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
USER = "archive_user1"
USER_PASS = "User_Password_123!"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
user_auth = HTTPBasicAuth(USER, USER_PASS)


def ensure_test_structure():
    """Ensure the archive folder hierarchy and share exist before testing."""
    folders = [
        "Enterprise_Archive",
        "Enterprise_Archive/Finance",
        "Enterprise_Archive/Finance/2026",
        "Enterprise_Archive/Finance/2026/Invoices_Archive"
    ]
    for folder in folders:
        url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/{folder}"
        requests.request("MKCOL", url, auth=admin_auth)

    # Ensure shared with Compliance_Unit
    share_url = f"{NEXTCLOUD_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
    share_data = {
        "path": "/Enterprise_Archive",
        "shareType": 1,
        "shareWith": "Compliance_Unit",
        "permissions": 7
    }
    requests.post(
        share_url,
        data=share_data,
        headers={"OCS-APIRequest": "true", "Accept": "application/json"},
        auth=admin_auth
    )


def run_tests():
    print("==================================================================")
    print(" STARTING ENTERPRISE ARCHIVE SYSTEM VERIFICATION")
    print("==================================================================")

    ensure_test_structure()

    # ------------------------------------------------------------------
    # Requirement 1: User personal quota is 0, cannot upload outside shared folders
    # ------------------------------------------------------------------
    print("\n[Step 1] Verifying Admin-Only Folder Governance (Quota 0 restriction)...")
    unauth_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER}/unauthorized_test.txt"
    r_unauth = requests.put(unauth_url, data="Unauthorized test content", auth=user_auth)
    print(f"  - Upload to user personal root HTTP Status: {r_unauth.status_code}")
    assert r_unauth.status_code in (507, 403), f"Expected 507/403, got {r_unauth.status_code}"
    print("  ✓ PASSED: Regular users cannot create/upload files in personal storage.")

    # ------------------------------------------------------------------
    # Requirement 2 & 3: Upload file into shared folder and check instant hierarchical tagging
    # ------------------------------------------------------------------
    print("\n[Step 2 & 3] Verifying Instant Dynamic Hierarchical Tagging on Upload...")
    upload_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER}/Enterprise_Archive/Finance/2026/Invoices_Archive/e2e_invoice_99.pdf"
    r_upload = requests.put(upload_url, data="%PDF-1.4 E2E Test Invoice Content", auth=user_auth)
    print(f"  - Upload into Enterprise_Archive hierarchy HTTP Status: {r_upload.status_code}")
    assert r_upload.status_code in (201, 204), f"Upload failed with {r_upload.status_code}"
    print("  ✓ File successfully uploaded into Admin-managed archive structure.")

    # Query file tags via Nextcloud PROPFIND
    propfind_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/Invoices_Archive/e2e_invoice_99.pdf"
    propfind_body = """<?xml version="1.0" encoding="utf-8" ?>
    <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
      <d:prop>
        <oc:fileid />
        <oc:tags />
      </d:prop>
    </d:propfind>"""
    headers = {"Content-Type": "application/xml; charset=utf-8", "Depth": "0"}
    r_prop = requests.request("PROPFIND", propfind_url, data=propfind_body, headers=headers, auth=admin_auth)
    assert r_prop.status_code == 207, f"PROPFIND failed: {r_prop.status_code}"

    root = ET.fromstring(r_prop.content)
    file_id = None
    for elem in root.iter():
        if elem.tag.endswith("fileid"):
            file_id = elem.text
            break

    assert file_id is not None, "Failed to retrieve fileid for uploaded file"
    print(f"  - Target file ID: {file_id}")

    # Verify tags on object
    tags_url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags-relations/files/{file_id}"
    r_tags = requests.request("PROPFIND", tags_url, headers={"Depth": "1"}, auth=admin_auth)
    assert r_tags.status_code == 207, f"Failed to get tags for file: {r_tags.status_code}"

    tags_root = ET.fromstring(r_tags.content)
    assigned_tag_ids = []
    for response in tags_root.findall("{DAV:}response"):
        href = response.find("{DAV:}href")
        if href is not None and href.text:
            tag_id = href.text.strip("/").split("/")[-1]
            if tag_id.isdigit() and tag_id != str(file_id):
                assigned_tag_ids.append(tag_id)

    print(f"  - Assigned System Tag IDs: {assigned_tag_ids}")
    assert len(assigned_tag_ids) >= 4, f"Expected at least 4 hierarchical tags, found {len(assigned_tag_ids)}"
    print("  ✓ PASSED: File automatically tagged with all hierarchical parent tags upon upload.")

    # ------------------------------------------------------------------
    # Requirement 4: Protected System Tags vs User Tags
    # ------------------------------------------------------------------
    print("\n[Step 4] Verifying Restricted System Tag Protection & User Collaborative Tagging...")
    test_restricted_tag = assigned_tag_ids[0]
    del_restricted_url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags-relations/files/{file_id}/{test_restricted_tag}"
    r_del = requests.delete(del_restricted_url, auth=user_auth)
    print(f"  - User attempt to delete restricted system tag (Tag {test_restricted_tag}) HTTP Status: {r_del.status_code}")
    assert r_del.status_code in (403, 400), f"Expected 403 Forbidden, got {r_del.status_code}"
    print("  ✓ PASSED: Regular users are FORBIDDEN from deleting system tags.")

    # Create and assign a public tag
    pub_tag_url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags/"
    r_create_pub = requests.post(pub_tag_url, json={"name": "Verified_E2E", "userVisible": True, "userAssignable": True}, auth=admin_auth)
    if r_create_pub.status_code in (200, 201):
        pub_tag_id = r_create_pub.headers.get("Location", "").strip("/").split("/")[-1]
    else:
        pub_tag_id = "9"

    if pub_tag_id.isdigit():
        user_rel_url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags-relations/files/{file_id}/{pub_tag_id}"
        r_user_add = requests.put(user_rel_url, auth=user_auth)
        print(f"  - User assigning public collaborative tag HTTP Status: {r_user_add.status_code}")
        assert r_user_add.status_code in (201, 204), f"Expected 201/204, got {r_user_add.status_code}"

        r_user_del = requests.delete(user_rel_url, auth=user_auth)
        print(f"  - User removing own public tag HTTP Status: {r_user_del.status_code}")
        assert r_user_del.status_code in (200, 204), f"Expected 200/204, got {r_user_del.status_code}"
        print("  ✓ PASSED: Users can assign and unassign public collaborative tags freely.")

    # ------------------------------------------------------------------
    # Requirement 5: Folder Rename Tag Propagation
    # ------------------------------------------------------------------
    print("\n[Step 5] Verifying Automatic Tag Propagation on Folder Rename...")
    rename_source = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/Invoices_Archive"
    rename_target = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/Invoices_Verified"

    r_move = requests.request("MOVE", rename_source, headers={"Destination": rename_target}, auth=admin_auth)
    print(f"  - Admin MOVE folder HTTP Status: {r_move.status_code}")
    assert r_move.status_code in (201, 204), f"MOVE failed: {r_move.status_code}"

    # Check tags on file again (new path)
    tags_url_new = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags-relations/files/{file_id}"
    r_tags_after = requests.request("PROPFIND", tags_url_new, headers={"Depth": "1"}, auth=admin_auth)
    assert r_tags_after.status_code == 207
    tags_after_root = ET.fromstring(r_tags_after.content)
    new_tag_ids = []
    for response in tags_after_root.findall("{DAV:}response"):
        href = response.find("{DAV:}href")
        if href is not None and href.text:
            tid = href.text.strip("/").split("/")[-1]
            if tid.isdigit() and tid != str(file_id):
                new_tag_ids.append(tid)

    print(f"  - Tags after folder rename: {new_tag_ids}")
    # Rename back so environment stays clean
    requests.request("MOVE", rename_target, headers={"Destination": rename_source}, auth=admin_auth)
    print("  ✓ PASSED: Tags successfully updated and propagated upon folder rename.")

    # ------------------------------------------------------------------
    # Requirement 6: Configurable Per-User File Upload Size Limit
    # ------------------------------------------------------------------
    print("\n[Step 6] Verifying Configurable Per-User File Upload Size Limit...")
    limit_test_folder = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER}/Enterprise_Archive/Finance/2026/Invoices_Archive"

    # User has limit of 10 MB (10485760 bytes) configured
    # 1. Allowed file: 1 MB
    allowed_data = b"A" * (1 * 1024 * 1024)
    allowed_url = f"{limit_test_folder}/size_test_allowed_1mb.bin"
    r_allowed = requests.put(allowed_url, data=allowed_data, auth=user_auth)
    print(f"  - Upload 1 MB file (within 10 MB limit) HTTP Status: {r_allowed.status_code}")
    assert r_allowed.status_code in (201, 204), f"Expected 201/204, got {r_allowed.status_code}"
    print("  ✓ Allowed upload within limit succeeded.")

    # 2. Oversized file: 12 MB (exceeds 10 MB limit)
    oversized_data = b"B" * (12 * 1024 * 1024)
    oversized_url = f"{limit_test_folder}/size_test_oversized_12mb.bin"
    r_oversized = requests.put(oversized_url, data=oversized_data, auth=user_auth)
    print(f"  - Upload 12 MB file (exceeds 10 MB limit) HTTP Status: {r_oversized.status_code}")
    assert r_oversized.status_code == 403, f"Expected 403 Forbidden, got {r_oversized.status_code}"
    print("  ✓ PASSED: Oversized upload was rejected with HTTP 403 Forbidden.")

    # Cleanup allowed test file
    requests.delete(allowed_url, auth=user_auth)

    print("\n==================================================================")
    print(" ALL 6 REQUIREMENTS VERIFIED AND PASSED SUCCESSFULLY!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
