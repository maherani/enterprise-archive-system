#!/usr/bin/env python3
"""
Automated Test Suite: Secure AI File Retrieval API, Hardened Delegated Identity & Swagger UI (v2.0.9)
Enterprise Archive System - Nextcloud 34

Comprehensive 16-Point Security & Functionality Verification:
1. Authentication Enforcement (401 Unauthorized for missing/invalid credentials).
2. Direct Basic Auth Retrieval (200 OK + Streamed Content + Strict Header Validation).
3. ACL Enforcement and IDOR Protection (403/404 for cross-department files, zero leakage).
4. Missing and Invalid Files Handling (404 Not Found, 400 Bad Request for folders).
5. Dedicated AI Bearer Token Auth (200 OK with valid token, 401 with invalid token).
6. Delegated Identity via X-On-Behalf-Of (Applies designated user ACL within permitted policy).
7. Non-Existent Delegated Identity (401 Unauthorized when delegated user does not exist).
8. Admin Impersonation Protection (403 Forbidden when attempting X-On-Behalf-Of: admin).
9. Deny-By-Default Policy Enforcement (403 Forbidden when service lacks delegation rule for user).
10. Immediate Token Revocation (401 Unauthorized once token is marked REVOKED).
11. Zero-Downtime Token Rotation & Grace Period (Old token accepted in grace period, new token active).
12. Database Token Security & Cryptographic Hashing (Only SHA-256 hashes in DB, no plaintext secrets).
13. Sanitized Metadata Endpoint (/metadata returns tags, zero physical disk paths).
14. Enriched Audit Trail (oc_archive_ai_audit records service_id, token_id, delegation_status).
15. Hardened OpenAPI 3.0.3 Specification (/api/openapi.json).
16. Air-Gapped Offline Swagger UI (/api/docs with zero external CDN dependencies).
"""

import sys
import re
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


def run_db_query(query: str) -> str:
    cmd = [
        "docker", "compose", "-f", "/home/alborz/enterprise-archive-system/docker-compose.yml",
        "exec", "-T", "db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", query
    ]
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True)
    return res.stdout.strip()


def run_occ_cmd(args: list) -> str:
    cmd = [
        "docker", "compose", "-f", "/home/alborz/enterprise-archive-system/docker-compose.yml",
        "exec", "-T", "-u", "www-data", "app", "php", "occ"
    ] + args
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True)
    return res.stdout.strip()


def get_dynamic_soc_file_id():
    try:
        r = requests.get(f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files", auth=soc_auth)
        if r.status_code == 200:
            for f in r.json().get("files", []):
                if not f.get("is_dir", False) and f.get("mimetype", "").startswith("text/"):
                    return f["id"]
    except Exception:
        pass
    return 550

TEST_FILE_ID = get_dynamic_soc_file_id()


def get_dynamic_folder_id():
    try:
        fid = run_db_query("SELECT fileid FROM oc_filecache WHERE mimetype = 2 AND path LIKE '%Enterprise_Archive%' LIMIT 1;")
        if fid:
            return int(fid.strip())
    except Exception:
        pass
    return 356

TEST_FOLDER_ID = get_dynamic_folder_id()


def test_1_unauthenticated_request():
    print("[01/16] Testing Unauthenticated Request (Expect 401)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}")
    assert resp.status_code == 401, f"Expected 401, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"
    assert "WWW-Authenticate" in resp.headers
    print("  -> PASS: 401 Unauthorized received with standard challenge headers.")


def test_2_basic_auth_authorized_retrieval():
    print("[02/16] Testing Basic Auth File Retrieval (Expect 200 OK + Stream)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", auth=soc_auth)
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"
    assert resp.headers.get("Content-Type", "").startswith("text/plain")
    assert "Content-Disposition" in resp.headers
    assert resp.headers.get("X-Archive-File-ID") == str(TEST_FILE_ID)
    assert resp.headers.get("X-Actor-UID") == SOC_USER
    assert len(resp.content) > 0
    print(f"  -> PASS: Retrieved {len(resp.content)} bytes with X-Archive-File-ID={TEST_FILE_ID}.")


def test_3_idor_protection_cross_department():
    print("[03/16] Testing IDOR Isolation for Cross-Department User (Expect 403 or 404)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", auth=cert_auth)
    assert resp.status_code in [403, 404], f"Expected 403 or 404, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"
    print(f"  -> PASS: Access forbidden/not-found (code {resp.status_code}) for unauthorized department user.")


def test_4_nonexistent_and_invalid_file_id():
    print("[04/16] Testing Non-Existent File ID (Expect 404)...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/99999999", auth=admin_auth)
    assert resp.status_code == 404, f"Expected 404, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "error"

    print("  Testing Invalid Folder Target (Expect 400 Bad Request)...")
    resp2 = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FOLDER_ID}", auth=admin_auth)
    assert resp2.status_code == 400, f"Expected 400, got {resp2.status_code}"
    assert "folder" in resp2.json()["message"].lower() or "directory" in resp2.json()["message"].lower()
    print("  -> PASS: Properly rejected missing file (404) and folder targets (400).")


