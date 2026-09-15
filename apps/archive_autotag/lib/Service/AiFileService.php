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
    ) {
    }

    /**
     * Authenticate incoming request using dual-mode mechanism:
     * 1. Nextcloud Native User Session / HTTP Basic Auth
     * 2. Dedicated AI Service Bearer Token or X-API-KEY
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
                'error' => null,
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

        if ($token !== '') {
            $configuredToken = (string)$this->config->getAppValue('archive_autotag', 'ai_service_token', '');
            if ($configuredToken !== '' && hash_equals($configuredToken, $token)) {
                // Check for Delegated User (X-On-Behalf-Of header)
                $onBehalfOf = trim((string)$request->getHeader('X-On-Behalf-Of'));
                $actorUid = $this->config->getAppValue('archive_autotag', 'ai_default_user', 'api_worker');

                if ($onBehalfOf !== '') {
                    if ($this->userManager->userExists($onBehalfOf)) {
                        $actorUid = $onBehalfOf;
                    } else {
                        return [
                            'authenticated' => false,
                            'actor_uid' => null,
                            'auth_type' => 'BEARER_TOKEN',
                            'client_id' => 'unknown',
                            'error' => "Delegated user '{$onBehalfOf}' does not exist in archive system",
                        ];
                    }
                }

                $clientId = (string)($request->getHeader('X-Client-ID') ?: 'ai_service');
                return [
                    'authenticated' => true,
                    'actor_uid' => $actorUid,
                    'auth_type' => 'BEARER_TOKEN',
                    'client_id' => $clientId,
                    'error' => null,
                ];
            }
        }

        return [
            'authenticated' => false,
            'actor_uid' => null,
            'auth_type' => 'NONE',
            'client_id' => 'unknown',
            'error' => 'Authentication required. Provide valid HTTP Basic Auth, Bearer Token, or X-API-KEY',
        ];
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

        $nodes = $this->rootFolder->getById($fileId);
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
     * Record structured audit entry for all AI retrieval requests.
     */
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
        ?string $errorMessage = null
    ): void {
        try {
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_audit')
               ->values([
                   'request_id' => $qb->createNamedParameter($requestId),
                   'actor_uid' => $qb->createNamedParameter($actorUid),
                   'client_id' => $qb->createNamedParameter($clientId),
                   'file_id' => $qb->createNamedParameter($fileId),
                   'file_name' => $qb->createNamedParameter($fileName),
                   'auth_type' => $qb->createNamedParameter($authType),
                   'result' => $qb->createNamedParameter($result),
                   'client_ip' => $qb->createNamedParameter($clientIp),
                   'bytes_served' => $qb->createNamedParameter($bytesServed),
                   'error_message' => $qb->createNamedParameter($errorMessage),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $this->logger->info("archive_autotag_ai: [{$requestId}] File {$fileId} ({$fileName}) accessed by '{$actorUid}' via {$authType}: Result={$result}");
        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag_ai: Failed to record audit log: " . $t->getMessage());
        }
    }

    private function formatBytes(int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . ($units[$i] ?? 'B');
    }
}
