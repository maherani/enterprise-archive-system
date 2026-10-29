<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Security\Permission\IPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class AiOutboxService {
    public const MAX_FILE_SIZE_BYTES = 2097152; // 2 MiB

    public const SUPPORTED_TEXT_EXTENSIONS = [
        '.txt', '.md', '.log', '.csv', '.json', '.xml', '.yaml', '.yml'
    ];

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
        private IPermissionResolver $permissionResolver,
    ) {
    }

    /**
     * Resolves dynamically all workspaces currently accessible by the given user.
     * 
     * Includes:
     * 1. Personal Workspace: personal-{sanitized_uid}
     * 2. Managed / Shared Folders: folder-{fileid} evaluated in real-time via CentralPermissionResolver
     */
    public function getUserAccessibleWorkspaces(string $userId): array {
        $sanitizedUid = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($userId)));
        $sanitizedUid = trim(preg_replace('/-+/', '-', $sanitizedUid), '-_') ?: 'unknown';

        $workspaces = [];

        // 1. Personal workspace
        $workspaces[] = [
            'slug' => "personal-{$sanitizedUid}",
            'name' => 'اسناد شخصی من (Personal)',
            'scope' => 'personal',
            'folder_id' => 0,
        ];

        $isAdmin = ($userId === 'admin' || $this->groupManager->isAdmin($userId));

        // 2. Candidate folders
        $candidateFolders = [];

        // a) Top-level child folders of Enterprise_Archive
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid', 'path')
               ->from('filecache')
               ->where($qb->expr()->orX(
                   $qb->expr()->eq('path', $qb->createNamedParameter('files/Enterprise_Archive')),
                   $qb->expr()->eq('path', $qb->createNamedParameter('Enterprise_Archive'))
               ))
               ->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter(2)))
               ->setMaxResults(1);
            $eaRow = $qb->executeQuery()->fetchAssociative();

            if ($eaRow) {
                $eaFileId = (int)$eaRow['fileid'];
                // Only admin can access Enterprise_Archive root workspace
                if ($isAdmin) {
                    $candidateFolders[$eaFileId] = (string)$eaRow['path'];
                }

                $cqb = $this->db->getQueryBuilder();
                $cqb->select('fileid', 'path')
                    ->from('filecache')
                    ->where($cqb->expr()->eq('parent', $cqb->createNamedParameter($eaFileId)))
                    ->andWhere($cqb->expr()->eq('mimetype', $cqb->createNamedParameter(2)));
                foreach ($cqb->executeQuery()->fetchAllAssociative() as $r) {
                    $candidateFolders[(int)$r['fileid']] = (string)$r['path'];
                }
            }
        } catch (\Throwable $t) {
            $this->logger->warning("AiOutboxService: Failed to query Enterprise_Archive candidates: " . $t->getMessage());
        }

        // b) Active folder shares from oc_share
        try {
            $sqb = $this->db->getQueryBuilder();
            $sqb->select('item_source')
                ->from('share')
                ->where($sqb->expr()->eq('item_type', $sqb->createNamedParameter('folder')));
            $shareRows = $sqb->executeQuery()->fetchAllAssociative();
            $shareIds = [];
            foreach ($shareRows as $sr) {
                $fid = (int)$sr['item_source'];
                if ($fid > 0 && !isset($candidateFolders[$fid])) {
                    $shareIds[] = $fid;
                }
            }
            if (!empty($shareIds)) {
                $fqb = $this->db->getQueryBuilder();
                $fqb->select('fileid', 'path')
                    ->from('filecache')
                    ->where($fqb->expr()->in('fileid', $fqb->createNamedParameter($shareIds, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($fqb->expr()->eq('mimetype', $fqb->createNamedParameter(2)));
                foreach ($fqb->executeQuery()->fetchAllAssociative() as $fr) {
                    $candidateFolders[(int)$fr['fileid']] = (string)$fr['path'];
                }
            }
        } catch (\Throwable $t) {
            $this->logger->warning("AiOutboxService: Failed to query share candidates: " . $t->getMessage());
        }

        // 3. Evaluate each candidate folder via CentralPermissionResolver in real time
        foreach ($candidateFolders as $fileId => $folderPath) {
            $parts = explode('/', trim(str_replace('\\', '/', $folderPath), '/'));
            $displayName = end($parts) ?: "Folder {$fileId}";

            // Strict Gate: Enterprise_Archive root is strictly reserved for administrators only
            $isEnterpriseArchiveRoot = (
                strcasecmp($displayName, 'Enterprise_Archive') === 0 ||
                preg_match('#^(files/)?Enterprise_Archive$#i', trim($folderPath, '/'))
            );
            if ($isEnterpriseArchiveRoot && !$isAdmin) {
                continue;
            }

            try {
                $decision = $this->permissionResolver->evaluateFolder($userId, $folderPath, PermissionOperation::READ);
                if ($decision->allowed) {
                    $workspaces[] = [
                        'slug' => "folder-{$fileId}",
                        'name' => $displayName,
                        'scope' => 'folder',
                        'folder_id' => $fileId,
                    ];
                }
            } catch (\Throwable $t) {
                $this->logger->warning("AiOutboxService: Evaluation failed for folder {$fileId}: " . $t->getMessage());
            }
        }

        return $workspaces;
    }

    /**
     * Resolves the governing top-level folder / workspace for any file or path.
     */
    /**
     * Helper to look up fileid of a named folder under Enterprise_Archive.
     */
    private function lookupEnterpriseFolder(string $candidateName): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid', 'path')
               ->from('filecache')
               ->where($qb->expr()->orX(
                   $qb->expr()->eq('path', $qb->createNamedParameter("files/Enterprise_Archive/{$candidateName}")),
                   $qb->expr()->eq('path', $qb->createNamedParameter("Enterprise_Archive/{$candidateName}"))
               ))
               ->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter(2)))
               ->setMaxResults(1);
            $row = $qb->executeQuery()->fetchAssociative();
            if ($row) {
                $fid = (int)$row['fileid'];
                return [
                    'scope' => 'folder',
                    'workspace_slug' => "folder-{$fid}",
                    'workspace_name' => $candidateName,
                    'folder_id' => $fid,
                ];
            }
        } catch (\Throwable $t) {
            $this->logger->warning("AiOutboxService: lookupEnterpriseFolder failed for {$candidateName}: " . $t->getMessage());
        }
        return null;
    }

    public function resolveGoverningFolder(File|Node|string $fileOrPath, string $ownerUid): array {
        $filePath = is_string($fileOrPath) ? $fileOrPath : $fileOrPath->getPath();
        $cleanPath = trim(str_replace('\\', '/', $filePath), '/');
        $cleanPath = preg_replace('#^[^/]+/files/#', '', $cleanPath);
        $cleanPath = preg_replace('#^files/#', '', $cleanPath);
        $parts = explode('/', $cleanPath);
        $fileName = end($parts);

        // 1. Check if under Enterprise_Archive/<Folder>/... or shared Enterprise folder
        foreach ($parts as $idx => $segment) {
            if (strcasecmp($segment, 'Enterprise_Archive') === 0 && isset($parts[$idx + 1])) {
                $candidateName = trim($parts[$idx + 1]);
                if ($candidateName !== '' && $candidateName !== $fileName) {
                    $match = $this->lookupEnterpriseFolder($candidateName);
                    if ($match) {
                        return $match;
                    }
                }
            }
        }

        // Also check if any leading path segment directly matches an Enterprise Archive folder
        foreach ($parts as $segment) {
            $candidateName = trim($segment);
            if ($candidateName !== '' && $candidateName !== $fileName && strcasecmp($candidateName, 'Enterprise_Archive') !== 0) {
                $match = $this->lookupEnterpriseFolder($candidateName);
                if ($match) {
                    return $match;
                }
            }
        }

        // 2. Personal scope
        $sanitizedUid = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($ownerUid)));
        $sanitizedUid = trim(preg_replace('/-+/', '-', $sanitizedUid), '-_') ?: 'unknown';
        return [
            'scope' => 'personal',
            'workspace_slug' => "personal-{$sanitizedUid}",
            'workspace_name' => "اسناد شخصی من (Personal)",
            'folder_id' => 0,
        ];
    }

    /**
     * Inspect file against qualification criteria and insert into Outbox if qualified.
     * 
     * If file is not qualified (e.g. image, binary, oversized), returns null gracefully.
     * If file IS qualified but database insertion fails, throws RuntimeException (Fail-Closed).
     *
     * @throws \RuntimeException when database insertion fails for a qualified file.
     */
    public function recordFileEvent(File $file, string $ownerUid): ?string {
        $fileId = (int)$file->getId();
        $fileName = $file->getName();
        $this->logger->info("archive_autotag: AiOutboxService::recordFileEvent called for file ID {$fileId} ({$fileName})");

        $ext = '.' . strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // 1. Qualification checks: if unqualified, gracefully return null (not an error)
        if (!in_array($ext, self::SUPPORTED_TEXT_EXTENSIONS, true)) {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: unsupported extension '{$ext}'");
            return null;
        }

        $size = (int)$file->getSize();
        if ($size <= 0 || $size > self::MAX_FILE_SIZE_BYTES) {
            $reason = $size <= 0
                ? "size is zero or negative ({$size} bytes)"
                : "size exceeds maximum allowed limit ({$size} bytes > " . self::MAX_FILE_SIZE_BYTES . " bytes)";
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: {$reason}");
            return null;
        }

        $content = $file->getContent();
        if ($content === false || $content === '') {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: content is empty or unreadable");
            return null;
        }

        // Disguised PDF check
        if (str_starts_with($content, '%PDF') || str_contains(substr($content, 0, 1024), '%PDF-')) {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: PDF signature detected");
            return null;
        }

        // Disguised ZIP / DOCX check
        if (str_starts_with($content, "PK\x03\x04")) {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: ZIP/DOCX signature detected");
            return null;
        }

        // NUL byte check (binary file disguised as text)
        if (str_contains($content, "\0")) {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: NUL byte detected (binary content)");
            return null;
        }

        // UTF-8 validation
        if (!mb_check_encoding($content, 'UTF-8')) {
            $this->logger->info("archive_autotag: File ID {$fileId} ({$fileName}) rejected from AI Outbox: invalid UTF-8 encoding");
            return null;
        }

        $sha256 = hash('sha256', $content);
        $filePath = $file->getPath();

        // Resolve governing workspace folder dynamically
        $gov = $this->resolveGoverningFolder($file, $ownerUid);
        $scope = $gov['scope'];
        $workspaceSlug = $gov['workspace_slug'];
        $workspaceName = $gov['workspace_name'];
        $groupName = $workspaceName;

        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $now = time();

        // 2. Database insertion: Fail-Closed (must NOT swallow DB errors for qualified files)
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_outbox')
               ->values([
                   'event_id' => $qb->createNamedParameter($eventId),
                   'event_type' => $qb->createNamedParameter('file.created'),
                   'file_id' => $qb->createNamedParameter($fileId),
                   'file_path' => $qb->createNamedParameter($filePath),
                   'file_name' => $qb->createNamedParameter($fileName),
                   'owner_uid' => $qb->createNamedParameter($ownerUid),
                   'group_name' => $qb->createNamedParameter($groupName),
                   'scope' => $qb->createNamedParameter($scope),
                   'workspace_slug' => $qb->createNamedParameter($workspaceSlug),
                   'workspace_name' => $qb->createNamedParameter($workspaceName),
                   'mime_type' => $qb->createNamedParameter($file->getMimetype() ?: 'text/plain'),
                   'file_size' => $qb->createNamedParameter($size),
                   'sha256_hash' => $qb->createNamedParameter($sha256),
                   'status' => $qb->createNamedParameter('PENDING'),
                   'retry_count' => $qb->createNamedParameter(0),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $this->logger->info("archive_autotag: Registered AI Outbox event {$eventId} (workspace={$workspaceSlug}) for file ID {$fileId} ({$fileName})");
            return $eventId;
        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag: CRITICAL: Database insertion failed for AI Outbox event on file ID {$fileId}: " . $t->getMessage());
            throw new \RuntimeException("AI Outbox registration failed for file ID {$fileId}: " . $t->getMessage(), 0, $t);
        }
    }

    /**
     * Record file deletion event in Outbox to purge corresponding vector embeddings.
     */
    public function recordFileDeleted(File $file, string $ownerUid): ?string {
        $fileId = (int)$file->getId();
        $fileName = $file->getName();
        $filePath = $file->getPath();

        $gov = $this->resolveGoverningFolder($file, $ownerUid);
        $workspaceSlug = $gov['workspace_slug'];
        $workspaceName = $gov['workspace_name'];
        $scope = $gov['scope'];

        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $now = time();

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_outbox')
               ->values([
                   'event_id' => $qb->createNamedParameter($eventId),
                   'event_type' => $qb->createNamedParameter('file.deleted'),
                   'file_id' => $qb->createNamedParameter($fileId),
                   'file_path' => $qb->createNamedParameter($filePath),
                   'file_name' => $qb->createNamedParameter($fileName),
                   'owner_uid' => $qb->createNamedParameter($ownerUid),
                   'group_name' => $qb->createNamedParameter($workspaceName),
                   'scope' => $qb->createNamedParameter($scope),
                   'workspace_slug' => $qb->createNamedParameter($workspaceSlug),
                   'workspace_name' => $qb->createNamedParameter($workspaceName),
                   'mime_type' => $qb->createNamedParameter('text/plain'),
                   'file_size' => $qb->createNamedParameter(0),
                   'sha256_hash' => $qb->createNamedParameter(''),
                   'status' => $qb->createNamedParameter('PENDING'),
                   'retry_count' => $qb->createNamedParameter(0),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $this->logger->info("archive_autotag: Registered AI Outbox deletion event {$eventId} for file ID {$fileId} ({$fileName})");
            return $eventId;
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Failed to register deletion event for file ID {$fileId}: " . $t->getMessage());
            return null;
        }
    }

    /**
     * Record file moved/renamed event in Outbox to migrate vectors between workspaces if needed.
     */
    public function recordFileMoved(File $sourceFile, File $targetFile, string $ownerUid): ?string {
        $fileId = (int)$targetFile->getId();
        $fileName = $targetFile->getName();
        $filePath = $targetFile->getPath();

        $oldGov = $this->resolveGoverningFolder($sourceFile, $ownerUid);
        $newGov = $this->resolveGoverningFolder($targetFile, $ownerUid);

        $oldSlug = $oldGov['workspace_slug'];
        $newSlug = $newGov['workspace_slug'];
        $newName = $newGov['workspace_name'];
        $scope = $newGov['scope'];

        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $now = time();

        $eventType = ($oldSlug !== $newSlug) ? 'file.moved' : 'file.created';

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_outbox')
               ->values([
                   'event_id' => $qb->createNamedParameter($eventId),
                   'event_type' => $qb->createNamedParameter($eventType),
                   'file_id' => $qb->createNamedParameter($fileId),
                   'file_path' => $qb->createNamedParameter($filePath),
                   'file_name' => $qb->createNamedParameter($fileName),
                   'owner_uid' => $qb->createNamedParameter($ownerUid),
                   'group_name' => $qb->createNamedParameter($newName),
                   'scope' => $qb->createNamedParameter($scope),
                   'workspace_slug' => $qb->createNamedParameter($newSlug),
                   'workspace_name' => $qb->createNamedParameter($newName),
                   'old_workspace_slug' => $qb->createNamedParameter($oldSlug),
                   'mime_type' => $qb->createNamedParameter($targetFile->getMimetype() ?: 'text/plain'),
                   'file_size' => $qb->createNamedParameter((int)$targetFile->getSize()),
                   'sha256_hash' => $qb->createNamedParameter(''),
                   'status' => $qb->createNamedParameter('PENDING'),
                   'retry_count' => $qb->createNamedParameter(0),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $this->logger->info("archive_autotag: Registered AI Outbox moved event {$eventId} (from {$oldSlug} to {$newSlug}) for file ID {$fileId}");
            return $eventId;
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Failed to register moved event for file ID {$fileId}: " . $t->getMessage());
            return null;
        }
    }

    /**
     * Fetch pending or failed events ready for publication.
     */
    public function getPendingEvents(int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_ai_outbox')
           ->where($qb->expr()->in('status', $qb->createNamedParameter(['PENDING', 'FAILED'], IQueryBuilder::PARAM_STR_ARRAY)))
           ->andWhere($qb->expr()->lt('retry_count', $qb->createNamedParameter(10)))
           ->orderBy('id', 'ASC')
           ->setMaxResults(max(1, min($limit, 200)));

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Mark an outbox event as successfully PUBLISHED after confirmed broker routing.
     */
    public function markPublished(string $eventId): bool {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_ai_outbox')
           ->set('status', $qb->createNamedParameter('PUBLISHED'))
           ->set('published_at', $qb->createNamedParameter($now))
           ->set('last_error', $qb->createNamedParameter(null))
           ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)));

        $affected = $qb->executeStatement();
        if ($affected > 0) {
            $this->logger->info("archive_autotag: Marked AI Outbox event {$eventId} as PUBLISHED");
            return true;
        }
        return false;
    }

    /**
     * Mark an outbox event as FAILED with an error description, incrementing retry count.
     */
    public function markFailed(string $eventId, string $error): bool {
        $event = $this->getEvent($eventId);
        if ($event === null) {
            return false;
        }
        $retryCount = ((int)($event['retry_count'] ?? 0)) + 1;

        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_ai_outbox')
           ->set('status', $qb->createNamedParameter('FAILED'))
           ->set('retry_count', $qb->createNamedParameter($retryCount))
           ->set('last_error', $qb->createNamedParameter($error))
           ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)));

        $affected = $qb->executeStatement();
        if ($affected > 0) {
            $this->logger->warning("archive_autotag: Marked AI Outbox event {$eventId} as FAILED: {$error}");
            return true;
        }
        return false;
    }

    /**
     * Retrieve a specific event by event_id.
     */
    public function getEvent(string $eventId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_ai_outbox')
           ->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row !== false ? $row : null;
    }
}
