<?php
declare(strict_types=1);

/** @var array $_ */
use OCP\Util;

Util::addScript(\OCA\ArchiveAutoTag\AppInfo\Application::APP_ID, 'archive_portal');
Util::addStyle(\OCA\ArchiveAutoTag\AppInfo\Application::APP_ID, 'archive_portal');
?>

<div id="archive-portal-root" class="archive-portal-app"
     data-user-id="<?php p($_['userId'] ?? ''); ?>"
     data-user-display="<?php p($_['displayName'] ?? ''); ?>">
</div>
