<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\TagOwnershipService;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TagGovernanceCommand extends Command {
    protected static $defaultName = 'archive:tag:gov';

    public function __construct(
        private TagOwnershipService $tagOwnershipService,
        private ISystemTagManager $tagManager,
        private IDBConnection $db,
        private AutoTagService $autoTagService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('archive:tag:gov')
            ->setDescription('Administrator tag governance: list, inspect, set owner, delete, and reconcile tags')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: list, delete, set-owner, reconcile')
            ->addArgument('tag', InputArgument::OPTIONAL, 'Tag ID or Tag Name (required for delete and set-owner)')
            ->addArgument('owner', InputArgument::OPTIONAL, 'Owner ID: system, admin, or username (for set-owner)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = strtolower((string)$input->getArgument('action'));
        $tagArg = (string)$input->getArgument('tag');
        $ownerArg = (string)$input->getArgument('owner');

        switch ($action) {
            case 'list':
                $qb = $this->db->getQueryBuilder();
                $qb->select('t.id', 't.name', 't.visibility', 't.editable', 'o.owner_uid')
                   ->from('systemtag', 't')
                   ->leftJoin('t', 'archive_tag_ownership', 'o', $qb->expr()->eq('t.id', 'o.tag_id'))
                   ->orderBy('t.id', 'ASC');
                $rows = $qb->executeQuery()->fetchAllAssociative();

                if (empty($rows)) {
                    $output->writeln("<info>No tags found in the system.</info>");
                    return Command::SUCCESS;
                }

                $output->writeln("<info>All System and User Tags in Archive:</info>");
                foreach ($rows as $r) {
                    $tid = $r['id'];
                    $name = $r['name'];
                    $owner = $r['owner_uid'] ?? 'system';
                    $vis = ((int)$r['visibility'] === 1) ? 'visible' : 'hidden';
                    $edit = ((int)$r['editable'] === 1) ? 'user-assignable' : 'restricted';
                    $output->writeln("  - [ID: <comment>{$tid}</comment>] '<info>{$name}</info>' | Owner: <comment>{$owner}</comment> | ({$vis}, {$edit})");
                }
                return Command::SUCCESS;

            case 'delete':
                if ($tagArg === '') {
                    $output->writeln("<error>Please specify a tag ID or tag name to delete.</error>");
                    return Command::FAILURE;
                }
                $tagId = $this->resolveTagId($tagArg);
                if ($tagId === null) {
                    $output->writeln("<error>Tag '{$tagArg}' not found.</error>");
                    return Command::FAILURE;
                }

                $this->tagManager->deleteTags([$tagId]);
                $output->writeln("<info>Successfully deleted tag ID {$tagId} ('{$tagArg}') across the entire system.</info>");
                return Command::SUCCESS;

            case 'set-owner':
                if ($tagArg === '' || $ownerArg === '') {
                    $output->writeln("<error>Usage: php occ archive:tag:gov set-owner <tag> <owner></error>");
                    return Command::FAILURE;
                }
                $tagId = $this->resolveTagId($tagArg);
                if ($tagId === null) {
                    $output->writeln("<error>Tag '{$tagArg}' not found.</error>");
                    return Command::FAILURE;
                }

                $this->tagOwnershipService->setTagOwner($tagId, $ownerArg);
                $output->writeln("<info>Successfully updated owner of tag ID {$tagId} to '<comment>{$ownerArg}</comment>'.</info>");
                return Command::SUCCESS;

            case 'reconcile':
            case 'sync':
                $output->writeln("<info>Reconciling enterprise archive tags...</info>");
                $report = $this->autoTagService->reconcileAllTags();
                $output->writeln("  - Stale mappings cleaned: <comment>{$report['stale_mappings_cleaned']}</comment>");
                $output->writeln("  - Active folder tags: <info>" . count($report['active_folder_tags']) . "</info>");
                $output->writeln("  - Surplus tags deleted: <comment>" . count($report['surplus_tags_deleted']) . "</comment>");
                $output->writeln("  - Tags retained: <info>" . count($report['tags_retained']) . "</info>");
                return Command::SUCCESS;

            default:
                $output->writeln("<error>Unknown action: {$action}. Use list, delete, set-owner, or reconcile.</error>");
                return Command::FAILURE;
        }
    }

    private function resolveTagId(string $tagArg): ?int {
        if (is_numeric($tagArg)) {
            return (int)$tagArg;
        }

        $allTags = $this->tagManager->getAllTags();
        foreach ($allTags as $t) {
            if (strcasecmp($t->getName(), $tagArg) === 0) {
                return (int)$t->getId();
            }
        }
        return null;
    }
}
