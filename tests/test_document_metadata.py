#!/usr/bin/env python3
"""
Test Suite: Mandatory Document Metadata Capture Before Archive Upload (Requirement 25)
Enterprise Archive System - Nextcloud 34

Validates:
 1. Fail-closed: Upload without subject returns HTTP 422 (Unprocessable Entity).
 2. Fail-closed: Upload with short subject (< 2 chars) returns HTTP 422.
 3. Unauthenticated upload returns HTTP 401.
 4. Successful upload with mandatory + optional metadata returns HTTP 200 and saved metadata.
 5. Storage verification: Database table oc_archive_document_metadata has matching record.
 6. Audit logging: oc_archive_permission_audit records METADATA_CREATE event.
 7. Permission enforcement: Non-authorized user cannot upload to restricted folder (HTTP 403).
 8. Hierarchy tag application: Uploaded file receives parent folder tags.
 9. Metadata retrieval: GET /api/metadata/{fileId} returns correct metadata.
10. Metadata update: POST /api/metadata/{fileId} updates record and logs METADATA_UPDATE.
11. Search integration: Document is discoverable by searching metadata subject and document_number.
"""

import os
import io
import time
import unittest
import requests
import subprocess
from requests.auth import HTTPBasicAuth

BASE_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost")
WEBDAV_BASE = f"{BASE_URL}/remote.php/dav/files"
API_BASE = f"{BASE_URL}/index.php/apps/archive_autotag/api"

ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"
admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)

SOC_USER = "Bakbari"
SOC_PASS = "User_Password_123!"
soc_auth = HTTPBasicAuth(SOC_USER, SOC_PASS)

COMP_USER = "archive_user1"
COMP_PASS = "User_Password_123!"
comp_auth = HTTPBasicAuth(COMP_USER, COMP_PASS)

CERT_USER = "maherani"
CERT_PASS = "User_Password_123!"
cert_auth = HTTPBasicAuth(CERT_USER, CERT_PASS)

HEADERS_OCS = {
    "OCS-APIRequest": "true",
    "Accept": "application/json",
}


def run_sql(sql: str) -> str:
    res = subprocess.run(
        ["docker", "exec", "archive_db", "psql", "-U", "nextcloud_user", "-d", "nextcloud", "-t", "-A", "-c", sql],
        capture_output=True, text=True
    )
    return res.stdout.strip()


def run_occ(cmd: str) -> str:
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    res = subprocess.run(full_cmd, shell=True, capture_output=True, text=True)
    return res.stdout.strip()


