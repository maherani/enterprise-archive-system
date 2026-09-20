<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Security\Permission;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use Psr\Log\LoggerInterface;

class CentralPermissionResolver implements IPermissionResolver {
    // In-memory request-level cache for performance
    private array $decisionCache = [];
    private array $userGroupsCache = [];
    private array $ancestorCache = [];
    private array $ownerCache = [];

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly ISystemTagManager $tagManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fast-path boolean check
     */
    public function can(string $userId, int $fileId, int $operation = PermissionOperation::READ): bool {
        return $this->evaluateFile($userId, $fileId, $operation)->allowed;
    }

    /**
     * Evaluate comprehensive permission on a file resource
     */
    public function evaluateFile(string $userId, int $fileId, int $operation = PermissionOperation::READ): PermissionDecision {
        $cacheKey = "file:{$userId}:{$fileId}:{$operation}";
        if (isset($this->decisionCache[$cacheKey])) {
            return $this->decisionCache[$cacheKey];
        }

        // Rule 0: Deny on invalid input (Fail-Close)
        if ($userId === '' || $fileId <= 0) {
            return $this->cacheDecision($cacheKey, PermissionDecision::deny(
                'DENY_INVALID_INPUT',
                'Invalid user ID or file ID provided.'
            ));
        }

        // Rule 1: Superuser Admin Bypass
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return $this->cacheDecision($cacheKey, PermissionDecision::allow(
                'ADMIN_BYPASS',
                'System administrator has unrestricted superuser privileges.',
                PermissionOperation::ALL
            ));
        }

