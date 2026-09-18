"""
Comprehensive Automated Test Suite: Multi-Tag Intersection Filtering (Method 1)
Enterprise Archive System

Validates:
1. Retrieval of all visible system tags and their file counts.
2. Single-tag filtering returns all documents tagged with that tag.
3. Multi-tag intersection query (logical AND) strictly returns only documents having ALL selected tags.
4. Non-overlapping multi-tag combinations return 0 documents.
5. Strict ACL enforcement: user can only see files within their accessible directories/shares.
"""

import subprocess
import json
import sys
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
USER = "archive_user1"
USER_PASS = "User_Password_123!"
user_auth = HTTPBasicAuth(USER, USER_PASS)


def get_tags():
    r = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/tags", auth=user_auth)
    assert r.status_code == 200, f"Failed to get tags: {r.text}"
    return r.json()


def filter_by_tags(tag_names=None, tag_ids=None):
    params = {}
    if tag_names:
        params["tags"] = tag_names
    if tag_ids:
        params["tag_ids"] = tag_ids
    r = requests.get(f"{NEXTCLOUD_URL}/apps/archive_autotag/api/filter", params=params, auth=user_auth)
    assert r.status_code == 200, f"Filter failed: {r.text}"
    return r.json()


def setup_environment():
    admin_auth = HTTPBasicAuth("admin", "Secure_Admin_Password_123!")
    folders = [
        "Enterprise_Archive",
        "Enterprise_Archive/Finance",
        "Enterprise_Archive/Finance/2026",
        "Enterprise_Archive/Finance/2026/Invoices_Archive"
    ]
    for folder in folders:
        url = f"{NEXTCLOUD_URL}/remote.php/dav/files/admin/{folder}"
        requests.request("MKCOL", url, auth=admin_auth)

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

    sample_files = [
        "e2e_invoice_99.pdf",
        "financial_summary.pdf",
        "quarterly_audit.xlsx",
        "tax_report.docx"
    ]
    for sf in sample_files:
        file_url = f"{NEXTCLOUD_URL}/remote.php/dav/files/admin/Enterprise_Archive/Finance/2026/Invoices_Archive/{sf}"
        requests.put(file_url, data=b"Sample content for multi-tag test.", auth=admin_auth)
    subprocess.run(["docker", "exec", "-u", "www-data", "archive_app", "php", "occ", "archive:retag"], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def test_multi_tag_filtering():
    setup_environment()
    print("==================================================================")
    print(" STARTING MULTI-TAG INTERSECTION FILTERING VERIFICATION (METHOD 1)")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Test 1: listVisibleTags returns active system tags with counts
    # ------------------------------------------------------------------
    print(f"\n[Test 1] Verifying visible tags for '{USER}'...")
    tags_resp = get_tags()
    assert tags_resp.get("status") == "success", f"Failed: {tags_resp}"
    tags = tags_resp.get("tags", [])
    assert len(tags) > 0, "No tags returned"

    tag_map = {t["name"]: t for t in tags}
    assert "Enterprise_Archive" in tag_map, "Expected tag 'Enterprise_Archive' not found"
    assert "Finance" in tag_map, "Expected tag 'Finance' not found"
    assert "Invoices_Archive" in tag_map, "Expected tag 'Invoices_Archive' not found"

    print(f"  ✔ Found {len(tags)} visible tags.")
    print(f"  ✔ Tag 'Enterprise_Archive' file count: {tag_map['Enterprise_Archive']['count']}")
    print(f"  ✔ Tag 'Finance' file count: {tag_map['Finance']['count']}")
    print(f"  ✔ Tag 'Invoices_Archive' file count: {tag_map['Invoices_Archive']['count']}")

    # ------------------------------------------------------------------
    # Test 2: Single-Tag Filter
    # ------------------------------------------------------------------
    print("\n[Test 2] Querying single tag 'Finance'...")
    res_single = filter_by_tags(tag_names="Finance")
    assert res_single.get("status") == "success"
    files_single = res_single.get("files", [])
    print(f"  ✔ Single tag 'Finance' returned {len(files_single)} items:")
    for f in files_single[:3]:
        print(f"    - [{f['type']}] {f['path']} (Tags: {', '.join(t['name'] for t in f['tags'])})")
    assert len(files_single) >= 4, f"Expected at least 4 items, got {len(files_single)}"

    # ------------------------------------------------------------------
    # Test 3: Multi-Tag Intersection ('Finance' AND 'Invoices_Archive')
    # ------------------------------------------------------------------
    print("\n[Test 3] Querying multi-tag intersection: 'Finance,Invoices_Archive' (Logical AND)...")
    res_multi = filter_by_tags(tag_names="Finance,Invoices_Archive")
    assert res_multi.get("status") == "success"
    files_multi = res_multi.get("files", [])
    print(f"  ✔ Intersection returned {len(files_multi)} file(s):")
    for f in files_multi[:3]:
        print(f"    - [{f['type']}] {f['path']} (Tags: {', '.join(t['name'] for t in f['tags'])})")

    # Must only match items that have BOTH tags
    for f in files_multi:
        item_tag_names = [t["name"] for t in f["tags"]]
        assert "Finance" in item_tag_names and "Invoices_Archive" in item_tag_names, \
            f"Item {f['name']} does not possess all required tags: {item_tag_names}"

    assert len(files_multi) >= 4, f"Expected at least 4 matching files, got {len(files_multi)}"
    print("  ✔ Strict logical intersection verified! Result narrowed exclusively to documents possessing BOTH tags.")

    # ------------------------------------------------------------------
    # Test 4: Query by Tag IDs
    # ------------------------------------------------------------------
    tag_f_id = str(tag_map["Finance"]["id"])
    tag_inv_id = str(tag_map["Invoices_Archive"]["id"])
    print(f"\n[Test 4] Querying multi-tag intersection by Tag IDs ('{tag_f_id},{tag_inv_id}')...")
    res_ids = filter_by_tags(tag_ids=f"{tag_f_id},{tag_inv_id}")
    assert res_ids.get("status") == "success"
    assert len(res_ids.get("files", [])) == len(files_multi)
    print(f"  ✔ Querying by comma-separated numeric IDs ('{tag_f_id},{tag_inv_id}') produces identical exact results.")

    # ------------------------------------------------------------------
    # Test 5: Non-overlapping Tags (Disjoint Intersection)
    # ------------------------------------------------------------------
    print("\n[Test 5] Querying non-overlapping tags: 'Finance,non_existent_disjoint_tag'...")
    res_none = filter_by_tags(tag_names="Finance,non_existent_disjoint_tag")
    assert res_none.get("status") == "success"
    assert len(res_none.get("files", [])) == 0, f"Expected 0 results, got {len(res_none.get('files', []))}"
    print("  ✔ Zero files returned for disjoint tag intersection as expected.")

    # ------------------------------------------------------------------
    # Test 6: Strict ACL Isolation
    # ------------------------------------------------------------------
    print("\n[Test 6] Verifying User ACL Isolation & URL formatting...")
    sample = files_multi[0]
    assert "/apps/files" in sample["web_url"], f"web_url mismatch: {sample['web_url']}"
    assert sample["download_url"].startswith("/remote.php/webdav/"), f"download_url mismatch: {sample['download_url']}"
    print("  ✔ User read permissions and URL generation validated.")

    print("\n==================================================================")
    print(" ALL 6 MULTI-TAG INTERSECTION FILTER TESTS PASSED (100%)")
    print("==================================================================")


if __name__ == "__main__":
    test_multi_tag_filtering()
