"""
Diagnostic Failure Reporter for E2E Tests
Captures full-page screenshot and Playwright trace on failure.
"""

import time
import traceback
from contextlib import contextmanager
from typing import Generator
from playwright.sync_api import Page, BrowserContext
from tests.e2e.config import SCREENSHOTS_DIR, TRACES_DIR


@contextmanager
def capture_failure(page: Page, test_name: str) -> Generator[None, None, None]:
    context: BrowserContext = page.context
    try:
        context.tracing.start(screenshots=True, snapshots=True, sources=True)
    except Exception:
        pass

    try:
        yield
    except Exception as exc:
        timestamp = int(time.time())
        screenshot_path = SCREENSHOTS_DIR / f"FAIL_{test_name}_{timestamp}.png"
        trace_path = TRACES_DIR / f"FAIL_{test_name}_{timestamp}_trace.zip"
        
        try:
            page.screenshot(path=str(screenshot_path), full_page=True)
            print(f"\n[FAILURE DIAGNOSTIC] Saved failure screenshot to: {screenshot_path}")
        except Exception as se:
            print(f"\n[FAILURE DIAGNOSTIC] Could not capture screenshot: {se}")

        try:
            context.tracing.stop(path=str(trace_path))
            print(f"[FAILURE DIAGNOSTIC] Saved Playwright trace to: {trace_path}")
        except Exception as te:
            print(f"[FAILURE DIAGNOSTIC] Could not save trace: {te}")

        raise exc
    else:
        try:
            context.tracing.stop()
        except Exception:
            pass

failure_diagnostics = capture_failure
