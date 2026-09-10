<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserLimitCommand extends Command {
    protected static $defaultName = 'archive:user:limit';

    private UploadLimitService $uploadLimitService;
    private IUserManager $userManager;

    public function __construct(
        UploadLimitService $uploadLimitService,
        IUserManager $userManager
    ) {
        parent::__construct();
        $this->uploadLimitService = $uploadLimitService;
        $this->userManager = $userManager;
    }

    protected function configure(): void {
        $this->setName('archive:user:limit')
            ->setDescription('Configure per-user maximum file upload size limits')
            ->addArgument('user', InputArgument::OPTIONAL, 'Target username')
            ->addArgument('limit', InputArgument::OPTIONAL, 'Max file size limit (e.g. 50M, 500K, 1G, or 0 for unlimited)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List all users with configured limits');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('list')) {
            $limits = $this->uploadLimitService->getAllConfiguredLimits();
            if (empty($limits)) {
                $output->writeln("<info>No custom upload limits configured for any user.</info>");
                return Command::SUCCESS;
            }
            $output->writeln("<info>Configured User Upload Limits:</info>");
            foreach ($limits as $uid => $bytes) {
                $formatted = UploadLimitService::formatSize($bytes);
                $output->writeln("  - User: <comment>{$uid}</comment> => Limit: <comment>{$formatted}</comment> ({$bytes} bytes)");
            }
            return Command::SUCCESS;
        }

        $targetUser = $input->getArgument('user');
        if (!$targetUser) {
            $output->writeln("<error>Please specify a username or use --list.</error>");
            $output->writeln("Usage: php occ archive:user:limit <user> [<limit>] [--list]");
            return Command::FAILURE;
        }

        $user = $this->userManager->get($targetUser);
        if ($user === null) {
            $output->writeln("<error>User '{$targetUser}' does not exist.</error>");
            return Command::FAILURE;
        }

        $limitArg = $input->getArgument('limit');
        if ($limitArg === null) {
            // Display current limit
            $currentBytes = $this->uploadLimitService->getUserLimit($targetUser);
            $formatted = UploadLimitService::formatSize($currentBytes);
            $output->writeln("Current upload limit for <comment>{$targetUser}</comment>: <info>{$formatted}</info>");
            return Command::SUCCESS;
        }

        $bytes = UploadLimitService::parseSize($limitArg);
        $this->uploadLimitService->setUserLimit($targetUser, $bytes);

        if ($bytes <= 0) {
            $output->writeln("<info>Upload limit for user '{$targetUser}' has been removed (Unlimited).</info>");
        } else {
            $formatted = UploadLimitService::formatSize($bytes);
            $output->writeln("<info>Successfully set upload limit for user '{$targetUser}' to {$formatted} ({$bytes} bytes).</info>");
        }

        return Command::SUCCESS;
    }
}
