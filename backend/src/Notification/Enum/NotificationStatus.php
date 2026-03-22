<?php

declare(strict_types=1);

namespace App\Notification\Enum;

enum NotificationStatus: string
{
    case SENT = 'sent';
    case FAILED = 'failed';
    case CLICKED = 'clicked';
}
