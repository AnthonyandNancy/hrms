<?php

declare(strict_types=1);

namespace App\Shared\Notifications;

enum NotificationDeliveryStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
