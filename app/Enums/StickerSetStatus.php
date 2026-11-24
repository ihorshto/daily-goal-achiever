<?php

declare(strict_types=1);

namespace App\Enums;

enum StickerSetStatus: string
{
    case Offered = 'offered';
    case Active = 'active';
    case Dismissed = 'dismissed';
}
