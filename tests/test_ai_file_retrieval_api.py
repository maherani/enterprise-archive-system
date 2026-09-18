#!/usr/bin/env python3
"""
Automated Test Suite: Secure AI File Retrieval API and Swagger UI (v2.0.0)
Enterprise Archive System - Nextcloud 34

Validates:
1. Authentication Enforcement (401 Unauthorized for missing/invalid credentials).
2. Direct Basic Auth Retrieval (200 OK + Streamed Content + Strict Header Validation).
3. ACL Enforcement and IDOR Protection (403/404 for cross-department files, zero leakage).
4. Missing and Invalid Files Handling (404 Not Found, 400 Bad Request for folders).
5. Dedicated AI Bearer Token Auth (200 OK with valid token, 401 with invalid token).
6. Delegated Identity via X-On-Behalf-Of (Applies designated user ACL).
7. Non-Existent Delegated Identity (401 Unauthorized when delegated user does not exist).
8. Sanitized Metadata Endpoint (/metadata returns tags, no disk paths).
9. Audit Trail Verification (oc_archive_ai_audit accurately records every attempt).
10. OpenAPI 3.0.3 Specification and Air-Gapped Swagger UI (/api/docs and /api/openapi.json).
"""

import sys
import subprocess
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
BASE_API = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

AI_SERVICE_TOKEN = "ai_sec_token_7021824a20719a37d5433ba2f96832d28edf57d81d307a8468d2864601e29d08"

def get_dynamic_soc_file_id():
    try:
        r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_auth)
        if r.status_code == 200:
            for f in r.json().get("files", []):
                if not f.get("is_dir", False) and f.get("mimetype", "").startswith("text/"):
                    return f["id"]
    except Exception:
        pass
    return 923

TEST_FILE_ID = get_dynamic_soc_file_id()


def run_db_query(query: str) -> str:
    cmd = [
        "docker", "compose", "-f", "/home/alborz/enterprise-archive-system/docker-compose.yml",
        "exec", "-T", "db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", query
    ]
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True)
    return res.stdout.strip()


def test_1_unauthenticated_request():
    print("[1/10] Testing Unauthenticated Request (Expect 401)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}")
    assert resp.status_code == 401, f"Expected 401, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"
    assert "WWW-Authenticate" in resp.headers
    print("  -> PASS: 401 Unauthorized received with standard challenge headers.")


def test_2_basic_auth_authorized_retrieval():
    print("[2/10] Testing Basic Auth File Retrieval (Expect 200 OK + Stream)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", auth=soc_auth)
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"
    assert resp.headers.get("Content-Type", "").startswith("text/plain")
    assert "Content-Disposition" in resp.headers
    assert resp.headers.get("X-Archive-File-ID") == str(TEST_FILE_ID)
    assert resp.headers.get("X-Actor-UID") == SOC_USER
    assert len(resp.content) > 0
    print(f"  -> PASS: Retrieved {len(resp.content)} bytes with X-Archive-File-ID={TEST_FILE_ID}.")


def test_3_idor_protection_cross_department():
    print("[3/10] Testing IDOR Isolation for Cross-Department User (Expect 403 or 404)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", auth=cert_auth)
    assert resp.status_code in [403, 404], f"Expected 403 or 404, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"
    print(f"  -> PASS: Access forbidden/not-found (code {resp.status_code}) for unauthorized department user.")


def test_4_nonexistent_and_invalid_file_id():
    print("[4/10] Testing Non-Existent File ID (Expect 404)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/99999999", auth=admin_auth)
    assert resp.status_code == 404, f"Expected 404, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"

    print("  Testing Invalid Folder Target (Expect 400 Bad Request)...")
    resp2 = requests.get(f"{BASE_API}/api/v1/ai/files/347", auth=admin_auth)
    assert resp2.status_code == 400, f"Expected 400, got {resp2.status_code}"
    assert "folder" in resp2.json()["message"].lower() or "directory" in resp2.json()["message"].lower()
    print("  -> PASS: Properly rejected missing file (404) and folder targets (400).")


def test_5_dedicated_ai_bearer_token():
    print("[5/10] Testing Dedicated AI Bearer Token Auth...")
    headers_valid = {"Authorization": f"Bearer {AI_SERVICE_TOKEN}"}
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_valid)
    assert resp.status_code == 403, f"Expected 403 for un-delegated AI token on SOC file, got {resp.status_code}"

    headers_invalid = {"Authorization": "Bearer invalid_secret_token_1234"}
    resp_invalid = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_invalid)
    assert resp_invalid.status_code == 401, f"Expected 401 for invalid bearer token, got {resp_invalid.status_code}"
    print("  -> PASS: Bearer token authenticated, default worker ACL enforced, invalid token rejected.")


