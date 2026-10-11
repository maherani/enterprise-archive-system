<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\HintException;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PasswordController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Dedicated self-service password change for authenticated users (Regular User, Group Admin, Admin).
     * Validates current password, enforces Nextcloud password policies, and updates session token securely.
     */
    #[NoAdminRequired]
    #[BruteForceProtection(action: 'archive_autotag_change_password')]
    public function changePassword(): JSONResponse {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'UNAUTHORIZED',
                'message' => 'احراز هویت کاربر الزامی است.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $currentUser->getUID();
        $params = $this->request->getParams();
        $oldPassword = (string)($params['oldPassword'] ?? $params['old_password'] ?? '');
        $newPassword = (string)($params['newPassword'] ?? $params['new_password'] ?? '');
        $confirmPassword = (string)($params['confirmPassword'] ?? $params['confirm_password'] ?? '');

        // 1. Mandatory fields validation
        if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'MISSING_FIELDS',
                'message' => 'تکمیل تمامی فیلدها (رمز عبور فعلی، رمز عبور جدید و تکرار آن) الزامی است.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // 2. Confirmation match validation
        if ($newPassword !== $confirmPassword) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'PASSWORD_MISMATCH',
                'message' => 'رمز عبور جدید با تکرار آن مطابقت ندارد.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // 3. Length constraint validation
        if (strlen($newPassword) < 8) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'PASSWORD_TOO_SHORT',
                'message' => 'رمز عبور جدید باید حداقل دارای ۸ کاراکتر باشد.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (strlen($newPassword) > IUserManager::MAX_PASSWORD_LENGTH) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'PASSWORD_TOO_LONG',
                'message' => 'طول رمز عبور بیش از حد مجاز است.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // 4. Verification that new password is not identical to current
        if ($oldPassword === $newPassword) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'PASSWORD_SAME_AS_OLD',
                'message' => 'رمز عبور جدید نمی‌تواند با رمز عبور فعلی یکسان باشد.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // 5. Secure verification of old password
        $loginName = method_exists($this->userSession, 'getLoginName') ? $this->userSession->getLoginName() : null;
        if (empty($loginName)) {
            $loginName = $userId;
        }

        $verifiedUser = $this->userManager->checkPassword($loginName, $oldPassword);
        if ($verifiedUser === false || $verifiedUser === null || $verifiedUser->getUID() !== $userId) {
            $response = new JSONResponse([
                'status' => 'error',
                'code' => 'INVALID_OLD_PASSWORD',
                'message' => 'رمز عبور فعلی نادرست است.',
            ], Http::STATUS_FORBIDDEN);
            $response->throttle();
            return $response;
        }

        // 6. Set new password using Nextcloud official IUser API
        try {
            $result = $currentUser->setPassword($newPassword);
            if ($result === false) {
                return new JSONResponse([
                    'status' => 'error',
                    'code' => 'PASSWORD_CHANGE_FAILED',
                    'message' => 'تغییر رمز عبور در سامانه ناموفق بود. لطفاً مجدداً تلاش کنید.',
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (HintException $e) {
            return new JSONResponse([
                'status' => 'error',
                'code' => 'POLICY_VIOLATION',
                'message' => $e->getHint(),
            ], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("PasswordController::changePassword error: " . $e->getMessage());
            return new JSONResponse([
                'status' => 'error',
                'code' => 'SYSTEM_ERROR',
                'message' => 'خطای سیستمی در فرآیند تغییر رمز عبور رخ داد.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // 7. Update session token password to keep current session active
        if (method_exists($this->userSession, 'updateSessionTokenPassword')) {
            try {
                $this->userSession->updateSessionTokenPassword($newPassword);
            } catch (\Throwable $e) {
                // Ignore session token update failure if method throws
            }
        }

        return new JSONResponse([
            'status' => 'success',
            'code' => 'SUCCESS',
            'message' => 'رمز عبور شما با موفقیت به‌روزرسانی شد.',
        ], Http::STATUS_OK);
    }
}
