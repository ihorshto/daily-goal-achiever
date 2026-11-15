<?php

declare(strict_types=1);

namespace App\Enums;

enum MemoryVerification: string
{
    case Stated = 'stated';
    case Inferred = 'inferred';
    case Confirmed = 'confirmed';
}
