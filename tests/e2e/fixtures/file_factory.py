"""
Test File Factory and Deterministic Cleanup Fixture
"""

import time
import subprocess
from pathlib import Path

TEMP_DIR = Path("/tmp/e2e_test_files")
TEMP_DIR.mkdir(parents=True, exist_ok=True)


def create_test_file(prefix: str = "e2e_sample", extension: str = "txt", size_kb: int = 4) -> Path:
    ts = int(time.time() * 1000)
    filename = f"{prefix}_{ts}.{extension}"
    file_path = TEMP_DIR / filename
    
    content = f"Enterprise Archive System E2E Automated Test Payload - {ts}\n" * (size_kb * 10)
    file_path.write_text(content, encoding="utf-8")
    return file_path


def cleanup_temp_files():
    for f in TEMP_DIR.glob("*"):
        try:
            if f.is_file():
                f.unlink()
        except Exception:
            pass


def cleanup_remote_test_folder(folder_name: str, admin_user: str = "admin", admin_pass: str = "Secure_Admin_Password_123!"):
    cmd = [
        "curl", "-s", "-u", f"{admin_user}:{admin_pass}",
        "-X", "DELETE",
        f"http://127.0.0.1/remote.php/dav/files/{admin_user}/Enterprise_Archive/{folder_name}"
    ]
    try:
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        pass


class FileFactory:
    def __init__(self):
        self.created_files = []

    def create_txt_file(self, filename: str, content: str = "") -> Path:
        file_path = TEMP_DIR / filename
        file_path.write_text(content or "Enterprise Archive E2E payload", encoding="utf-8")
        self.created_files.append(file_path)
        return file_path

    def cleanup(self):
        for f in self.created_files:
            try:
                if f.exists():
                    f.unlink()
            except Exception:
                pass
        cleanup_temp_files()
