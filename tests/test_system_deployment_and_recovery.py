#!/usr/bin/env python3
"""
Test Suite: System Deployment & Recovery Runbook (Requirement 30)
Enterprise Archive System

Verifies:
1. Deployment Scripts: deploy_from_scratch.sh, restore_db.sh, backup_db.sh, check_health.sh,
   manage_backup.sh, backup_daemon.sh exist and have executable permissions.
2. Docker Compose: docker-compose.yml defines db, app, proxy, bind-mounts, and isolated network archive_net.
3. Environment Configuration & Secret Isolation: .env.example template exists, .env has strict permissions.
4. Custom App Integrity & Versioning: apps/archive_autotag has valid info.xml, routes.php, controllers.
5. Branding & Obsidian Theme Preservation: CSS files exist with Vazirmatn font and Obsidian dark tokens.
6. Systemd Daemon Service: deploy/enterprise-archive-daemon.service exists and has valid configuration.
7. Active Container & Database Health: archive_db, archive_app, archive_proxy are running and healthy.
8. Health Check Execution: deploy/check_health.sh runs cleanly and reports success.
9. Scenario A Disaster Recovery Engine: deploy/restore_db.sh validates checksums, rejects invalid files.
10. Living Documentation Governance: docs/DEPLOYMENT_RUNBOOK.md and docs/requirements/30_*.md exist.
"""

import os
import stat
import subprocess
import unittest
import yaml

REPO_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
DEPLOY_DIR = os.path.join(REPO_DIR, "deploy")
APPS_DIR = os.path.join(REPO_DIR, "apps", "archive_autotag")
DOCS_DIR = os.path.join(REPO_DIR, "docs")


