<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingState: string
{
    case Received = 'received';
    case Transcribing = 'transcribing';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