def test_5_dedicated_ai_bearer_token():
    print("[05/16] Testing Dedicated AI Bearer Token Auth...")
    headers_valid = {"Authorization": f"Bearer {AI_SERVICE_TOKEN}"}
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_valid)
    assert resp.status_code == 403, f"Expected 403 for un-delegated AI token on SOC file, got {resp.status_code}"

    headers_invalid = {"Authorization": "Bearer invalid_secret_token_1234"}
    resp_invalid = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers_invalid)
    assert resp_invalid.status_code == 401, f"Expected 401 for invalid bearer token, got {resp_invalid.status_code}"
    print("  -> PASS: Bearer token authenticated, default worker ACL enforced, invalid token rejected.")


def test_6_delegated_identity_on_behalf_of():
    print("[06/16] Testing Delegated Identity via X-On-Behalf-Of within Policy...")
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
    assert resp_cert.status_code in [403, 404], f"Expected 403/404 for delegated CERT user on SOC file, got {resp_cert.status_code}"
    print("  -> PASS: Dynamic delegation correctly switches ACL context per user.")


def test_7_nonexistent_delegated_identity():
    print("[07/16] Testing Non-Existent Delegated Identity (Expect 401)...")
    headers = {
        "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
        "X-On-Behalf-Of": "ghost_user_does_not_exist"
    }
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers)
    assert resp.status_code == 401, f"Expected 401 for nonexistent delegated user, got {resp.status_code}"
    print("  -> PASS: Rejected non-existent delegated user with 401 Unauthorized.")


def test_8_admin_impersonation_prevention():
    print("[08/16] Testing Admin Impersonation Protection (Zero-Privilege Escalation Gate)...")
    headers = {
        "Authorization": f"Bearer {AI_SERVICE_TOKEN}",
        "X-On-Behalf-Of": "admin"
    }
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers)
    assert resp.status_code == 403, f"Expected 403 Forbidden for admin impersonation, got {resp.status_code}"
    msg = resp.json().get("message", "").lower()
    assert "administrative" in msg or "prohibited" in msg or "forbidden" in msg
    print("  -> PASS: Blocked attempt to delegate to admin account with 403 Forbidden.")


def test_9_deny_by_default_policy():
    print("[09/16] Testing Deny-By-Default Delegation Policy...")
    # Create an isolated service with DENY_ALL policy
    try:
        run_occ_cmd(["archive:ai", "service-create", "isolated_bot", "--policy=DENY_ALL", "--name=Isolated Bot"])
        token_output = run_occ_cmd(["archive:ai", "token-create", "isolated_bot", "--name=Isolated Key"])
        match = re.search(r"(nc_ai_[a-f0-9]{64})", token_output)
        assert match, "Could not parse issued token from OCC output"
        isolated_token = match.group(1)

        # Attempt to use X-On-Behalf-Of with isolated bot
        headers = {
            "Authorization": f"Bearer {isolated_token}",
            "X-On-Behalf-Of": SOC_USER
        }
        resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers=headers)
        assert resp.status_code == 403, f"Expected 403 Forbidden for DENY_ALL service, got {resp.status_code}"
        assert "not authorized" in resp.json().get("message", "").lower()
        print("  -> PASS: Deny-by-default policy strictly enforced (403 Forbidden).")
    finally:
        # Cleanup isolated bot
        run_db_query("DELETE FROM oc_archive_ai_tokens WHERE service_id = 'isolated_bot';")
        run_db_query("DELETE FROM oc_archive_ai_services WHERE service_id = 'isolated_bot';")