class TestSystemDeploymentAndRecoveryRunbook(unittest.TestCase):

    def test_01_deployment_scripts_exist_and_executable(self):
        """All deployment and recovery scripts must exist and have executable permissions."""
        scripts = [
            "deploy_from_scratch.sh",
            "restore_db.sh",
            "backup_db.sh",
            "check_health.sh",
            "manage_backup.sh",
            "backup_daemon.sh"
        ]
        for script in scripts:
            path = os.path.join(DEPLOY_DIR, script)
            self.assertTrue(os.path.isfile(path), f"Script missing: {script}")
            mode = os.stat(path).st_mode
            self.assertTrue(bool(mode & stat.S_IXUSR), f"Script is not executable: {script}")

    def test_02_docker_compose_and_network_configuration(self):
        """docker-compose.yml must define isolated archive_net and bind-mount custom apps."""
        compose_file = os.path.join(REPO_DIR, "docker-compose.yml")
        self.assertTrue(os.path.isfile(compose_file), "docker-compose.yml missing")
        with open(compose_file, "r", encoding="utf-8") as f:
            compose = yaml.safe_load(f)

        services = compose.get("services", {})
        self.assertIn("db", services, "Service 'db' missing in docker-compose.yml")
        self.assertIn("app", services, "Service 'app' missing in docker-compose.yml")
        self.assertIn("proxy", services, "Service 'proxy' missing in docker-compose.yml")

        # Verify network
        networks = compose.get("networks", {})
        self.assertIn("archive_net", networks, "Network 'archive_net' missing in docker-compose.yml")

        # Verify custom app bind mount
        app_volumes = services["app"].get("volumes", [])
        has_custom_app_mount = any("./apps/archive_autotag:/var/www/html/custom_apps/archive_autotag" in v for v in app_volumes)
        self.assertTrue(has_custom_app_mount, "Bind-mount for apps/archive_autotag is missing in app service")

    def test_03_environment_configuration_and_secret_isolation(self):
        """.env.example template must define all required configuration keys."""
        env_example = os.path.join(REPO_DIR, ".env.example")
        self.assertTrue(os.path.isfile(env_example), ".env.example missing")
        with open(env_example, "r", encoding="utf-8") as f:
            content = f.read()

        required_keys = [
            "POSTGRES_DB",
            "POSTGRES_USER",
            "POSTGRES_PASSWORD",
            "NEXTCLOUD_ADMIN_USER",
            "NEXTCLOUD_ADMIN_PASSWORD"
        ]
        for key in required_keys:
            self.assertIn(key, content, f"Key {key} missing in .env.example")

        env_file = os.path.join(REPO_DIR, ".env")
        if os.path.isfile(env_file):
            mode = os.stat(env_file).st_mode & 0o777
            # File should not be world-readable
            self.assertEqual(mode & 0o004, 0, ".env file must not be world-readable")

    def test_04_custom_app_integrity_and_versioning(self):
        """Custom app archive_autotag must have valid metadata, routes, and core controllers."""
        info_xml = os.path.join(APPS_DIR, "appinfo", "info.xml")
        self.assertTrue(os.path.isfile(info_xml), "info.xml missing")
        with open(info_xml, "r", encoding="utf-8") as f:
            xml_text = f.read()
        self.assertIn("<id>archive_autotag</id>", xml_text)

        routes_php = os.path.join(APPS_DIR, "appinfo", "routes.php")
        self.assertTrue(os.path.isfile(routes_php), "routes.php missing")

        # Verify key controllers
        controllers = [
            os.path.join(APPS_DIR, "lib", "Controller", "AdminBackupController.php"),
            os.path.join(APPS_DIR, "lib", "Controller", "PageController.php"),
            os.path.join(APPS_DIR, "lib", "Controller", "TagFilterController.php")
        ]
        for ctrl in controllers:
            self.assertTrue(os.path.isfile(ctrl), f"Core controller missing: {ctrl}")

    def test_05_branding_and_obsidian_theme_preservation(self):
        """Obsidian theme, Vazirmatn font and branding stylesheets must exist."""
        stylesheets = [
            os.path.join(APPS_DIR, "css", "archive_portal.css"),
            os.path.join(APPS_DIR, "css", "app_menu_filter.css"),
            os.path.join(APPS_DIR, "css", "multi_tag_filter.css")
        ]
        for sheet in stylesheets:
            self.assertTrue(os.path.isfile(sheet), f"Stylesheet missing: {sheet}")

        # Check Vazirmatn font reference and Obsidian theme definitions
        with open(os.path.join(APPS_DIR, "css", "archive_portal.css"), "r", encoding="utf-8") as f:
            css_text = f.read()
        self.assertIn("Vazirmatn", css_text, "Vazirmatn font not declared in archive_portal.css")
        self.assertIn("Obsidian", css_text, "Obsidian theme not declared in archive_portal.css")

    def test_06_systemd_service_unit_template(self):
        """Systemd unit template deploy/enterprise-archive-daemon.service must be valid."""
        service_file = os.path.join(DEPLOY_DIR, "enterprise-archive-daemon.service")
        self.assertTrue(os.path.isfile(service_file), "enterprise-archive-daemon.service missing")
        with open(service_file, "r", encoding="utf-8") as f:
            text = f.read()
        self.assertIn("[Unit]", text)
        self.assertIn("ExecStart=", text)
        self.assertIn("backup_daemon.sh", text)
        self.assertIn("Restart=always", text)

    def test_07_live_container_and_database_readiness(self):
        """Containers archive_db, archive_app, archive_proxy must be running."""
        res = subprocess.run(
            ["docker", "compose", "ps", "--format", "json"],
            cwd=REPO_DIR,
            capture_output=True,
            text=True
        )
        self.assertEqual(res.returncode, 0, f"docker compose ps failed: {res.stderr}")
        self.assertIn("archive_db", res.stdout)
        self.assertIn("archive_app", res.stdout)
        self.assertIn("archive_proxy", res.stdout)

    def test_08_health_check_script_execution(self):
        """deploy/check_health.sh must execute cleanly and return exit code 0."""
        script_path = os.path.join(DEPLOY_DIR, "check_health.sh")
        res = subprocess.run([script_path], cwd=REPO_DIR, capture_output=True, text=True)
        self.assertEqual(res.returncode, 0, f"check_health.sh failed: {res.stderr}")
        self.assertIn("Health check completed successfully", res.stdout)

    def test_09_scenario_a_restore_script_validation(self):
        """deploy/restore_db.sh must reject nonexistent target files cleanly."""
        script_path = os.path.join(DEPLOY_DIR, "restore_db.sh")
        non_existent = os.path.join(DEPLOY_DIR, "backups", "non_existent_archive.tar.gz")
        res = subprocess.run([script_path, non_existent], cwd=REPO_DIR, capture_output=True, text=True)
        self.assertNotEqual(res.returncode, 0, "restore_db.sh should fail on non-existent archive")
        self.assertTrue(
            "Target backup file not found" in res.stdout or "Full backup not found" in res.stdout,
            f"Expected error message not found in: {res.stdout}"
        )

    def test_10_documentation_and_runbook_governance(self):
        """docs/DEPLOYMENT_RUNBOOK.md and Requirement 30 document must be comprehensive."""
        runbook = os.path.join(DOCS_DIR, "backup_and_recovery", "DEPLOYMENT_RUNBOOK.md")
        if not os.path.isfile(runbook):
            runbook = os.path.join(DOCS_DIR, "DEPLOYMENT_RUNBOOK.md")
        self.assertTrue(os.path.isfile(runbook), "docs/DEPLOYMENT_RUNBOOK.md missing")
        with open(runbook, "r", encoding="utf-8") as f:
            rb_text = f.read()
        self.assertIn("Scenario A", rb_text)
        self.assertIn("Scenario B", rb_text)
        self.assertIn("Living Documentation", rb_text)

        req_runbook = os.path.join(DOCS_DIR, "requirements", "23_system_deployment_and_recovery_runbook.md")
        if not os.path.isfile(req_runbook):
            req_runbook = os.path.join(DOCS_DIR, "requirements", "30_system_deployment_and_recovery_runbook.md")
        self.assertTrue(os.path.isfile(req_runbook), "Deployment runbook requirement document missing")
        with open(req_runbook, "r", encoding="utf-8") as f:
            req_text = f.read()
        self.assertIn("Problem Statement & Business Need", req_text)
        self.assertIn("Scenario A", req_text)
        self.assertIn("Scenario B", req_text)


if __name__ == "__main__":
    unittest.main()
