"""
Test Suite: Enterprise Folder Hierarchy Protection
Validates that:
1. Regular users can upload documents into existing archive folders (HTTP 201/204).
2. Regular users CANNOT create new folders or subfolders (HTTP 403 Forbidden).
3. Administrators retain full authority to create and manage folders (HTTP 201).
"""

import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
USER = "archive_user1"
USER_PASS = "User_Password_123!"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
user_auth = HTTPBasicAuth(USER, USER_PASS)

def run_tests():
    print("==================================================================")
    print(" TESTING ENTERPRISE FOLDER CREATION RESTRICTION POLICY")
    print("==================================================================")

    base_shared_path = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER}/Enterprise_Archive/Finance/2026/Invoices_Archive"

    # 1. User uploads a valid document into existing folder -> MUST SUCCEED
    print("\n[Step 1] Verifying that regular user CAN upload files into existing folders...")
    test_file_url = f"{base_shared_path}/allowed_document_sample.txt"
    r_upload = requests.put(test_file_url, data=b"Confidential archive document content.", auth=user_auth)
    print(f"  - User PUT file HTTP Status: {r_upload.status_code}")
    assert r_upload.status_code in (201, 204), f"Expected 201/204 for file upload, got {r_upload.status_code}"
    print("  ✓ PASSED: Regular user successfully uploaded document into existing folder.")

    # 2. User attempts to create a new folder/subfolder via MKCOL -> MUST FAIL WITH 403
    print("\n[Step 2] Verifying that regular user CANNOT create a new subfolder...")
    unauthorized_folder_url = f"{base_shared_path}/Unauthorized_User_Subfolder"
    r_mkcol_user = requests.request("MKCOL", unauthorized_folder_url, auth=user_auth)
    print(f"  - User MKCOL folder HTTP Status: {r_mkcol_user.status_code}")
    print(f"  - Server response message: {r_mkcol_user.text.strip()}")
    assert r_mkcol_user.status_code == 403, f"Expected 403 Forbidden for folder creation, got {r_mkcol_user.status_code}"
    print("  ✓ PASSED: Regular user folder creation was rejected with HTTP 403 Forbidden.")

    # 3. User attempts to create a folder at any hierarchy level -> MUST FAIL WITH 403
    print("\n[Step 3] Verifying that regular user cannot create folder at parent level...")
    parent_level_folder = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER}/Enterprise_Archive/Finance/New_Department_Folder"
    r_mkcol_parent = requests.request("MKCOL", parent_level_folder, auth=user_auth)
    print(f"  - User MKCOL parent-level folder HTTP Status: {r_mkcol_parent.status_code}")
    assert r_mkcol_parent.status_code in (403, 507), f"Expected 403/507, got {r_mkcol_parent.status_code}"
    print("  ✓ PASSED: User cannot bypass restriction at any hierarchy level.")

    # 4. Administrator creates a new folder -> MUST SUCCEED WITH 201
    print("\n[Step 4] Verifying that Administrator CAN create folders...")
    admin_folder_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Finance/2026/Admin_Official_Folder"
    # Ensure it doesn't exist before test
    requests.delete(admin_folder_url, auth=admin_auth)
    r_mkcol_admin = requests.request("MKCOL", admin_folder_url, auth=admin_auth)
    print(f"  - Admin MKCOL folder HTTP Status: {r_mkcol_admin.status_code}")
    assert r_mkcol_admin.status_code in (201, 204), f"Expected 201 for admin folder creation, got {r_mkcol_admin.status_code}"
    print("  ✓ PASSED: Administrator successfully created new archive folder.")

    # Cleanup test files/folders
    requests.delete(test_file_url, auth=user_auth)
    requests.delete(admin_folder_url, auth=admin_auth)

    print("\n==================================================================")
    print(" ALL FOLDER CREATION RESTRICTION TESTS PASSED SUCCESSFULLY!")
    print("==================================================================")

if __name__ == "__main__":
    run_tests()