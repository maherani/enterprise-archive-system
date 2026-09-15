#!/usr/bin/env python3
"""
Test Suite: Group Archive Folders Dynamic Tree API
Verifies that:
1. System Admin can list folder hierarchy for any group.
2. Group Admin (Bakbari) can list folder hierarchy for their assigned group (SOC).
3. Regular User (archive_user1) is forbidden (HTTP 403).
4. Cross-group query by Group Admin is strictly forbidden (HTTP 403).
"""

import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_AUTH = HTTPBasicAuth("admin", "Secure_Admin_Password_123!")
BAKBARI_AUTH = HTTPBasicAuth("Bakbari", "User_Password_123!")
USER_AUTH = HTTPBasicAuth("archive_user1", "User_Password_123!")

def test_group_folders_api():
    print("=== Testing GET /api/group-folders as Admin for SOC ===")
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-folders?group_id=SOC", auth=ADMIN_AUTH)
    assert r.status_code == 200, f"Expected 200, got {r.status_code}"
    data = r.json()
    assert data.get("status") == "success"
    folders = data.get("folders", [])
    assert len(folders) > 0
    assert folders[0]["path"] == ""
    print(f"  ✔ Admin received {len(folders)} folders for group SOC.")

    print("=== Testing GET /api/group-folders as Bakbari (SOC Admin) ===")
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-folders?group_id=SOC", auth=BAKBARI_AUTH)
    assert r.status_code == 200, f"Expected 200, got {r.status_code}"
    data = r.json()
    assert data.get("status") == "success"
    print(f"  ✔ Group Admin received {len(data.get('folders', []))} folders for group SOC.")

    print("=== Testing GET /api/group-folders as Regular User ===")
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-folders?group_id=SOC", auth=USER_AUTH)
    assert r.status_code == 403, f"Expected 403, got {r.status_code}"
    print("  ✔ Regular user strictly forbidden (HTTP 403).")

    print("=== Testing GET /api/group-folders Cross-Group Isolation ===")
    r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-folders?group_id=CERT", auth=BAKBARI_AUTH)
    assert r.status_code == 403, f"Expected 403, got {r.status_code}"
    print("  ✔ Cross-group query strictly forbidden (HTTP 403).")

    print("\n==========================================")
    print(" ALL GROUP FOLDERS API TESTS PASSED (100%)!")
    print("==========================================")

if __name__ == "__main__":
    test_group_folders_api()
