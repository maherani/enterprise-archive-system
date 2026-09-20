"""
E2E Test Configuration for Enterprise Archive System
"""

import os
from pathlib import Path

# Base Paths
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
E2E_ROOT = Path(__file__).resolve().parent
ARTIFACTS_DIR = PROJECT_ROOT / "artifacts" / "e2e_reports"
SCREENSHOTS_DIR = ARTIFACTS_DIR / "screenshots"
TRACES_DIR = ARTIFACTS_DIR / "traces"

# Ensure diagnostic directories exist
SCREENSHOTS_DIR.mkdir(parents=True, exist_ok=True)
TRACES_DIR.mkdir(parents=True, exist_ok=True)

# Nextcloud URLs
BASE_URL = os.environ.get("NEXTCLOUD_URL", "http://127.0.0.1")
LOGIN_URL = f"{BASE_URL}/index.php/login"
PORTAL_URL = f"{BASE_URL}/index.php/apps/archive_autotag/"
SWAGGER_URL = f"{BASE_URL}/index.php/apps/archive_autotag/api/docs"
FILES_URL = f"{BASE_URL}/index.php/apps/files/"

# Timeouts (milliseconds)
DEFAULT_TIMEOUT = 12000
NAVIGATION_TIMEOUT = 15000
ACTION_TIMEOUT = 8000

# Viewports
DESKTOP_VIEWPORT = {"width": 1280, "height": 800}
MOBILE_VIEWPORT = {"width": 375, "height": 812}

# Test Users and Passwords
USERS = {
    "admin": {
        "username": "admin",
        "password": "Secure_Admin_Password_123!",
        "role": "Super Admin",
        "groups": ["admin"],
    },
    "soc_admin": {
        "username": "Bakbari",
        "password": "User_Password_123!",
        "role": "SOC Group Admin",
        "groups": ["SOC"],
    },
    "cert_admin": {
        "username": "maherani",
        "password": "User_Password_123!",
        "role": "CERT Group Admin",
        "groups": ["CERT"],
    },
    "regular_cert": {
        "username": "Adli",
        "password": "User_Password_123!",
        "role": "Regular CERT User",
        "groups": ["CERT"],
    },
    "compliance_user": {
        "username": "archive_user1",
        "password": "User_Password_123!",
        "role": "Compliance Officer",
        "groups": ["Compliance_Unit"],
    },
    "regular_soc": {
        "username": "soc_reg_user",
        "password": "User_Password_123!",
        "role": "Regular SOC User",
        "groups": ["SOC"],
    }
}