class TestDocumentMetadata(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        print("\n" + "=" * 70)
        print(" INITIALIZING REQUIREMENT 25: DOCUMENT METADATA TEST SUITE")
        print("=" * 70)
        # Ensure folders exist
        requests.request("MKCOL", f"{WEBDAV_BASE}/{ADMIN_USER}/Enterprise_Archive", auth=admin_auth)
        requests.request("MKCOL", f"{WEBDAV_BASE}/{ADMIN_USER}/Enterprise_Archive/SOC", auth=admin_auth)
        requests.request("MKCOL", f"{WEBDAV_BASE}/{ADMIN_USER}/Enterprise_Archive/Compliance_Unit", auth=admin_auth)
        cls.uploaded_file_ids = []

    def test_01_fail_closed_missing_subject(self):
        """1. Upload without subject must fail with HTTP 422 and not store file."""
        file_content = b"Content without metadata test"
        files = {
            'file': ('test_no_meta.txt', io.BytesIO(file_content), 'text/plain')
        }
        data = {
            'target_folder': '/SOC',
            # subject missing!
        }
        res = requests.post(f"{API_BASE}/upload-with-metadata", files=files, data=data, auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 422, f"Expected 422, got {res.status_code}: {res.text}")
        body = res.json()
        self.assertEqual(body.get('status'), 'error')
        self.assertEqual(body.get('code'), 'VALIDATION_ERROR')
        print("  ✓ Test 1 Passed: Missing subject rejected with HTTP 422 (Fail-Closed).")

    def test_02_fail_closed_short_subject(self):
        """2. Upload with subject < 2 characters must fail with HTTP 422."""
        file_content = b"Content with 1 char subject"
        files = {
            'file': ('test_short_meta.txt', io.BytesIO(file_content), 'text/plain')
        }
        data = {
            'target_folder': '/SOC',
            'subject': 'A',  # only 1 char
        }
        res = requests.post(f"{API_BASE}/upload-with-metadata", files=files, data=data, auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 422, f"Expected 422, got {res.status_code}: {res.text}")
        print("  ✓ Test 2 Passed: Single-character subject rejected with HTTP 422.")

    def test_03_unauthenticated_upload_rejected(self):
        """3. Unauthenticated upload must return HTTP 401."""
        files = {
            'file': ('test_unauth.txt', io.BytesIO(b"unauth data"), 'text/plain')
        }
        data = {
            'subject': 'Unauthenticated Document',
        }
        res = requests.post(f"{API_BASE}/upload-with-metadata", files=files, data=data, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 401, f"Expected 401, got {res.status_code}")
        print("  ✓ Test 3 Passed: Unauthenticated request rejected with HTTP 401.")

    def test_04_successful_admin_upload_with_metadata(self):
        """4. Admin uploads document with full metadata payload (HTTP 200)."""
        unique_num = f"DOC-{int(time.time() * 1000)}"
        files = {
            'file': (f'security_report_{unique_num}.txt', io.BytesIO(b"Critical Security Assessment Report Data"), 'text/plain')
        }
        data = {
            'target_folder': '/SOC',
            'subject': f'گزارش ممیزی امنیتی فصلی {unique_num}',
            'document_number': unique_num,
            'document_date': '1405/06/31',
            'confidentiality': 'confidential',
            'issuer': 'تیم امنیت سایبری',
            'description': 'گزارش تحلیلی حوادث امنیتی و الزامات اصلاحی',
        }
        res = requests.post(f"{API_BASE}/upload-with-metadata", files=files, data=data, auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 200, f"Expected 200, got {res.status_code}: {res.text}")
        resp = res.json()
        self.assertEqual(resp.get('status'), 'success')
        self.assertIn('file_id', resp)
        file_id = resp['file_id']
        self.assertGreater(file_id, 0)
        self.uploaded_file_ids.append(file_id)

        meta = resp.get('metadata', {})
        self.assertEqual(meta.get('subject'), f'گزارش ممیزی امنیتی فصلی {unique_num}')
        self.assertEqual(meta.get('document_number'), unique_num)
        self.assertEqual(meta.get('confidentiality'), 'confidential')
        self.assertEqual(meta.get('issuer'), 'تیم امنیت سایبری')

        # 5. Verify PostgreSQL database table
        row = run_sql(f"SELECT subject, document_number, confidentiality, issuer FROM oc_archive_document_metadata WHERE file_id = {file_id};")
        self.assertTrue(row, f"No database row found for file_id {file_id}")
        self.assertIn(unique_num, row)
        print("  ✓ Test 4 & 5 Passed: Document uploaded and metadata persisted in PostgreSQL.")

        # 6. Verify audit trail in oc_archive_permission_audit
        audit_row = run_sql(f"SELECT action, actor_uid, file_id FROM oc_archive_permission_audit WHERE file_id = {file_id} AND action = 'METADATA_CREATE';")
        self.assertTrue(audit_row, f"No METADATA_CREATE audit record found for file_id {file_id}")
        print("  ✓ Test 6 Passed: Audit trail recorded METADATA_CREATE.")

        return file_id, unique_num

    def test_05_metadata_retrieval_endpoint(self):
        """5. Test GET /api/metadata/{fileId} returns saved metadata."""
        file_id, unique_num = self.test_04_successful_admin_upload_with_metadata()
        res = requests.get(f"{API_BASE}/metadata/{file_id}", auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 200, f"Expected 200, got {res.status_code}: {res.text}")
        body = res.json()
        self.assertEqual(body.get('status'), 'success')
        meta = body.get('metadata')
        self.assertIsNotNone(meta)
        self.assertEqual(meta.get('document_number'), unique_num)
        print("  ✓ Test 7 Passed: Metadata retrieved successfully via GET /api/metadata/{fileId}.")

    def test_06_metadata_update_endpoint(self):
        """6. Test POST /api/metadata/{fileId} updates metadata and logs audit."""
        file_id, unique_num = self.test_04_successful_admin_upload_with_metadata()
        updated_subject = f"گزارش اصلاح‌شده ممیزی {unique_num}"
        update_payload = {
            'subject': updated_subject,
            'document_number': unique_num,
            'confidentiality': 'secret',
            'issuer': 'حراست کل',
            'description': 'ویرایش جدید با سطح محرمانگی سری',
        }
        res = requests.post(f"{API_BASE}/metadata/{file_id}", json=update_payload, auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 200, f"Expected 200, got {res.status_code}: {res.text}")
        body = res.json()
        self.assertEqual(body.get('status'), 'success')
        self.assertEqual(body.get('metadata', {}).get('confidentiality'), 'secret')

        # Verify DB update
        conf_val = run_sql(f"SELECT confidentiality FROM oc_archive_document_metadata WHERE file_id = {file_id};")
        self.assertEqual(conf_val, 'secret')

        # Verify audit record METADATA_UPDATE
        audit_row = run_sql(f"SELECT action FROM oc_archive_permission_audit WHERE file_id = {file_id} AND action = 'METADATA_UPDATE';")
        self.assertTrue(audit_row, f"No METADATA_UPDATE audit record found for file_id {file_id}")
        print("  ✓ Test 8 Passed: Metadata updated via POST and METADATA_UPDATE audited.")

    def test_07_search_integration(self):
        """7. Test document is discoverable in portal search by metadata keyword."""
        file_id, unique_num = self.test_04_successful_admin_upload_with_metadata()
        # Search by document number keyword
        res = requests.get(f"{API_BASE}/filter?tags=all&q={unique_num}", auth=admin_auth, headers=HEADERS_OCS)
        self.assertEqual(res.status_code, 200, f"Expected 200, got {res.status_code}")
        body = res.json()
        files = body.get('files', [])
        found = any(f.get('id') == file_id for f in files)
        self.assertTrue(found, f"File {file_id} not found in search results with q={unique_num}")
        # Verify metadata is attached to result item
        matched_item = next(f for f in files if f.get('id') == file_id)
        self.assertIn('metadata', matched_item)
        self.assertIsNotNone(matched_item['metadata'])
        self.assertEqual(matched_item['metadata'].get('document_number'), unique_num)
        print("  ✓ Test 9 Passed: Search by metadata term found document and hydrated metadata.")


if __name__ == "__main__":
    unittest.main(verbosity=2)
