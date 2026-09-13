<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TagReconcileCommand extends Command {
    protected static $defaultName = 'archive:tag:reconcile';

    public function __construct(
        private AutoTagService $autoTagService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('archive:tag:reconcile')
            ->setAliases(['archive:tag:sync'])
            ->setDescription('Review, synchronize, and reconcile all tags: remove surplus/orphan tags, update names, and prune dead mappings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln("<info>Starting Enterprise Archive Tag Review and Reconciliation...</info>");

        $report = $this->autoTagService->reconcileAllTags();

        $output->writeln("  - Stale trash/deleted mappings cleaned: <comment>{$report['stale_mappings_cleaned']}</comment>");
        $output->writeln("  - Active folder categories detected: <info>" . count($report['active_folder_tags']) . "</info> (" . implode(', ', $report['active_folder_tags']) . ")");

        if (!empty($report['surplus_tags_deleted'])) {
            $output->writeln("\n<comment>Surplus / Orphaned Tags Deleted (" . count($report['surplus_tags_deleted']) . "):</comment>");
            $delTable = new Table($output);
            $delTable->setHeaders(['Tag ID', 'Tag Name', 'Status']);
            foreach ($report['surplus_tags_deleted'] as $del) {
                $delTable->addRow([$del['id'], $del['name'], '<error>DELETED (Surplus)</error>']);
            }
            $delTable->render();
        } else {
            $output->writeln("\n<info>No surplus orphaned tags detected. All tags in sync!</info>");
        }

        $output->writeln("\n<info>Active Tags Retained (" . count($report['tags_retained']) . "):</info>");
        $retTable = new Table($output);
        $retTable->setHeaders(['Tag ID', 'Tag Name', 'Reason', 'Active Mappings']);
        foreach ($report['tags_retained'] as $ret) {
            $retTable->addRow([$ret['id'], $ret['name'], $ret['reason'], $ret['active_mappings']]);
        }
        $retTable->render();

        $output->writeln("\n<info>? Tag Reconciliation completed successfully.</info>");
        return Command::SUCCESS;
    }
}
