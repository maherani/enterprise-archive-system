import sys
import time
import requests
import subprocess
import xml.etree.ElementTree as ET

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

USER_A = "archive_user1"
USER_A_PASS = "User_Password_123!"

USER_B = "api_worker"
USER_B_PASS = "5NJ8SmJLllNypBwaus3TmQwhdbjDdYQ4PFwbUz6h4LJtiMbA14QwyvCazozux7lh8aOKc72b"

admin_auth = (ADMIN_USER, ADMIN_PASS)
user_a_auth = (USER_A, USER_A_PASS)
user_b_auth = (USER_B, USER_B_PASS)


def run_occ(cmd: str) -> str:
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    res = subprocess.run(full_cmd, shell=True, capture_output=True, text=True)
    return res.stdout.strip()


def run_tests():
    print("==================================================================")
    print(" STARTING FILE ACCESS CONTROL & TAG ISOLATION VERIFICATION SUITE")
    print("==================================================================")

    filename = "isolation_test_doc_99.pdf"
    folder_rel = "Enterprise_Archive/jj"
    file_content = b"%PDF-1.4 Strictly Confidential User A Document 99\n"

    url_user_a = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER_A}/{folder_rel}/{filename}"
    url_user_b = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER_B}/{folder_rel}/{filename}"
    url_admin = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/{folder_rel}/{filename}"

    folder_url_user_b = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER_B}/{folder_rel}/"
    folder_url_user_a = f"{NEXTCLOUD_URL}/remote.php/dav/files/{USER_A}/{folder_rel}/"
    folder_url_admin = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/{folder_rel}/"

    # Cleanup any pre-existing test file
    requests.delete(url_admin, auth=admin_auth)

    # ------------------------------------------------------------------
    # Test 1: User A uploads file -> User B has ZERO access or visibility
    # ------------------------------------------------------------------
    print("\n[Test 1] User A uploads confidential file; verifying isolation from User B...")
    r_up = requests.put(url_user_a, data=file_content, auth=user_a_auth)
    assert r_up.status_code in (201, 204), f"User A upload failed: {r_up.status_code}"
    print("  ? User A successfully uploaded file into archive.")

    # 1.1 User B tries to GET User A's file -> 404
    r_b_get = requests.get(url_user_b, auth=user_b_auth)
    assert r_b_get.status_code == 404, f"User B unexpectedly able to GET file! Status: {r_b_get.status_code}"
    print(f"  ? PASSED: User B GET returned HTTP {r_b_get.status_code} (Not Found).")

    # 1.2 User B tries PROPFIND directly on file -> 404
    r_b_prop_file = requests.request("PROPFIND", url_user_b, headers={"Depth": "0"}, auth=user_b_auth)
    assert r_b_prop_file.status_code == 404, f"User B file PROPFIND returned {r_b_prop_file.status_code}"
    print("  ? PASSED: User B direct file PROPFIND returned HTTP 404.")

    # 1.3 User B tries PROPFIND on parent directory -> file must NOT appear in listing
    r_b_prop_dir = requests.request("PROPFIND", folder_url_user_b, headers={"Depth": "1"}, auth=user_b_auth)
    assert r_b_prop_dir.status_code == 207, f"Directory PROPFIND failed: {r_b_prop_dir.status_code}"
    assert filename not in r_b_prop_dir.text, "LEAK: User A's file appeared in User B's folder PROPFIND!"
    print("  ? PASSED: User A's file is completely omitted from User B's folder PROPFIND listing.")

    # 1.4 User B tries to DELETE User A's file -> 404
    r_b_del = requests.delete(url_user_b, auth=user_b_auth)
    assert r_b_del.status_code == 404, f"User B was able to delete file: {r_b_del.status_code}"
    print("  ? PASSED: User B cannot delete User A's file (HTTP 404).")

    # ------------------------------------------------------------------
    # Test 2: Admin has 100% visibility & download access over all files
    # ------------------------------------------------------------------
    print("\n[Test 2] Verifying System Admin Authority over User A's uploaded file...")
    # 2.1 Admin GET
    r_adm_get = requests.get(url_admin, auth=admin_auth)
    assert r_adm_get.status_code == 200 and r_adm_get.content == file_content, \
        f"Admin GET failed: {r_adm_get.status_code}"
    print("  ? System Admin successfully downloaded User A's file via GET.")

    # 2.2 Admin folder PROPFIND sees the file
    r_adm_prop = requests.request("PROPFIND", folder_url_admin, headers={"Depth": "1"}, auth=admin_auth)
    assert filename in r_adm_prop.text, "Admin folder PROPFIND missing uploaded file!"
    print("  ? System Admin sees file in folder PROPFIND listing.")

    # Extract fileid from Admin PROPFIND
    prop_body = """<?xml version="1.0" encoding="utf-8" ?>
    <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
      <d:prop><oc:fileid /></d:prop>
    </d:propfind>"""
    r_prop_f = requests.request("PROPFIND", url_admin, data=prop_body, headers={"Depth": "0"}, auth=admin_auth)
    root = ET.fromstring(r_prop_f.content)
    file_id = None
    for elem in root.iter():
        if elem.tag.endswith("fileid"):
            file_id = elem.text
            break
    assert file_id is not None, "Failed to resolve file ID"
    print(f"  ? Target file resolved with ID: {file_id}")

    # ------------------------------------------------------------------
    # Test 3: Admin Grants & Revokes Access for User B
    # ------------------------------------------------------------------
    print("\n[Test 3] Verifying Admin File Grant & Revocation Workflow...")
    # 3.1 Admin grants access to User B
    grant_out = run_occ(f"archive:file:grant grant {file_id} {USER_B}")
    print(f"  - occ grant output: {grant_out}")

    # 3.2 User B now CAN GET the file
    r_b_granted_get = requests.get(url_user_b, auth=user_b_auth)
    assert r_b_granted_get.status_code == 200 and r_b_granted_get.content == file_content, \
        f"Expected HTTP 200 after grant, got {r_b_granted_get.status_code}"
    print("  ? PASSED: User B successfully downloaded file after Admin grant (HTTP 200).")

    # 3.3 User B now SEES the file in folder PROPFIND
    r_b_granted_prop = requests.request("PROPFIND", folder_url_user_b, headers={"Depth": "1"}, auth=user_b_auth)
    assert filename in r_b_granted_prop.text, "User B PROPFIND missing granted file!"
    print("  ? PASSED: File is now visible in User B's folder PROPFIND listing.")

    # 3.4 Admin revokes access from User B
    revoke_out = run_occ(f"archive:file:grant revoke {file_id} {USER_B}")
    print(f"  - occ revoke output: {revoke_out}")

    # 3.5 User B is BLOCKED again (HTTP 404)
    r_b_revoked_get = requests.get(url_user_b, auth=user_b_auth)
    assert r_b_revoked_get.status_code == 404, \
        f"Expected HTTP 404 after revoke, got {r_b_revoked_get.status_code}"
    print("  ? PASSED: User B blocked with HTTP 404 immediately upon revocation.")

    # 3.6 File disappears from User B's folder PROPFIND
    r_b_revoked_prop = requests.request("PROPFIND", folder_url_user_b, headers={"Depth": "1"}, auth=user_b_auth)
    assert filename not in r_b_revoked_prop.text, "Revoked file still visible in User B's folder PROPFIND!"
    print("  ? PASSED: Revoked file is excluded from User B's folder PROPFIND listing.")

    # ------------------------------------------------------------------
    # Test 4: Tag Isolation (Private User Tag vs System/Admin Tag)
    # ------------------------------------------------------------------
    print("\n[Test 4] Verifying Tag Ownership & Visibility Isolation...")
    private_tag_name = f"PrivateTag_UserA_{int(time.time())}"
    tag_create_url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags/"

    # 4.1 User A creates a private tag
    r_tag_c = requests.post(tag_create_url, json={"name": private_tag_name, "userVisible": True, "userAssignable": True}, auth=user_a_auth)
    assert r_tag_c.status_code in (200, 201), f"Tag creation failed: {r_tag_c.status_code}"

    # 4.2 User A sees the tag in API and resolve tag_id
    r_a_tags = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags", auth=user_a_auth).json()
    a_tag_names = [t["name"] for t in r_a_tags.get("tags", [])]
    assert private_tag_name in a_tag_names, "User A does not see their own tag!"
    tag_id = [str(t["id"]) for t in r_a_tags["tags"] if t["name"] == private_tag_name][0]
    print(f"  ? User A created private tag '{private_tag_name}' (Tag ID: {tag_id}).")

    # 4.3 Admin sees the private tag
    r_adm_tags = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags", auth=admin_auth).json()
    adm_tag_names = [t["name"] for t in r_adm_tags.get("tags", [])]
    assert private_tag_name in adm_tag_names, "Admin does not see user's private tag!"
    print("  ? System Admin sees User A's private tag.")

    # 4.4 User B CANNOT see the private tag
    r_b_tags = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags", auth=user_b_auth).json()
    b_tag_names = [t["name"] for t in r_b_tags.get("tags", [])]
    assert private_tag_name not in b_tag_names, f"LEAK: User B can see User A's private tag!"
    print("  ? PASSED: User B CANNOT see User A's private tag in /api/tags.")

    # 4.5 User B WebDAV DELETE attempt on User A's tag -> 404
    r_b_tag_del = requests.delete(f"{NEXTCLOUD_URL}/remote.php/dav/systemtags/{tag_id}", auth=user_b_auth)
    assert r_b_tag_del.status_code == 404, f"User B should get 404, got {r_b_tag_del.status_code}"
    print("  ? PASSED: User B cannot delete or access User A's tag via WebDAV (HTTP 404).")

    # ------------------------------------------------------------------
    # Test 5: System / Admin Tag Visibility
    # ------------------------------------------------------------------
    print("\n[Test 5] Verifying System Tag Visibility for all users...")
    system_tag = "Enterprise_Archive"
    assert system_tag in a_tag_names, "System tag missing for User A"
    assert system_tag in b_tag_names, "System tag missing for User B"
    assert system_tag in adm_tag_names, "System tag missing for Admin"
    print(f"  ? PASSED: System tag '{system_tag}' is visible to User A, User B, and Admin.")

    # ------------------------------------------------------------------
    # Test 6: Admin Authority (Modify / Delete Tags)
    # ------------------------------------------------------------------
    print("\n[Test 6] Verifying Admin Authority to Delete Any Tag...")
    r_adm_tag_del = requests.delete(f"{NEXTCLOUD_URL}/remote.php/dav/systemtags/{tag_id}", auth=admin_auth)
    assert r_adm_tag_del.status_code == 204, f"Admin failed to delete tag: {r_adm_tag_del.status_code}"
    print("  ? PASSED: System Admin successfully deleted User A's tag (HTTP 204).")

    # Verify tag is completely gone
    r_a_tags_post = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags", auth=user_a_auth).json()
    assert private_tag_name not in [t["name"] for t in r_a_tags_post.get("tags", [])], "Tag still exists after admin deletion!"
    print("  ? PASSED: Tag confirmed deleted from system.")

    # ------------------------------------------------------------------
    # Test 7: Admin Authority to Delete Uploaded Files & Clean-up
    # ------------------------------------------------------------------
    print("\n[Test 7] Verifying Admin Authority to Delete Uploaded File...")
    r_adm_file_del = requests.delete(url_admin, auth=admin_auth)
    assert r_adm_file_del.status_code in (200, 204), f"Admin file deletion failed: {r_adm_file_del.status_code}"
    print("  ? PASSED: System Admin successfully deleted User A's uploaded file (HTTP 204).")

    print("\n==================================================================")
    print(" ALL 7 ACL & TAG ISOLATION TESTS PASSED WITH 100% SUCCESS!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
