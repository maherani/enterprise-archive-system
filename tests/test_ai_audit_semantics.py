#!/usr/bin/env python3
"""
Enterprise Archive System - AI Audit Semantic Model & Lifecycle Test Suite (Prompt 07 / Requirement 22)

Validates:
1. Precise byte accounting and semantic lifecycle for complete transfers (bytes_served == bytes_requested, COMPLETED, FINISHED).
2. Accurate detection and byte recording of premature client disconnections (ABORTED, TERMINATED, bytes_served < bytes_requested).
3. Storage read error during streaming (FAILED, TERMINATED).
4. Stream open failure / file vanished between auth and streaming (STREAM_FAILED, FAILED).
5. Unauthenticated request lifecycle stage (UNAUTHORIZED, AUTH, bytes=0).
6. Forbidden / cross-department access rejection stage (FORBIDDEN, ACCESS_CHECK, bytes=0).
7. Missing resource rejection stage (NOT_FOUND, ACCESS_CHECK, bytes=0).
8. End-to-end correlation ID, client ID, actor UID, and client IP durability.
"""

import subprocess
import time
import unittest
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://127.0.0.1"
API_BASE = f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

USER_COMP = "archive_user1"
USER_COMP_PASS = "User_Password_123!"
comp_auth = HTTPBasicAuth(USER_COMP, USER_COMP_PASS)

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
}

TEST_FILE_ID = 701


def run_psql(query: str) -> str:
    cmd = [
        "docker", "exec", "archive_db",
        "psql", "-U", "oc_admin", "-d", "nextcloud", "-t", "-A", "-c", query
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, check=True)
    return res.stdout.strip()


