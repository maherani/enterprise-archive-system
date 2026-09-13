# File Access Control & Dynamic Tag Isolation Architecture (ACL)

## Overview & Security Requirements

This document specifies the architecture, database schema, enforcement mechanisms, and administrative workflows for **Per-User File Access Control** and **Dynamic Tag Isolation** within the Nextcloud Enterprise Archive System (`archive_autotag v1.4.0`).

### Core Security Mandates:
1. **Strict File Isolation**: Every regular user strictly only has access to files they personally uploaded or an administrator explicitly granted them access to.
2. **Strict Tag Isolation**: Every regular user strictly only sees tags they personally created or system/administrator-generated hierarchical tags.
3. **Full Administrator Authority**: System administrators (`admin`) retain 100% full visibility and authority over all uploaded files and generated tags, including viewing, downloading, modifying, granting permissions, or deleting any file or tag.
4. **Zero Data Leakage**: No user may view, query, download, edit, or delete another regular user's files or private tags.

---

## 1. Database Architecture (Migration 1400)

Three relational tables track ownership, grants, and tag creators:

### 1.1. `oc_archive_file_ownership`
Maps files in the archive to the user who uploaded them.
```sql
CREATE TABLE oc_archive_file_ownership (
    id SERIAL PRIMARY KEY,
    file_id BIGINT NOT NULL UNIQUE,
    owner_uid VARCHAR(64) NOT NULL,
    created_at BIGINT NOT NULL
);
CREATE INDEX idx_archive_file_owner ON oc_archive_file_ownership (owner_uid);
```

### 1.2. `oc_archive_file_grants`
Records explicit administrative grants for specific users or groups.
```sql
CREATE TABLE oc_archive_file_grants (
    id SERIAL PRIMARY KEY,
    file_id BIGINT NOT NULL,
    grantee_type VARCHAR(16) NOT NULL, -- 'user' or 'group'
    grantee_id VARCHAR(64) NOT NULL,
    granted_by VARCHAR(64) NOT NULL DEFAULT 'admin',
    permissions INT NOT NULL DEFAULT 31, -- Bitmask: Read, Update, Create, Delete, Share
    created_at BIGINT NOT NULL,
    CONSTRAINT uq_archive_file_grant UNIQUE (file_id, grantee_type, grantee_id)
);
CREATE INDEX idx_archive_file_grants_lookup ON oc_archive_file_grants (file_id, grantee_type, grantee_id);
```

### 1.3. `oc_archive_tag_ownership`
Tracks the creator of every system tag in Nextcloud.
```sql
CREATE TABLE oc_archive_tag_ownership (
    id SERIAL PRIMARY KEY,
    tag_id BIGINT NOT NULL UNIQUE,
    owner_uid VARCHAR(64) NOT NULL, -- 'system', 'admin', or specific user UID
    created_at BIGINT NOT NULL
);
CREATE INDEX idx_archive_tag_owner ON oc_archive_tag_ownership (owner_uid);
```

---

## 2. Enforcement Mechanisms

```text
[ Client (WebDAV / Web UI / REST API) ]
                   ?
                   ?
???????????????????????????????????????????????????????
?              SabreDAV / Nextcloud Kernel            ?
???????????????????????????????????????????????????????
?    File Operations Hook  ?     Tag Manager Hook     ?
? (SabrePluginInitListener)? (IsolatedSystemTagManager?
???????????????????????????????????????????????????????
             ?                           ?
             ?                           ?
????????????????????????????????????????????????????????
?   FileOwnershipService   ??   TagOwnershipService    ?
?  - Is user admin?        ??  - Is tag system/admin?  ?
?  - Is user uploader?     ??  - Is tag owned by user? ?
?  - Explicit admin grant? ??  - Filter visible tag IDs?
????????????????????????????????????????????????????????
             ?                           ?
             ?                           ?
???????????????????????????????????????????????????????
?               PostgreSQL Database                   ?
? (oc_archive_file_ownership, oc_archive_file_grants) ?
? (oc_archive_tag_ownership)                          ?
???????????????????????????????????????????????????????
```

### 2.1. WebDAV / SabreDAV Interception (`SabrePluginInitListener.php`)
- **Directory Listings (`PROPFIND`)**:
  - The listener subscribes to the `propFind` event at priority 50.
  - When evaluating child files inside a collection, it verifies `$fileOwnershipService->canUserAccessFile($fileId, $userId)`.
  - If the user is neither the owner nor an authorized grantee, the listener returns `false`, instructing SabreDAV to omit the file from the multistatus response XML.
- **Direct File Operations (`GET`, `HEAD`, `DELETE`, `PROPPATCH`, `COPY`, `MOVE`, `PROPFIND`, `PUT`)**:
  - Subscribes to `beforeMethod:*` at priority 150 (after tree initialization).
  - Resolves `$server->tree->getNodeForPath($path)`.
  - If the node is a `File` and access is unauthorized, it immediately throws `\Sabre\DAV\Exception\NotFound('File not found')`, returning `HTTP 404 Not Found`.

