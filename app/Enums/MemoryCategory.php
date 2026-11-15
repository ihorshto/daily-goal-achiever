<?php

declare(strict_types=1);

namespace App\Enums;

enum MemoryCategory: string
{
    case Fact = 'fact';
    case Preference = 'preference';
    case Decision = 'decision';
    case Problem = 'problem';
    case Resolution = 'resolution';
    case GoalContext = 'goal_context';
    case Person = 'person';
    case Routine = 'routine';
    case Episode = 'episode';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
