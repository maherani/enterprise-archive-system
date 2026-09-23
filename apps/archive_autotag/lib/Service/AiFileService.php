<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class AiFileService {

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private FileOwnershipService $fileOwnershipService,
        private TagOwnershipService $tagOwnershipService,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private LoggerInterface $logger,
        private ?ReliableAuditService $reliableAuditService = null,
    ) {
    }

    /**
     * Authenticate incoming request using hardened dual-mode mechanism:
     * 1. Nextcloud Native User Session / HTTP Basic Auth
     * 2. Dedicated Cryptographically Secure AI Service Bearer Token with Hashed Lookup & Delegated Identity Policy Enforcement
     */
    public function authenticateRequest(IRequest $request): array {
        // 1. Check Native User Session / Basic Auth
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null) {
            return [
                'authenticated' => true,
                'actor_uid' => $currentUser->getUID(),
                'auth_type' => 'BASIC_AUTH',
                'client_id' => (string)($request->getHeader('X-Client-ID') ?: 'user_client'),
                'service_id' => 'user_session',
                'token_id' => null,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => null,
                'status_code' => 200,
            ];
        }

        // 2. Check Service Bearer Token or X-API-KEY header
        $authHeader = trim((string)$request->getHeader('Authorization'));
        $apiKeyHeader = trim((string)$request->getHeader('X-API-KEY'));

        $token = '';
        if ($authHeader !== '' && str_starts_with(strtolower($authHeader), 'bearer ')) {
            $token = trim(substr($authHeader, 7));
        } elseif ($apiKeyHeader !== '') {
            $token = $apiKeyHeader;
        }

        if ($token === '') {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'NONE',
                'client_id' => 'unknown',
                'service_id' => 'unknown',
                'token_id' => null,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => 'Authentication required. Provide valid HTTP Basic Auth, Bearer Token, or X-API-KEY',
                'status_code' => 401,
            ];
        }

        // Resolve token from database using secure hash and prefix index
        $prefix = substr($token, 0, 12);
        $hash = hash('sha256', $token);

        $tokenRecord = null;
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_ai_tokens')
               ->where($qb->expr()->eq('token_prefix', $qb->createNamedParameter($prefix)));
            $res = $qb->executeQuery();
            while ($row = $res->fetchAssociative()) {
                if (hash_equals((string)$row['token_hash'], $hash)) {
                    $tokenRecord = $row;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // In case table does not exist or schema updating
        }

        // Graceful fallback for legacy token stored in appconfig before migration
        if ($tokenRecord === null) {
            $legacyConfigToken = (string)$this->config->getAppValue('archive_autotag', 'ai_service_token', '');
            if ($legacyConfigToken !== '' && hash_equals($legacyConfigToken, $token)) {
                $tokenRecord = [
                    'id' => null,
                    'service_id' => 'default_ai_service',
                    'token_name' => 'Legacy Unmigrated Token',
                    'status' => 'ACTIVE',
                    'expires_at' => null,
                    'grace_period_until' => null,
                ];
            }
        }

        if ($tokenRecord === null) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => 'unknown',
                'service_id' => 'unknown',
                'token_id' => null,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => 'Invalid Bearer token or API key provided',
                'status_code' => 401,
            ];
        }

        // Check token lifecycle: status, expiration, grace period
        $now = time();
        $tokenStatus = (string)($tokenRecord['status'] ?? 'ACTIVE');
        $expiresAt = !empty($tokenRecord['expires_at']) ? (int)$tokenRecord['expires_at'] : null;
        $graceUntil = !empty($tokenRecord['grace_period_until']) ? (int)$tokenRecord['grace_period_until'] : null;
        $serviceId = (string)$tokenRecord['service_id'];
        $tokenId = !empty($tokenRecord['id']) ? (int)$tokenRecord['id'] : null;

        if ($tokenStatus === 'REVOKED') {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => 'unknown',
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => 'Token has been revoked. Re-authentication required.',
                'status_code' => 401,
            ];
        }

        if ($tokenStatus === 'EXPIRED' || ($expiresAt !== null && $now > $expiresAt)) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => 'unknown',
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => 'Token has expired. Please rotate or obtain a new token.',
                'status_code' => 401,
            ];
        }

        if ($tokenStatus === 'GRACE_PERIOD') {
            if ($graceUntil !== null && $now > $graceUntil) {
                return [
                    'authenticated' => false,
                    'actor_uid' => null,
                    'auth_type' => 'BEARER_TOKEN',
                    'client_id' => 'unknown',
                    'service_id' => $serviceId,
                    'token_id' => $tokenId,
                    'delegation_status' => 'NONE',
                    'delegation_requested' => null,
                    'error' => 'Token grace period has ended. Token is no longer valid.',
                    'status_code' => 401,
                ];
            }
        }

        // Fetch Service Metadata & Delegation Policies
        $serviceRecord = null;
        try {
            $sqb = $this->db->getQueryBuilder();
            $sqb->select('*')
                ->from('archive_ai_services')
                ->where($sqb->expr()->eq('service_id', $sqb->createNamedParameter($serviceId)));
            $serviceRecord = $sqb->executeQuery()->fetchAssociative();
        } catch (\Throwable $e) {}

        $defaultActorUid = 'api_worker';
        $delegationPolicy = 'SPECIFIC_GROUPS';
        $allowAdminDelegation = false;
        $isServiceActive = true;

        if ($serviceRecord) {
            $defaultActorUid = (string)($serviceRecord['default_actor_uid'] ?: 'api_worker');
            $delegationPolicy = strtoupper((string)($serviceRecord['delegation_policy'] ?: 'SPECIFIC_GROUPS'));
            $allowAdminDelegation = (bool)($serviceRecord['allow_admin_delegation'] ?? false);
            $isServiceActive = (bool)($serviceRecord['is_active'] ?? true);
        }

        if (!$isServiceActive) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => 'unknown',
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => "AI Service '{$serviceId}' is disabled by security policy.",
                'status_code' => 401,
            ];
        }

        // Record last used timestamp & IP
        if ($tokenId !== null) {
            try {
                $uqb = $this->db->getQueryBuilder();
                $uqb->update('archive_ai_tokens')
                    ->set('last_used_at', $uqb->createNamedParameter($now))
                    ->set('last_used_ip', $uqb->createNamedParameter((string)$request->getRemoteAddress()))
                    ->where($uqb->expr()->eq('id', $uqb->createNamedParameter($tokenId)));
                $uqb->executeStatement();
            } catch (\Throwable $e) {}
        }

        $clientId = (string)($request->getHeader('X-Client-ID') ?: $serviceId);
        $onBehalfOf = trim((string)$request->getHeader('X-On-Behalf-Of'));

        // Case A: No Delegation Requested -> Use default actor UID
        if ($onBehalfOf === '') {
            return [
                'authenticated' => true,
                'actor_uid' => $defaultActorUid,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'NONE',
                'delegation_requested' => null,
                'error' => null,
                'status_code' => 200,
            ];
        }

        // Case B: Delegation Requested via X-On-Behalf-Of
        // 1. Delegated user must exist
        if (!$this->userManager->userExists($onBehalfOf)) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'DENIED_USER_NOT_FOUND',
                'delegation_requested' => $onBehalfOf,
                'error' => "Delegated user '{$onBehalfOf}' does not exist in archive system",
                'status_code' => 401,
            ];
        }

        // 2. Admin Delegation Hard Protection (Zero-Privilege Escalation Gate)
        $isAdmin = ($onBehalfOf === 'admin' || $this->groupManager->isAdmin($onBehalfOf));
        if ($isAdmin && !$allowAdminDelegation) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'DENIED_ADMIN_PROTECTION',
                'delegation_requested' => $onBehalfOf,
                'error' => "Delegation to administrative accounts is strictly prohibited by security policy.",
                'status_code' => 403,
            ];
        }

        // 3. Delegation Policy Check (Deny-by-default)
        $delegationAllowed = $this->isDelegationPermitted($serviceId, $delegationPolicy, $onBehalfOf);
        if (!$delegationAllowed) {
            return [
                'authenticated' => false,
                'actor_uid' => null,
                'auth_type' => 'BEARER_TOKEN',
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'token_id' => $tokenId,
                'delegation_status' => 'DENIED_POLICY',
                'delegation_requested' => $onBehalfOf,
                'error' => "AI service '{$serviceId}' is not authorized to act on behalf of user '{$onBehalfOf}'.",
                'status_code' => 403,
            ];
        }

        return [
            'authenticated' => true,
            'actor_uid' => $onBehalfOf,
            'auth_type' => 'BEARER_TOKEN',
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'token_id' => $tokenId,
            'delegation_status' => 'ALLOWED',
            'delegation_requested' => $onBehalfOf,
            'error' => null,
            'status_code' => 200,
        ];
    }

    /**
     * Check if delegation to target user is allowed under the service policy.
     */
    private function isDelegationPermitted(string $serviceId, string $policy, string $targetUid): bool {
        if ($policy === 'DENY_ALL') {
            return false;
        }

        try {
            // 1. Check user-specific delegation rule
            if ($policy === 'SPECIFIC_USERS' || $policy === 'SPECIFIC_USERS_AND_GROUPS') {
                $uqb = $this->db->getQueryBuilder();
                $uqb->select('id')
                    ->from('archive_ai_delegations')
                    ->where($uqb->expr()->eq('service_id', $uqb->createNamedParameter($serviceId)))
                    ->andWhere($uqb->expr()->eq('subject_type', $uqb->createNamedParameter('USER')))
                    ->andWhere($uqb->expr()->eq('subject_id', $uqb->createNamedParameter($targetUid)));
                if ($uqb->executeQuery()->fetchOne()) {
                    return true;
                }
                if ($policy === 'SPECIFIC_USERS') {
                    return false;
                }
            }

            // 2. Check group-specific delegation rule
            if ($policy === 'SPECIFIC_GROUPS' || $policy === 'SPECIFIC_USERS_AND_GROUPS') {
                $user = $this->userManager->get($targetUid);
                if ($user === null) {
                    return false;
                }
                $userGroups = $this->groupManager->getUserGroupIds($user);
                if (empty($userGroups)) {
                    return false;
                }

                $gqb = $this->db->getQueryBuilder();
                $gqb->select('subject_id')
                    ->from('archive_ai_delegations')
                    ->where($gqb->expr()->eq('service_id', $gqb->createNamedParameter($serviceId)))
                    ->andWhere($gqb->expr()->eq('subject_type', $gqb->createNamedParameter('GROUP')))
                    ->andWhere($gqb->expr()->in('subject_id', $gqb->createNamedParameter($userGroups, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                if ($gqb->executeQuery()->fetchOne()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag_ai: Delegation check error: " . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Validate file existence, node type, and enforce strict ACL check.
     */
    public function validateAndGetFileNode(int $fileId, string $actorUid): array {
        if ($fileId <= 0) {
            return [
                'allowed' => false,
                'node' => null,
                'error_code' => 400,
                'error_message' => 'Invalid file ID. Must be a positive integer.',
            ];
        }

        $nodes = [];
        if ($actorUid !== '') {
            try {
                $userFolder = $this->rootFolder->getUserFolder($actorUid);
                $nodes = $userFolder->getById($fileId);
            } catch (\Throwable $e) {}
        }
        if (empty($nodes)) {
            try {
                $adminFolder = $this->rootFolder->getUserFolder('admin');
                $nodes = $adminFolder->getById($fileId);
            } catch (\Throwable $e) {}
        }
        if (empty($nodes)) {
            $nodes = $this->rootFolder->getById($fileId);
        }
        if (empty($nodes)) {
            return [
                'allowed' => false,
                'node' => null,
                'error_code' => 404,
                'error_message' => "File ID {$fileId} not found in archive repository.",
            ];
        }

        /** @var Node $node */
        $node = $nodes[0];
        if ($node->getType() === FileInfo::TYPE_FOLDER) {
            return [
                'allowed' => false,
                'node' => null,
                'error_code' => 400,
                'error_message' => "Requested object ID {$fileId} is a folder, not a file.",
            ];
        }

        // Strict Archive Access Control Enforcement
        if (!$this->fileOwnershipService->canUserAccessFile($fileId, $actorUid)) {
            return [
                'allowed' => false,
                'node' => null,
                'error_code' => 403,
                'error_message' => 'Access denied to the requested archive file.',
            ];
        }

        return [
            'allowed' => true,
            'node' => $node,
            'error_code' => 200,
            'error_message' => null,
        ];
    }

    /**
     * Extract sanitized metadata for authorized AI retrieval without leaking server paths.
     */
    public function getFileMetadata(Node $node, int $fileId, string $actorUid): array {
        $name = $node->getName();
        $size = $node->getSize();
        $mimetype = $node->getMimetype();
        $mtime = $node->getMTime();

        // Visible tags for the requesting user
        $tagIds = $this->tagMapper->getTagIdsForObjects([$fileId], 'files')[$fileId] ?? [];
        $tags = [];
        foreach ($tagIds as $tid) {
            $tidInt = (int)$tid;
            if ($this->tagOwnershipService->canUserSeeTag($tidInt, $actorUid)) {
                try {
                    $t = $this->tagManager->getTagsByIds([$tidInt])[$tidInt] ?? null;
                    if ($t !== null) {
                        $tags[] = [
                            'id' => $tidInt,
                            'name' => $t->getName(),
                        ];
                    }
                } catch (\Throwable $e) {}
            }
        }

        $owner = $this->fileOwnershipService->getFileOwner($fileId) ?? 'system';

        return [
            'status' => 'success',
            'file' => [
                'id' => $fileId,
                'name' => $name,
                'size' => $size,
                'human_size' => $this->formatBytes($size),
                'mimetype' => $mimetype,
                'mtime' => $mtime,
                'owner' => $owner,
                'tags' => $tags,
            ]
        ];
    }

    /**
     * Record structured audit entry for all AI retrieval requests with service principal and delegation status.
     */
    /**
     * Update real-time transfer progress, byte accounting, and completion/abort status.
     */
    public function updateTransferProgress(
        string $requestId,
        int $bytesServed,
        string $transferStatus,
        string $stage = 'STREAMING',
        int $durationMs = 0,
        ?string $errorMessage = null
    ): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('archive_ai_audit')
               ->set('bytes_served', $qb->createNamedParameter($bytesServed))
               ->set('transfer_status', $qb->createNamedParameter($transferStatus))
               ->set('stage', $qb->createNamedParameter($stage));

            if ($durationMs > 0) {
                $qb->set('duration_ms', $qb->createNamedParameter($durationMs));
            }
            if ($errorMessage !== null) {
                $qb->set('error_message', $qb->createNamedParameter($errorMessage));
            }

            $qb->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)));
            $qb->executeStatement();

            $this->logger->info("archive_autotag_ai: [{$requestId}] Transfer progress updated: Status={$transferStatus}, Served={$bytesServed}B, Stage={$stage}, Duration={$durationMs}ms");
        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag_ai: Failed to update transfer progress for [{$requestId}]: " . $t->getMessage());
            if ($this->reliableAuditService !== null) {
                $this->reliableAuditService->recordBestEffort('archive_ai_audit_emergency', [
                    'request_id' => $requestId,
                    'bytes_served' => $bytesServed,
                    'transfer_status' => $transferStatus,
                    'stage' => $stage,
                    'duration_ms' => $durationMs,
                    'error_message' => $errorMessage,
                    'updated_at' => time(),
                ]);
            }
        }
    }

    public function recordAudit(
        string $requestId,
        string $actorUid,
        string $clientId,
        int $fileId,
        string $fileName,
        string $authType,
        string $result,
        string $clientIp,
        int $bytesServed = 0,
        ?string $errorMessage = null,
        string $serviceId = 'unknown',
        ?int $tokenId = null,
        ?string $delegationRequested = null,
        string $delegationStatus = 'NONE',
        string $correlationId = '',
        int $bytesRequested = 0,
        string $transferStatus = 'NONE',
        string $stage = 'INIT'
    ): void {
        $auditData = [
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'actor_uid' => $actorUid,
            'client_id' => $clientId,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'auth_type' => $authType,
            'result' => $result,
            'client_ip' => $clientIp,
            'bytes_requested' => $bytesRequested,
            'bytes_served' => $bytesServed,
            'transfer_status' => $transferStatus,
            'stage' => $stage,
            'error_message' => $errorMessage,
            'created_at' => time(),
            'service_id' => $serviceId,
            'token_id' => $tokenId,
            'delegation_requested' => $delegationRequested,
            'delegation_status' => $delegationStatus,
        ];

        if ($this->reliableAuditService !== null) {
            if ($result === 'ALLOWED') {
                // Audit-Required: Guarantees audit record exists BEFORE file retrieval. Throws on failure (Fail-Closed).
                $this->reliableAuditService->recordRequired('archive_ai_audit', $auditData);
            } else {
                // Audit-Best-Effort: Records unauthenticated or forbidden probes with emergency DLQ fallback (Anti-DoS)
                $this->reliableAuditService->recordBestEffort('archive_ai_audit', $auditData);
            }
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_audit')
               ->values([
                   'request_id' => $qb->createNamedParameter($requestId),
                   'correlation_id' => $qb->createNamedParameter($correlationId),
                   'actor_uid' => $qb->createNamedParameter($actorUid),
                   'client_id' => $qb->createNamedParameter($clientId),
                   'file_id' => $qb->createNamedParameter($fileId),
                   'file_name' => $qb->createNamedParameter($fileName),
                   'auth_type' => $qb->createNamedParameter($authType),
                   'result' => $qb->createNamedParameter($result),
                   'client_ip' => $qb->createNamedParameter($clientIp),
                   'bytes_requested' => $qb->createNamedParameter($bytesRequested),
                   'bytes_served' => $qb->createNamedParameter($bytesServed),
                   'transfer_status' => $qb->createNamedParameter($transferStatus),
                   'stage' => $qb->createNamedParameter($stage),
                   'error_message' => $qb->createNamedParameter($errorMessage),
                   'created_at' => $qb->createNamedParameter(time()),
                   'service_id' => $qb->createNamedParameter($serviceId),
                   'token_id' => $qb->createNamedParameter($tokenId),
                   'delegation_requested' => $qb->createNamedParameter($delegationRequested),
                   'delegation_status' => $qb->createNamedParameter($delegationStatus),
               ]);
            $qb->executeStatement();
        }

        $this->logger->info("archive_autotag_ai: [{$requestId}] File {$fileId} ({$fileName}) accessed by '{$actorUid}' via {$authType} (Service: {$serviceId}, Delegation: {$delegationStatus}): Result={$result}");
    }

    private function formatBytes(int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . ($units[$i] ?? 'B');
    }
}
