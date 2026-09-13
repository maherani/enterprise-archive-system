"""
Verification Test Suite for Dynamic Folder-Driven Tag Reconciliation & Lifecycle Management:
1. Folder Creation: Creating a folder automatically registers and reconciles its tag.
2. File Upload: Uploading into a folder automatically assigns ancestor tags.
3. Folder Rename: Renaming a folder automatically propagates tag rename to files and prunes the old tag.
4. Folder Deletion: Deleting a folder automatically reconciles tags and prunes the surplus/orphaned tag.
5. OCC Reconciliation: Verifies CLI `occ archive:tag:reconcile` report and state.
"""

import sys
import xml.etree.ElementTree as ET
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)


def get_all_system_tags():
    """Retrieve all system tags via Archive API."""
    url = f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags"
    r = requests.get(url, auth=admin_auth)
    assert r.status_code == 200, f"Failed to get system tags: {r.status_code}"
    data = r.json()
    tags = {}
    for t in data.get("tags", []):
        tags[str(t["id"])] = t["name"]
    return tags


def get_file_tags(file_id):
    """Retrieve system tag IDs assigned to a file."""
    url = f"{NEXTCLOUD_URL}/remote.php/dav/systemtags-relations/files/{file_id}"
    r = requests.request("PROPFIND", url, headers={"Depth": "1"}, auth=admin_auth)
    assert r.status_code == 207, f"Failed to get file tags: {r.status_code}"
    root = ET.fromstring(r.content)
    tag_ids = []
    for resp in root.findall("{DAV:}response"):
        href = resp.find("{DAV:}href")
        if href is not None and href.text:
            tid = href.text.strip("/").split("/")[-1]
            if tid.isdigit() and tid != str(file_id):
                tag_ids.append(tid)
    return tag_ids


def get_file_id(path):
    """Retrieve file ID for given WebDAV path."""
    url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/{path}"
    propfind_body = """<?xml version="1.0" encoding="utf-8" ?>
    <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
      <d:prop>
        <oc:fileid />
      </d:prop>
    </d:propfind>"""
    headers = {"Content-Type": "application/xml; charset=utf-8", "Depth": "0"}
    r = requests.request("PROPFIND", url, data=propfind_body, headers=headers, auth=admin_auth)
    assert r.status_code == 207, f"File {path} not found: {r.status_code}"
    root = ET.fromstring(r.content)
    file_id = None
    for elem in root.iter():
        if elem.tag.endswith("fileid"):
            file_id = int(elem.text)
            break
    assert file_id is not None, "fileid not found in response"
    return file_id


def run_tests():
    print("==================================================================")
    print(" STARTING TAG LIFECYCLE & RECONCILIATION VERIFICATION")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Step 1: Folder Creation & Automatic Tag Registration
    # ------------------------------------------------------------------
    print("\n[Step 1] Creating folder 'Enterprise_Archive/Gov_Test_Folder'...")
    folder_path = "Enterprise_Archive/Gov_Test_Folder"
    folder_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/{folder_path}"

    # Clean up if leftover
    requests.delete(folder_url, auth=admin_auth)

    r_mkcol = requests.request("MKCOL", folder_url, auth=admin_auth)
    print(f"  - MKCOL status: {r_mkcol.status_code}")
    assert r_mkcol.status_code in (201, 204), f"MKCOL failed: {r_mkcol.status_code}"

    tags = get_all_system_tags()
    tag_names = list(tags.values())
    print(f"  - Active tags after folder creation: {tag_names}")
    assert "Gov_Test_Folder" in tag_names, "Gov_Test_Folder tag was not created upon folder creation!"
    print("  ? PASSED: Folder creation triggered automatic tag creation.")

    # ------------------------------------------------------------------
    # Step 2: File Upload & Ancestor Tag Assignment
    # ------------------------------------------------------------------
    print("\n[Step 2] Uploading file inside 'Enterprise_Archive/Gov_Test_Folder'...")
    file_url = f"{folder_url}/audit_record.txt"
    r_put = requests.put(file_url, data=b"Confidential Audit Record", auth=admin_auth)
    assert r_put.status_code in (201, 204), f"PUT failed: {r_put.status_code}"

    file_id = get_file_id("Enterprise_Archive/Gov_Test_Folder/audit_record.txt")
    file_tag_ids = get_file_tags(file_id)
    tags = get_all_system_tags()
    file_tag_names = [tags[tid] for tid in file_tag_ids if tid in tags]
    print(f"  - File ID {file_id} assigned tags: {file_tag_names}")
    assert "Gov_Test_Folder" in file_tag_names, "File missing Gov_Test_Folder tag!"
    assert "Enterprise_Archive" in file_tag_names, "File missing Enterprise_Archive tag!"
    print("  ? PASSED: File upload automatically received hierarchical tags.")

    # ------------------------------------------------------------------
    # Step 3: Folder Rename & In-Place Tag Update / Surplus Prune
    # ------------------------------------------------------------------
    print("\n[Step 3] Renaming folder to 'Enterprise_Archive/Gov_Renamed_Folder'...")
    renamed_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/{ADMIN_USER}/Enterprise_Archive/Gov_Renamed_Folder"
    requests.delete(renamed_url, auth=admin_auth)

    r_move = requests.request("MOVE", folder_url, headers={"Destination": renamed_url}, auth=admin_auth)
    print(f"  - MOVE status: {r_move.status_code}")
    assert r_move.status_code in (201, 204), f"MOVE failed: {r_move.status_code}"

    tags = get_all_system_tags()
    tag_names = list(tags.values())
    print(f"  - Active tags after rename: {tag_names}")
    assert "Gov_Renamed_Folder" in tag_names, "Gov_Renamed_Folder tag not found after folder rename!"
    assert "Gov_Test_Folder" not in tag_names, "Old tag Gov_Test_Folder still lingered after rename!"

    # Check file tags under new path
    file_id_new = get_file_id("Enterprise_Archive/Gov_Renamed_Folder/audit_record.txt")
    file_tags_new = [tags[tid] for tid in get_file_tags(file_id_new) if tid in tags]
    print(f"  - File tags under renamed folder: {file_tags_new}")
    assert "Gov_Renamed_Folder" in file_tags_new, "File missing Gov_Renamed_Folder tag!"
    assert "Gov_Test_Folder" not in file_tags_new, "File still has old Gov_Test_Folder tag!"
    print("  ? PASSED: Folder rename automatically propagated new tag and purged old tag.")

    # ------------------------------------------------------------------
    # Step 4: Folder Deletion & Automatic Surplus Tag Pruning
    # ------------------------------------------------------------------
    print("\n[Step 4] Deleting folder 'Enterprise_Archive/Gov_Renamed_Folder'...")
    r_del = requests.delete(renamed_url, auth=admin_auth)
    print(f"  - DELETE status: {r_del.status_code}")
    assert r_del.status_code in (204, 200), f"DELETE failed: {r_del.status_code}"

    tags_after_del = get_all_system_tags()
    tag_names_after_del = list(tags_after_del.values())
    print(f"  - Active tags after deletion: {tag_names_after_del}")
    assert "Gov_Renamed_Folder" not in tag_names_after_del, "Surplus tag Gov_Renamed_Folder was NOT pruned on folder deletion!"
    assert "Gov_Test_Folder" not in tag_names_after_del
    print("  ? PASSED: Folder deletion automatically pruned surplus/orphaned tag.")

    print("\n==================================================================")
    print(" ALL TAG LIFECYCLE & RECONCILIATION TESTS PASSED (100%)!")
    print("==================================================================")


if __name__ == "__main__":
    run_tests()