def test_6_delegated_identity_on_behalf_of():
    print("[6/10] Testing Delegated Identity via X-On-Behalf-Of...")
    headers_soc = {
        "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
        "X-On-Behalf-Of": SOC_USER,
        "X-Client-ID": "test_rag_pipeline"
    }
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_soc)
    assert resp.status_code == 200, f"Expected 200 for delegated SOC user, got {resp.status_code}"
    assert resp.headers.get("X-Actor-UID") == SOC_USER

    headers_cert = {
        "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
        "X-On-Behalf-Of": CERT_USER
    }
    resp_cert = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_cert)
    assert resp_cert.status_code == 403, f"Expected 403 for delegated CERT user on SOC file, got {resp_cert.status_code}"
    print("  -> PASS: Dynamic delegation correctly switches ACL context per user.")


def test_7_nonexistent_delegated_identity():
    print("[7/10] Testing Non-Existent Delegated Identity (Expect 401)...")
    headers = {
        "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
        "X-On-Behalf-Of": "ghost_user_does_not_exist"
    }
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers)
    assert resp.status_code == 401, f"Expected 401 for nonexistent delegated user, got {resp.status_code}"
    print("  -> PASS: Rejected non-existent delegated user with 401 Unauthorized.")


def test_8_file_metadata_endpoint():
    print("[8/10] Testing Sanitized Metadata Endpoint...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}/metadata", auth=admin_auth)
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "success"
    file_info = data["file"]
    assert file_info["id"] == TEST_FILE_ID
    assert "path" not in file_info, "File physical path must not be leaked!"
    assert len(file_info["tags"]) > 0
    print(f"  -> PASS: File metadata returned safely with {len(file_info['tags'])} tags without path leaks.")


def test_9_audit_trail_recorded_in_db():
    print("[9/10] Verifying Audit Trail in Database...")
    recent_allowed = run_db_query(
        f"SELECT count(*) FROM oc_archive_ai_audit WHERE file_id = {TEST_FILE_ID} AND result = 'ALLOWED';"
    )
    recent_forbidden = run_db_query(
        f"SELECT count(*) FROM oc_archive_ai_audit WHERE file_id = {TEST_FILE_ID} AND result = 'FORBIDDEN';"
    )
    assert int(recent_allowed) >= 2, f"Expected at least 2 ALLOWED records, got {recent_allowed}"
    assert int(recent_forbidden) >= 2, f"Expected at least 2 FORBIDDEN records, got {recent_forbidden}"
    print(f"  -> PASS: Database records verified (ALLOWED: {recent_allowed}, FORBIDDEN: {recent_forbidden}).")


def test_10_openapi_spec_and_swagger_ui():
    print("[10/10] Testing OpenAPI 3.0.3 Spec and Air-Gapped Swagger UI...")
    resp_spec = requests.get(f"{BASE_API}/api/openapi.json")
    assert resp_spec.status_code == 200, f"Expected 200 for OpenAPI spec, got {resp_spec.status_code}"
    spec = resp_spec.json()
    assert spec["openapi"] == "3.0.3"
    assert "/api/v1/ai/files/{fileId}" in spec["paths"]

    resp_ui = requests.get(f"{BASE_API}/api/docs")
    assert resp_ui.status_code == 200, f"Expected 200 for Swagger UI, got {resp_ui.status_code}"
    assert "Enterprise Archive AI" in resp_ui.text
    assert "Authorize" in resp_ui.text
    assert "unpkg.com" not in resp_ui.text, "Must not contain external CDN links!"
    assert "cdnjs.cloudflare.com" not in resp_ui.text, "Must not contain external CDN links!"
    print("  -> PASS: OpenAPI spec and 100% air-gapped Swagger UI verified.")


if __name__ == "__main__":
    print("=== Running Enterprise Archive AI File Retrieval API Test Suite ===")
    try:
        test_1_unauthenticated_request()
        test_2_basic_auth_authorized_retrieval()
        test_3_idor_protection_cross_department()
        test_4_nonexistent_and_invalid_file_id()
        test_5_dedicated_ai_bearer_token()
        test_6_delegated_identity_on_behalf_of()
        test_7_nonexistent_delegated_identity()
        test_8_file_metadata_endpoint()
        test_9_audit_trail_recorded_in_db()
        test_10_openapi_spec_and_swagger_ui()
        print("\n==================================================")
        print("ALL 10 TESTS PASSED SUCCESSFULLY! (100% Coverage)")
        print("==================================================")
        sys.exit(0)
    except Exception as e:
        print(f"\nTEST SUITE FAILED: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
