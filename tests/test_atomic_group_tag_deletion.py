#!/usr/bin/env python3
"""
Test Suite: Atomic Group Tag Deletion & Consistency (Requirement 20 / Prompt 05)
================================================================================
Validates that:
1. Successful atomic deletion cleans all tables (systemtag, object_mapping, ownership, groups) and logs audit success.
2. Non-existent tag deletion returns 404 (never false success).
3. Cross-group deletion attempt is rejected with 403 Forbidden without modifying DB.
4. Tag in use by files is rejected with 409 Conflict (code: TAG_IN_USE) unless force is specified.
5. Forced deletion cascades detachment from files, deletes tag, and records success.
6. System failure during deletion triggers 100% rollback, returns 500 (never false 200), logs audit failure.
7. Low-level DB failure prevents partial deletion.
8. Repeated deletion is rejected with 404 (idempotency defense).
9. Concurrent deletion requests serialize cleanly via row-level locks (exactly 1 succeeds, 4 get 404/409).
10. Reconciliation engine recovers orphaned system tags and purges ghost metadata.
11. CLI command parity: occ archive:tag:gov supports --force and reconcile-group.
"""

import os
import sys
import time
import subprocess
import unittest
import concurrent.futures
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost")

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

SOC_ADMIN_USER = "Bakbari"
SOC_ADMIN_PASS = "User_Password_123!"

CERT_ADMIN_USER = "maherani"
CERT_ADMIN_PASS = "User_Password_123!"

HEADERS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
    "Content-Type": "application/json"
}

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
soc_admin_auth = HTTPBasicAuth(SOC_ADMIN_USER, SOC_ADMIN_PASS)
cert_admin_auth = HTTPBasicAuth(CERT_ADMIN_USER, CERT_ADMIN_PASS)


def run_psql(sql: str) -> str:
    """Execute raw SQL in archive_db container."""
    cmd = [
        "docker", "exec", "archive_db",
        "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", sql
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, check=True)
    return res.stdout.strip()


def run_occ(occ_args: list) -> subprocess.CompletedProcess:
    """Execute occ command in archive_app container."""
    cmd = ["docker", "exec", "-u", "www-data", "archive_app", "php", "occ"] + occ_args
    return subprocess.run(cmd, capture_output=True, text=True)