        // Lookup file metadata in filecache
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'storage', 'path', 'mimetype', 'parent')
           ->from('filecache')
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
        $fileRow = $qb->executeQuery()->fetchAssociative();

        if (!$fileRow) {
            return $this->cacheDecision($cacheKey, PermissionDecision::deny(
                'NOT_FOUND',
                "Resource ID {$fileId} does not exist in archive filecache."
            ));
        }

        $filePath = (string)$fileRow['path'];
        $userGroups = $this->getUserGroups($userId);
        $ancestorIds = $this->getAncestorFolderIds($fileId);
        $owner = $this->getFileOwner($fileId, (int)$fileRow['storage']);

        // Detect Department Group Scope from Path (e.g. Enterprise_Archive/<Department>/...)
        $deptGroup = $this->extractDepartmentFromPath($filePath);

        // Rule 2: Explicit Grants & Revocations (archive_file_grants) - MAC Layer
        $eligibleGrantIds = array_values(array_unique(array_merge([$fileId], $ancestorIds)));
        $gQb = $this->db->getQueryBuilder();
        $orConds = [
            $gQb->expr()->andX(
                $gQb->expr()->eq('grantee_type', $gQb->createNamedParameter('user')),
                $gQb->expr()->eq('grantee_id', $gQb->createNamedParameter($userId))
            )
        ];
        if (!empty($userGroups)) {
            $orConds[] = $gQb->expr()->andX(
                $gQb->expr()->eq('grantee_type', $gQb->createNamedParameter('group')),
                $gQb->expr()->in('grantee_id', $gQb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        $gQb->select('id', 'file_id', 'grantee_type', 'grantee_id', 'permissions')
            ->from('archive_file_grants')
            ->where($gQb->expr()->in('file_id', $gQb->createNamedParameter($eligibleGrantIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($gQb->expr()->orX(...$orConds))
            ->orderBy('id', 'DESC');
        $grantRows = $gQb->executeQuery()->fetchAllAssociative();

        $grantRow = null;
        $isAncestor = false;
        if (!empty($grantRows)) {
            // Direct grant on $fileId takes priority over ancestor grants
            foreach ($grantRows as $r) {
                if ((int)$r['file_id'] === $fileId) {
                    $grantRow = $r;
                    $isAncestor = false;
                    break;
                }
            }
            if ($grantRow === null) {
                $grantRow = $grantRows[0];
                $isAncestor = true;
            }
        }

        if ($grantRow) {
            $grantedMask = (int)$grantRow['permissions'];

            // Explicit Revocation (permissions = 0)
            if ($grantedMask === 0) {
                return $this->cacheDecision($cacheKey, PermissionDecision::deny(
                    'EXPLICIT_REVOCATION',
                    "Access explicitly revoked by administrator grant #{$grantRow['id']} on object #{$grantRow['file_id']} ({$grantRow['grantee_type']}:{$grantRow['grantee_id']}).",
                    0,
                    ['grant_id' => $grantRow['id'], 'grantee' => "{$grantRow['grantee_type']}:{$grantRow['grantee_id']}"]
                ));
            }

            // Normalize mask: Nextcloud 31 = Read(1) | Update(2) | Create(4) | Delete(8) | Share(16)
            $effectiveMask = $this->normalizeGrantMask($grantedMask);

            if (($effectiveMask & $operation) === $operation) {
                $ruleName = $isAncestor ? 'ANCESTOR_GRANT' : 'EXPLICIT_GRANT';
                return $this->cacheDecision($cacheKey, PermissionDecision::allow(
                    $ruleName,
                    "Authorized via " . ($isAncestor ? "ancestor folder grant" : "explicit grant") . " #{$grantRow['id']} on object #{$grantRow['file_id']} ({$grantRow['grantee_type']}:{$grantRow['grantee_id']}).",
                    $effectiveMask,
                    ['grant_id' => $grantRow['id'], 'grantee' => "{$grantRow['grantee_type']}:{$grantRow['grantee_id']}", 'cascaded' => $isAncestor]
                ));
            } else {
                return $this->cacheDecision($cacheKey, PermissionDecision::deny(
                    'EXPLICIT_GRANT_INSUFFICIENT',
                    "Operation " . PermissionOperation::toString($operation) . " not permitted by grant mask ({$effectiveMask}).",
                    $effectiveMask
                ));
            }
        }

        // Rule 3: File Ownership
        if ($owner !== null && $owner === $userId) {
            // Check Hierarchy Constraint: If file is in a department folder and user was removed from department
            if ($deptGroup !== null && !in_array($deptGroup, $userGroups, true)) {
                return $this->cacheDecision($cacheKey, PermissionDecision::deny(
                    'HIERARCHY_TRUMPS_OWNERSHIP',
                    "User '{$userId}' owns file #{$fileId} but has no membership or grant in department '{$deptGroup}'."
                ));
            }

            return $this->cacheDecision($cacheKey, PermissionDecision::allow(
                'OWNERSHIP',
                "User '{$userId}' is the registered creator and owner of file #{$fileId}.",
                PermissionOperation::READ | PermissionOperation::WRITE | PermissionOperation::DELETE | PermissionOperation::READ_METADATA
            ));
        }

        // Rule 4: Native Nextcloud Shares (oc_share) - DAC Layer
        $sQb = $this->db->getQueryBuilder();
        $shareSourceIds = [(string)$fileId];
        if ($owner === 'admin' || $owner === 'system' || $owner === null) {
            foreach ($ancestorIds as $aid) {
                $shareSourceIds[] = (string)$aid;
            }
        }
        $sOrConds = [
            $sQb->expr()->andX(
                $sQb->expr()->in('share_type', $sQb->createNamedParameter([0, 2], IQueryBuilder::PARAM_INT_ARRAY)),
                $sQb->expr()->eq('share_with', $sQb->createNamedParameter($userId))
            )
        ];
        if (!empty($userGroups)) {
            $sOrConds[] = $sQb->expr()->andX(
                $sQb->expr()->eq('share_type', $sQb->createNamedParameter(1)),
                $sQb->expr()->in('share_with', $sQb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        $sQb->select('id', 'item_source', 'share_type', 'share_with', 'permissions')
            ->from('share')
            ->where($sQb->expr()->in('item_source', $sQb->createNamedParameter($shareSourceIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($sQb->expr()->orX(...$sOrConds))
            ->orderBy('id', 'DESC');
        $shareRow = $sQb->executeQuery()->fetchAssociative();

        if ($shareRow) {
            $shareMask = $this->normalizeGrantMask((int)$shareRow['permissions']);
            if (($shareMask & $operation) === $operation) {
                return $this->cacheDecision($cacheKey, PermissionDecision::allow(
                    'SHARE',
                    "Authorized via Nextcloud share #{$shareRow['id']} on item #{$shareRow['item_source']}.",
                    $shareMask,
                    ['share_id' => $shareRow['id']]
                ));
            }
        }

        // Rule 5: Department Group Scope
        // If file resides in Enterprise_Archive/<DepartmentGroup> and user is an active member
        if ($deptGroup !== null && in_array($deptGroup, $userGroups, true)) {
            $deptMask = PermissionOperation::READ | PermissionOperation::READ_METADATA | PermissionOperation::WRITE | PermissionOperation::CREATE;
            if (($deptMask & $operation) === $operation) {
                return $this->cacheDecision($cacheKey, PermissionDecision::allow(
                    'DEPARTMENT_SCOPE',
                    "User is an active member of department group '{$deptGroup}'.",
                    $deptMask
                ));
            }
        }

        // Rule 6: Deny by Default
        return $this->cacheDecision($cacheKey, PermissionDecision::deny(
            'DENY_BY_DEFAULT',
            "No matching grant, share, or department membership permits " . PermissionOperation::toString($operation) . " on file #{$fileId} for user '{$userId}'."
        ));
    }

    /**
     * Evaluate permission on a directory path
     */
    public function evaluateFolder(string $userId, string $folderPath, int $operation = PermissionOperation::READ): PermissionDecision {
        $cleanPath = trim(trim($folderPath, '/'), '.');

        // Admin Bypass
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return PermissionDecision::allow('ADMIN_BYPASS', 'System admin folder superuser', PermissionOperation::ALL);
        }

        // Archive Root: visible to all authenticated users
        if ($cleanPath === '' || $cleanPath === 'Enterprise_Archive' || strcasecmp($cleanPath, 'Enterprise_Archive') === 0) {
            return PermissionDecision::allow(
                'ARCHIVE_ROOT_DISCOVERY',
                'Enterprise Archive root is accessible for browsing.',
                PermissionOperation::READ | PermissionOperation::READ_METADATA
            );
        }

        $userGroups = $this->getUserGroups($userId);

        // Department folder: Enterprise_Archive/<DeptName>
        $deptGroup = $this->extractDepartmentFromPath($cleanPath);
        if ($deptGroup !== null) {
            // Direct membership check
            if (in_array($deptGroup, $userGroups, true)) {
                $isSubadmin = $this->isUserSubadminOfGroup($userId, $deptGroup);
                $mask = PermissionOperation::READ | PermissionOperation::READ_METADATA | PermissionOperation::WRITE | PermissionOperation::CREATE;
                if ($isSubadmin) {
                    $mask |= PermissionOperation::MANAGE | PermissionOperation::TAG_ASSIGN;
                }
                return PermissionDecision::allow('DEPARTMENT_MEMBERSHIP', "User is member of group '{$deptGroup}'.", $mask);
            }

            // Check if folder has an explicit grant
            $folderFileId = $this->getFileIdByPath($cleanPath);
            if ($folderFileId > 0) {
                $grantDecision = $this->evaluateFile($userId, $folderFileId, $operation);
                if ($grantDecision->allowed) {
                    return $grantDecision;
                }
            }
        }

        return PermissionDecision::deny('DENY_BY_DEFAULT', "Folder '{$folderPath}' is restricted to authorized departments.");
    }

    /**
     * Evaluate tag visibility and management
     */
    public function evaluateTag(string $userId, int $tagId, int $operation = PermissionOperation::READ_METADATA): PermissionDecision {
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return PermissionDecision::allow('ADMIN_BYPASS', 'Admin sees and manages all tags', PermissionOperation::ALL);
        }

        $userGroups = $this->getUserGroups($userId);

        // 1. Group Tags in archive_tag_groups
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_id')
           ->from('archive_tag_groups')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
        $assignedGroups = array_map(fn($r) => (string)$r['group_id'], $qb->executeQuery()->fetchAllAssociative());

        if (!empty($assignedGroups)) {
            $matches = array_intersect($userGroups, $assignedGroups);
            if (!empty($matches)) {
                $matchedGroup = reset($matches);
                $isSubadmin = $this->isUserSubadminOfGroup($userId, $matchedGroup);
                $mask = PermissionOperation::READ_METADATA;
                if ($isSubadmin) {
                    $mask |= PermissionOperation::TAG_ASSIGN | PermissionOperation::MANAGE;
                }
                return PermissionDecision::allow('TAG_GROUP_MATCH', "Tag belongs to group '{$matchedGroup}' where user is member.", $mask);
            } else {
                return PermissionDecision::deny('TAG_CROSS_GROUP_ISOLATION', "Tag #{$tagId} belongs to groups [" . implode(',', $assignedGroups) . "] outside user's groups.");
            }
        }

        // 2. Tag matches a Nextcloud group name
        $sqb = $this->db->getQueryBuilder();
        $sqb->select('name')->from('systemtag')->where($sqb->expr()->eq('id', $sqb->createNamedParameter($tagId)));
        $tagName = (string)($sqb->executeQuery()->fetchOne() ?: '');

        if ($tagName !== '') {
            foreach ($this->groupManager->search('') as $grp) {
                if (strcasecmp($tagName, $grp->getGID()) === 0) {
                    if (in_array($grp->getGID(), $userGroups, true)) {
                        return PermissionDecision::allow('TAG_DEPARTMENT_NAME', "Tag corresponds to department '{$grp->getGID()}'.", PermissionOperation::READ_METADATA);
                    } else {
                        return PermissionDecision::deny('TAG_DEPARTMENT_RESTRICTED', "Tag corresponds to department '{$grp->getGID()}'.");
                    }
                }
            }
        }

        // 3. Private User Tag Ownership
        $oqb = $this->db->getQueryBuilder();
        $oqb->select('owner_uid')->from('archive_tag_ownership')->where($oqb->expr()->eq('tag_id', $oqb->createNamedParameter($tagId)));
        $owner = $oqb->executeQuery()->fetchOne();
        if ($owner && $owner !== 'system' && $owner !== 'admin') {
            if ($owner === $userId) {
                return PermissionDecision::allow('TAG_OWNER', "User owns private tag #{$tagId}.", PermissionOperation::ALL);
            } else {
                return PermissionDecision::deny('TAG_PRIVATE', "Private tag owned by another user.");
            }
        }

        // 4. Universal system tag (e.g. Enterprise_Archive)
        return PermissionDecision::allow('TAG_PUBLIC_SYSTEM', 'Universal archive tag.', PermissionOperation::READ_METADATA);
    }

    /**
     * Filter accessible file IDs in bulk
     */
    public function filterAccessibleFileIds(string $userId, array $fileIds, int $operation = PermissionOperation::READ): array {
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return $fileIds;
        }

        $accessible = [];
        foreach ($fileIds as $fid) {
            $fIdInt = (int)$fid;
            if ($this->can($userId, $fIdInt, $operation)) {
                $accessible[] = $fIdInt;
            }
        }
        return $accessible;
    }

    /**
     * Filter accessible folders for navigation
     */
    public function filterAccessibleFolders(string $userId, array $folders): array {
        $isAdmin = ($userId === 'admin' || $this->groupManager->isAdmin($userId));
        if ($isAdmin) {
            return $folders;
        }

        $accessible = [];
        foreach ($folders as $f) {
            $name = is_object($f) ? $f->getName() : ($f['name'] ?? '');
            $path = is_object($f) ? $f->getPath() : ($f['path'] ?? $name);

            $decision = $this->evaluateFolder($userId, (string)$path, PermissionOperation::READ_METADATA);
            if ($decision->allowed) {
                $accessible[] = $f;
            }
        }
        return $accessible;
    }

    /**
     * Filter accessible tag IDs
     */
    public function filterAccessibleTagIds(string $userId, array $tagIds): array {
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return $tagIds;
        }

        $accessible = [];
        foreach ($tagIds as $tid) {
            $tIdInt = (int)$tid;
            $dec = $this->evaluateTag($userId, $tIdInt, PermissionOperation::READ_METADATA);
            if ($dec->allowed) {
                $accessible[] = $tIdInt;
            }
        }
        return $accessible;
    }

    // =========================================================================
    // Helper Methods & Metadata Lookups
    // =========================================================================

    private function getUserGroups(string $userId): array {
        if (isset($this->userGroupsCache[$userId])) {
            return $this->userGroupsCache[$userId];
        }

        $user = $this->userManager->get($userId);
        $groups = $user !== null ? $this->groupManager->getUserGroupIds($user) : [];
        $this->userGroupsCache[$userId] = $groups;
        return $groups;
    }

    private function isUserSubadminOfGroup(string $userId, string $groupId): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gid')
               ->from('group_admin')
               ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($groupId)));
            $res = $qb->executeQuery()->fetchAssociative();
            return !empty($res);
        } catch (\Throwable $t) {
            return false;
        }
    }

    private function extractDepartmentFromPath(string $path): ?string {
        $clean = trim(str_replace('\\', '/', $path), '/');
        // Handle variations like: files/Enterprise_Archive/SOC/report.txt or Enterprise_Archive/SOC/...
        $parts = explode('/', $clean);
        foreach ($parts as $idx => $segment) {
            if (strcasecmp($segment, 'Enterprise_Archive') === 0 && isset($parts[$idx + 1])) {
                return $parts[$idx + 1];
            }
        }
        // Direct group folder variation: files/SOC/... or SOC/...
        foreach ($parts as $segment) {
            if ($segment === 'files' || $segment === '' || $segment === '.') {
                continue;
            }
            if ($this->groupManager->groupExists($segment)) {
                return $segment;
            }
            break; // only check first meaningful path component
        }
        return null;
    }

    private function getAncestorFolderIds(int $fileId): array {
        if (isset($this->ancestorCache[$fileId])) {
            return $this->ancestorCache[$fileId];
        }

        $ancestors = [];
        $currId = $fileId;
        for ($i = 0; $i < 10; $i++) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('parent')
               ->from('filecache')
               ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($currId)));
            $parentId = $qb->executeQuery()->fetchOne();
            if (!$parentId || (int)$parentId <= 0 || (int)$parentId === $currId) {
                break;
            }
            $currId = (int)$parentId;
            $ancestors[] = $currId;
        }

        $this->ancestorCache[$fileId] = $ancestors;
        return $ancestors;
    }

    private function getFileOwner(int $fileId, int $storageNumericId): ?string {
        if (isset($this->ownerCache[$fileId])) {
            return $this->ownerCache[$fileId];
        }

        // Check archive_file_ownership table
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('owner_uid')
               ->from('archive_file_ownership')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
            $owner = $qb->executeQuery()->fetchOne();
            if ($owner) {
                $this->ownerCache[$fileId] = (string)$owner;
                return (string)$owner;
            }
        } catch (\Throwable $t) {}

        // Fallback: check storage id (e.g. home::<uid>)
        if ($storageNumericId > 0) {
            $sqb = $this->db->getQueryBuilder();
            $sqb->select('id')
                ->from('storages')
                ->where($sqb->expr()->eq('numeric_id', $sqb->createNamedParameter($storageNumericId)));
            $sId = $sqb->executeQuery()->fetchOne();
            if ($sId && str_starts_with((string)$sId, 'home::')) {
                $ownerUid = substr((string)$sId, strlen('home::'));
                $this->ownerCache[$fileId] = $ownerUid;
                return $ownerUid;
            }
        }

        return null;
    }

    private function getFileIdByPath(string $path): int {
        $clean = trim(trim($path, '/'), '.');
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
           ->from('filecache')
           ->where($qb->expr()->like('path', $qb->createNamedParameter('%' . $clean)))
           ->setMaxResults(1);
        $fid = $qb->executeQuery()->fetchOne();
        return $fid ? (int)$fid : 0;
    }

    private function normalizeGrantMask(int $mask): int {
        // Nextcloud POSIX-like bitmask: 1=Read, 2=Update, 4=Create, 8=Delete, 16=Share, 31=All
        $res = 0;
        if ($mask & 1)  $res |= PermissionOperation::READ | PermissionOperation::READ_METADATA;
        if ($mask & 2)  $res |= PermissionOperation::WRITE;
        if ($mask & 4)  $res |= PermissionOperation::CREATE;
        if ($mask & 8)  $res |= PermissionOperation::DELETE;
        if ($mask & 16) $res |= PermissionOperation::SHARE;
        if ($mask === 31) $res |= PermissionOperation::ALL;
        return $res ?: PermissionOperation::READ;
    }

    private function cacheDecision(string $key, PermissionDecision $decision): PermissionDecision {
        $this->decisionCache[$key] = $decision;
        return $decision;
    }
}
