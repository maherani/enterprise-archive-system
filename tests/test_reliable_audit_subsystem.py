#!/usr/bin/env python3
"""
Enterprise Archive System - Reliable Audit Subsystem Hardening Test Suite (Prompt 06 / Requirement 21)

Validates:
1. Transactional coupling of Audit-Required operations (Folder approval, Tag creation/deletion, Permission grants).
2. Fail-Closed pre-stream gating on AI document retrieval (zero byte leak on audit failure).
3. Audit-Best-Effort handling for auth failures with anti-DoS protection.
4. Mandatory audit logging for Permission Grant and Revoke in oc_archive_permission_audit.
5. CRLF & control character sanitization (Audit Log Injection defense).
6. Dead Letter Queue (DLQ) emergency fallback logging, replay, and OCC flushing.
7. Real-time Audit Health and Unified Stream APIs for UI observability.
"""

import json
import os
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

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
}


def run_psql(query: str) -> str:
    cmd = [
        "docker", "exec", "archive_db",
        "psql", "-U", "oc_admin", "-d", "nextcloud", "-t", "-A", "-c", query
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, check=True)
    return res.stdout.strip()


def run_occ(args: list) -> subprocess.CompletedProcess:
    cmd = ["docker", "exec", "-u", "www-data", "archive_app", "php", "occ"] + args
    return subprocess.run(cmd, capture_output=True, text=True)


