<?php

declare(strict_types=1);

namespace App\Enums;

enum PendingActionStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';
}
