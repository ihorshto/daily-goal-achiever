<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Actor;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ActivityEvent extends Model
{
    use StoresUtc;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actor' => Actor::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: history is preserved, never silently rewritten.
        static::updating(fn () => throw new LogicException('Activity events are append-only.'));
        static::deleting(fn () => throw new LogicException('Activity events are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
