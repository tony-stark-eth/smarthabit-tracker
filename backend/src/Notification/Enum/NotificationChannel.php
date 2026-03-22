<?php

declare(strict_types=1);

namespace App\Notification\Enum;

enum NotificationChannel: string
{
    case WEB_PUSH = 'web_push';
    case NTFY = 'ntfy';
    case APNS = 'apns';
}
