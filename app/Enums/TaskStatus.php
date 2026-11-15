<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';
    case Canceled = 'canceled';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Canceled], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Todo->value, self::InProgress->value, self::Blocked->value];
    }
}
