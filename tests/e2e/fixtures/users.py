"""
User Fixtures and StorageState Session Pooling
"""

from pathlib import Path
from typing import Dict, Any
from playwright.sync_api import Browser, BrowserContext, Page
from tests.e2e.config import USERS, DESKTOP_VIEWPORT
from tests.e2e.pages.login_page import LoginPage

STORAGE_DIR = Path("/tmp/e2e_storage_states")
STORAGE_DIR.mkdir(parents=True, exist_ok=True)


def get_user_credentials(role_key: str) -> Dict[str, Any]:
    if role_key not in USERS:
        raise ValueError(f"Unknown role key '{role_key}'. Available: {list(USERS.keys())}")
    return USERS[role_key]


def get_authenticated_context(browser: Browser, role_key: str, viewport: Dict[str, int] = None) -> BrowserContext:
    user_info = get_user_credentials(role_key)
    username = user_info["username"]
    password = user_info["password"]
    state_file = STORAGE_DIR / f"state_{role_key}_{username}.json"

    vp = viewport or DESKTOP_VIEWPORT

    if state_file.exists():
        try:
            context = browser.new_context(storage_state=str(state_file), viewport=vp)
            return context
        except Exception:
            state_file.unlink(missing_ok=True)

    # First time login to establish session
    temp_context = browser.new_context(viewport=vp)
    page = temp_context.new_page()
    login_page = LoginPage(page)
    login_page.login(username, password)
    
    # Save session cookies & local storage
    temp_context.storage_state(path=str(state_file))
    temp_context.close()

    # Return clean context with saved state
    return browser.new_context(storage_state=str(state_file), viewport=vp)


def get_authenticated_page(browser: Browser, role_key: str, viewport: Dict[str, int] = None) -> Page:
    context = get_authenticated_context(browser, role_key, viewport)
    return context.new_page()


class UserManager:
    @staticmethod
    def get_storage_context(browser: Browser, role_key: str, viewport: Dict[str, int] = None) -> BrowserContext:
        return get_authenticated_context(browser, role_key, viewport)

    @staticmethod
    def get_storage_page(browser: Browser, role_key: str, viewport: Dict[str, int] = None) -> Page:
        return get_authenticated_page(browser, role_key, viewport)
