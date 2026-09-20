<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCA\ArchiveAutoTag\Service\AiFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AiAdminController extends Controller {
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IDBConnection $db,
        private readonly AiFileService $aiFileService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Check if current session user is system administrator
     */
    private function checkAdmin(): ?DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['status' => 'error', 'message' => 'Access denied: System administrator privileges required.'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Complete overview of AI services, tokens, delegations, and system metrics
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function overview(): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        try {
            // 1. Fetch Services
            $sqb = $this->db->getQueryBuilder();
            $sqb->select('*')->from('archive_ai_services')->orderBy('id', 'ASC');
            $services = $sqb->executeQuery()->fetchAllAssociative();

            // 2. Fetch Tokens
            $tqb = $this->db->getQueryBuilder();
            $tqb->select('id', 'service_id', 'token_name', 'token_prefix', 'status', 'expires_at', 'grace_period_until', 'created_by', 'created_at', 'last_used_at')
                ->from('archive_ai_tokens')
                ->orderBy('id', 'DESC');
            $allTokens = $tqb->executeQuery()->fetchAllAssociative();

            // 3. Fetch Delegations
            $dqb = $this->db->getQueryBuilder();
            $dqb->select('id', 'service_id', 'subject_type', 'subject_id', 'created_at')
                ->from('archive_ai_delegations')
                ->orderBy('id', 'ASC');
            $allDelegations = $dqb->executeQuery()->fetchAllAssociative();

            // 4. Fetch Audit Stats
            $aqb = $this->db->getQueryBuilder();
            $aqb->selectAlias($aqb->createFunction('COUNT(*)'), 'total')
                ->from('archive_ai_audit');
            $totalAudit = (int)$aqb->executeQuery()->fetchOne();

            $aqb2 = $this->db->getQueryBuilder();
            $aqb2->selectAlias($aqb2->createFunction('COUNT(*)'), 'blocked')
                 ->from('archive_ai_audit')
                 ->where($aqb2->expr()->eq('result', $aqb2->createNamedParameter('FORBIDDEN')));
            $totalBlocked = (int)$aqb2->executeQuery()->fetchOne();

            // Group tokens and delegations by service
            $tokensByService = [];
            foreach ($allTokens as $t) {
                $sid = $t['service_id'];
                $tokensByService[$sid][] = $t;
            }

            $delegationsByService = [];
            foreach ($allDelegations as $d) {
                $sid = $d['service_id'];
                $delegationsByService[$sid][] = $d;
            }

            foreach ($services as &$s) {
                $sid = $s['service_id'];
                $s['tokens'] = $tokensByService[$sid] ?? [];
                $s['delegations'] = $delegationsByService[$sid] ?? [];
            }
            unset($s);

            // Nextcloud groups for dropdown
            $groups = [];
            foreach ($this->groupManager->search('') as $grp) {
                $groups[] = $grp->getGID();
            }

            return new DataResponse([
                'status' => 'success',
                'services' => $services,
                'available_groups' => $groups,
                'metrics' => [
                    'total_services' => count($services),
                    'total_tokens' => count($allTokens),
                    'total_delegations' => count($allDelegations),
                    'total_audit_events' => $totalAudit,
                    'total_blocked_attempts' => $totalBlocked,
                ],
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new AI Service Principal
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createService(
        string $service_id = '',
        string $display_name = '',
        string $description = '',
        string $default_actor_uid = 'ai_worker',
        string $delegation_policy = 'SPECIFIC_GROUPS',
        bool $allow_admin_delegation = false
    ): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $sid = trim($service_id !== '' ? $service_id : (string)($body['service_id'] ?? ''));
        $name = trim($display_name !== '' ? $display_name : (string)($body['display_name'] ?? ''));
        $desc = trim($description !== '' ? $description : (string)($body['description'] ?? ''));
        $defUid = trim($default_actor_uid !== '' ? $default_actor_uid : (string)($body['default_actor_uid'] ?? 'ai_worker'));
        $policy = strtoupper(trim($delegation_policy !== '' ? $delegation_policy : (string)($body['delegation_policy'] ?? 'SPECIFIC_GROUPS')));
        $allowAdmin = isset($body['allow_admin_delegation']) ? (bool)$body['allow_admin_delegation'] : $allow_admin_delegation;

        if ($sid === '') {
            return new DataResponse(['status' => 'error', 'message' => 'Service ID is required.'], Http::STATUS_BAD_REQUEST);
        }

        $validPolicies = ['DENY_ALL', 'SPECIFIC_USERS', 'SPECIFIC_GROUPS', 'SPECIFIC_USERS_AND_GROUPS'];
        if (!in_array($policy, $validPolicies, true)) {
            return new DataResponse(['status' => 'error', 'message' => 'Invalid delegation policy.'], Http::STATUS_BAD_REQUEST);
        }

        $now = time();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_services')
               ->values([
                   'service_id' => $qb->createNamedParameter($sid),
                   'display_name' => $qb->createNamedParameter($name ?: $sid),
                   'description' => $qb->createNamedParameter($desc),
                   'default_actor_uid' => $qb->createNamedParameter($defUid),
                   'delegation_policy' => $qb->createNamedParameter($policy),
                   'allow_admin_delegation' => $qb->createNamedParameter($allowAdmin, IQueryBuilder::PARAM_BOOL),
                   'is_active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                   'created_at' => $qb->createNamedParameter($now),
                   'updated_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            return new DataResponse([
                'status' => 'success',
                'message' => "AI Service Principal '{$sid}' created successfully.",
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => 'Failed to create service: ' . $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate and issue a new cryptographically secure token
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createToken(
        string $service_id = '',
        string $token_name = '',
        int $expires_days = 90
    ): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $sid = trim($service_id !== '' ? $service_id : (string)($body['service_id'] ?? ''));
        $name = trim($token_name !== '' ? $token_name : (string)($body['token_name'] ?? ''));
        $expDays = isset($body['expires_days']) ? (int)$body['expires_days'] : $expires_days;

        if ($sid === '') {
            return new DataResponse(['status' => 'error', 'message' => 'Service ID is required.'], Http::STATUS_BAD_REQUEST);
        }

        // Verify service exists
        $sqb = $this->db->getQueryBuilder();
        $sqb->select('id')->from('archive_ai_services')->where($sqb->expr()->eq('service_id', $sqb->createNamedParameter($sid)));
        if (!$sqb->executeQuery()->fetchOne()) {
            return new DataResponse(['status' => 'error', 'message' => "Service '{$sid}' does not exist."], Http::STATUS_NOT_FOUND);
        }

        $expiresAt = $expDays > 0 ? time() + ($expDays * 86400) : null;
        $rawSecret = bin2hex(random_bytes(32));
        $rawToken = 'nc_ai_' . $rawSecret;
        $prefix = substr($rawToken, 0, 12);
        $hash = hash('sha256', $rawToken);
        $now = time();
        $adminUser = $this->userSession->getUser()->getUID();

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_tokens')
               ->values([
                   'service_id' => $qb->createNamedParameter($sid),
                   'token_name' => $qb->createNamedParameter($name ?: ('Token ' . date('Y-m-d H:i'))),
                   'token_prefix' => $qb->createNamedParameter($prefix),
                   'token_hash' => $qb->createNamedParameter($hash),
                   'status' => $qb->createNamedParameter('ACTIVE'),
                   'expires_at' => $qb->createNamedParameter($expiresAt),
                   'created_by' => $qb->createNamedParameter("ui_{$adminUser}"),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            return new DataResponse([
                'status' => 'success',
                'message' => 'Token issued successfully.',
                'raw_token' => $rawToken,
                'token_prefix' => $prefix,
                'expires_at' => $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : 'Never',
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Rotate active token with grace period
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function rotateToken(
        string $service_id = '',
        int $grace_hours = 24
    ): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $sid = trim($service_id !== '' ? $service_id : (string)($body['service_id'] ?? ''));
        $graceHours = isset($body['grace_hours']) ? (int)$body['grace_hours'] : $grace_hours;

        if ($sid === '') {
            return new DataResponse(['status' => 'error', 'message' => 'Service ID is required.'], Http::STATUS_BAD_REQUEST);
        }

        $graceUntil = time() + ($graceHours * 3600);
        $now = time();

        try {
            // 1. Mark existing ACTIVE tokens as GRACE_PERIOD
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_ai_tokens')
                 ->set('status', $upQb->createNamedParameter('GRACE_PERIOD'))
                 ->set('grace_period_until', $upQb->createNamedParameter($graceUntil))
                 ->where($upQb->expr()->eq('service_id', $upQb->createNamedParameter($sid)))
                 ->andWhere($upQb->expr()->eq('status', $upQb->createNamedParameter('ACTIVE')));
            $upQb->executeStatement();

            // 2. Issue new ACTIVE token
            $rawSecret = bin2hex(random_bytes(32));
            $rawToken = 'nc_ai_' . $rawSecret;
            $prefix = substr($rawToken, 0, 12);
            $hash = hash('sha256', $rawToken);
            $name = 'Rotated Token ' . date('Y-m-d H:i');
            $adminUser = $this->userSession->getUser()->getUID();

            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_tokens')
               ->values([
                   'service_id' => $qb->createNamedParameter($sid),
                   'token_name' => $qb->createNamedParameter($name),
                   'token_prefix' => $qb->createNamedParameter($prefix),
                   'token_hash' => $qb->createNamedParameter($hash),
                   'status' => $qb->createNamedParameter('ACTIVE'),
                   'created_by' => $qb->createNamedParameter("ui_rotate_{$adminUser}"),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            return new DataResponse([
                'status' => 'success',
                'message' => "Token for service '{$sid}' rotated successfully with {$graceHours}h grace period.",
                'new_raw_token' => $rawToken,
                'token_prefix' => $prefix,
                'grace_period_until' => date('Y-m-d H:i:s', $graceUntil),
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Instantly revoke a token
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function revokeToken(int $token_id = 0): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $tid = $token_id > 0 ? $token_id : (int)($body['token_id'] ?? 0);

        if ($tid <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Valid token_id is required.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->update('archive_ai_tokens')
               ->set('status', $qb->createNamedParameter('REVOKED'))
               ->set('revoked_at', $qb->createNamedParameter($now))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($tid)));
            $affected = $qb->executeStatement();

            if ($affected > 0) {
                return new DataResponse(['status' => 'success', 'message' => "Token #{$tid} revoked immediately."]);
            }
            return new DataResponse(['status' => 'error', 'message' => "Token #{$tid} not found."], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add allowed user or group delegation rule
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addDelegation(
        string $service_id = '',
        string $subject_type = 'GROUP',
        string $subject_id = ''
    ): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $sid = trim($service_id !== '' ? $service_id : (string)($body['service_id'] ?? ''));
        $type = strtoupper(trim($subject_type !== '' ? $subject_type : (string)($body['subject_type'] ?? 'GROUP')));
        $subject = trim($subject_id !== '' ? $subject_id : (string)($body['subject_id'] ?? ''));

        if ($sid === '' || !in_array($type, ['USER', 'GROUP'], true) || $subject === '') {
            return new DataResponse(['status' => 'error', 'message' => 'service_id, subject_type (USER/GROUP), and subject_id are required.'], Http::STATUS_BAD_REQUEST);
        }

        if ($type === 'USER' && !$this->userManager->userExists($subject)) {
            return new DataResponse(['status' => 'error', 'message' => "User '{$subject}' does not exist."], Http::STATUS_BAD_REQUEST);
        }
        if ($type === 'GROUP' && !$this->groupManager->groupExists($subject)) {
            return new DataResponse(['status' => 'error', 'message' => "Group '{$subject}' does not exist."], Http::STATUS_BAD_REQUEST);
        }

        try {
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_delegations')
               ->values([
                   'service_id' => $qb->createNamedParameter($sid),
                   'subject_type' => $qb->createNamedParameter($type),
                   'subject_id' => $qb->createNamedParameter($subject),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            return new DataResponse([
                'status' => 'success',
                'message' => "Added delegation rule for Service '{$sid}' -> {$type}:{$subject}.",
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove delegation rule
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeDelegation(int $delegation_id = 0): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $did = $delegation_id > 0 ? $delegation_id : (int)($body['delegation_id'] ?? 0);

        if ($did <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Valid delegation_id is required.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_ai_delegations')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($did)));
            $affected = $qb->executeStatement();

            if ($affected > 0) {
                return new DataResponse(['status' => 'success', 'message' => 'Delegation rule removed.']);
            }
            return new DataResponse(['status' => 'error', 'message' => 'Delegation rule not found.'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Fetch recent AI audit logs
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function audit(int $limit = 50): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $limit = max(1, min($limit, 200));

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('archive_ai_audit')
               ->orderBy('id', 'DESC')
               ->setMaxResults($limit);
            $rows = $qb->executeQuery()->fetchAllAssociative();

            return new DataResponse([
                'status' => 'success',
                'count' => count($rows),
                'logs' => $rows,
            ]);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Interactive AI Sandbox Test API
     * Executes test retrieval / validation with live status and audit reflection
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function testApi(
        string $token = '',
        int $file_id = 0,
        string $on_behalf_of = ''
    ): DataResponse {
        if ($err = $this->checkAdmin()) return $err;

        $body = $this->request->getParams();
        $rawToken = trim($token !== '' ? $token : (string)($body['token'] ?? ''));
        $fileId = $file_id > 0 ? $file_id : (int)($body['file_id'] ?? 0);
        $onBehalf = trim($on_behalf_of !== '' ? $on_behalf_of : (string)($body['on_behalf_of'] ?? ''));

        if ($rawToken === '') {
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_UNAUTHORIZED,
                'result_label' => '401 Unauthorized',
                'reason' => 'No Bearer token provided.',
            ]);
        }

        if ($fileId <= 0) {
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_BAD_REQUEST,
                'result_label' => '400 Bad Request',
                'reason' => 'Invalid or missing file_id.',
            ]);
        }

        // 1. Authenticate Token directly against DB hash
        $tokenHash = hash('sha256', $rawToken);
        $prefix = substr($rawToken, 0, 12);
        $now = time();

        $tqb = $this->db->getQueryBuilder();
        $tqb->select('*')
            ->from('archive_ai_tokens')
            ->where($tqb->expr()->eq('token_prefix', $tqb->createNamedParameter($prefix)));
        $tokenRows = $tqb->executeQuery()->fetchAllAssociative();

        $matchedToken = null;
        foreach ($tokenRows as $r) {
            if (hash_equals((string)$r['token_hash'], $tokenHash)) {
                $matchedToken = $r;
                break;
            }
        }

        $clientIp = $this->request->getRemoteAddress() ?: '127.0.0.1';
        $requestId = 'test_' . bin2hex(random_bytes(8));

        if (!$matchedToken) {
            $this->aiFileService->recordAudit(
                $requestId, 'anonymous', 'UI_TEST', $fileId, "file_{$fileId}",
                'UI_SANDBOX', 'UNAUTHORIZED', $clientIp, 0, 'Invalid token hash',
                'unknown', null, $onBehalf ?: null, 'NONE'
            );
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_UNAUTHORIZED,
                'result_label' => '401 Unauthorized',
                'reason' => 'Token hash not recognized in database or invalid secret.',
                'request_id' => $requestId,
            ]);
        }

        // Check token revocation
        if ($matchedToken['status'] === 'REVOKED') {
            $this->aiFileService->recordAudit(
                $requestId, 'anonymous', $matchedToken['service_id'], $fileId, "file_{$fileId}",
                'UI_SANDBOX', 'REVOKED', $clientIp, 0, 'Token has been revoked',
                $matchedToken['service_id'], (int)$matchedToken['id'], $onBehalf ?: null, 'NONE'
            );
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_UNAUTHORIZED,
                'result_label' => '401 Unauthorized (REVOKED)',
                'reason' => 'Token status is REVOKED. Access immediately denied.',
                'request_id' => $requestId,
            ]);
        }

        // Check token expiry
        if (!empty($matchedToken['expires_at']) && (int)$matchedToken['expires_at'] < $now) {
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_UNAUTHORIZED,
                'result_label' => '401 Unauthorized (EXPIRED)',
                'reason' => 'Token has expired on ' . date('Y-m-d H:i:s', (int)$matchedToken['expires_at']),
                'request_id' => $requestId,
            ]);
        }

        // 2. Service Lookup
        $sqb = $this->db->getQueryBuilder();
        $sqb->select('*')->from('archive_ai_services')->where($sqb->expr()->eq('service_id', $sqb->createNamedParameter($matchedToken['service_id'])));
        $service = $sqb->executeQuery()->fetchAssociative();

        if (!$service || empty($service['is_active'])) {
            return new DataResponse([
                'success' => false,
                'http_status' => Http::STATUS_FORBIDDEN,
                'result_label' => '403 Forbidden',
                'reason' => "Service '{$matchedToken['service_id']}' is inactive or disabled.",
                'request_id' => $requestId,
            ]);
        }

        // 3. Delegation Validation
        $actorUid = $service['default_actor_uid'] ?: 'ai_worker';
        $delegationStatus = 'NONE';

        if ($onBehalf !== '') {
            // Check admin impersonation
            if ($onBehalf === 'admin' && empty($service['allow_admin_delegation'])) {
                $this->aiFileService->recordAudit(
                    $requestId, 'admin', $service['service_id'], $fileId, "file_{$fileId}",
                    'UI_SANDBOX', 'FORBIDDEN', $clientIp, 0, 'Blocked: Admin impersonation forbidden',
                    $service['service_id'], (int)$matchedToken['id'], 'admin', 'FORBIDDEN'
                );
                return new DataResponse([
                    'success' => false,
                    'http_status' => Http::STATUS_FORBIDDEN,
                    'result_label' => '403 Forbidden (Admin Spoof Protection)',
                    'reason' => "⛔ HARDENED GATE TRIGGERED: Service '{$service['service_id']}' is blocked from impersonating administrator account 'admin' (allow_admin_delegation = false).",
                    'actor_uid' => 'BLOCKED',
                    'delegation_status' => 'FORBIDDEN',
                    'request_id' => $requestId,
                ]);
            }

            // Verify user exists
            if (!$this->userManager->userExists($onBehalf)) {
                $this->aiFileService->recordAudit(
                    $requestId, $onBehalf, $service['service_id'], $fileId, "file_{$fileId}",
                    'UI_SANDBOX', 'UNAUTHORIZED', $clientIp, 0, 'Delegated user not found',
                    $service['service_id'], (int)$matchedToken['id'], $onBehalf, 'FORBIDDEN'
                );
                return new DataResponse([
                    'success' => false,
                    'http_status' => Http::STATUS_UNAUTHORIZED,
                    'result_label' => '401 Unauthorized',
                    'reason' => "Delegated user '{$onBehalf}' does not exist in Nextcloud.",
                    'request_id' => $requestId,
                ]);
            }

            // Policy check
            $policy = (string)($service['delegation_policy'] ?? 'DENY_ALL');
            if ($policy === 'DENY_ALL') {
                $this->aiFileService->recordAudit(
                    $requestId, $onBehalf, $service['service_id'], $fileId, "file_{$fileId}",
                    'UI_SANDBOX', 'FORBIDDEN', $clientIp, 0, 'Service policy is DENY_ALL',
                    $service['service_id'], (int)$matchedToken['id'], $onBehalf, 'FORBIDDEN'
                );
                return new DataResponse([
                    'success' => false,
                    'http_status' => Http::STATUS_FORBIDDEN,
                    'result_label' => '403 Forbidden (Deny-All)',
                    'reason' => "⛔ Service policy is configured to DENY_ALL delegation.",
                    'request_id' => $requestId,
                ]);
            }

            // Check if user is in permitted users or permitted groups
            $allowed = false;
            $delQb = $this->db->getQueryBuilder();
            $delQb->select('*')
                  ->from('archive_ai_delegations')
                  ->where($delQb->expr()->eq('service_id', $delQb->createNamedParameter($service['service_id'])));
            $delegations = $delQb->executeQuery()->fetchAllAssociative();

            $targetUser = $this->userManager->get($onBehalf);
            $targetUserGroups = $targetUser !== null ? $this->groupManager->getUserGroupIds($targetUser) : [];

            foreach ($delegations as $d) {
                if ($d['subject_type'] === 'USER' && $d['subject_id'] === $onBehalf) {
                    $allowed = true;
                    break;
                }
                if ($d['subject_type'] === 'GROUP' && in_array($d['subject_id'], $targetUserGroups, true)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                $this->aiFileService->recordAudit(
                    $requestId, $onBehalf, $service['service_id'], $fileId, "file_{$fileId}",
                    'UI_SANDBOX', 'FORBIDDEN', $clientIp, 0, 'Deny-by-default: User not in delegation allowlist',
                    $service['service_id'], (int)$matchedToken['id'], $onBehalf, 'FORBIDDEN'
                );
                return new DataResponse([
                    'success' => false,
                    'http_status' => Http::STATUS_FORBIDDEN,
                    'result_label' => '403 Forbidden (Deny-by-Default)',
                    'reason' => "⛔ DENY-BY-DEFAULT POLICY: Service '{$service['service_id']}' does not have permission to act on behalf of user '{$onBehalf}' (neither user nor their groups are in allowlist).",
                    'actor_uid' => $onBehalf,
                    'delegation_status' => 'FORBIDDEN',
                    'request_id' => $requestId,
                ]);
            }

            $actorUid = $onBehalf;
            $delegationStatus = 'ALLOWED';
        }

        // 4. File Retrieval and ACL Check via validateAndGetFileNode
        try {
            $nodeResult = $this->aiFileService->validateAndGetFileNode($fileId, $actorUid);
            $node = $nodeResult['node'];

            // Get metadata
            $meta = $this->aiFileService->getFileMetadata($node, $fileId, $actorUid);

            $this->aiFileService->recordAudit(
                $requestId, $actorUid, $service['service_id'], $fileId, $node->getName(),
                'UI_SANDBOX', 'ALLOWED', $clientIp, $node->getSize(), 'Success via UI Sandbox',
                $service['service_id'], (int)$matchedToken['id'], $onBehalf ?: null, $delegationStatus
            );

            return new DataResponse([
                'success' => true,
                'http_status' => Http::STATUS_OK,
                'result_label' => '200 OK - Access Granted',
                'reason' => "File #{$fileId} successfully resolved within ACL boundaries of effective actor '{$actorUid}'.",
                'actor_uid' => $actorUid,
                'delegation_status' => $delegationStatus,
                'service_id' => $service['service_id'],
                'file_meta' => $meta,
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $t) {
            $msg = $t->getMessage();
            $code = $t->getCode() ?: Http::STATUS_NOT_FOUND;
            $this->aiFileService->recordAudit(
                $requestId, $actorUid, $service['service_id'], $fileId, "file_{$fileId}",
                'UI_SANDBOX', 'ERROR', $clientIp, 0, $msg,
                $service['service_id'], (int)$matchedToken['id'], $onBehalf ?: null, $delegationStatus
            );
            return new DataResponse([
                'success' => false,
                'http_status' => $code,
                'result_label' => "HTTP {$code}",
                'reason' => $msg,
                'actor_uid' => $actorUid,
                'delegation_status' => $delegationStatus,
                'request_id' => $requestId,
            ]);
        }
    }
}