### 2.2. Tag Manager Interception (`IsolatedSystemTagManager.php`)
- Overrides Nextcloud's core `systemtags.managerFactory` service via `config.php`:
  `'systemtags.managerFactory' => 'OCA\ArchiveAutoTag\SystemTag\IsolatedManagerFactory'`
- **Filtering (`getTags()`, `getTagsByIds()`)**:
  - Intercepts tag queries and restricts results to `$tagOwnershipService->getVisibleTagIds($userId)`.
  - Tags owned by other users are excluded.
- **Modifications & Deletions (`deleteTags()`)**:
  - Regular users attempting to delete or query another user's tag receive `HTTP 404 (Tag with id X not found)`.
  - System administrators retain bypass privileges to view, update, and delete any tag.

### 2.3. REST API (`TagFilterController.php`)
- `/apps/archive_autotag/api/tags`: Returns only tags visible to the authenticated user.
- `/apps/archive_autotag/api/filter`: Performs multi-tag intersection queries and applies `$fileOwnershipService->canUserAccessFile($fileId, $uid)` to every candidate document.

---

## 3. Administrator CLI Commands

### 3.1. File Access Grants (`occ archive:file:grant`)

```bash
# 1. Grant access on a file to a specific user
docker exec -u www-data archive_app php occ archive:file:grant grant 440 api_worker

# 2. Grant access on a file to an entire group
docker exec -u www-data archive_app php occ archive:file:grant grant 440 Compliance_Unit --group

# 3. View active grants and file ownership
docker exec -u www-data archive_app php occ archive:file:grant list 440

# 4. Revoke access from a user
docker exec -u www-data archive_app php occ archive:file:grant revoke 440 api_worker

# 5. Revoke access from a group
docker exec -u www-data archive_app php occ archive:file:grant revoke 440 Compliance_Unit --group

# 6. Reassign file owner
docker exec -u www-data archive_app php occ archive:file:grant set-owner 440 archive_user1
```

### 3.2. Tag Governance (`occ archive:tag:gov`)

```bash
# 1. List all system tags and their respective owners
docker exec -u www-data archive_app php occ archive:tag:gov list

# 2. Filter tag listing by owner UID
docker exec -u www-data archive_app php occ archive:tag:gov list --user=archive_user1

# 3. Delete a tag as administrator
docker exec -u www-data archive_app php occ archive:tag:gov delete 20
```

---

## 4. Automated E2E Verification

The complete isolation lifecycle is verified via `tests/test_archive_acl_and_tag_isolation.py`:

```bash
python3 tests/test_archive_acl_and_tag_isolation.py
```

### Verification Steps:
1. **User A Uploads Confidential File**: User B receives `HTTP 404` on direct `GET`, direct `PROPFIND`, and file is omitted from directory listings.
2. **Admin Authority**: Administrator downloads file (`HTTP 200`) and resolves file ID.
3. **Admin Grant & Revocation**:
   - Admin grants access to User B -> User B can download file (`HTTP 200`) and sees file in directory listing.
   - Admin revokes access -> User B is immediately blocked (`HTTP 404`) and file vanishes from directory listing.
4. **Private Tag Isolation**: User A creates private tag -> User A and Admin see it in `/api/tags`; User B cannot see it in `/api/tags` and receives `HTTP 404` on WebDAV queries.
5. **System Tag Visibility**: System tags (e.g., `Enterprise_Archive`) remain globally visible to all users.
6. **Admin Tag Authority**: Admin deletes User A's private tag (`HTTP 204`).
7. **Admin File Authority**: Admin deletes User A's file (`HTTP 204`).

---

## 5. Dynamic Folder-Driven Tag Lifecycle & Automatic Reconciliation

Since the enterprise archive system tags are driven by folder hierarchy, the tagging engine automatically keeps tag state strictly in sync with folder creation, renaming, and deletion:

### 1. Folder Creation
- When an admin creates a folder (e.g., `Enterprise_Archive/Contracts`), a restricted system tag (`Contracts`) is automatically created (`userVisible=true`, `userAssignable=false`) and registered with ownership `system`.
- Hierarchical ancestor tags (`Enterprise_Archive`) are assigned to the new folder node.

### 2. Folder Rename
- When a folder is renamed (e.g., `Invoices_Archive` -> `Invoices_Verified`):
  - If the old tag name was uniquely used by this folder, the system tag is renamed in-place.
  - If the new tag already exists or the old name was shared, descendant files are retagged with the new tag and unassigned from the old tag.
  - Automatic reconciliation runs to ensure no obsolete tags linger.

### 3. Folder Deletion & Trashbin
- When a folder is deleted or moved to trashbin:
  - Stale object mappings in `oc_systemtag_object_mapping` pointing to deleted or trashbin files (`files_trashbin`) are pruned.
  - The system evaluates all tags: any tag with **0 active folders** and **0 active files** is recognized as a surplus/orphan tag and permanently deleted from `oc_systemtag` and `oc_archive_tag_ownership`.

### 4. Admin Reconciliation Commands
```bash
# Full tag audit and reconciliation (CLI)
docker exec -u www-data archive_app php occ archive:tag:reconcile

# Tag governance sync action
docker exec -u www-data archive_app php occ archive:tag:gov reconcile
```
