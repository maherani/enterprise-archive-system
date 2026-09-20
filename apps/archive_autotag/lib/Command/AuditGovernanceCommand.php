<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\ReliableAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AuditGovernanceCommand extends Command {
    protected static $defaultName = 'archive:audit:gov';

    public function __construct(
        private ReliableAuditService $reliableAuditService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('archive:audit:gov')
            ->setDescription('Audit subsystem governance: monitor health, inspect statistics, and flush emergency Dead Letter Queue (DLQ)')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: health, flush-dlq, stats');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = strtolower(trim((string)$input->getArgument('action')));

        switch ($action) {
            case 'health':
            case 'stats':
                $health = $this->reliableAuditService->getAuditHealth();
                $output->writeln("=================================================");
                $output->writeln(" ENTERPRISE ARCHIVE SYSTEM - AUDIT HEALTH MONITOR");
                $output->writeln("=================================================");
                $statusColor = $health['status'] === 'HEALTHY' ? 'info' : ($health['status'] === 'DEGRADED' ? 'comment' : 'error');
                $output->writeln("<{$statusColor}>Overall Status:       {$health['status']}</{$statusColor}>");
                $output->writeln("Database Connected:   " . ($health['database_connected'] ? '<info>YES</info>' : '<error>NO</error>'));
                $output->writeln("Total Recorded Audits: <comment>{$health['total_audits']}</comment>");
                $output->writeln("Table Breakdown:");
                foreach ($health['table_counts'] as $tbl => $cnt) {
                    $output->writeln("  - {$tbl}: {$cnt}");
                }
                $dlqColor = $health['dlq_pending_count'] === 0 ? 'info' : 'error';
                $output->writeln("DLQ Pending Count:    <{$dlqColor}>{$health['dlq_pending_count']}</{$dlqColor}>");
                $output->writeln("Emergency Log Path:   {$health['emergency_log_path']}");
                $output->writeln("=================================================");
                return Command::SUCCESS;

            case 'flush-dlq':
                $output->writeln("<comment>Initiating Dead Letter Queue flush to PostgreSQL...</comment>");
                $result = $this->reliableAuditService->flushDeadLetterQueue();
                $output->writeln("<info>DLQ Flush Completed:</info>");
                $output->writeln("  - Total entries processed: {$result['total']}");
                $output->writeln("  - Successfully restored:   <info>{$result['restored']}</info>");
                $output->writeln("  - Failed / Retained:       " . ($result['failed'] > 0 ? "<error>{$result['failed']}</error>" : "0"));
                return Command::SUCCESS;

            default:
                $output->writeln("<error>Invalid action: {$action}. Supported actions: health, stats, flush-dlq</error>");
                return Command::FAILURE;
        }
    }
}
