<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\SystemTag;

use OCA\ArchiveAutoTag\Service\TagOwnershipService;
use OC\SystemTag\SystemTagManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\TagUpdateForbiddenException;

class IsolatedSystemTagManager extends SystemTagManager {
    private ?TagOwnershipService $tagOwnershipService = null;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        IEventDispatcher $dispatcher,
        IUserSession $userSession,
        IAppConfig $appConfig,
        ?TagOwnershipService $tagOwnershipService = null,
    ) {
        parent::__construct($connection, $groupManager, $dispatcher, $userSession, $appConfig);
        $this->tagOwnershipService = $tagOwnershipService;
    }

    private function getOwnershipService(): TagOwnershipService {
        if ($this->tagOwnershipService === null) {
            $this->tagOwnershipService = \OC::$server->get(TagOwnershipService::class);
        }
        return $this->tagOwnershipService;
    }

    #[\Override]
    public function getAllTags($visibilityFilter = null, $nameSearchPattern = null): array {
        $allTags = parent::getAllTags($visibilityFilter, $nameSearchPattern);

        $user = \OC::$server->get(IUserSession::class)->getUser();
        if ($user === null) {
            return $allTags;
        }

        $uid = $user->getUID();
        if ($uid === 'admin' || $this->groupManager->isAdmin($uid)) {
            return $allTags;
        }

        $ownershipService = $this->getOwnershipService();
        $filtered = [];
        foreach ($allTags as $id => $tag) {
            if ($ownershipService->canUserSeeTag((int)$id, $uid)) {
                $filtered[$id] = $tag;
            }
        }
        return $filtered;
    }

    #[\Override]
    public function canUserSeeTag(ISystemTag $tag, ?IUser $user): bool {
        if ($user === null) {
            $user = \OC::$server->get(IUserSession::class)->getUser();
        }

        if ($user === null) {
            return parent::canUserSeeTag($tag, null);
        }

        $uid = $user->getUID();
        if ($uid === 'admin' || $this->groupManager->isAdmin($uid)) {
            return true;
        }

        if (!$this->getOwnershipService()->canUserSeeTag((int)$tag->getId(), $uid)) {
            return false;
        }

        return parent::canUserSeeTag($tag, $user);
    }

    #[\Override]
    public function getTagsByIds($tagIds, ?IUser $user = null): array {
        if ($user === null) {
            $user = \OC::$server->get(IUserSession::class)->getUser();
        }

        $tags = parent::getTagsByIds($tagIds, $user);
        if ($user === null) {
            return $tags;
        }

        $uid = $user->getUID();
        if ($uid === 'admin' || $this->groupManager->isAdmin($uid)) {
            return $tags;
        }

        $ownershipService = $this->getOwnershipService();
        $filtered = [];
        foreach ($tags as $id => $tag) {
            if ($ownershipService->canUserSeeTag((int)$id, $uid)) {
                $filtered[$id] = $tag;
            }
        }
        return $filtered;
    }

    #[\Override]
    public function createTag(string $tagName, bool $userVisible, bool $userAssignable, ?IUser $user = null): ISystemTag {
        $tag = parent::createTag($tagName, $userVisible, $userAssignable, $user);

        if ($user === null) {
            $user = \OC::$server->get(IUserSession::class)->getUser();
        }

        $ownerUid = 'system';
        if ($user !== null) {
            $ownerUid = ($user->getUID() === 'admin' || $this->groupManager->isAdmin($user->getUID())) ? 'admin' : $user->getUID();
        }

        $this->getOwnershipService()->setTagOwner((int)$tag->getId(), $ownerUid);
        return $tag;
    }

    #[\Override]
    public function deleteTags($tagIds): void {
        $user = \OC::$server->get(IUserSession::class)->getUser();
        $uid = $user !== null ? $user->getUID() : null;

        $isAdmin = ($uid === 'admin' || ($uid !== null && $this->groupManager->isAdmin($uid)));

        if (!\is_array($tagIds)) {
            $tagIds = [$tagIds];
        }

        $ownershipService = $this->getOwnershipService();
        if (!$isAdmin && $uid !== null) {
            foreach ($tagIds as $tid) {
                if (!$ownershipService->canUserManageTag((int)$tid, $uid)) {
                    throw new TagUpdateForbiddenException("Permission denied: You cannot delete tag ID {$tid}");
                }
            }
        }

        parent::deleteTags($tagIds);

        foreach ($tagIds as $tid) {
            try {
                $qb = $this->connection->getQueryBuilder();
                $qb->delete('archive_tag_ownership')
                   ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter((int)$tid)))
                   ->executeStatement();
            } catch (\Throwable $t) {
            }
        }
    }

    #[\Override]
    public function updateTag(
        string $tagId,
        string $newName,
        bool $userVisible,
        bool $userAssignable,
        ?string $color,
        ?IUser $user = null,
    ): void {
        if ($user === null) {
            $user = \OC::$server->get(IUserSession::class)->getUser();
        }
        $uid = $user !== null ? $user->getUID() : null;
        $isAdmin = ($uid === 'admin' || ($uid !== null && $this->groupManager->isAdmin($uid)));

        if (!$isAdmin && $uid !== null) {
            if (!$this->getOwnershipService()->canUserManageTag((int)$tagId, $uid)) {
                throw new TagUpdateForbiddenException("Permission denied: You cannot update tag ID {$tagId}");
            }
        }

        parent::updateTag($tagId, $newName, $userVisible, $userAssignable, $color, $user);
    }
}