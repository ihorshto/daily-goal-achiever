<?php

declare(strict_types=1);

namespace App\Enums;

enum MemoryStatus: string
{
    case PendingSync = 'pending_sync';
    case Synced = 'synced';
    case SyncFailed = 'sync_failed';
    case Superseded = 'superseded';
    case Deleted = 'deleted';
}
