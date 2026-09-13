<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Command;

use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FileGrantCommand extends Command {
    protected static $defaultName = 'archive:file:grant';

    public function __construct(
        private FileOwnershipService $fileOwnershipService,
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IRootFolder $rootFolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('archive:file:grant')
            ->setDescription('Manage administrator file access grants for users and groups')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: grant, revoke, list, set-owner')
            ->addArgument('file', InputArgument::REQUIRED, 'Target file ID or file path (e.g. 195 or /Enterprise_Archive/doc.pdf)')
            ->addArgument('grantee', InputArgument::OPTIONAL, 'Target username or group name')
            ->addOption('group', 'g', InputOption::VALUE_NONE, 'Target grantee is a group rather than a user')
            ->addOption('permissions', 'p', InputOption::VALUE_OPTIONAL, 'Numeric permissions mask (default: 31)', '31');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = strtolower((string)$input->getArgument('action'));
        $fileArg = (string)$input->getArgument('file');
        $grantee = (string)$input->getArgument('grantee');
        $isGroup = (bool)$input->getOption('group');
        $perms = (int)$input->getOption('permissions');

        $fileId = $this->resolveFileId($fileArg);
        if ($fileId === null) {
            $output->writeln("<error>Could not find file: {$fileArg}</error>");
            return Command::FAILURE;
        }

        switch ($action) {
            case 'grant':
                if ($grantee === '') {
                    $output->writeln("<error>Please specify a grantee (user or group).</error>");
                    return Command::FAILURE;
                }
                if ($isGroup) {
                    if (!$this->groupManager->groupExists($grantee)) {
                        $output->writeln("<error>Group '{$grantee}' does not exist.</error>");
                        return Command::FAILURE;
                    }
                } else {
                    if ($this->userManager->get($grantee) === null) {
                        $output->writeln("<error>User '{$grantee}' does not exist.</error>");
                        return Command::FAILURE;
                    }
                }
                $this->fileOwnershipService->grantAccess($fileId, $grantee, $isGroup, 'admin', $perms);
                $typeStr = $isGroup ? 'Group' : 'User';
                $output->writeln("<info>Successfully granted access on file ID {$fileId} to {$typeStr} '{$grantee}'.</info>");
                return Command::SUCCESS;

            case 'revoke':
                if ($grantee === '') {
                    $output->writeln("<error>Please specify a grantee to revoke.</error>");
                    return Command::FAILURE;
                }
                $this->fileOwnershipService->revokeAccess($fileId, $grantee, $isGroup);
                $typeStr = $isGroup ? 'Group' : 'User';
                $output->writeln("<info>Successfully revoked access on file ID {$fileId} from {$typeStr} '{$grantee}'.</info>");
                return Command::SUCCESS;

            case 'list':
                $owner = $this->fileOwnershipService->getFileOwner($fileId) ?? '(unassigned / admin)';
                $output->writeln("<info>File ID: <comment>{$fileId}</comment> | Owner: <comment>{$owner}</comment></info>");
                $grants = $this->fileOwnershipService->getGrants($fileId);
                if (empty($grants)) {
                    $output->writeln("  (No explicit grants configured for this file)");
                } else {
                    $output->writeln("  Active Grants:");
                    foreach ($grants as $g) {
                        $output->writeln("    - [{$g['grantee_type']}] {$g['grantee_id']} (Permissions: {$g['permissions']}, Granted by: {$g['granted_by']})");
                    }
                }
                return Command::SUCCESS;

            case 'set-owner':
                if ($grantee === '') {
                    $output->writeln("<error>Please specify new owner username.</error>");
                    return Command::FAILURE;
                }
                $this->fileOwnershipService->setFileOwner($fileId, $grantee);
                $output->writeln("<info>Successfully updated owner of file ID {$fileId} to '{$grantee}'.</info>");
                return Command::SUCCESS;

            default:
                $output->writeln("<error>Unknown action: {$action}. Use grant, revoke, list, or set-owner.</error>");
                return Command::FAILURE;
        }
    }

    private function resolveFileId(string $fileArg): ?int {
        if (is_numeric($fileArg)) {
            return (int)$fileArg;
        }

        $cleanPath = ltrim($fileArg, '/');
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
           ->from('filecache')
           ->where(
               $qb->expr()->orX(
                   $qb->expr()->eq('path', $qb->createNamedParameter($cleanPath)),
                   $qb->expr()->like('path', $qb->createNamedParameter('%/' . $cleanPath)),
                   $qb->expr()->like('path', $qb->createNamedParameter('files/' . $cleanPath))
               )
           )
           ->setMaxResults(1);
        $res = $qb->executeQuery()->fetchAssociative();
        return $res ? (int)$res['fileid'] : null;
    }
}