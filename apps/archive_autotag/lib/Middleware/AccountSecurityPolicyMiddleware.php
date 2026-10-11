<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Middleware;

use OCA\ArchiveAutoTag\Middleware\Exceptions\AccountSecurityPolicyForbiddenException;
use OCA\ArchiveAutoTag\Service\PrimarySystemAdminResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Server-Side Account Security Policy Middleware
 *
 * Enforces server-side restrictions on credential management (App Passwords/Tokens,
 * WebAuthn Security Keys, Two-Factor TOTP, and Backup Codes) and personal settings pages
 * for non-admin users (both Regular Users and Group Admins).
 *
 * Full access is preserved only for the configured primary UID, provided that account is also a member of Nextcloud's 'admin' group.
 * All authentication flows (login, 2FA challenges during login, logout, account recovery)
 * and the dedicated self-service password change feature remain completely functional and unaffected.
 */
class AccountSecurityPolicyMiddleware extends Middleware {

    /**
     * Target blocked controllers and methods for non-admin users.
     * Maps fully-qualified controller class names to an array of blocked method names.
     */
    private const BLOCKED_TARGETS = [
        // 1. Personal Auth Tokens & App Passwords management
        'OCA\Settings\Controller\AuthSettingsController' => [
            'create',
            'update',
            'destroy',
            'destroyOthers',
            'wipe',
            '*',
        ],

        // 2. WebAuthn & Security Keys registration & deletion
        'OCA\Settings\Controller\WebAuthnController' => [
            'startRegistration',
            'finishRegistration',
            'deleteRegistration',
            '*',
        ],

        // 3. Two-Factor TOTP configuration & state
        'OCA\TwoFactorTOTP\Controller\SettingsController' => [
            'state',
            'enable',
            '*',
        ],

        // 4. Two-Factor Backup Codes generation
        'OCA\TwoFactorBackupCodes\Controller\SettingsController' => [
            'createCodes',
            '*',
        ],

        // 5. Personal Settings & Security HTML pages
        'OCA\Settings\Controller\PersonalSettingsController' => [
            'index',
            '*',
        ],

        // 6. OAuth2 Client management (Registration and Deletion of OAuth2 client credentials)
        'OCA\OAuth2\Controller\SettingsController' => [
            'addClient',
            'deleteClient',
            '*',
        ],

        // 7. Core App Password issuance and credential management.
        // Keep confirmUserPassword available; it does not create a credential.
        'OC\Core\Controller\AppPasswordController' => [
            'getAppPassword',
            'deleteAppPassword',
            'rotateAppPassword',
            'getAppPasswordWithOneTimePassword',
        ],

        // 8. Block credential issuance in the V2 client login flow only.
        // Preserve polling, initialization, and authentication handlers.
        'OC\Core\Controller\ClientFlowLoginV2Controller' => [
            'generateAppPassword',
        ],

        // 9. Block credential issuance in the legacy client login flow only.
        // Preserve its other authentication handlers.
        'OC\Core\Controller\ClientFlowLoginController' => [
            'generateAppPassword',
        ],


        // Restrict native Accounts Settings pages to the configured primary admin.
        'OCA\Settings\Controller\UsersController' => [
            '*',
        ],

        // Only the primary admin may change another user's password through Accounts.
        'OCA\Settings\Controller\ChangePasswordController' => [
            'changeUserPassword',
        ],

        // Restrict native Provisioning API account listing and management.
        // getCurrentUser is deliberately not included.
        'OCA\Provisioning_API\Controller\UsersController' => [
            'getUsers',
            'getUsersDetails',
            'getDisabledUsersDetails',
            'getLastLoggedInUsers',
            'searchByPhoneNumbers',
            'getUser',
            'getEditableFields',
            'getEditableFieldsForUser',
            'getEnabledApps',
            'addUser',
            'editUser',
            'editUserMultiValue',
            'editUserMultiField',
            'wipeUserDevices',
            'deleteUser',
            'disableUser',
            'enableUser',
            'getUsersGroups',
            'getUsersGroupsDetails',
            'addToGroup',
            'removeFromGroup',
            'getUserSubAdminGroups',
            'getUserSubAdminGroupsDetails',
            'addSubAdmin',
            'removeSubAdmin',
            'resendWelcomeMessage',
        ],

        // Restrict native administrative Settings pages and configuration APIs
        // to the configured Primary System Administrator.
        'OCA\Settings\Controller\AdminSettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\TwoFactorSettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\AISettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\MailSettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\LogSettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\CheckSetupController' => [
            '*',
        ],
        'OCA\Settings\Controller\DeclarativeSettingsController' => [
            '*',
        ],
        'OCA\Settings\Controller\AuthorizedGroupController' => [
            '*',
        ],
        'OCA\Settings\Controller\PresetController' => [
            '*',
        ],

        // Restrict native Nextcloud group administration mutations.
        'OCA\Provisioning_API\Controller\GroupsController' => [
            '*',
        ],

    ];

    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IRequest $request;
    private LoggerInterface $logger;
    private PrimarySystemAdminResolver $primarySystemAdminResolver;

