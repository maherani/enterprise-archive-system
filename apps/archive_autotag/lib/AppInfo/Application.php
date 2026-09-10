<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\AppInfo;

use OCA\ArchiveAutoTag\Listener\NodeCreatedListener;
use OCA\ArchiveAutoTag\Listener\NodeRenamedListener;
use OCA\ArchiveAutoTag\Listener\NodeWrittenListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'archive_autotag';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(NodeCreatedEvent::class, NodeCreatedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
