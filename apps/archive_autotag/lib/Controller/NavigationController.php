<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Security\Permission\CentralPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\IPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
use OCA\ArchiveAutoTag\Service\FolderRequestService;
use OCA\ArchiveAutoTag\Service\TagOwnershipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;

class NavigationController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private TagOwnershipService $tagOwnershipService,
        private ISystemTagManager $tagManager,
        private FolderRequestService $folderRequestService,
        private ?IPermissionResolver $permissionResolver = null,
    ) {
        parent::__construct($appName, $request);
    }

    private function getResolver(): ?IPermissionResolver {
        if ($this->permissionResolver !== null) {
            return $this->permissionResolver;
        }
        try {
            $resolver = \OC::$server->get(CentralPermissionResolver::class);
            if ($resolver instanceof IPermissionResolver) {
                $this->permissionResolver = $resolver;
                return $resolver;
            }
        } catch (\Throwable $t) {}
        return null;
    }

    /**
     * Get accessible archive resources and navigation items for current user.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getResources(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($userId);
        $userGroups = $this->groupManager->getUserGroupIds($user);

        // Resolve admin user folder to inspect Enterprise_Archive root
        $adminUser = $this->userManager->get('admin');
        if ($adminUser === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Admin account not initialized.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());
        if (!$adminHome->nodeExists('Enterprise_Archive')) {
            return new DataResponse([
                'status' => 'success',
                'root_folder' => 'Enterprise_Archive',
                'items' => [
                    [
                        'id' => 'all',
                        'name' => 'همه اسناد',
                        'type' => 'all',
                        'icon' => '🗂️',
                        'path' => 'Enterprise_Archive',
                        'folder_url' => '/index.php/apps/files/files?dir=%2FEnterprise_Archive',
                        'tag_id' => null,
                        'description' => 'مشاهده کلیه اسناد مجاز بدون فیلتر',
                    ]
                ],
                'user' => [
                    'uid' => $userId,
                    'is_admin' => $isAdmin,
                    'groups' => $userGroups,
                ]
            ]);
        }

        $archiveRoot = $adminHome->get('Enterprise_Archive');
        if (!($archiveRoot instanceof Folder)) {
            return new DataResponse(['status' => 'error', 'message' => 'Enterprise_Archive is not a directory.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // 1. Base items: "همه اسناد" and "Enterprise_Archive"
        $items = [];
        $items[] = [
            'id' => 'all',
            'name' => 'همه اسناد',
            'type' => 'all',
            'icon' => '🗂️',
            'path' => 'Enterprise_Archive',
            'folder_url' => '/index.php/apps/files/files?dir=%2FEnterprise_Archive',
            'tag_id' => null,
            'description' => 'مشاهده کلیه اسناد مجاز بدون فیلتر',
        ];

        $items[] = [
            'id' => 'root',
            'name' => 'Enterprise_Archive',
            'type' => 'root',
            'icon' => '🏛️',
            'path' => 'Enterprise_Archive',
            'folder_url' => '/index.php/apps/files/files?dir=%2FEnterprise_Archive',
            'tag_id' => null,
            'description' => 'ریشه اصلی مخزن بایگانی سازمانی',
        ];

        // 2. Scan top-level folders under Enterprise_Archive
        $visibleTagIds = $this->tagOwnershipService->getVisibleTagIds($userId);
        $allTags = $this->tagManager->getAllTags(true);
        $tagMapByName = [];
        foreach ($allTags as $t) {
            $tagMapByName[$t->getName()] = (int)$t->getId();
        }

        if (isset($tagMapByName['Enterprise_Archive'])) {
            $items[1]['tag_id'] = $tagMapByName['Enterprise_Archive'];
        }

        $subNodes = $archiveRoot->getDirectoryListing();
        usort($subNodes, function ($a, $b) {
            return strcmp($a->getName(), $b->getName());
        });

        $resolver = $this->getResolver();

        foreach ($subNodes as $node) {
            if (!($node instanceof Folder)) {
                continue;
            }

            $folderName = $node->getName();
            $folderRelPath = 'Enterprise_Archive/' . $folderName;

            // Unified Access Check via CentralPermissionResolver
            if ($isAdmin) {
                $hasAccess = true;
            } else {
                if ($resolver !== null) {
                    $decision = $resolver->evaluateFolder($userId, $folderRelPath, PermissionOperation::READ_METADATA);
                    $hasAccess = $decision->allowed;
                } else {
                    $matchesGroup = in_array($folderName, $userGroups, true);
                    $tagId = $tagMapByName[$folderName] ?? null;
                    $tagVisible = $tagId !== null && in_array($tagId, $visibleTagIds, true);
                    $hasAccess = $matchesGroup || $tagVisible;
                }
            }

            if (!$hasAccess) {
                continue;
            }

            $tagId = $tagMapByName[$folderName] ?? null;
            $items[] = [
                'id' => $folderName,
                'name' => $folderName,
                'type' => 'department',
                'icon' => '📁',
                'path' => $folderRelPath,
                'folder_url' => '/index.php/apps/files/files?dir=' . rawurlencode('/' . $folderRelPath),
                'tag_id' => $tagId,
                'is_group' => in_array($folderName, $userGroups, true),
            ];
        }

        return new DataResponse([
            'status' => 'success',
            'root_folder' => 'Enterprise_Archive',
            'items' => $items,
            'user' => [
                'uid' => $userId,
                'display_name' => $user->getDisplayName(),
                'is_admin' => $isAdmin,
                'groups' => $userGroups,
            ]
        ]);
    }
}
