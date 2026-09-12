"""
Comprehensive Automated Test Suite: Strict User Account Governance & Role Boundaries

Validates:
1. System Administrator has full authority (can modify and delete accounts).
2. Group Administrator CAN modify users within their assigned group.
3. Group Administrator is BLOCKED from modifying users outside their assigned group.
4. Group Administrator is BLOCKED from deleting users (restricted exclusively to System Admin).
5. Regular User is BLOCKED from modifying or deleting any user account.
"""

import subprocess
import time
import requests
from requests.auth import HTTPBasicAuth

NEXTCLOUD_URL = "http://localhost"
ADMIN_USER = "admin"
ADMIN_PASS = "Secure_Admin_Password_123!"

GA_USER = "alpha_admin"
GA_PASS = "Pass_Alpha_Admin_123!"

ALPHA_MEMBER = "alpha_member"
ALPHA_MEMBER_PASS = "Pass_Alpha_Mem_123!"

BETA_MEMBER = "beta_member"
BETA_MEMBER_PASS = "Pass_Beta_Mem_123!"

REG_USER = "regular_test_user"
REG_PASS = "Pass_Reg_User_123!"

GROUP_A = "Gov_Group_Alpha"
GROUP_B = "Gov_Group_Beta"

admin_auth = HTTPBasicAuth(ADMIN_USER, ADMIN_PASS)
ga_auth = HTTPBasicAuth(GA_USER, GA_PASS)
reg_auth = HTTPBasicAuth(REG_USER, REG_PASS)
headers = {"OCS-APIRequest": "true", "Accept": "application/json"}


