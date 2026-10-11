import getpass
import os
import sys
from playwright.sync_api import sync_playwright

BASE_URL = os.environ.get("PORTAL_TEST_URL", "http://localhost").rstrip("/")

USERS = [
    ("Regular User", os.environ.get("PORTAL_USER_A_TEST_USER", "test_user_a")),
    ("Group Admin", os.environ.get("PORTAL_GROUP_ADMIN_TEST_USER", "Admin_user_b")),
    ("Primary System Admin", os.environ.get("PORTAL_ADMIN_TEST_USER", "admin")),
]

PROTECTED_PATHS = [
    "/index.php/settings/users",
    "/index.php/settings/user",
    "/index.php/settings/user/security",
    "/ocs/v2.php/cloud/users?format=json",
    "/ocs/v2.php/cloud/users/details?format=json",
    "/ocs/v2.php/cloud/users/disabled?format=json",
    "/ocs/v2.php/cloud/users/test_user_b?format=json",
    "/ocs/v2.php/cloud/users/test_user_b/groups?format=json",
    "/ocs/v2.php/cloud/users/test_user_b/groups/details?format=json",
    "/ocs/v2.php/cloud/groups?format=json",
    "/ocs/v2.php/cloud/groups/details?format=json",
    "/ocs/v2.php/cloud/groups/Group%20B?format=json",
    "/ocs/v2.php/cloud/groups/Group%20B/users?format=json",
    "/ocs/v2.php/cloud/groups/Group%20B/users/details?format=json",
]

results = []


def record(name, passed, detail=""):
    status = "PASS" if passed else "FAIL"
    results.append((status, name, detail))
    print(f"[{status}] {name}" + (f": {detail}" if detail else ""))


def visible_menu_links(page):
    return page.evaluate("""() => {
        const nodes = Array.from(document.querySelectorAll(
            '#user-menu a, .account-menu a, .user-menu a'
        ));
        return nodes.filter(a => a.offsetParent !== null).map(a => ({
            id: (a.id || '').toLowerCase(),
            href: (a.getAttribute('href') || '').toLowerCase(),
            text: (a.innerText || a.getAttribute('aria-label') || '')
                .trim().toLowerCase()
        }));
    }""")


def get_status(page, path):
    return page.evaluate("""async (path) => {
        try {
            const response = await fetch(path, {
                method: 'GET',
                headers: {
                    'OCS-APIRequest': 'true',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            return response.status;
        } catch (_) {
            return 0;
        }
    }""", path)


def run():
    passwords = {}
    for role, username in USERS:
        env_key = {
            "Regular User": "PORTAL_USER_A_TEST_PASSWORD",
            "Group Admin": "PORTAL_GROUP_ADMIN_TEST_PASSWORD",
            "Primary System Admin": "PORTAL_ADMIN_TEST_PASSWORD",
        }[role]
        password = os.environ.get(env_key)
        if not password:
            password = getpass.getpass(f"Current password for {role} ({username}): ")
        if not password:
            print(f"STOP: No password supplied for {role}; no login attempted.")
            return False
        passwords[role] = password

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)

        for role, username in USERS:
            print(f"\n--- {role} ({username}) ---")
            context = browser.new_context()
            page = context.new_page()
            page.set_default_timeout(8000)

            try:
                page.goto(f"{BASE_URL}/login")
                page.locator("#user").fill(username)
                page.locator("#password").fill(passwords[role])
                page.locator("button[type='submit'], #submit-form").first.click()
                page.wait_for_selector("#archive-portal-root", timeout=15000)

                current_uid = page.evaluate("""async () => {
                    const r = await fetch('/ocs/v2.php/cloud/user?format=json', {
                        headers: {'OCS-APIRequest': 'true', 'Accept': 'application/json'}
                    });
                    const j = await r.json();
                    return j && j.ocs && j.ocs.data ? j.ocs.data.id : null;
                }""")
                record(f"{role} - Login and UID verification",
                       current_uid == username, f"UID returned: {current_uid}")

                if current_uid != username:
                    context.close()
                    continue

                # Read-only role state from server-provided app state.
                is_primary = page.evaluate("""() => {
                    try {
                        return window.OCP?.InitialState?.loadState(
                            'archive_autotag', 'is_admin'
                        ) === true;
                    } catch (_) {
                        return false;
                    }
                }""")
                record(f"{role} - Primary admin state",
                       is_primary == (role == "Primary System Admin"),
                       f"is_admin={is_primary}")

                # Check menu without clicking actions or submitting forms.
                trigger = page.locator(
                    "#user-menu button, .avatardiv, #settings .avatardiv"
                ).first
                try:
                    if trigger.is_visible():
                        trigger.click()
                        page.wait_for_timeout(300)
                        links = visible_menu_links(page)

                        def matches(item, terms):
                            joined = " ".join(
                                [item["id"], item["href"], item["text"]]
                            )
                            return any(term in joined for term in terms)

                        settings_items = [
                            x for x in links
                            if matches(x, ["settings", "تنظیمات"])
                        ]
                        logout_items = [
                            x for x in links
                            if matches(x, ["logout", "log out", "/logout", "خروج"])
                        ]
                        profile_items = [
                            x for x in links
                            if matches(x, ["profile", "/u/"])
                        ]
                        accounts_items = [
                            x for x in links
                            if matches(x, ["core_users", "/settings/users", "accounts"])
                        ]

                        record(f"{role} - Settings menu visible",
                               bool(settings_items))
                        record(f"{role} - Logout menu visible",
                               bool(logout_items))

                        if role != "Primary System Admin":
                            record(f"{role} - Profile hidden",
                                   not bool(profile_items))
                            record(f"{role} - Accounts hidden",
                                   not bool(accounts_items))
                            record(f"{role} - No other visible menu entries",
                                   all(matches(x, [
                                       "settings", "تنظیمات",
                                       "logout", "log out", "/logout", "خروج"
                                   ]) for x in links),
                                   f"Visible link count: {len(links)}")
                except Exception as exc:
                    record(f"{role} - Menu inspection", False, str(exc))

                # Read-only access checks. Do not submit POST, PUT, PATCH or DELETE.
                expected = 200 if role == "Primary System Admin" else 403
                for path in PROTECTED_PATHS:
                    status = get_status(page, path)
                    record(
                        f"{role} - GET {path}",
                        status == expected,
                        f"HTTP {status}; expected {expected}"
                    )

            except Exception as exc:
                record(f"{role} - Safe audit execution", False, str(exc))
            finally:
                context.close()

        browser.close()

    print("\n--- READ-ONLY AUDIT SUMMARY ---")
    for status, name, detail in results:
        print(f"[{status}] {name}" + (f": {detail}" if detail else ""))

    failed = [r for r in results if r[0] == "FAIL"]
    print(f"\nTotal checks: {len(results)}; PASS: {len(results)-len(failed)}; FAIL: {len(failed)}")
    return len(failed) == 0


if __name__ == "__main__":
    sys.exit(0 if run() else 1)
