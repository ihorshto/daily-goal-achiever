<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingActionConfirmation extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => PendingActionType::class,
            'status' => PendingActionStatus::class,
            'proposed_changes' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
