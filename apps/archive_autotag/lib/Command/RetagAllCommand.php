<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetagAllCommand extends Command {
    protected static $defaultName = 'archive:retag';

    private AutoTagService $autoTagService;
    private IRootFolder $rootFolder;
    private IUserManager $userManager;

    public function __construct(
        AutoTagService $autoTagService,
        IRootFolder $rootFolder,
        IUserManager $userManager
    ) {
        parent::__construct();
        $this->autoTagService = $autoTagService;
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
    }

    protected function configure(): void {
        $this->setName('archive:retag')
            ->setDescription('Retroactively tag files with hierarchical parent folder names')
            ->addArgument('user', InputArgument::OPTIONAL, 'Target username (default: all users)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $targetUser = $input->getArgument('user');

        if ($targetUser) {
            $user = $this->userManager->get($targetUser);
            if ($user === null) {
                $output->writeln("<error>User '{$targetUser}' not found.</error>");
                return Command::FAILURE;
            }
            $users = [$user];
        } else {
            $users = $this->userManager->search('');
        }

        $totalTagged = 0;
        foreach ($users as $user) {
            $uid = $user->getUID();
            $output->writeln("<info>Processing user: {$uid}...</info>");
            try {
                $userFolder = $this->rootFolder->getUserFolder($uid);
                $count = $this->autoTagService->retagAllRecursive($userFolder);
                $totalTagged += $count;
                $output->writeln("  - Tagged/inspected {$count} items for {$uid}");
            } catch (\Throwable $e) {
                $output->writeln("<error>Error processing user {$uid}: {$e->getMessage()}</error>");
            }
        }

        $output->writeln("<info>Completed. Total items inspected/tagged: {$totalTagged}</info>");
        return Command::SUCCESS;
    }
}
