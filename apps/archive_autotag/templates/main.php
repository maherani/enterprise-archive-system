<?php
declare(strict_types=1);

/** @var array $_ */
use OCP\Util;

Util::addScript(\OCA\ArchiveAutoTag\AppInfo\Application::APP_ID, 'archive_portal');
Util::addStyle(\OCA\ArchiveAutoTag\AppInfo\Application::APP_ID, 'archive_portal');

$showWelcome = !empty($_['showWelcome']) && $_['showWelcome'] === '1';
$userDisplay = (string)($_['displayName'] ?? '');
?>

<?php if ($showWelcome): ?>
<div id="ea-welcome-overlay" class="ea-welcome-overlay" aria-live="polite" role="dialog" aria-modal="true" aria-label="خوش‌آمدگویی سامانه بایگانی اسناد سازمانی">
    <div class="ea-welcome-card">
        <div class="ea-welcome-icon-wrap" aria-hidden="true" title="بانک مسکن">
            <svg class="ea-welcome-icon" viewBox="0 0 119.24 119.24" width="46" height="46">
                <path fill-rule="evenodd" fill="#f97316" d="M102.2014008,17.0287781H17.0359039v85.1729126h85.1654968V17.0287781z M119.2376099,0 H0v119.2378845h119.2376099V0z"/>
                <polygon fill-rule="evenodd" fill="#f97316" points="98.9226074,76.647583 98.9226074,58.3074951 59.6188049,42.5828857 20.3150024,58.3074951 20.3150024,76.647583 59.6188049,60.9306946"/>
            </svg>
        </div>
        <h1 class="ea-welcome-title">سامانه بایگانی اسناد سازمانی</h1>
        <div class="ea-welcome-greeting">
            خوش آمدید<?php if ($userDisplay !== ''): ?>، <span class="ea-welcome-user-name"><?php p($userDisplay); ?></span><?php endif; ?>
        </div>
        <p class="ea-welcome-subtitle">سامانه در حال آماده‌سازی محیط کاری شماست...</p>

        <div id="ea-welcome-status-box" class="ea-welcome-status-box">
            <div class="ea-welcome-progress-track">
                <div id="ea-welcome-progress-bar" class="ea-welcome-progress-bar"></div>
            </div>
            <div class="ea-welcome-status-row">
                <span class="ea-welcome-spinner" aria-hidden="true"></span>
                <span id="ea-welcome-status-text" class="ea-welcome-status-text">در حال آماده‌سازی محیط بایگانی شما...</span>
            </div>
        </div>

        <div id="ea-welcome-error-box" class="ea-welcome-error-box" style="display: none;" role="alert">
            <div class="ea-welcome-error-msg">آماده‌سازی محیط بایگانی با مشکل مواجه شد. لطفاً دوباره تلاش کنید.</div>
            <button id="ea-welcome-retry-btn" class="ea-welcome-retry-btn" type="button">تلاش مجدد</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="archive-portal-root" class="archive-portal-app"
     data-user-id="<?php p($_['userId'] ?? ''); ?>"
     data-user-display="<?php p($_['displayName'] ?? ''); ?>"
     data-initial-dir="<?php p($_['initialDir'] ?? ''); ?>"
     data-initial-display-dir="<?php p($_['initialDisplayDir'] ?? ''); ?>"
     data-show-welcome="<?php echo $showWelcome ? '1' : '0'; ?>">
</div>