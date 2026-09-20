<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AiServiceCommand extends Command {

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('archive:ai')
            ->setDescription('Enterprise Archive AI Service, Token Lifecycle & Delegation Management')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: service-create, service-list, token-create, token-rotate, token-revoke, token-list, delegation-add, delegation-remove, delegation-list')
            ->addArgument('service_id', InputArgument::OPTIONAL, 'Target Service ID')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Display name for service or token', '')
            ->addOption('desc', null, InputOption::VALUE_OPTIONAL, 'Description for service', '')
            ->addOption('default-user', null, InputOption::VALUE_OPTIONAL, 'Default actor UID for undelegated requests', 'api_worker')
            ->addOption('policy', null, InputOption::VALUE_OPTIONAL, 'Delegation Policy (DENY_ALL, SPECIFIC_USERS, SPECIFIC_GROUPS, SPECIFIC_USERS_AND_GROUPS)', 'SPECIFIC_GROUPS')
            ->addOption('allow-admin', null, InputOption::VALUE_NONE, 'Explicitly permit delegation to admin accounts (High Risk)')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Delegation subject type: user or group')
            ->addOption('subject', null, InputOption::VALUE_OPTIONAL, 'Delegation subject ID (UID or Group ID)')
            ->addOption('grace-hours', null, InputOption::VALUE_OPTIONAL, 'Grace period hours for rotated tokens', '48')
            ->addOption('expires-days', null, InputOption::VALUE_OPTIONAL, 'Days until token expiration', null)
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Token prefix or ID for revocation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = strtolower((string)$input->getArgument('action'));
        $serviceId = (string)($input->getArgument('service_id') ?? '');

        switch ($action) {
            case 'service-create':
                return $this->handleServiceCreate($input, $output, $serviceId);
            case 'service-list':
                return $this->handleServiceList($output);
            case 'token-create':
                return $this->handleTokenCreate($input, $output, $serviceId);
            case 'token-rotate':
                return $this->handleTokenRotate($input, $output, $serviceId);
            case 'token-revoke':
                return $this->handleTokenRevoke($input, $output);
            case 'token-list':
                return $this->handleTokenList($output, $serviceId);
            case 'delegation-add':
                return $this->handleDelegationAdd($input, $output, $serviceId);
            case 'delegation-remove':
                return $this->handleDelegationRemove($input, $output, $serviceId);
            case 'delegation-list':
                return $this->handleDelegationList($output, $serviceId);
            default:
                $output->writeln("<error>Unknown action '{$action}'. Use --help for available actions.</error>");
                return 1;
        }
    }

    private function handleServiceCreate(InputInterface $input, OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        $displayName = (string)$input->getOption('name') ?: $serviceId;
        $desc = (string)$input->getOption('desc');
        $defaultUser = (string)$input->getOption('default-user');
        $policy = strtoupper((string)$input->getOption('policy'));
        $allowAdmin = (bool)$input->getOption('allow-admin');

        $validPolicies = ['DENY_ALL', 'SPECIFIC_USERS', 'SPECIFIC_GROUPS', 'SPECIFIC_USERS_AND_GROUPS'];
        if (!in_array($policy, $validPolicies, true)) {
            $output->writeln("<error>Invalid policy '{$policy}'. Valid: " . implode(', ', $validPolicies) . "</error>");
            return 1;
        }

        $now = time();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_services')
               ->values([
                   'service_id' => $qb->createNamedParameter($serviceId),
                   'display_name' => $qb->createNamedParameter($displayName),
                   'description' => $qb->createNamedParameter($desc),
                   'default_actor_uid' => $qb->createNamedParameter($defaultUser),
                   'delegation_policy' => $qb->createNamedParameter($policy),
                   'allow_admin_delegation' => $qb->createNamedParameter($allowAdmin, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                   'is_active' => $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                   'created_at' => $qb->createNamedParameter($now),
                   'updated_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();
            $output->writeln("<info>AI Service '{$serviceId}' created successfully.</info>");
            return 0;
        } catch (\Throwable $t) {
            $output->writeln("<error>Failed to create service: " . $t->getMessage() . "</error>");
            return 1;
        }
    }

    private function handleServiceList(OutputInterface $output): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('archive_ai_services')->orderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            $output->writeln('<comment>No AI services registered.</comment>');
            return 0;
        }

        $output->writeln(sprintf("%-4s | %-20s | %-24s | %-14s | %-20s | %-12s | %-6s", "ID", "Service ID", "Name", "Default UID", "Delegation Policy", "Admin Deleg", "Active"));
        $output->writeln(str_repeat('-', 112));
        foreach ($rows as $r) {
            $output->writeln(sprintf(
                "%-4d | %-20s | %-24s | %-14s | %-20s | %-12s | %-6s",
                $r['id'],
                $r['service_id'],
                substr((string)$r['display_name'], 0, 24),
                $r['default_actor_uid'],
                $r['delegation_policy'],
                $r['allow_admin_delegation'] ? 'TRUE (RISK)' : 'FALSE',
                $r['is_active'] ? 'YES' : 'NO'
            ));
        }
        return 0;
    }

    private function handleTokenCreate(InputInterface $input, OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        // Verify service exists
        $sqb = $this->db->getQueryBuilder();
        $sqb->select('id')->from('archive_ai_services')->where($sqb->expr()->eq('service_id', $sqb->createNamedParameter($serviceId)));
        if (!$sqb->executeQuery()->fetchOne()) {
            $output->writeln("<error>Service '{$serviceId}' does not exist.</error>");
            return 1;
        }

        $name = (string)$input->getOption('name') ?: 'Token ' . date('Y-m-d H:i');
        $expiresDays = $input->getOption('expires-days') ? (int)$input->getOption('expires-days') : null;
        $expiresAt = $expiresDays !== null ? time() + ($expiresDays * 86400) : null;

        // Generate cryptographically secure token
        $rawSecret = bin2hex(random_bytes(32));
        $rawToken = 'nc_ai_' . $rawSecret;
        $prefix = substr($rawToken, 0, 12);
        $hash = hash('sha256', $rawToken);
        $now = time();

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_tokens')
               ->values([
                   'service_id' => $qb->createNamedParameter($serviceId),
                   'token_name' => $qb->createNamedParameter($name),
                   'token_prefix' => $qb->createNamedParameter($prefix),
                   'token_hash' => $qb->createNamedParameter($hash),
                   'status' => $qb->createNamedParameter('ACTIVE'),
                   'expires_at' => $qb->createNamedParameter($expiresAt),
                   'created_by' => $qb->createNamedParameter('occ_cli'),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $output->writeln("<info>Successfully issued token for service '{$serviceId}'!</info>");
            $output->writeln("<comment>Prefix:</comment>  {$prefix}");
            $output->writeln("<comment>Raw Token (SAVE THIS NOW, IT WILL NEVER BE SHOWN AGAIN):</comment>");
            $output->writeln("<info>{$rawToken}</info>");
            if ($expiresAt) {
                $output->writeln("<comment>Expires at:</comment> " . date('Y-m-d H:i:s', $expiresAt));
            }
            return 0;
        } catch (\Throwable $t) {
            $output->writeln("<error>Failed to issue token: " . $t->getMessage() . "</error>");
            return 1;
        }
    }

    private function handleTokenRotate(InputInterface $input, OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        $graceHours = (int)($input->getOption('grace-hours') ?: 48);
        $graceUntil = time() + ($graceHours * 3600);
        $now = time();

        // 1. Mark existing ACTIVE tokens as GRACE_PERIOD
        $upQb = $this->db->getQueryBuilder();
        $upQb->update('archive_ai_tokens')
             ->set('status', $upQb->createNamedParameter('GRACE_PERIOD'))
             ->set('grace_period_until', $upQb->createNamedParameter($graceUntil))
             ->where($upQb->expr()->eq('service_id', $upQb->createNamedParameter($serviceId)))
             ->andWhere($upQb->expr()->eq('status', $upQb->createNamedParameter('ACTIVE')));
        $upQb->executeStatement();

        // 2. Issue new ACTIVE token
        $rawSecret = bin2hex(random_bytes(32));
        $rawToken = 'nc_ai_' . $rawSecret;
        $prefix = substr($rawToken, 0, 12);
        $hash = hash('sha256', $rawToken);
        $name = 'Rotated Token ' . date('Y-m-d H:i');

        $insQb = $this->db->getQueryBuilder();
        $insQb->insert('archive_ai_tokens')
              ->values([
                  'service_id' => $insQb->createNamedParameter($serviceId),
                  'token_name' => $insQb->createNamedParameter($name),
                  'token_prefix' => $insQb->createNamedParameter($prefix),
                  'token_hash' => $insQb->createNamedParameter($hash),
                  'status' => $insQb->createNamedParameter('ACTIVE'),
                  'created_by' => $insQb->createNamedParameter('occ_rotate'),
                  'created_at' => $insQb->createNamedParameter($now),
              ]);
        $insQb->executeStatement();

        $output->writeln("<info>Successfully rotated tokens for service '{$serviceId}'.</info>");
        $output->writeln("<comment>Previous active tokens set to GRACE_PERIOD until " . date('Y-m-d H:i:s', $graceUntil) . " ({$graceHours}h grace).</comment>");
        $output->writeln("<comment>New Token Prefix:</comment> {$prefix}");
        $output->writeln("<comment>New Raw Token (SAVE THIS NOW):</comment>");
        $output->writeln("<info>{$rawToken}</info>");
        return 0;
    }

    private function handleTokenRevoke(InputInterface $input, OutputInterface $output): int {
        $tokenIdentifier = (string)($input->getOption('token') ?: $input->getArgument('service_id'));
        if ($tokenIdentifier === '') {
            $output->writeln('<error>Specify --token=<prefix_or_id> to revoke.</error>');
            return 1;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_ai_tokens')
           ->set('status', $qb->createNamedParameter('REVOKED'))
           ->where(
               $qb->expr()->orX(
                   $qb->expr()->eq('token_prefix', $qb->createNamedParameter($tokenIdentifier)),
                   $qb->expr()->eq('id', $qb->createNamedParameter((int)$tokenIdentifier))
               )
           );
        $affected = $qb->executeStatement();

        if ($affected > 0) {
            $output->writeln("<info>Revoked {$affected} token(s) matching '{$tokenIdentifier}'.</info>");
            return 0;
        } else {
            $output->writeln("<comment>No active token found matching '{$tokenIdentifier}'.</comment>");
            return 1;
        }
    }

    private function handleTokenList(OutputInterface $output, string $serviceId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('archive_ai_tokens');
        if ($serviceId !== '') {
            $qb->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)));
        }
        $qb->orderBy('id', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            $output->writeln('<comment>No tokens found.</comment>');
            return 0;
        }

        $output->writeln(sprintf("%-4s | %-16s | %-14s | %-12s | %-20s | %-20s", "ID", "Service ID", "Prefix", "Status", "Created At", "Last Used At"));
        $output->writeln(str_repeat('-', 94));
        foreach ($rows as $r) {
            $created = $r['created_at'] ? date('Y-m-d H:i', (int)$r['created_at']) : '-';
            $used = $r['last_used_at'] ? date('Y-m-d H:i', (int)$r['last_used_at']) : 'Never';
            $output->writeln(sprintf(
                "%-4d | %-16s | %-14s | %-12s | %-20s | %-20s",
                $r['id'],
                $r['service_id'],
                $r['token_prefix'],
                $r['status'],
                $created,
                $used
            ));
        }
        return 0;
    }

    private function handleDelegationAdd(InputInterface $input, OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        $type = strtoupper((string)$input->getOption('type'));
        $subject = (string)$input->getOption('subject');

        if (!in_array($type, ['USER', 'GROUP'], true) || $subject === '') {
            $output->writeln('<error>Specify valid --type=user|group and --subject=<id>.</error>');
            return 1;
        }

        // Validate subject exists
        if ($type === 'USER' && !$this->userManager->userExists($subject)) {
            $output->writeln("<error>User '{$subject}' does not exist in Nextcloud.</error>");
            return 1;
        }
        if ($type === 'GROUP' && !$this->groupManager->groupExists($subject)) {
            $output->writeln("<error>Group '{$subject}' does not exist in Nextcloud.</error>");
            return 1;
        }

        $now = time();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_ai_delegations')
               ->values([
                   'service_id' => $qb->createNamedParameter($serviceId),
                   'subject_type' => $qb->createNamedParameter($type),
                   'subject_id' => $qb->createNamedParameter($subject),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();
            $output->writeln("<info>Added delegation rule: Service '{$serviceId}' -> {$type}:{$subject}</info>");
            return 0;
        } catch (\Throwable $t) {
            $output->writeln("<error>Failed to add delegation rule: " . $t->getMessage() . "</error>");
            return 1;
        }
    }

    private function handleDelegationRemove(InputInterface $input, OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        $type = strtoupper((string)$input->getOption('type'));
        $subject = (string)$input->getOption('subject');

        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_ai_delegations')
           ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
           ->andWhere($qb->expr()->eq('subject_type', $qb->createNamedParameter($type)))
           ->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subject)));
        $affected = $qb->executeStatement();

        if ($affected > 0) {
            $output->writeln("<info>Removed delegation rule for {$type}:{$subject}.</info>");
            return 0;
        } else {
            $output->writeln('<comment>No matching delegation rule found.</comment>');
            return 1;
        }
    }

    private function handleDelegationList(OutputInterface $output, string $serviceId): int {
        if ($serviceId === '') {
            $output->writeln('<error>Missing required service_id argument.</error>');
            return 1;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_ai_delegations')
           ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
           ->orderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            $output->writeln("<comment>No delegation rules configured for '{$serviceId}'.</comment>");
            return 0;
        }

        $output->writeln(sprintf("%-4s | %-16s | %-8s | %-24s | %-20s", "ID", "Service ID", "Type", "Subject", "Created At"));
        $output->writeln(str_repeat('-', 78));
        foreach ($rows as $r) {
            $output->writeln(sprintf(
                "%-4d | %-16s | %-8s | %-24s | %-20s",
                $r['id'],
                $r['service_id'],
                $r['subject_type'],
                $r['subject_id'],
                date('Y-m-d H:i', (int)$r['created_at'])
            ));
        }
        return 0;
    }
}
