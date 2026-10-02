<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly NavigationController $navigationController,
        private readonly ISession $session,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Render the modern, minimal Enterprise Archive Portal
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'archive_portal');
        Util::addStyle(Application::APP_ID, 'archive_portal');

        $user = $this->userSession->getUser();
        $uid = $user !== null ? $user->getUID() : '';
        $highest = $this->navigationController->getHighestAccessibleDir($uid !== '' ? $uid : null);

        $sessionKey = 'ea_welcome_seen_' . $uid;
        $alreadySeen = $uid !== '' && (bool)$this->session->get($sessionKey);
        $showWelcome = !$alreadySeen;

        if ($showWelcome && $uid !== '') {
            $this->session->set($sessionKey, true);
        }

        $params = [
            'userId' => $uid,
            'displayName' => $user !== null ? $user->getDisplayName() : '',
            'initialDir' => $highest['dir'] ?? '/',
            'initialDisplayDir' => $highest['display_dir'] ?? '/',
            'showWelcome' => $showWelcome ? '1' : '0',
        ];

        return new TemplateResponse(Application::APP_ID, 'main', $params);
    }
}