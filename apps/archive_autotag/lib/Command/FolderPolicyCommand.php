<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\FolderPolicyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FolderPolicyCommand extends Command {
    private FolderPolicyService $folderPolicyService;

    public function __construct(FolderPolicyService $folderPolicyService) {
        parent::__construct();
        $this->folderPolicyService = $folderPolicyService;
    }

    protected function configure(): void {
        $this
            ->setName('archive:folder:policy')
            ->setDescription('Manage folder creation restriction policy (admin-only folder hierarchy)')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action to perform: status, enable, disable',
                'status'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = strtolower((string)$input->getArgument('action'));

        switch ($action) {
            case 'enable':
                $this->folderPolicyService->setPolicyEnabled(true);
                $output->writeln('<info>Folder creation restriction policy ENABLED (Admin-only folder hierarchy).</info>');
                return 0;

            case 'disable':
                $this->folderPolicyService->setPolicyEnabled(false);
                $output->writeln('<comment>Folder creation restriction policy DISABLED (Regular users can create folders).</comment>');
                return 0;

            case 'status':
            default:
                $enabled = $this->folderPolicyService->isPolicyEnabled();
                if ($enabled) {
                    $output->writeln('<info>Folder creation policy: ENABLED (Restricted to Administrators only).</info>');
                } else {
                    $output->writeln('<comment>Folder creation policy: DISABLED.</comment>');
                }
                return 0;
        }
    }
}