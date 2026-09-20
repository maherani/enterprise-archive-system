#!/usr/bin/env python3
"""
Enterprise Archive System - Master Test Suite Runner
Executes all 27 automated test suites and produces a unified audit matrix.
"""

import os
import sys
import time
import subprocess

TESTS = [
    ("test_vertical_scroll_and_layout.py", "UI/UX: Full-Width Canvas, Obsidian Scrollbar, No Layout Leaks"),
    ("test_app_menu_isolation.py", "Security: Non-Admin App Menu Isolation (Only Archive Portal)"),
    ("test_app_store_isolation.py", "Security: Complete Elimination of App Store & Non-Admin Apps"),
    ("test_folder_creation_restriction.py", "Governance: Folder Creation Restrictions & Root Quota 0"),
    ("test_folder_request_workflow.py", "Workflow: 2-Tier Department Folder Request & OCC Approval"),
    ("test_folder_request_governance_v2.py", "Governance: Notifications, Spoof Protection & Rejection Flow"),
    ("test_group_folders_api.py", "API: Department Hierarchy & Group Folders Tree Resolution"),
    ("test_group_tag_isolation.py", "Security: Multi-Department System Tag Isolation (SOC vs CERT)"),
    ("test_tag_lifecycle_reconciliation.py", "Lifecycle: Tag Hierarchy Reconciliation & Orphan Pruning"),
    ("test_user_governance.py", "Governance: User Lifecycle, Tag Reassignment & Deletion Hooks"),
    ("test_archive_acl_and_tag_isolation.py", "Security: WebDAV File Isolation, Tag Scoping & Admin Grants"),
    ("test_multi_tag_filter.py", "Search: Multi-Tag Logical AND Intersection & Tag ID Filtering"),
    ("test_dynamic_archive_system.py", "Core: Dynamic Parent Tagging, Quota 0 & Per-User Upload Limits"),
    ("test_ai_file_retrieval_api.py", "AI Engine: Bearer Token, Delegated Identity & IDOR Defense"),
    ("test_archive_portal.py", "Frontend: Portal UI Components, Search, Filter & Drawer"),
    ("test_file_location_navigation.py", "Navigation: Deep-Folder Resolution & Direct Directory Links"),
    ("test_url_masking.py", "Hardening: WebDAV URL Obfuscation & Path Masking"),
    ("test_admin_folder_creation.py", "Admin: Direct Folder Creation via API, OCC and Portal UI"),
    ("test_air_gapped_isolation.py", "Air-Gapped: External Services, Store, Federation & Route Lockdown"),
    ("test_group_admin_tag_governance.py", "Governance: Group-Specific Tag Management by Group Admin & Zero-Bypass Isolation"),
    ("test_ui_upload_and_tagging.py", "Upload & Auto-Tagging: Direct Portal & WebDAV Upload Flow with Hierarchical Auto-Tags"),
("test_access_aware_navigation.py", "Navigation: Access-Aware Global Navigation & Dynamic Catalog Resolution"),
    ("test_unified_table.py", "UI/UX: Unified 4-Column Obsidian Table Across Browsing & Tag Filter"),
    ("test_ai_admin_ui_api.py", "AI Admin: Service Registration, Delegation Rules & Audit Sandbox API"),
    ("test_central_permission_resolver.py", "Authorization: Central Permission Resolver, Effective ACL & Dynamic Precedence"),
    ("test_fail_close_isolation_wrapper.py", "Storage Security: Fail-Closed Storage Isolation & Cache Hardening"),
    ("test_file_ownership_effective_acl.py", "Authorization: FileOwnershipService Redesign, Explicit Revocation & Cascade ACL"),
    ("test_atomic_group_tag_deletion.py", "Governance: Atomic Group Tag Deletion, Consistency & Reconciliation Engine"),
    ("test_reliable_audit_subsystem.py", "Audit Security: Reliable Audit Subsystem, Fail-Closed Gating & DLQ Flush"),
    ("test_ai_audit_semantics.py", "AI Engine: Audit Semantic Model, Precise Byte Accounting & Lifecycle Transitions"),
]

def main():
    print("=" * 80)
    print(" ENTERPRISE ARCHIVE SYSTEM - COMPREHENSIVE 29-SUITE AUDIT")
    print("=" * 80)

    results = []
    total_start = time.time()
    
    base_dir = "/home/alborz/enterprise-archive-system"
    python_bin = f"{base_dir}/venv/bin/python"

    for idx, (test_file, desc) in enumerate(TESTS, 1):
        test_path = os.path.join(base_dir, "tests", test_file)
        print(f"\n[{idx:02d}/{len(TESTS):02d}] Running: {test_file}")
        print(f"       Desc: {desc}")
        
        t0 = time.time()
        proc = subprocess.run(
            [python_bin, test_path],
            cwd=base_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True
        )
        duration = time.time() - t0
        passed = (proc.returncode == 0)
        
        status_str = "PASSED" if passed else "FAILED"
        print(f"       Status: {status_str} ({duration:.2f}s)")
        if not passed:
            print("--- Error Output Preview ---")
            lines = proc.stdout.strip().splitlines()
            for l in lines[-15:]:
                print("  ", l)
            print("----------------------------")
            
        results.append({
            "num": idx,
            "file": test_file,
            "desc": desc,
            "passed": passed,
            "duration": duration,
            "output": proc.stdout
        })

    total_time = time.time() - total_start
    total_passed = sum(1 for r in results if r["passed"])
    total_failed = len(results) - total_passed

    print("\n" + "=" * 80)
    print(" COMPREHENSIVE AUDIT EXECUTION SUMMARY")
    print("=" * 80)
    print(f"{'#':<3} | {'Test Suite':<38} | {'Status':<8} | {'Time':<6} | {'Description'}")
    print("-" * 80)
    for r in results:
        status_sym = "[OK] PASS" if r["passed"] else "[X] FAIL"
        print(f"{r['num']:<3} | {r['file']:<38} | {status_sym:<8} | {r['duration']:>5.2f}s | {r['desc']}")
    print("-" * 80)
    print(f"TOTAL: {len(results)} Suites | PASSED: {total_passed} | FAILED: {total_failed} | Time: {total_time:.2f}s")
    print("=" * 80)

    if total_failed > 0:
        sys.exit(1)
    else:
        print("\n ALL TEST SUITES PASSED WITH 100% SUCCESS!")
        sys.exit(0)

if __name__ == "__main__":
    main()