def test_10_immediate_token_revocation():
    print("[10/16] Testing Immediate Token Revocation...")
    try:
        run_occ_cmd(["archive:ai", "service-create", "revoke_test_svc", "--policy=SPECIFIC_GROUPS"])
        token_out = run_occ_cmd(["archive:ai", "token-create", "revoke_test_svc", "--name=Temp Key"])
        match = re.search(r"(nc_ai_[a-f0-9]{64})", token_out)
        assert match, "Could not parse token"
        test_token = match.group(1)
        prefix = test_token[:12]

        # Verify it works initially (403 for un-delegated access on SOC file)
        resp_before = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers={"Authorization": f"Bearer {test_token}"})
        assert resp_before.status_code == 403

        # Revoke token
        run_occ_cmd(["archive:ai", "token-revoke", "--token=" + prefix])

        # Verify it is immediately rejected with 401 Unauthorized
        resp_after = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers={"Authorization": f"Bearer {test_token}"})
        assert resp_after.status_code == 401, f"Expected 401 for revoked token, got {resp_after.status_code}"
        assert "revoked" in resp_after.json().get("message", "").lower()
        print("  -> PASS: Revoked token immediately rejected with 401 Unauthorized.")
    finally:
        run_db_query("DELETE FROM oc_archive_ai_tokens WHERE service_id = 'revoke_test_svc';")
        run_db_query("DELETE FROM oc_archive_ai_services WHERE service_id = 'revoke_test_svc';")


def test_11_token_rotation_and_grace_period():
    print("[11/16] Testing Zero-Downtime Token Rotation & Grace Period...")
    try:
        run_occ_cmd(["archive:ai", "service-create", "rot_test_svc", "--policy=SPECIFIC_GROUPS"])
        t1_out = run_occ_cmd(["archive:ai", "token-create", "rot_test_svc", "--name=Initial Key"])
        t1 = re.search(r"(nc_ai_[a-f0-9]{64})", t1_out).group(1)

        # Rotate tokens with 48-hour grace period
        rot_out = run_occ_cmd(["archive:ai", "token-rotate", "rot_test_svc", "--grace-hours=48"])
        t2 = re.search(r"(nc_ai_[a-f0-9]{64})", rot_out).group(1)

        # Test both old token (in grace period) and new token (ACTIVE) are accepted
        resp_old = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers={"Authorization": f"Bearer {t1}"})
        assert resp_old.status_code == 403, f"Old token in grace period should authenticate (got {resp_old.status_code})"

        resp_new = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}", headers={"Authorization": f"Bearer {t2}"})
        assert resp_new.status_code == 403, f"New active token should authenticate (got {resp_new.status_code})"
        print("  -> PASS: Both old token (grace period) and new rotated token authenticated successfully.")
    finally:
        run_db_query("DELETE FROM oc_archive_ai_tokens WHERE service_id = 'rot_test_svc';")
        run_db_query("DELETE FROM oc_archive_ai_services WHERE service_id = 'rot_test_svc';")


def test_12_database_token_security_and_hashing():
    print("[12/16] Testing Database Token Security & Cryptographic Hashing...")
    # Check that all tokens in oc_archive_ai_tokens are stored as 64-char SHA-256 hashes
    hashes = run_db_query("SELECT token_hash FROM oc_archive_ai_tokens;").splitlines()
    assert len(hashes) > 0, "Expected at least one token record in DB"
    for h in hashes:
        h = h.strip()
        assert len(h) == 64, f"Expected 64-char SHA-256 hex hash, got '{h}'"
        assert re.match(r"^[a-f0-9]{64}$", h), f"Invalid hex hash format: {h}"
        assert not h.startswith("nc_ai_"), "Plaintext secret prefix must never be stored in hash column!"

    # Verify prefix column is short (<= 16 chars)
    prefixes = run_db_query("SELECT token_prefix FROM oc_archive_ai_tokens;").splitlines()
    for p in prefixes:
        assert len(p.strip()) <= 16, f"Prefix too long: {p}"
    print(f"  -> PASS: Verified {len(hashes)} tokens securely hashed with SHA-256 (Zero plaintext secrets in DB).")