def run_occ(cmd):
    full_cmd = f"docker exec -u www-data archive_app php occ {cmd}"
    subprocess.run(full_cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def create_user(username, password):
    full_cmd = f"docker exec -e OC_PASS='{password}' archive_app php occ user:add --password-from-env --display-name='{username}' {username}"
    subprocess.run(full_cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def run_sql(sql):
    full_cmd = f"docker exec archive_db psql -U nextcloud_user -d nextcloud -c \"{sql}\""
    subprocess.run(full_cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def setup_test_entities():
    print("[Setup] Provisioning test groups and users...")
    teardown_test_entities()

    # Create groups
    run_occ(f"group:add {GROUP_A}")
    run_occ(f"group:add {GROUP_B}")

    # Create users
    for u, p in [(GA_USER, GA_PASS), (ALPHA_MEMBER, ALPHA_MEMBER_PASS), (BETA_MEMBER, BETA_MEMBER_PASS), (REG_USER, REG_PASS)]:
        create_user(u, p)

    # Assign group memberships
    run_occ(f"group:adduser {GROUP_A} {GA_USER}")
    run_occ(f"group:adduser {GROUP_A} {ALPHA_MEMBER}")
    run_occ(f"group:adduser {GROUP_B} {BETA_MEMBER}")
    run_occ(f"group:adduser {GROUP_B} {REG_USER}")

    # Assign GA_USER as Subadmin (Group Admin) of GROUP_A
    run_sql(f"INSERT INTO oc_group_admin (gid, uid) VALUES ('{GROUP_A}', '{GA_USER}');")

    time.sleep(1)
    print("  ✓ Setup completed.")


def teardown_test_entities():
    # Cleanup test users and groups
    for u in [GA_USER, ALPHA_MEMBER, BETA_MEMBER, REG_USER, "sys_del_target"]:
        run_occ(f"user:delete {u}")
    for g in [GROUP_A, GROUP_B]:
        run_occ(f"group:delete {g}")


def run_tests():
    print("==================================================================")
    print(" STARTING USER ACCOUNT GOVERNANCE & ROLE BOUNDARY VERIFICATION")
    print("==================================================================")

    setup_test_entities()

    try:
        # ------------------------------------------------------------------
        # Test 1: System Admin has full authority (Modify + Delete)
        # ------------------------------------------------------------------
        print("\n[Test 1] Verifying System Admin Authority...")
        # 1.1 Modify user display name
        r_mod = requests.put(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            data={"key": "displayname", "value": "Alpha Member (Admin Updated)"},
            headers=headers,
            auth=admin_auth
        )
        assert r_mod.status_code == 200 and r_mod.json()["ocs"]["meta"]["statuscode"] in (100, 200), \
            f"Admin update failed: {r_mod.text}"
        print("  ✓ System Admin successfully modified user details.")

        # 1.2 Admin can delete accounts
        create_user("sys_del_target", "Temp_Password_123!")
        r_del = requests.delete(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/sys_del_target",
            headers=headers,
            auth=admin_auth
        )
        assert r_del.status_code == 200 and r_del.json()["ocs"]["meta"]["statuscode"] in (100, 200), \
            f"Admin delete failed: {r_del.text}"
        print("  ✓ System Admin successfully deleted user account.")

        # ------------------------------------------------------------------
        # Test 2: Group Admin CAN modify users in their own group
        # ------------------------------------------------------------------
        print("\n[Test 2] Verifying Group Admin permissions within own group...")
        r_ga_mod = requests.put(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            data={"key": "displayname", "value": "Alpha Member (GA Updated)"},
            headers=headers,
            auth=ga_auth
        )
        assert r_ga_mod.status_code == 200 and r_ga_mod.json()["ocs"]["meta"]["statuscode"] in (100, 200), \
            f"Group Admin failed to modify member in own group: {r_ga_mod.text}"
        print("  ✓ Group Admin successfully modified member in own group.")

        # ------------------------------------------------------------------
        # Test 3: Group Admin is BLOCKED from modifying users outside group
        # ------------------------------------------------------------------
        print("\n[Test 3] Verifying Group Admin isolation from other groups...")
        r_ga_cross = requests.put(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{BETA_MEMBER}",
            data={"key": "displayname", "value": "Illegitimate Update"},
            headers=headers,
            auth=ga_auth
        )
        status_code = r_ga_cross.json().get("ocs", {}).get("meta", {}).get("statuscode", 0)
        assert status_code != 100 and status_code != 200, \
            f"Expected rejection, but Group Admin was able to modify user in another group: {r_ga_cross.text}"
        print(f"  ✓ PASSED: Group Admin blocked from modifying external user (Status code: {status_code}).")

        # ------------------------------------------------------------------
        # Test 4: Group Admin is BLOCKED from deleting users (Admin-only delete)
        # ------------------------------------------------------------------
        print("\n[Test 4] Verifying Group Admin deletion restriction (Admin-only deletion policy)...")
        r_ga_del = requests.delete(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            headers=headers,
            auth=ga_auth
        )
        meta = r_ga_del.json().get("ocs", {}).get("meta", {})
        status_code = meta.get("statuscode", 0)
        msg = meta.get("message", "")
        assert status_code not in (100, 200), \
            f"Expected deletion rejection for Group Admin, got success: {r_ga_del.text}"
        
        # Verify user was NOT deleted
        r_check = requests.get(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            headers=headers,
            auth=admin_auth
        )
        assert r_check.status_code == 200 and r_check.json()["ocs"]["meta"]["statuscode"] in (100, 200), \
            "Target user was deleted despite restriction!"
        print(f"  ✓ PASSED: Group Admin blocked from deleting user (Response: {msg or status_code}).")

        # ------------------------------------------------------------------
        # Test 5: Regular user is BLOCKED from modifying or deleting accounts
        # ------------------------------------------------------------------
        print("\n[Test 5] Verifying Regular User has ZERO account management privileges...")
        # 5.1 Modify attempt
        r_reg_mod = requests.put(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            data={"key": "displayname", "value": "Hacked Display Name"},
            headers=headers,
            auth=reg_auth
        )
        reg_mod_status = r_reg_mod.json().get("ocs", {}).get("meta", {}).get("statuscode", 0)
        assert reg_mod_status not in (100, 200), f"Regular user unexpectedly modified account: {r_reg_mod.text}"
        print(f"  ✓ PASSED: Regular user blocked from modifying accounts (Status code: {reg_mod_status}).")

        # 5.2 Delete attempt
        r_reg_del = requests.delete(
            f"{NEXTCLOUD_URL}/ocs/v1.php/cloud/users/{ALPHA_MEMBER}",
            headers=headers,
            auth=reg_auth
        )
        reg_del_status = r_reg_del.json().get("ocs", {}).get("meta", {}).get("statuscode", 0)
        assert reg_del_status not in (100, 200), f"Regular user unexpectedly deleted account: {r_reg_del.text}"
        print(f"  ✓ PASSED: Regular user blocked from deleting accounts (Status code: {reg_del_status}).")

        print("\n==================================================================")
        print(" ALL 5 USER GOVERNANCE & ROLE BOUNDARY TESTS PASSED (100%)")
        print("==================================================================")

    finally:
        print("\n[Teardown] Cleaning up temporary test entities...")
        teardown_test_entities()
        print("  ✓ Teardown completed.")


if __name__ == "__main__":
    run_tests()
