<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
