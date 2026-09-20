<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Exception\AuditRequiredException;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class ReliableAuditService {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private IConfig $config;
    private ?string $emergencyLogPath = null;

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        IConfig $config
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Sanitize audit string to prevent Audit Log Injection (CRLF, control characters).
     */
    public function sanitize(?string $input): string {
        if ($input === null) {
            return '';
        }
        // Replace CRLF and newlines with space to prevent log forging
        $cleaned = str_replace(["\r\n", "\r", "\n"], ' ', $input);
        // Strip non-printable control characters except tabs/spaces
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);
        return trim($cleaned ?? '');
    }

    /**
     * Audit-Required: Guarantees that the audit record is written to the database.
     * If DB write fails, logs emergency record and throws AuditRequiredException (Fail-Closed).
     *
     * @param string $table Target audit table (e.g. 'archive_permission_audit', 'archive_ai_audit')
     * @param array $data Column-value map
     * @param IDBConnection|null $transactionalDb Specific connection if inside transaction
     * @throws AuditRequiredException
     */
    public function recordRequired(string $table, array $data, ?IDBConnection $transactionalDb = null): void {
        $conn = $transactionalDb ?? $this->db;
        $preparedData = $this->prepareAuditData($data);

        try {
            $qb = $conn->getQueryBuilder();
            $qb->insert($table);
            $values = [];
            foreach ($preparedData as $col => $val) {
                $values[$col] = $qb->createNamedParameter($val);
            }
            $qb->values($values);
            $qb->executeStatement();
        } catch (Throwable $t) {
            // Write to durable emergency DLQ file
            $this->writeEmergencyLog($table, $preparedData, $t);

            $this->logger->error(
                "archive_audit_failure [CRITICAL]: Audit-Required write failed for table {$table}. Aborting operation (Fail-Closed). Error: " . $t->getMessage(),
                ['app' => 'archive_autotag']
            );

            throw new AuditRequiredException(
                "Audit subsystem failure: mandatory audit record could not be written to {$table}. Operation aborted for security compliance.",
                $table,
                (string)($preparedData['target_id'] ?? $preparedData['file_id'] ?? $preparedData['request_id'] ?? ''),
                500,
                $t
            );
        }
    }

    /**
     * Audit-Best-Effort: Tries to write to DB. If write fails, captures to emergency DLQ without throwing.
     */
    public function recordBestEffort(string $table, array $data): bool {
        $preparedData = $this->prepareAuditData($data);

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert($table);
            $values = [];
            foreach ($preparedData as $col => $val) {
                $values[$col] = $qb->createNamedParameter($val);
            }
            $qb->values($values);
            $qb->executeStatement();
            return true;
        } catch (Throwable $t) {
            $this->writeEmergencyLog($table, $preparedData, $t);
            $this->logger->warning(
                "archive_audit_fallback: Audit-Best-Effort write failed for table {$table}. Preserved in emergency DLQ. Error: " . $t->getMessage(),
                ['app' => 'archive_autotag']
            );
            return false;
        }
    }

    /**
     * Prepare and sanitize audit data fields.
     */
    private function prepareAuditData(array $data): array {
        $clean = [];
        foreach ($data as $key => $val) {
            if (is_string($val)) {
                $clean[$key] = $this->sanitize($val);
            } else {
                $clean[$key] = $val;
            }
        }

        if (!isset($clean['created_at']) || (int)$clean['created_at'] <= 0) {
            $clean['created_at'] = time();
        }

        if (!isset($clean['request_id']) || $clean['request_id'] === '') {
            $clean['request_id'] = 'req_' . bin2hex(random_bytes(8));
        }

        return $clean;
    }

    /**
     * Append failed audit event to Dead Letter Queue (DLQ) file with SHA-256 integrity hash.
     */
    public function writeEmergencyLog(string $table, array $data, Throwable $t): void {
        try {
            $logPath = $this->getEmergencyLogPath();
            $dir = dirname($logPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }

            $now = time();
            $payload = [
                'timestamp' => $now,
                'iso_time' => date('c', $now),
                'table' => $table,
                'data' => $data,
                'error_message' => $t->getMessage(),
                'error_code' => $t->getCode(),
                'trace' => substr($t->getTraceAsString(), 0, 1024),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', (string)$json);
            $payload['integrity_hash'] = $hash;

            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $ignore) {
            $this->logger->critical("archive_audit_emergency: Failed to write to emergency DLQ file: " . $ignore->getMessage());
        }
    }

    /**
     * Get DLQ emergency log path.
     */
    public function getEmergencyLogPath(): string {
        if ($this->emergencyLogPath !== null) {
            return $this->emergencyLogPath;
        }

        $datadir = $this->config->getSystemValueString('datadirectory', '/var/www/html/data');
        $this->emergencyLogPath = rtrim($datadir, '/') . '/archive_audit_emergency.jsonl';
        return $this->emergencyLogPath;
    }

    public function setEmergencyLogPathForTesting(string $path): void {
        $this->emergencyLogPath = $path;
    }

    /**
     * Get count of pending entries in DLQ.
     */
    public function getDlqCount(): int {
        $path = $this->getEmergencyLogPath();
        if (!file_exists($path)) {
            return 0;
        }
        $count = 0;
        $handle = @fopen($path, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $count++;
                }
            }
            fclose($handle);
        }
        return $count;
    }

    /**
     * Read and replay pending DLQ records into PostgreSQL database.
     */
    public function flushDeadLetterQueue(): array {
        $path = $this->getEmergencyLogPath();
        if (!file_exists($path)) {
            return ['total' => 0, 'restored' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return ['total' => 0, 'restored' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $restored = 0;
        $failed = 0;
        $remainingLines = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || !isset($entry['table'], $entry['data'])) {
                $failed++;
                continue;
            }

            $table = (string)$entry['table'];
            $data = (array)$entry['data'];

            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert($table);
                $values = [];
                foreach ($data as $col => $val) {
                    $values[$col] = $qb->createNamedParameter($val);
                }
                $qb->values($values);
                $qb->executeStatement();
                $restored++;
            } catch (Throwable $t) {
                $failed++;
                $remainingLines[] = $line;
            }
        }

        if (empty($remainingLines)) {
            @unlink($path);
        } else {
            file_put_contents($path, implode("\n", $remainingLines) . "\n", LOCK_EX);
        }

        $this->logger->info("archive_audit_dlq: Flushed DLQ. Total: " . count($lines) . ", Restored: {$restored}, Failed: {$failed}");

        return [
            'total' => count($lines),
            'restored' => $restored,
            'failed' => $failed,
            'remaining' => count($remainingLines),
        ];
    }

    /**
     * Fetch health summary for audit subsystem.
     */
    public function getAuditHealth(): array {
        $dbConnected = true;
        $counts = [];
        $tables = [
            'folder_request' => 'archive_folder_request_audit',
            'tag' => 'archive_tag_audit',
            'ai' => 'archive_ai_audit',
            'permission' => 'archive_permission_audit',
        ];

        try {
            foreach ($tables as $key => $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*) as cnt'))
                   ->from($table);
                $res = $qb->executeQuery()->fetchAssociative();
                $counts[$key] = (int)($res['cnt'] ?? 0);
            }
        } catch (Throwable $t) {
            $dbConnected = false;
            $this->logger->error("archive_audit_health: DB connection check failed: " . $t->getMessage());
        }

        $dlqCount = $this->getDlqCount();
        $status = ($dbConnected && $dlqCount === 0) ? 'HEALTHY' : ($dbConnected ? 'DEGRADED' : 'CRITICAL');

        return [
            'status' => $status,
            'database_connected' => $dbConnected,
            'total_audits' => array_sum($counts),
            'table_counts' => $counts,
            'dlq_pending_count' => $dlqCount,
            'emergency_log_path' => $this->getEmergencyLogPath(),
        ];
    }

    /**
     * Unified audit stream for Admin UI console.
     */
    public function getUnifiedAuditStream(int $limit = 50, ?string $domain = null, ?string $resultFilter = null): array {
        $events = [];

        // 1. Permission Audit
        if ($domain === null || $domain === 'permission') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_permission_audit')
               ->orderBy('created_at', 'DESC')
               ->setMaxResults($limit);
            if ($resultFilter !== null) {
                $qb->where($qb->expr()->eq('result', $qb->createNamedParameter($resultFilter)));
            }
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $events[] = [
                    'id' => 'perm_' . $r['id'],
                    'domain' => 'permission',
                    'action' => $r['action'],
                    'actor_uid' => $r['actor_uid'],
                    'target' => "File #{$r['file_id']} ({$r['grantee_type']}:{$r['grantee_id']})",
                    'details' => "Mask: {$r['permissions']}",
                    'result' => $r['result'],
                    'request_id' => $r['request_id'],
                    'correlation_id' => $r['correlation_id'],
                    'client_ip' => $r['client_ip'],
                    'created_at' => (int)$r['created_at'],
                ];
            }
        }

        // 2. AI Audit
        if ($domain === null || $domain === 'ai') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_ai_audit')
               ->orderBy('created_at', 'DESC')
               ->setMaxResults($limit);
            if ($resultFilter !== null) {
                $qb->where($qb->expr()->eq('result', $qb->createNamedParameter($resultFilter)));
            }
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $events[] = [
                    'id' => 'ai_' . $r['id'],
                    'domain' => 'ai',
                    'action' => 'file_retrieval',
                    'actor_uid' => $r['actor_uid'],
                    'target' => "File #{$r['file_id']} ({$r['file_name']})",
                    'details' => "Auth: {$r['auth_type']}, Svc: {$r['service_id']}",
                    'result' => $r['result'],
                    'request_id' => $r['request_id'],
                    'correlation_id' => $r['correlation_id'] ?? '',
                    'client_ip' => $r['client_ip'],
                    'created_at' => (int)$r['created_at'],
                ];
            }
        }

        // 3. Tag Audit
        if ($domain === null || $domain === 'tag') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_tag_audit')
               ->orderBy('created_at', 'DESC')
               ->setMaxResults($limit);
            if ($resultFilter !== null) {
                $qb->where($qb->expr()->eq('result', $qb->createNamedParameter($resultFilter)));
            }
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $events[] = [
                    'id' => 'tag_' . $r['id'],
                    'domain' => 'tag',
                    'action' => $r['action'],
                    'actor_uid' => $r['actor_uid'],
                    'target' => "Tag '{$r['tag_name']}' (#{$r['tag_id']})",
                    'details' => $r['details'] ?? '',
                    'result' => $r['result'],
                    'request_id' => $r['request_id'] ?? '',
                    'correlation_id' => $r['correlation_id'] ?? '',
                    'client_ip' => $r['client_ip'] ?? '',
                    'created_at' => (int)$r['created_at'],
                ];
            }
        }

        // 4. Folder Request Audit
        if ($domain === null || $domain === 'folder') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_folder_request_audit')
               ->orderBy('created_at', 'DESC')
               ->setMaxResults($limit);
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $events[] = [
                    'id' => 'fld_' . $r['id'],
                    'domain' => 'folder',
                    'action' => $r['event_type'],
                    'actor_uid' => $r['actor_uid'],
                    'target' => "Req #{$r['request_id']}: {$r['folder_name']}",
                    'details' => $r['details'] ?? '',
                    'result' => ($r['new_status'] === 'rejected' || $r['new_status'] === 'failed') ? 'failure' : 'success',
                    'request_id' => 'req_fld_' . $r['request_id'],
                    'correlation_id' => $r['correlation_id'] ?? '',
                    'client_ip' => $r['client_ip'] ?? '',
                    'created_at' => (int)$r['created_at'],
                ];
            }
        }

        // Sort merged events by created_at DESC
        usort($events, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        return array_slice($events, 0, $limit);
    }
}