    public function __construct(
        ?IUserSession $userSession = null,
        ?IGroupManager $groupManager = null,
        ?IRequest $request = null,
        ?LoggerInterface $logger = null,
        ?PrimarySystemAdminResolver $primarySystemAdminResolver = null,
    ) {
        $container = \OC::$server;
        $this->userSession = $userSession ?? $container->get(IUserSession::class);
        $this->groupManager = $groupManager ?? $container->get(IGroupManager::class);
        $this->request = $request ?? $container->get(IRequest::class);
        $this->logger = $logger ?? $container->get(LoggerInterface::class);
        $this->primarySystemAdminResolver = $primarySystemAdminResolver
            ?? $container->get(PrimarySystemAdminResolver::class);
    }

    /**
     * Uses the shared resolver for the one configured primary system administrator.
     */
    private function isPrimarySystemAdmin(?IUser $user): bool {
        return $this->primarySystemAdminResolver->isPrimarySystemAdmin($user);
    }

    /**
     * Intercepts execution before the target controller method is invoked.
     * Enforces server-side restrictions on credential management and settings pages
     * for non-admin users (both regular users and group admins).
     *
     * @param Controller $controller The controller instance being dispatched
     * @param string $methodName The target method name on the controller
     * @throws AccountSecurityPolicyForbiddenException when access is denied
     */
    #[\Override]
    public function beforeController(Controller $controller, string $methodName): void {
        $controllerClass = ltrim(get_class($controller), '\\');

        if (!isset(self::BLOCKED_TARGETS[$controllerClass])) {
            return;
        }

        $blockedMethods = self::BLOCKED_TARGETS[$controllerClass];
        if (!in_array('*', $blockedMethods, true) && !in_array($methodName, $blockedMethods, true)) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            // Unauthenticated request: allow standard Nextcloud core auth checks to handle login requirement
            return;
        }

        if ($this->isPrimarySystemAdmin($user)) {
            // Full Primary System Administrator: unrestricted access
            return;
        }

        // Access denied for non-admin user (Regular user or Group admin)
        $this->logger->warning(sprintf(
            'AccountSecurityPolicyMiddleware: Blocked unauthorized credential/settings operation by non-admin user "%s" targeting %s::%s',
            $user->getUID(),
            $controllerClass,
            $methodName
        ));

        throw new AccountSecurityPolicyForbiddenException(
            'Access denied: Credential and settings management is restricted to system administrators.'
        );
    }

    /**
     * Catches AccountSecurityPolicyForbiddenException and translates it to a secure HTTP 403 response:
     * - HTML page navigation: Renders standard Nextcloud 403 Forbidden template with explicit HTTP 403 status (no redirect).
     * - API / JSON / OCS requests: Returns structured HTTP 403 JSON response.
     *
     * @param Controller $controller
     * @param string $methodName
     * @param \Exception $exception
     * @return Response
     * @throws \Exception
     */
    #[\Override]
    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if ($exception instanceof AccountSecurityPolicyForbiddenException) {
            $accept = (string)$this->request->getHeader('Accept');

            // For HTML browser navigation requests, return genuine HTTP 403 Forbidden template
            if (stripos($accept, 'html') !== false) {
                $response = new TemplateResponse(
                    'core',
                    '403',
                    [
                        'message' => 'دسترسی به این بخش محدود شده است. تنظیمات امنیتی و مدیریت اعتبارنامه‌ها صرفاً در اختیار مدیر ارشد سامانه است.'
                    ],
                    TemplateResponse::RENDER_AS_GUEST,
                    Http::STATUS_FORBIDDEN
                );
                $response->setStatus(Http::STATUS_FORBIDDEN);
                return $response;
            }

            // For API / AJAX / OCS requests, return structured JSON with HTTP 403 Forbidden
            return new JSONResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => 'Access denied: Credential and settings management is restricted to system administrators.',
            ], Http::STATUS_FORBIDDEN);
        }

        throw $exception;
    }
}
