<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class DocumentMetadataService {
    public const CONFIDENTIALITY_NORMAL = 'normal';
    public const CONFIDENTIALITY_CONFIDENTIAL = 'confidential';
    public const CONFIDENTIALITY_SECRET = 'secret';

    public function __construct(
        private readonly IDBConnection $db,
        private readonly ReliableAuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validate and save metadata for a document file.
     *
     * @param int $fileId
     * @param array $data
     * @param string $userId
     * @return array
     * @throws \InvalidArgumentException
     */
    public function saveMetadata(int $fileId, array $data, string $userId): array {
        if ($fileId <= 0) {
            throw new \InvalidArgumentException('شناسه فایل نامعتبر است.');
        }

        $subject = trim((string)($data['subject'] ?? ''));
        if ($subject === '' || mb_strlen($subject) < 2) {
            throw new \InvalidArgumentException('ورود موضوع سند الزامی است (حداقل ۲ کاراکتر).');
        }

        $docNumber = trim((string)($data['document_number'] ?? ''));
        if ($docNumber === '') {
            throw new \InvalidArgumentException('ورود شماره سند الزامی است.');
        }
        $docDate = trim((string)($data['document_date'] ?? ''));
        $issuer = trim((string)($data['issuer'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        $confidentiality = strtolower(trim((string)($data['confidentiality'] ?? self::CONFIDENTIALITY_NORMAL)));
        if (!in_array($confidentiality, [self::CONFIDENTIALITY_NORMAL, self::CONFIDENTIALITY_CONFIDENTIAL, self::CONFIDENTIALITY_SECRET], true)) {
            $confidentiality = self::CONFIDENTIALITY_NORMAL;
        }

        $extra = null;
        if (isset($data['extra_metadata']) && is_array($data['extra_metadata'])) {
            $extra = json_encode($data['extra_metadata'], JSON_UNESCAPED_UNICODE);
        }

        $now = time();
        $existing = $this->getMetadataByFileId($fileId);

        $qb = $this->db->getQueryBuilder();
        if ($existing !== null) {
            $qb->update('archive_document_metadata')
                ->set('subject', $qb->createNamedParameter($subject))
                ->set('document_number', $qb->createNamedParameter($docNumber ?: null))
                ->set('document_date', $qb->createNamedParameter($docDate ?: null))
                ->set('confidentiality', $qb->createNamedParameter($confidentiality))
                ->set('description', $qb->createNamedParameter($description ?: null))
                ->set('issuer', $qb->createNamedParameter($issuer ?: null))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

            if ($extra !== null) {
                $qb->set('extra_metadata', $qb->createNamedParameter($extra));
            }
            $qb->executeStatement();
        } else {
            $qb->insert('archive_document_metadata')
                ->values([
                    'file_id' => $qb->createNamedParameter($fileId),
                    'subject' => $qb->createNamedParameter($subject),
                    'document_number' => $qb->createNamedParameter($docNumber ?: null),
                    'document_date' => $qb->createNamedParameter($docDate ?: null),
                    'confidentiality' => $qb->createNamedParameter($confidentiality),
                    'description' => $qb->createNamedParameter($description ?: null),
                    'issuer' => $qb->createNamedParameter($issuer ?: null),
                    'created_by' => $qb->createNamedParameter($userId),
                    'created_at' => $qb->createNamedParameter($now),
                    'updated_at' => $qb->createNamedParameter($now),
                    'extra_metadata' => $qb->createNamedParameter($extra),
                ])
                ->executeStatement();
        }

        // Record Audit Trail matching archive_permission_audit schema
        try {
            $this->auditService->recordBestEffort('archive_permission_audit', [
                'request_id' => 'req_meta_' . bin2hex(random_bytes(6)),
                'correlation_id' => '',
                'actor_uid' => $userId,
                'file_id' => $fileId,
                'grantee_type' => 'metadata',
                'grantee_id' => $userId,
                'action' => $existing ? 'METADATA_UPDATE' : 'METADATA_CREATE',
                'permissions' => 0,
                'prev_permissions' => 0,
                'result' => 'success',
                'client_ip' => '',
                'error_info' => json_encode([
                    'subject' => $subject,
                    'document_number' => $docNumber,
                    'confidentiality' => $confidentiality,
                    'issuer' => $issuer,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (\Throwable $t) {
            $this->logger->warning('Failed to log metadata audit: ' . $t->getMessage());
        }

        return $this->getMetadataByFileId($fileId) ?? [];
    }

    /**
     * Get document metadata by file ID.
     */
    public function getMetadataByFileId(int $fileId): ?array {
        if ($fileId <= 0) return null;

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('archive_document_metadata')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Bulk fetch metadata for a list of file IDs.
     *
     * @param int[] $fileIds
     * @return array<int, array> Map of fileId => metadata row
     */
    public function getMetadataByFileIds(array $fileIds): array {
        $validIds = array_values(array_filter(array_map('intval', $fileIds), fn($id) => $id > 0));
        if (empty($validIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('archive_document_metadata')
            ->where($qb->expr()->in('file_id', $qb->createNamedParameter($validIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['file_id']] = $row;
        }
        return $map;
    }

    /**
     * Search file IDs by metadata keyword in subject, document_number, issuer, or description.
     *
     * @param string $term
     * @return int[]
     */
    public function searchByMetadata(string $term): array {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $likeParam = '%' . $term . '%';
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('archive_document_metadata')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->like('subject', $qb->createNamedParameter($likeParam)),
                    $qb->expr()->like('document_number', $qb->createNamedParameter($likeParam)),
                    $qb->expr()->like('issuer', $qb->createNamedParameter($likeParam)),
                    $qb->expr()->like('description', $qb->createNamedParameter($likeParam))
                )
            );

        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(fn($r) => (int)$r['file_id'], $rows);
    }

    /**
     * Delete metadata record for a file.
     */
    public function deleteMetadata(int $fileId, string $userId): void {
        if ($fileId <= 0) return;

        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_document_metadata')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeStatement();
    }
}
