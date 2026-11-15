<?php

declare(strict_types=1);

namespace App\Memory;

use App\Models\User;

/** The only place a Mem0 scope id is constructed: tenant isolation for semantic memory. */
final class MemoryScope
{
    public static function for(User $user): string
    {
        return sprintf('%s:user:%d', config('companion.memory.mem0.user_prefix'), $user->id);
    }
}
