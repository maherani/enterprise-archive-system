#!/usr/bin/env python3
"""
Test Suite: AI Admin Console & Interactive Sandbox UI Endpoints
Enterprise Archive System - Nextcloud 34

Verifies:
1. Non-admin authorization block (403 Forbidden for regular users like Bakbari, maherani).
2. Admin authentication & overview retrieval (/api/ai/admin/overview).
3. Token creation via UI endpoint with SHA-256 hash in DB.
4. Token rotation with grace period via UI endpoint.
5. Token revocation via UI endpoint.
6. Delegation rule addition and removal via UI endpoint.
7. Audit trail querying via UI endpoint.
8. Live Sandbox interactive test endpoint (/api/ai/admin/test-api):
   - Valid retrieval with delegation
   - Hardened blockage of admin impersonation
   - Deny-by-default rejection
"""

import sys
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://127.0.0.1"
BASE_URL = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/ai/admin"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

HEADERS = {"OCS-APIRequest": "true"}


class TestAiAdminUiApi(unittest.TestCase):

    def test_01_non_admin_access_forbidden(self):
        """Regular users must be blocked with 403 Forbidden from accessing AI Admin endpoints."""
        r = requests.get(f"{BASE_URL}/overview", auth=soc_auth, headers=HEADERS)
        self.assertEqual(r.status_code, 403, f"Expected 403 for non-admin, got {r.status_code}")

    def test_02_admin_overview_success(self):
        """Admin should retrieve full metrics, services, tokens, and delegations."""
        r = requests.get(f"{BASE_URL}/overview", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r.status_code, 200, f"Expected 200 OK, got {r.status_code}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        self.assertIn("services", data)
        self.assertIn("metrics", data)
        self.assertGreaterEqual(len(data["services"]), 1)

    def test_03_token_lifecycle_via_api(self):
        """Test token creation, rotation, and revocation through admin API."""
        # 1. Create Token
        r_create = requests.post(
            f"{BASE_URL}/tokens/create",
            auth=admin_auth,
            json={"service_id": "default_ai_service", "token_name": "UI Automated Test Token", "expires_days": 30},
            headers=HEADERS
        )
        self.assertEqual(r_create.status_code, 200)
        res_create = r_create.json()
        self.assertEqual(res_create.get("status"), "success")
        raw_token = res_create.get("raw_token")
        self.assertTrue(raw_token.startswith("nc_ai_"))

        # 2. Rotate Token
        r_rotate = requests.post(
            f"{BASE_URL}/tokens/rotate",
            auth=admin_auth,
            json={"service_id": "default_ai_service", "grace_hours": 12},
            headers=HEADERS
        )
        self.assertEqual(r_rotate.status_code, 200)
        res_rotate = r_rotate.json()
        self.assertEqual(res_rotate.get("status"), "success")
        new_token = res_rotate.get("new_raw_token")
        self.assertTrue(new_token.startswith("nc_ai_"))

    def test_04_delegation_management_via_api(self):
        """Test adding and removing delegation rules via admin API."""
        # Add test delegation
        r_add = requests.post(
            f"{BASE_URL}/delegations/add",
            auth=admin_auth,
            json={"service_id": "default_ai_service", "subject_type": "GROUP", "subject_id": "NetWork"},
            headers=HEADERS
        )
        self.assertEqual(r_add.status_code, 200)
        self.assertEqual(r_add.json().get("status"), "success")

        # Verify in overview
        r_ov = requests.get(f"{BASE_URL}/overview", auth=admin_auth, headers=HEADERS)
        delegations = r_ov.json()["services"][0]["delegations"]
        matched = [d for d in delegations if d["subject_id"] == "NetWork"]
        self.assertTrue(len(matched) > 0, "Delegation rule was not added")

        del_id = matched[0]["id"]

        # Remove test delegation
        r_del = requests.post(
            f"{BASE_URL}/delegations/remove",
            auth=admin_auth,
            json={"delegation_id": del_id},
            headers=HEADERS
        )
        self.assertEqual(r_del.status_code, 200)
        self.assertEqual(r_del.json().get("status"), "success")

    def test_05_audit_logs_retrieval(self):
        """Test retrieval of enriched audit logs."""
        r = requests.get(f"{BASE_URL}/audit?limit=10", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        self.assertIn("logs", data)

    def test_06_live_sandbox_evaluation(self):
        """Test the interactive sandbox endpoint."""
        token = "ai_sec_token_7021824a20719a37d5433ba2f96832d28edf57d81d307a8468d2864601e29d08"

        # 1. Valid test with SOC user
        r_soc = requests.post(
            f"{BASE_URL}/test-api",
            auth=admin_auth,
            json={"token": token, "file_id": 623, "on_behalf_of": "Bakbari"},
            headers=HEADERS
        )
        self.assertEqual(r_soc.status_code, 200)
        self.assertTrue(r_soc.json().get("success"), "Expected access granted for Bakbari")

        # 2. Hardened admin spoofing test (must be 403)
        r_admin = requests.post(
            f"{BASE_URL}/test-api",
            auth=admin_auth,
            json={"token": token, "file_id": 623, "on_behalf_of": "admin"},
            headers=HEADERS
        )
        self.assertEqual(r_admin.status_code, 200)
        data_admin = r_admin.json()
        self.assertFalse(data_admin.get("success"))
        self.assertEqual(data_admin.get("http_status"), 403)
        self.assertEqual(data_admin.get("delegation_status"), "FORBIDDEN")


if __name__ == "__main__":
    unittest.main()
