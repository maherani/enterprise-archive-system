<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\SystemTag;

use OC\SystemTag\SystemTagObjectMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagManagerFactory;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Container\ContainerInterface;

class IsolatedManagerFactory implements ISystemTagManagerFactory {
    private ?ISystemTagManager $manager = null;
    private ?ISystemTagObjectMapper $objectMapper = null;

    public function __construct(
        private ContainerInterface $serverContainer,
    ) {
    }

    #[\Override]
    public function getManager(): ISystemTagManager {
        if ($this->manager === null) {
            $this->manager = new IsolatedSystemTagManager(
                $this->serverContainer->get(IDBConnection::class),
                $this->serverContainer->get(IGroupManager::class),
                $this->serverContainer->get(IEventDispatcher::class),
                $this->serverContainer->get(IUserSession::class),
                $this->serverContainer->get(IAppConfig::class),
            );
        }
        return $this->manager;
    }

    #[\Override]
    public function getObjectMapper(): ISystemTagObjectMapper {
        if ($this->objectMapper === null) {
            $this->objectMapper = new SystemTagObjectMapper(
                $this->serverContainer->get(IDBConnection::class),
                $this->getManager(),
                $this->serverContainer->get(IEventDispatcher::class),
            );
        }
        return $this->objectMapper;
    }
}