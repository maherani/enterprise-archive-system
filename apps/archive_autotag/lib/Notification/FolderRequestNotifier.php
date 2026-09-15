<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Notification;

use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class FolderRequestNotifier implements INotifier {
    public const NOTIFIER_ID = 'archive_autotag';

    public function __construct(
        private IURLGenerator $urlGenerator
    ) {}

    public function getID(): string {
        return self::NOTIFIER_ID;
    }

    public function getName(): string {
        return 'سامانه بایگانی اسناد سازمانی';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== self::NOTIFIER_ID) {
            throw new UnknownNotificationException();
        }

        $subject = $notification->getSubject();
        $params = $notification->getSubjectParameters();

        $folderName = (string)($params['folderName'] ?? 'پوشه');
        $groupId = (string)($params['groupId'] ?? '');
        $targetPath = (string)($params['targetPath'] ?? '');
        $reason = (string)($params['reason'] ?? '');
        $error = (string)($params['error'] ?? '');

        if ($subject === 'folder_request_approved') {
            $notification->setParsedSubject("درخواست ایجاد پوشه «{$folderName}» تأیید شد");
            $msg = "درخواست شما برای ایجاد پوشه «{$folderName}» در گروه «{$groupId}» توسط مدیر ارشد سیستم تأیید و در ساختار آرشیو فعال گردید.";
            if ($targetPath !== '') {
                $msg .= " (مسیر: {$targetPath})";
            }
            $notification->setParsedMessage($msg);
        } elseif ($subject === 'folder_request_rejected') {
            $notification->setParsedSubject("درخواست ایجاد پوشه «{$folderName}» رد شد");
            $msg = "درخواست ایجاد پوشه «{$folderName}» برای گروه «{$groupId}» توسط مدیر سیستم رد شد.";
            if ($reason !== '') {
                $msg .= " دلیل رد: {$reason}";
            }
            $notification->setParsedMessage($msg);
        } elseif ($subject === 'folder_request_failed') {
            $notification->setParsedSubject("خطا در ایجاد پوشه «{$folderName}»");
            $notification->setParsedMessage("در فرآیند ایجاد پوشه «{$folderName}» خطایی رخ داد: {$error}");
        } else {
            $notification->setParsedSubject("به‌روزرسانی درخواست پوشه «{$folderName}»");
            $notification->setParsedMessage("وضعیت درخواست پوشه شما تغییر یافت.");
        }

        $notification->setLink($this->urlGenerator->linkToRouteAbsolute('archive_autotag.Page.index'));

        try {
            $icon = $this->urlGenerator->imagePath('core', 'places/folder.svg');
            $notification->setIcon($icon);
        } catch (\Throwable $t) {
        }

        return $notification;
    }
}