class TestAtomicGroupTagDeletion(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        # Verify Nextcloud is healthy
        r = requests.get(f"{NEXTCLOUD_URL}/status.php", timeout=10)
        assert r.status_code == 200, f"Nextcloud unhealthy: {r.status_code}"

        # Find a valid file ID belonging to SOC group
        res = requests.get(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/files",
            auth=soc_admin_auth,
            headers=HEADERS,
            timeout=10
        )
        assert res.status_code == 200, "Failed to fetch files for SOC"
        files = res.json().get("files", [])
        soc_file = next((f for f in files if "SOC" in f.get("path", "") and not f.get("is_dir")), None)
        assert soc_file is not None, "Could not find a SOC file for tag mapping tests"
        cls.soc_file_id = int(soc_file["id"])
        cls.soc_file_path = soc_file["path"]

    def create_tag(self, auth, group_id: str, tag_name: str) -> dict:
        r = requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/create",
            auth=auth,
            headers=HEADERS,
            json={"group_id": group_id, "tag_name": tag_name},
            timeout=10
        )
        self.assertEqual(r.status_code, 200, f"Failed to create tag: {r.text}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")
        return data.get("tag", {})

    def delete_tag(self, auth, group_id: str, tag_id: int, force: bool = False) -> requests.Response:
        return requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/delete",
            auth=auth,
            headers=HEADERS,
            json={"group_id": group_id, "tag_id": tag_id, "force": force},
            timeout=10
        )

    def assign_tag(self, auth, group_id: str, tag_id: int, file_id: int) -> requests.Response:
        return requests.post(
            f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/assign",
            auth=auth,
            headers=HEADERS,
            json={"group_id": group_id, "tag_id": tag_id, "file_id": file_id},
            timeout=10
        )

    def test_01_successful_atomic_delete(self):
        """1. Admin creates a tag and deletes it. Verify complete atomic deletion and audit success."""
        tag_name = f"AtomicDel_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # Verify DB records exist
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "1")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "1")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "1")

        # Delete tag
        r = self.delete_tag(soc_admin_auth, "SOC", tag_id)
        self.assertEqual(r.status_code, 200, f"Delete failed: {r.text}")
        resp_json = r.json()
        self.assertEqual(resp_json.get("status"), "success")

        # Verify DB records removed atomically
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "0")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "0")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "0")

        # Verify audit log recorded success
        audit_res = run_psql(
            f"SELECT result FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'delete_tag' ORDER BY id DESC LIMIT 1"
        )
        self.assertEqual(audit_res, "success")

    def test_02_non_existing_tag_rejection(self):
        """2. Deleting non-existing tag returns 404 (never false 200)."""
        invalid_id = 999888777
        r = self.delete_tag(soc_admin_auth, "SOC", invalid_id)
        self.assertIn(r.status_code, [404, 400], f"Expected 404/400 for non-existing tag, got {r.status_code}")
        data = r.json()
        self.assertNotEqual(data.get("status"), "success")

    def test_03_wrong_group_forbidden(self):
        """3. Cross-group tag deletion attempt returns 403 Forbidden without modifying DB."""
        tag_name = f"CrossGroupTag_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        try:
            # CERT admin attempts to delete SOC tag
            r = self.delete_tag(cert_admin_auth, "CERT", tag_id)
            self.assertEqual(r.status_code, 403, f"Expected 403 Forbidden, got {r.status_code}: {r.text}")

            # Verify tag is STILL intact in DB
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "1")

            # Verify audit recorded failure
            audit_res = run_psql(
                f"SELECT result FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'delete_tag' AND actor_uid = 'maherani' ORDER BY id DESC LIMIT 1"
            )
            self.assertEqual(audit_res, "failure")
        finally:
            # Clean up tag with SOC admin
            self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)

    def test_04_tag_in_use_rejection_without_force(self):
        """4. Tag assigned to files rejects normal deletion with 409 Conflict and TAG_IN_USE."""
        tag_name = f"InUseTag_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        try:
            # Assign tag to SOC file
            assign_res = self.assign_tag(soc_admin_auth, "SOC", tag_id, self.soc_file_id)
            self.assertEqual(assign_res.status_code, 200)

            # Verify object mapping exists
            map_cnt = run_psql(f"SELECT count(*) FROM oc_systemtag_object_mapping WHERE systemtagid = {tag_id}")
            self.assertGreaterEqual(int(map_cnt), 1)

            # Normal delete without force -> must be rejected with 409 Conflict
            r = self.delete_tag(soc_admin_auth, "SOC", tag_id, force=False)
            self.assertEqual(r.status_code, 409, f"Expected 409 Conflict for in-use tag, got {r.status_code}: {r.text}")
            data = r.json()
            self.assertEqual(data.get("code"), "TAG_IN_USE")
            self.assertGreaterEqual(data.get("usage_count", 0), 1)

            # Verify tag still exists in DB
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "1")
        finally:
            # Clean up
            self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)

    def test_05_tag_in_use_forced_deletion(self):
        """5. Tag assigned to files is deleted when force=True, cascading detachment."""
        tag_name = f"ForceDelTag_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # Assign tag to file
        self.assign_tag(soc_admin_auth, "SOC", tag_id, self.soc_file_id)
        self.assertGreaterEqual(int(run_psql(f"SELECT count(*) FROM oc_systemtag_object_mapping WHERE systemtagid = {tag_id}")), 1)

        # Force delete
        r = self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)
        self.assertEqual(r.status_code, 200, f"Expected 200 OK for forced delete, got {r.status_code}: {r.text}")
        data = r.json()
        self.assertEqual(data.get("status"), "success")

        # Verify object mapping, systemtag, and metadata all wiped
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag_object_mapping WHERE systemtagid = {tag_id}"), "0")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "0")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "0")
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "0")

    def test_06_simulated_error_rollback_prevents_false_success(self):
        """6. Failure during systemtag delete triggers rollback; never returns false success."""
        tag_name = f"RollbackTest_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # Install a trigger in PostgreSQL to fail deletion on this specific tag ID
        trigger_sql = f"""
        CREATE OR REPLACE FUNCTION fail_tag_del_{tag_id}() RETURNS trigger AS $$
        BEGIN
            IF OLD.id = {tag_id} THEN
                RAISE EXCEPTION 'Simulated atomic failure during systemtag delete';
            END IF;
            RETURN OLD;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_fail_{tag_id} ON oc_systemtag;
        CREATE TRIGGER trg_fail_{tag_id}
        BEFORE DELETE ON oc_systemtag
        FOR EACH ROW EXECUTE FUNCTION fail_tag_del_{tag_id}();
        """
        run_psql(trigger_sql)

        try:
            # Attempt delete -> must fail with 500 error
            r = self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)
            self.assertEqual(r.status_code, 500, f"Expected 500 error during simulated failure, got {r.status_code}: {r.text}")
            data = r.json()
            self.assertNotEqual(data.get("status"), "success")

            # Verify complete rollback: tag and archive ownership STILL exist!
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "1")

            # Verify audit recorded failure
            audit_res = run_psql(
                f"SELECT result FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'delete_tag' ORDER BY id DESC LIMIT 1"
            )
            self.assertEqual(audit_res, "failure")
        finally:
            # Drop trigger and clean up tag
            run_psql(f"DROP TRIGGER IF EXISTS trg_fail_{tag_id} ON oc_systemtag; DROP FUNCTION IF EXISTS fail_tag_del_{tag_id}();")
            self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)

    def test_07_db_failure_atomic_rollback(self):
        """7. Verify database transaction guarantees zero orphan records upon failure."""
        tag_name = f"DbFailTest_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # Install trigger on archive_tag_ownership
        trigger_sql = f"""
        CREATE OR REPLACE FUNCTION fail_own_del_{tag_id}() RETURNS trigger AS $$
        BEGIN
            IF OLD.tag_id = {tag_id} THEN
                RAISE EXCEPTION 'Simulated failure on ownership delete';
            END IF;
            RETURN OLD;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_own_fail_{tag_id} ON oc_archive_tag_ownership;
        CREATE TRIGGER trg_own_fail_{tag_id}
        BEFORE DELETE ON oc_archive_tag_ownership
        FOR EACH ROW EXECUTE FUNCTION fail_own_del_{tag_id}();
        """
        run_psql(trigger_sql)

        try:
            r = self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)
            self.assertEqual(r.status_code, 500)

            # Verify oc_systemtag and archive_tag_groups are NOT orphaned: they rolled back!
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {tag_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {tag_id}"), "1")
        finally:
            run_psql(f"DROP TRIGGER IF EXISTS trg_own_fail_{tag_id} ON oc_archive_tag_ownership; DROP FUNCTION IF EXISTS fail_own_del_{tag_id}();")
            self.delete_tag(soc_admin_auth, "SOC", tag_id, force=True)

    def test_08_repeated_delete_idempotency(self):
        """8. Repeated delete on an already-deleted tag returns 404 (not false success)."""
        tag_name = f"RepeatedDel_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # First delete -> 200 OK
        r1 = self.delete_tag(soc_admin_auth, "SOC", tag_id)
        self.assertEqual(r1.status_code, 200)

        # Second delete -> 404
        r2 = self.delete_tag(soc_admin_auth, "SOC", tag_id)
        self.assertIn(r2.status_code, [404, 400])

    def test_09_concurrent_delete_safety(self):
        """9. 5 concurrent delete requests on the same tag serialize via row locks (exactly 1 succeeds)."""
        tag_name = f"ConcurrentDel_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        def send_delete():
            return requests.post(
                f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/delete",
                auth=soc_admin_auth,
                headers=HEADERS,
                json={"group_id": "SOC", "tag_id": tag_id, "force": True},
                timeout=10
            )

        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
            futures = [executor.submit(send_delete) for _ in range(5)]
            responses = [f.result() for f in futures]

        status_codes = [r.status_code for r in responses]
        success_count = status_codes.count(200)
        self.assertEqual(success_count, 1, f"Expected exactly 1 success among concurrent deletes, got {status_codes}")

        # Verify exactly one success audit entry
        success_audits = int(run_psql(
            f"SELECT count(*) FROM oc_archive_tag_audit WHERE tag_id = {tag_id} AND action = 'delete_tag' AND result = 'success'"
        ))
        self.assertEqual(success_audits, 1)

    def test_10_reconciliation_orphan_and_ghost_healing(self):
        """10. Reconciliation detects and repairs orphan system tags and purges ghost records."""
        # Inject an orphan system tag (in oc_systemtag with SOC prefix, but missing from archive tables)
        orphan_name = f"[SOC] InjectedOrphan_{int(time.time())}"
        orphan_out = run_psql(
            f"INSERT INTO oc_systemtag (name, visibility, editable) VALUES ('{orphan_name}', 1, 1) RETURNING id;"
        )
        orphan_id = orphan_out.splitlines()[0].strip()
        self.assertTrue(orphan_id.isdigit(), f"Expected numeric orphan_id, got: {orphan_out}")
        orphan_id = int(orphan_id)

        # Inject a ghost record (in archive_tag_groups pointing to non-existent tag 888777)
        ghost_id = 888777
        run_psql(
            f"INSERT INTO oc_archive_tag_groups (tag_id, group_id, created_at) VALUES ({ghost_id}, 'SOC', {int(time.time())}) ON CONFLICT DO NOTHING;"
        )
        run_psql(
            f"INSERT INTO oc_archive_tag_ownership (tag_id, owner_uid, created_at, status) VALUES ({ghost_id}, 'Bakbari', {int(time.time())}, 'ACTIVE') ON CONFLICT DO NOTHING;"
        )

        try:
            # Trigger reconciliation endpoint
            r = requests.post(
                f"{NEXTCLOUD_URL}/index.php/apps/archive_autotag/api/group-tags/reconcile",
                auth=soc_admin_auth,
                headers=HEADERS,
                json={"group_id": "SOC"},
                timeout=10
            )
            self.assertEqual(r.status_code, 200, f"Reconcile failed: {r.text}")
            data = r.json()
            self.assertEqual(data.get("status"), "success")
            report = data.get("report", {})

            # Verify orphan was restored into archive tables
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {orphan_id}"), "1")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {orphan_id}"), "1")

            # Verify ghost was purged from archive tables
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_groups WHERE tag_id = {ghost_id}"), "0")
            self.assertEqual(run_psql(f"SELECT count(*) FROM oc_archive_tag_ownership WHERE tag_id = {ghost_id}"), "0")

            # Verify audit recorded reconcile_tag
            audit_cnt = run_psql("SELECT count(*) FROM oc_archive_tag_audit WHERE action = 'reconcile_tag' AND group_id = 'SOC'")
            self.assertGreaterEqual(int(audit_cnt), 1)
        finally:
            # Clean up injected orphan
            self.delete_tag(soc_admin_auth, "SOC", orphan_id, force=True)

    def test_11_cli_governance_parity(self):
        """11. CLI command occ archive:tag:gov supports --force and reconcile-group."""
        # Create tag
        tag_name = f"CliTag_{int(time.time())}"
        tag_info = self.create_tag(soc_admin_auth, "SOC", tag_name)
        tag_id = int(tag_info["tag_id"])

        # Assign to file
        self.assign_tag(soc_admin_auth, "SOC", tag_id, self.soc_file_id)

        # Attempt delete without force via CLI -> must fail
        proc_fail = run_occ(["archive:tag:gov", "delete", str(tag_id)])
        self.assertNotEqual(proc_fail.returncode, 0)
        output_fail = (proc_fail.stdout or "") + (proc_fail.stderr or "")
        self.assertIn("assigned to", output_fail)

        # Delete with --force via CLI -> must succeed
        proc_ok = run_occ(["archive:tag:gov", "delete", str(tag_id), "--force"])
        self.assertEqual(proc_ok.returncode, 0)
        output_ok = (proc_ok.stdout or "") + (proc_ok.stderr or "")
        self.assertIn("Successfully and atomically deleted", output_ok)

        # Verify completely deleted
        self.assertEqual(run_psql(f"SELECT count(*) FROM oc_systemtag WHERE id = {tag_id}"), "0")

        # Test CLI reconcile-group
        proc_rec = run_occ(["archive:tag:gov", "reconcile-group", "SOC"])
        self.assertEqual(proc_rec.returncode, 0)
        output_rec = (proc_rec.stdout or "") + (proc_rec.stderr or "")
        self.assertIn("completed successfully", output_rec)


if __name__ == "__main__":
    unittest.main(verbosity=2)
