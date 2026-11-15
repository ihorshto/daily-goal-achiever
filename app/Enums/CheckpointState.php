<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckpointState: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Sent = 'sent';
    case Suppressed = 'suppressed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Failed = 'failed';
}
