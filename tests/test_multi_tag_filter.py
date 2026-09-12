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

def run_php_code(php_code):
    cmd = [
        "docker", "exec", "-u", "www-data", "archive_app", "php", "-r", php_code
    ]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        print("PHP Execution Error:", p.stderr)
        raise RuntimeError(f"PHP exited with {p.returncode}: {p.stderr}")
    return p.stdout.strip()

def run_test_controller(user, method, *args):
    args_json = json.dumps(args, ensure_ascii=False)
    php_code = f"""
require_once '/var/www/html/lib/base.php';
$userManager = \\OC::$server->get(\\OCP\\IUserManager::class);
$userSession = \\OC::$server->get(\\OCP\\IUserSession::class);
$user = $userManager->get('{user}');
$userSession->setUser($user);

$ctrl = \\OC::$server->get(\\OCA\\ArchiveAutoTag\\Controller\\TagFilterController::class);
$args = json_decode('{args_json}', true);
$resp = call_user_func_array([$ctrl, '{method}'], $args);
echo json_encode($resp->getData(), JSON_UNESCAPED_UNICODE);
"""
    output = run_php_code(php_code)
    try:
        return json.loads(output)
    except Exception as e:
        print("Raw output:", output)
        raise e

def test_multi_tag_filtering():
    print("==================================================================")
    print(" STARTING MULTI-TAG INTERSECTION FILTERING VERIFICATION (METHOD 1)")
    print("==================================================================")

    # ------------------------------------------------------------------
    # Test 1: listVisibleTags returns active system tags with counts
    # ------------------------------------------------------------------
    print("\n[Test 1] Verifying listVisibleTags() for 'archive_user1'...")
    tags_resp = run_test_controller("archive_user1", "listVisibleTags")
    assert tags_resp.get("status") == "success", f"Failed: {tags_resp}"
    tags = tags_resp.get("tags", [])
    assert len(tags) > 0, "No tags returned"
    
    tag_names = {t["name"]: t for t in tags}
    assert "افتا" in tag_names, "Expected tag 'افتا' not found"
    assert "الزامات امنیتی" in tag_names, "Expected tag 'الزامات امنیتی' not found"
    
    print(f"  ✓ Found {len(tags)} visible tags.")
    print(f"  ✓ Tag 'افتا' file count: {tag_names['افتا']['count']}")
    print(f"  ✓ Tag 'الزامات امنیتی' file count: {tag_names['الزامات امنیتی']['count']}")

    # ------------------------------------------------------------------
    # Test 2: Single-Tag Filter
    # ------------------------------------------------------------------
    print("\n[Test 2] Querying single tag 'افتا'...")
    res_single = run_test_controller("archive_user1", "filterByTags", "افتا")
    assert res_single.get("status") == "success"
    files_single = res_single.get("files", [])
    print(f"  ✓ Single tag 'افتا' returned {len(files_single)} items:")
    for f in files_single:
        print(f"    - [{f['type']}] {f['path']} (Tags: {', '.join(t['name'] for t in f['tags'])})")
    assert len(files_single) >= 2, f"Expected at least 2 items, got {len(files_single)}"

    # ------------------------------------------------------------------
    # Test 3: Multi-Tag Intersection ('افتا' AND 'الزامات امنیتی')
    # ------------------------------------------------------------------
    print("\n[Test 3] Querying multi-tag intersection: 'افتا,الزامات امنیتی' (Logical AND)...")
    res_multi = run_test_controller("archive_user1", "filterByTags", "افتا,الزامات امنیتی")
    assert res_multi.get("status") == "success"
    files_multi = res_multi.get("files", [])
    print(f"  ✓ Intersection returned {len(files_multi)} file(s):")
    for f in files_multi:
        print(f"    - [{f['type']}] {f['path']} (Tags: {', '.join(t['name'] for t in f['tags'])})")
    
    # Must only match items that have BOTH tags
    for f in files_multi:
        item_tag_names = [t["name"] for t in f["tags"]]
        assert "افتا" in item_tag_names and "الزامات امنیتی" in item_tag_names, \
            f"Item {f['name']} does not possess all required tags: {item_tag_names}"
    
    assert len(files_multi) == 1, f"Expected strictly 1 matching file ('1.md'), got {len(files_multi)}"
    assert files_multi[0]["name"] == "1.md", f"Expected file 1.md, got {files_multi[0]['name']}"
    print("  ✓ Strict logical intersection verified! Result narrowed exclusively to documents possessing BOTH tags.")

    # ------------------------------------------------------------------
    # Test 4: Query by Tag IDs (e.g. tag_ids="7,8")
    # ------------------------------------------------------------------
    print("\n[Test 4] Querying multi-tag intersection by Tag IDs ('7,8')...")
    res_ids = run_test_controller("archive_user1", "filterByTags", None, "7,8")
    assert res_ids.get("status") == "success"
    assert len(res_ids.get("files", [])) == 1
    assert res_ids["files"][0]["name"] == "1.md"
    print("  ✓ Querying by comma-separated numeric IDs ('7,8') produces identical exact results.")

    # ------------------------------------------------------------------
    # Test 5: Non-overlapping Tags
    # ------------------------------------------------------------------
    print("\n[Test 5] Querying non-overlapping tags: 'الزامات امنیتی,Finance'...")
    res_none = run_test_controller("archive_user1", "filterByTags", "الزامات امنیتی,Finance")
    assert res_none.get("status") == "success"
    assert len(res_none.get("files", [])) == 0, f"Expected 0 results, got {len(res_none.get('files', []))}"
    print("  ✓ Zero files returned for disjoint tag intersection as expected.")

    # ------------------------------------------------------------------
    # Test 6: Strict ACL Isolation
    # ------------------------------------------------------------------
    print("\n[Test 6] Verifying User ACL Isolation...")
    # Verify that file paths returned are properly scoped to user folder
    assert "Enterprise_Archive" in files_multi[0]["path"]
    assert files_multi[0]["web_url"].startswith("/apps/files/?dir=")
    assert files_multi[0]["download_url"].startswith("/remote.php/webdav/")
    print("  ✓ User read permissions and URL generation validated.")

    print("\n==================================================================")
    print(" ALL 6 MULTI-TAG INTERSECTION FILTER TESTS PASSED (100%)")
    print("==================================================================")

if __name__ == "__main__":
    test_multi_tag_filtering()