class TestReliableAuditSubsystem(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        # Ensure emergency DLQ is clean before starting
        subprocess.run(["docker", "exec", "archive_app", "rm", "-f", "/var/www/html/data/archive_audit_emergency.jsonl"], capture_output=True)

    def test_01_audit_health_api_initial_state(self):
        """1. Verify /api/ai/audit/health returns valid health stats and database status."""
        r = requests.get(f"{API_BASE}/ai/audit/health", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r.status_code, 200, f"Expected 200, got {r.status_code}: {r.text}")
        data = r.json()
        self.assertIn(data.get("status"), ["HEALTHY", "DEGRADED"])
        self.assertTrue(data.get("database_connected"), "Database should be connected")
        self.assertIn("table_counts", data)
        self.assertIn("folder_request", data["table_counts"])
        self.assertIn("tag", data["table_counts"])
        self.assertIn("ai", data["table_counts"])
        self.assertIn("permission", data["table_counts"])

    def test_02_tag_creation_and_deletion_audit_required(self):
        """2. Tag Create and Delete write durable records to oc_archive_tag_audit with request_id."""
        tag_name = f"AuditReqTag_{int(time.time())}"

        # Create tag as Bakbari (SOC group admin)
        r_create = requests.post(
            f"{API_BASE}/group-tags/create",
            auth=soc_auth,
            headers={**HEADERS, "Content-Type": "application/json"},
            json={"group_id": "SOC", "tag_name": tag_name}
        )
        self.assertEqual(r_create.status_code, 200, f"Create failed: {r_create.text}")
        tag_id = r_create.json()["tag"].get("tag_id") or r_create.json()["tag"].get("id")

        # Verify audit record in DB
        audit_row = run_psql(f"SELECT action, result, actor_uid, group_id FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'create_tag' ORDER BY id DESC LIMIT 1;")
        self.assertTrue(audit_row, "Audit row for create_tag must exist")
        self.assertIn("create_tag|success|Bakbari|SOC", audit_row)

        # Delete tag atomically
        r_delete = requests.post(
            f"{API_BASE}/group-tags/delete",
            auth=soc_auth,
            headers={**HEADERS, "Content-Type": "application/json"},
            json={"group_id": "SOC", "tag_id": tag_id, "force": True}
        )
        self.assertEqual(r_delete.status_code, 200, f"Delete failed: {r_delete.text}")

        # Verify audit record for delete_tag
        del_row = run_psql(f"SELECT action, result, actor_uid, group_id FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'delete_tag' AND result = 'success' ORDER BY id DESC LIMIT 1;")
        self.assertTrue(del_row, "Audit row for delete_tag must exist")
        self.assertIn("delete_tag|success|Bakbari|SOC", del_row)

    def test_03_permission_grant_and_revoke_audit_persistence(self):
        """3. Permission Grant, Revoke, and Purge write durable records into oc_archive_permission_audit."""
        # Find any active file
        file_id_str = run_psql("SELECT fileid FROM oc_filecache WHERE path LIKE '%files/Enterprise_Archive/%' AND mimetype != 2 LIMIT 1;")
        if not file_id_str:
            file_id_str = "623"
        file_id = int(file_id_str.splitlines()[0].strip())

        # 1. Grant access via OCC
        occ_grant = run_occ(["archive:file:grant", "grant", str(file_id), "Bakbari", "-p", "31"])
        self.assertEqual(occ_grant.returncode, 0, f"OCC grant failed: {occ_grant.stderr}")

        # Check DB audit for grant
        grant_row = run_psql(f"SELECT action, permissions, result, grantee_id FROM oc_archive_permission_audit WHERE file_id = {file_id} AND action = 'grant' ORDER BY id DESC LIMIT 1;")
        self.assertTrue(grant_row, "Permission audit row for grant must exist")
        self.assertIn("grant|31|success|Bakbari", grant_row)

        # 2. Revoke access (Explicit Deny mask 0)
        occ_revoke = run_occ(["archive:file:grant", "revoke", str(file_id), "Bakbari"])
        self.assertEqual(occ_revoke.returncode, 0, f"OCC revoke failed: {occ_revoke.stderr}")

        revoke_row = run_psql(f"SELECT action, permissions, result, grantee_id FROM oc_archive_permission_audit WHERE file_id = {file_id} AND action = 'revoke' ORDER BY id DESC LIMIT 1;")
        self.assertTrue(revoke_row, "Permission audit row for revoke must exist")
        self.assertIn("revoke|0|success|Bakbari", revoke_row)

        # 3. Purge grant record
        occ_purge = run_occ(["archive:file:grant", "revoke", str(file_id), "Bakbari", "--purge"])
        self.assertEqual(occ_purge.returncode, 0, f"OCC purge failed: {occ_purge.stderr}")

        purge_row = run_psql(f"SELECT action, permissions, result, grantee_id FROM oc_archive_permission_audit WHERE file_id = {file_id} AND action = 'purge' ORDER BY id DESC LIMIT 1;")
        self.assertTrue(purge_row, "Permission audit row for purge must exist")
        self.assertIn("purge|0|success|Bakbari", purge_row)

    def test_04_ai_retrieval_audit_required_and_pre_stream_logging(self):
        """4. AI file retrieval requires audit logging before streaming; records ALLOWED."""
        file_id_str = run_psql("SELECT fileid FROM oc_filecache WHERE path LIKE '%files/Enterprise_Archive/%' AND mimetype != 2 LIMIT 1;")
        if not file_id_str:
            file_id_str = "623"
        file_id = int(file_id_str.splitlines()[0].strip())

        # Make authorized AI retrieval request
        req_id = f"test_req_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/{file_id}",
            auth=admin_auth,
            headers={"X-Request-ID": req_id}
        )
        self.assertEqual(r.status_code, 200, f"AI retrieval failed: {r.status_code}")
        self.assertTrue(len(r.content) > 0, "File content should be received")

        # Verify audit record in archive_ai_audit
        ai_row = run_psql(f"SELECT result, bytes_served, auth_type FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;")
        self.assertTrue(ai_row, f"Audit row for request {req_id} must exist in archive_ai_audit")
        self.assertIn("ALLOWED", ai_row)

    def test_05_ai_auth_failure_best_effort_audit(self):
        """5. Unauthenticated AI probe records UNAUTHORIZED audit and does not crash or leak file."""
        req_id = f"unauth_req_{int(time.time())}"
        r = requests.get(
            f"{API_BASE}/v1/ai/files/623",
            headers={"X-Request-ID": req_id}  # No auth
        )
        self.assertIn(r.status_code, [401, 403], f"Expected 401/403, got {r.status_code}")

        # Check DB audit recorded
        ai_row = run_psql(f"SELECT result, auth_type FROM oc_archive_ai_audit WHERE request_id = '{req_id}' LIMIT 1;")
        self.assertTrue(ai_row, "Audit row for unauthorized probe must exist")
        self.assertIn("UNAUTHORIZED", ai_row)

    def test_06_crlf_audit_log_injection_sanitization(self):
        """6. Control characters and CRLF injection attempts are sanitized by ReliableAuditService."""
        php_test = """
        require_once '/var/www/html/lib/base.php';
        $container = \OC::$server;
        $svc = $container->get(\OCA\ArchiveAutoTag\Service\ReliableAuditService::class);
        $dirty = "AdminUser\\r\\n[CRITICAL] Injected Fake Event\\nNew line\\x00";
        $clean = $svc->sanitize($dirty);
        echo $clean;
        """
        res = subprocess.run(
            ["docker", "exec", "-u", "www-data", "archive_app", "php", "-r", php_test],
            capture_output=True, text=True, check=True
        )
        output = res.stdout
        self.assertNotIn("\r", output, "Carriage return must be stripped")
        self.assertNotIn("\n", output, "Newline must be replaced with space")
        self.assertNotIn("\x00", output, "Null byte must be stripped")
        self.assertIn("AdminUser", output)
        self.assertIn("Injected Fake Event", output)

    def test_07_dlq_emergency_fallback_and_occ_flush(self):
        """7. Emergency DLQ file can store failed records and be replayed via occ archive:audit:gov flush-dlq."""
        # Clean any existing file
        subprocess.run(["docker", "exec", "archive_app", "rm", "-f", "/var/www/html/data/archive_audit_emergency.jsonl"], capture_output=True)

        # Inject a synthetic record via PHP
        req_id = f"dlq_flush_test_{int(time.time())}"
        php_inject = f"""
        require_once '/var/www/html/lib/base.php';
        $container = \OC::$server;
        $svc = $container->get(\OCA\ArchiveAutoTag\Service\ReliableAuditService::class);
        $dummyEx = new \RuntimeException("Simulated DB timeout");
        $svc->writeEmergencyLog('archive_tag_audit', [
            'actor_uid' => 'test_dlq_actor',
            'group_id' => 'SOC',
            'action' => 'create_tag',
            'tag_id' => 99999,
            'tag_name' => '[SOC] DLQ_Restored_Tag',
            'result' => 'success',
            'details' => 'Restored from DLQ test',
            'request_id' => '{req_id}',
            'created_at' => time()
        ], $dummyEx);
        echo "INJECTED";
        """
        res = subprocess.run(
            ["docker", "exec", "-u", "www-data", "archive_app", "php", "-r", php_inject],
            capture_output=True, text=True, check=True
        )
        self.assertIn("INJECTED", res.stdout)

        # Verify DLQ count via health API
        r_health = requests.get(f"{API_BASE}/ai/audit/health", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r_health.status_code, 200)
        self.assertGreaterEqual(r_health.json()["dlq_pending_count"], 1)

        # Flush DLQ using OCC command
        flush_res = run_occ(["archive:audit:gov", "flush-dlq"])
        self.assertEqual(flush_res.returncode, 0, f"Flush failed: {flush_res.stderr}")
        self.assertIn("Successfully restored", flush_res.stdout)

        # Verify record now exists in PostgreSQL table
        row = run_psql(f"SELECT actor_uid, tag_name FROM oc_archive_tag_audit WHERE request_id = '{req_id}' LIMIT 1;")
        self.assertTrue(row, "Restored entry must exist in oc_archive_tag_audit")
        self.assertIn("test_dlq_actor", row)

    def test_08_ui_audit_stream_api(self):
        """8. Unified audit stream API aggregates records across all 4 domains."""
        r = requests.get(f"{API_BASE}/ai/audit/stream?limit=10", auth=admin_auth, headers=HEADERS)
        self.assertEqual(r.status_code, 200)
        data = r.json()
        self.assertEqual(data["status"], "success")
        events = data["events"]
        self.assertTrue(len(events) > 0, "Should return at least 1 audit event")
        first = events[0]
        self.assertIn("domain", first)
        self.assertIn("action", first)
        self.assertIn("actor_uid", first)
        self.assertIn("result", first)
        self.assertIn("created_at", first)

    def test_09_ui_simulate_failure_endpoint(self):
        """9. Simulate-failure endpoint triggers fail-closed recording and reflects in DLQ."""
        r = requests.post(
            f"{API_BASE}/ai/audit/simulate-failure",
            auth=admin_auth,
            headers={**HEADERS, "Content-Type": "application/json"},
            json={"operation": "ai_retrieval"}
        )
        self.assertEqual(r.status_code, 200)
        res = r.json()
        self.assertEqual(res["behavior"], "FAIL_CLOSED")
        self.assertGreaterEqual(res["dlq_count"], 1)

        # Flush afterwards to clean
        subprocess.run(["docker", "exec", "archive_app", "rm", "-f", "/var/www/html/data/archive_audit_emergency.jsonl"], capture_output=True)

    def test_10_non_admin_forbidden_on_audit_apis(self):
        """10. Non-admin users (SOC, CERT) receive 403 Forbidden on audit health and stream endpoints."""
        for user_auth in [soc_auth, cert_auth]:
            r_health = requests.get(f"{API_BASE}/ai/audit/health", auth=user_auth, headers=HEADERS)
            self.assertEqual(r_health.status_code, 403, f"Expected 403 for non-admin on health, got {r_health.status_code}")

            r_stream = requests.get(f"{API_BASE}/ai/audit/stream", auth=user_auth, headers=HEADERS)
            self.assertEqual(r_stream.status_code, 403, f"Expected 403 for non-admin on stream, got {r_stream.status_code}")

            r_flush = requests.post(f"{API_BASE}/ai/audit/flush-dlq", auth=user_auth, headers=HEADERS)
            self.assertEqual(r_flush.status_code, 403, f"Expected 403 for non-admin on flush, got {r_flush.status_code}")


if __name__ == "__main__":
    unittest.main(verbosity=2)