class TestAiAuditSemantics(unittest.TestCase):

    def test_01_full_successful_retrieval_semantic_audit(self):
        """1. Complete transfer records COMPLETED, FINISHED, and bytes_served == bytes_requested."""
        req_id = f"test_full_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
            auth=admin_auth,
            headers={**HEADERS, "X-Request-ID": req_id, "X-Client-ID": "rag_pipeline_test"}
        )
        self.assertEqual(r.status_code, 200, f"Expected 200, got {r.status_code}")
        received_bytes = len(r.content)
        self.assertGreater(received_bytes, 0)

        # Verify database record
        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage, client_id FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage, client_id = parts

        self.assertEqual(result, "ALLOWED")
        self.assertEqual(int(bytes_req), received_bytes)
        self.assertEqual(int(bytes_srv), received_bytes)
        self.assertEqual(transfer_status, "COMPLETED")
        self.assertEqual(stage, "FINISHED")
        self.assertEqual(client_id, "rag_pipeline_test")

    def test_02_early_client_disconnect_abort_semantic_audit(self):
        """2. Premature client abort records ABORTED, TERMINATED, and exact partial bytes_served."""
        req_id = f"test_abort_{int(time.time())}"
        try:
            r = requests.get(
                f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
                auth=admin_auth,
                headers={
                    **HEADERS,
                    "X-Request-ID": req_id,
                    "X-Simulate-Abort-After-Bytes": "8192"
                }
            )
            _ = r.content
        except Exception:
            # Expected stream truncation exception on client
            pass

        # Verify database record reflects partial byte delivery and ABORTED status
        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage, error_message FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage, error_msg = parts

        self.assertEqual(result, "ALLOWED")
        self.assertGreater(int(bytes_req), int(bytes_srv))
        self.assertEqual(int(bytes_srv), 8192)
        self.assertEqual(transfer_status, "ABORTED")
        self.assertEqual(stage, "TERMINATED")
        self.assertIn("disconnected prematurely", error_msg)

    def test_03_stream_read_error_semantic_audit(self):
        """3. Storage read failure during transfer records FAILED, TERMINATED, and error_message."""
        req_id = f"test_read_fail_{int(time.time())}"
        try:
            r = requests.get(
                f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
                auth=admin_auth,
                headers={
                    **HEADERS,
                    "X-Request-ID": req_id,
                    "X-Simulate-Stream-Failure": "true"
                }
            )
            _ = r.content
        except Exception:
            pass

        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage, error_message FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage, error_msg = parts

        self.assertEqual(result, "ALLOWED")
        self.assertEqual(int(bytes_srv), 0)
        self.assertEqual(transfer_status, "FAILED")
        self.assertEqual(stage, "TERMINATED")
        self.assertIn("read error", error_msg.lower())

    def test_04_stream_open_failure_fopen_semantic_audit(self):
        """4. Stream opening failure (file vanished) records STREAM_FAILED, FAILED, and HTTP 500."""
        req_id = f"test_fopen_fail_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
            auth=admin_auth,
            headers={
                **HEADERS,
                "X-Request-ID": req_id,
                "X-Simulate-Fopen-Failure": "true"
            }
        )
        self.assertEqual(r.status_code, 500)
        data = r.json()
        self.assertEqual(data["status"], "error")

        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage, error_message FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage, error_msg = parts

        self.assertEqual(result, "ALLOWED")
        self.assertEqual(int(bytes_srv), 0)
        self.assertEqual(transfer_status, "STREAM_FAILED")
        self.assertEqual(stage, "FAILED")
        self.assertIn("open file stream", error_msg.lower())

    def test_05_unauthenticated_request_semantic_audit(self):
        """5. Unauthenticated request records UNAUTHORIZED, AUTH, bytes=0, and HTTP 401."""
        req_id = f"test_unauth_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
            headers={"X-Request-ID": req_id}
        )
        self.assertEqual(r.status_code, 401)

        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage = parts

        self.assertEqual(result, "UNAUTHORIZED")
        self.assertEqual(int(bytes_req), 0)
        self.assertEqual(int(bytes_srv), 0)
        self.assertEqual(transfer_status, "NONE")
        self.assertEqual(stage, "AUTH")

    def test_06_forbidden_request_cross_department_semantic_audit(self):
        """6. Cross-department probe records FORBIDDEN, ACCESS_CHECK, bytes=0, and HTTP 403."""
        req_id = f"test_forbid_{int(time.time())}"
        ai_token = "ai_sec_token_7021824a20719a37d5433ba2f96832d28edf57d81d307a8468d2864601e29d08"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
            headers={
                **HEADERS,
                "Authorization": f"Bearer {ai_token}",
                "X-On-Behalf-Of": "Bakbari",  # SOC department user attempting cross-department access to CERT file 701
                "X-Request-ID": req_id,
            }
        )
        self.assertEqual(r.status_code, 403)

        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage = parts

        self.assertEqual(result, "FORBIDDEN")
        self.assertEqual(int(bytes_req), 0)
        self.assertEqual(int(bytes_srv), 0)
        self.assertEqual(transfer_status, "NONE")
        self.assertEqual(stage, "ACCESS_CHECK")

    def test_07_not_found_file_semantic_audit(self):
        """7. Missing file ID records NOT_FOUND, ACCESS_CHECK, bytes=0, and HTTP 404."""
        req_id = f"test_404_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/999999",
            auth=admin_auth,
            headers={**HEADERS, "X-Request-ID": req_id}
        )
        self.assertEqual(r.status_code, 404)

        query = f"SELECT result, bytes_requested, bytes_served, transfer_status, stage FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row, f"Audit row for {req_id} must exist")
        parts = row.split("|")
        result, bytes_req, bytes_srv, transfer_status, stage = parts

        self.assertEqual(result, "NOT_FOUND")
        self.assertEqual(int(bytes_req), 0)
        self.assertEqual(int(bytes_srv), 0)
        self.assertEqual(transfer_status, "NONE")
        self.assertEqual(stage, "ACCESS_CHECK")

    def test_08_correlation_and_client_tracking(self):
        """8. Request ID, correlation ID, client ID, actor UID, and client IP are preserved end-to-end."""
        req_id = f"req_trace_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{TEST_FILE_ID}",
            auth=admin_auth,
            headers={
                **HEADERS,
                "X-Request-ID": req_id,
                "X-Client-ID": "langchain_agent_v3"
            }
        )
        self.assertEqual(r.status_code, 200)

        query = f"SELECT request_id, correlation_id, actor_uid, client_id, client_ip FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;"
        row = run_psql(query)
        self.assertTrue(row)
        rid, cid, actor, client, ip = row.split("|")
        self.assertEqual(rid, req_id)
        self.assertEqual(cid, req_id)
        self.assertEqual(actor, ADMIN_USER)
        self.assertEqual(client, "langchain_agent_v3")
        self.assertTrue(len(ip) > 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
