<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\FolderRequestService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FolderCreateCommand extends Command {
    private FolderRequestService $folderRequestService;

    public function __construct(FolderRequestService $folderRequestService) {
        parent::__construct();
        $this->folderRequestService = $folderRequestService;
    }

    protected function configure(): void {
        $this
            ->setName('archive:folder:create')
            ->setDescription('Directly create an enterprise archive folder with automated hierarchical tagging')
            ->addArgument(
                'folder',
                InputArgument::REQUIRED,
                'Name of the new archive folder to create'
            )
            ->addOption(
                'parent',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Parent directory path inside Enterprise_Archive (e.g. "Finance/2026" or "SOC")',
                ''
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_OPTIONAL,
                'Group ID to bind permissions and tag isolation to (e.g. "SOC", "CERT")',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $folderName = (string)$input->getArgument('folder');
        $parentPath = (string)($input->getOption('parent') ?? '');
        $groupId = $input->getOption('group');

        try {
            $result = $this->folderRequestService->createFolderDirectly($folderName, $parentPath, $groupId, 'admin');
            $output->writeln("<info>✔ {$result['message']}</info>");
            $output->writeln("  - Folder Path: {$result['path']}");
            $output->writeln("  - Folder ID: {$result['folder_id']}");
            if ($groupId) {
                $output->writeln("  - Bound Group: {$groupId}");
            }
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>✘ Failed to create archive folder: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