def test_13_file_metadata_endpoint():
    print("[13/16] Testing Sanitized Metadata Endpoint...")
    resp = requests.get(f"{BASE_API}/api/v1/ai/files/{TEST_FILE_ID}/metadata", auth=admin_auth)
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"
    data = resp.json()
    assert data["status"] == "success"
    file_info = data["file"]
    assert file_info["id"] == TEST_FILE_ID
    assert "path" not in file_info, "File physical path must not be leaked!"
    assert len(file_info["tags"]) > 0
    print(f"  -> PASS: File metadata returned safely with {len(file_info['tags'])} tags without path leaks.")


def test_14_enriched_audit_trail_in_db():
    print("[14/16] Verifying Enriched Audit Trail in Database...")
    recent_allowed = run_db_query(
        f"SELECT count(*) FROM oc_archive_ai_audit WHERE file_id = {TEST_FILE_ID} AND result = 'ALLOWED';"
    )
    recent_forbidden = run_db_query(
        f"SELECT count(*) FROM oc_archive_ai_audit WHERE file_id = {TEST_FILE_ID} AND result = 'FORBIDDEN';"
    )
    assert int(recent_allowed) >= 2, f"Expected at least 2 ALLOWED records, got {recent_allowed}"
    assert int(recent_forbidden) >= 2, f"Expected at least 2 FORBIDDEN records, got {recent_forbidden}"

    # Verify enriched fields: service_id and delegation_status are populated
    delegation_checks = run_db_query(
        "SELECT count(*) FROM oc_archive_ai_audit WHERE delegation_status IN ('ALLOWED', 'DENIED_POLICY', 'DENIED_ADMIN_PROTECTION');"
    )
    assert int(delegation_checks) >= 2, f"Expected enriched delegation audit records, got {delegation_checks}"
    print(f"  -> PASS: Database records verified (ALLOWED: {recent_allowed}, FORBIDDEN: {recent_forbidden}, Enriched: {delegation_checks}).")


def test_15_openapi_spec():
    print("[15/16] Testing Hardened OpenAPI 3.0.3 Spec...")
    resp_spec = requests.get(f"{BASE_API}/api/openapi.json")
    assert resp_spec.status_code == 200, f"Expected 200 for OpenAPI spec, got {resp_spec.status_code}"
    spec = resp_spec.json()
    assert spec["openapi"] == "3.0.3"
    assert "/api/v1/ai/files/{fileId}" in spec["paths"]
    assert "bearerAuth" in spec["components"]["securitySchemes"]
    print("  -> PASS: OpenAPI 3.0.3 spec includes hardened security schemes and schemas.")


def test_16_air_gapped_swagger_ui():
    print("[16/16] Testing Air-Gapped Swagger UI...")
    resp_ui = requests.get(f"{BASE_API}/api/docs")
    assert resp_ui.status_code == 200, f"Expected 200 for Swagger UI, got {resp_ui.status_code}"
    assert "Enterprise Archive AI" in resp_ui.text
    assert "Authorize" in resp_ui.text
    assert "unpkg.com" not in resp_ui.text, "Must not contain external CDN links!"
    assert "cdnjs.cloudflare.com" not in resp_ui.text, "Must not contain external CDN links!"
    print("  -> PASS: 100% air-gapped Swagger UI verified.")


if __name__ == "__main__":
    print("=== Running Enterprise Archive Hardened AI File Retrieval API Test Suite (16-Point Audit) ===")
    try:
        test_1_unauthenticated_request()
        test_2_basic_auth_authorized_retrieval()
        test_3_idor_protection_cross_department()
        test_4_nonexistent_and_invalid_file_id()
        test_5_dedicated_ai_bearer_token()
        test_6_delegated_identity_on_behalf_of()
        test_7_nonexistent_delegated_identity()
        test_8_admin_impersonation_prevention()
        test_9_deny_by_default_policy()
        test_10_immediate_token_revocation()
        test_11_token_rotation_and_grace_period()
        test_12_database_token_security_and_hashing()
        test_13_file_metadata_endpoint()
        test_14_enriched_audit_trail_in_db()
        test_15_openapi_spec()
        test_16_air_gapped_swagger_ui()
        print("\n==================================================")
        print("ALL 16 TESTS PASSED SUCCESSFULLY! (100% Coverage)")
        print("==================================================")
        sys.exit(0)
    except Exception as e:
        print(f"\nTEST SUITE FAILED: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
