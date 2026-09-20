#!/usr/bin/env python3
"""
Master E2E Test Suite Runner
Enterprise Archive System - Requirement 23 / Prompt 08

Runs all 6 End-to-End Playwright Browser Test Suites (20 total scenarios):
- test_01_auth_and_portal.py   (Scenarios 01 - 05)
- test_02_tags_and_filter.py   (Scenarios 06, 13, 14)
- test_03_upload_lifecycle.py  (Scenarios 07 - 09)
- test_04_folder_workflow.py   (Scenarios 10 - 12)
- test_05_layout_and_ux.py     (Scenarios 15 - 19)
- test_06_ai_swagger.py        (Scenario 20)
"""

import os
import sys
import unittest
import time
from pathlib import Path

# Setup paths
PROJECT_ROOT = Path(__file__).resolve().parent
E2E_DIR = PROJECT_ROOT / "tests" / "e2e"
sys.path.insert(0, str(PROJECT_ROOT))


def run_e2e():
    print("=" * 70)
    print("  Enterprise Archive System - Real Browser E2E Test Suite (Playwright)")
    print("  Target: Nextcloud 34 (Headless Chromium | Air-Gapped)")
    print("=" * 70)
    print()

    # Discover tests in tests/e2e matching test_*.py
    loader = unittest.TestLoader()
    suite = loader.discover(start_dir=str(E2E_DIR), pattern="test_*.py")

    start_time = time.time()
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    duration = time.time() - start_time

    print()
    print("=" * 70)
    print(f"  E2E Test Execution Finished in {duration:.2f} seconds")
    print(f"  Total Scenarios Run: {result.testsRun}")
    print(f"  Passed: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"  Failures: {len(result.failures)}")
    print(f"  Errors: {len(result.errors)}")
    print("=" * 70)

    if not result.wasSuccessful():
        print("\n❌ SOME E2E TESTS FAILED!")
        print("Diagnostic screenshots and traces saved to: artifacts/e2e_reports/")
        sys.exit(1)
    else:
        print("\n✅ ALL 20 REAL BROWSER E2E SCENARIOS PASSED SUCCESSFULLY!")
        sys.exit(0)


if __name__ == "__main__":
    run_e2e()
