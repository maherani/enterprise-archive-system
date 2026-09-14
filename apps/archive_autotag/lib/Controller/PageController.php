<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
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
        $params = [
            'userId' => $user !== null ? $user->getUID() : '',
            'displayName' => $user !== null ? $user->getDisplayName() : '',
        ];

        return new TemplateResponse(Application::APP_ID, 'main', $params);
    }
}
