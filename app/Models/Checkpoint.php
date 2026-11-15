<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckpointKind;
use App\Enums\CheckpointState;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Checkpoint extends Model
{
    use HasFactory, StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => CheckpointKind::class,
            'state' => CheckpointState::class,
            'local_date' => 'immutable_date',
            'earliest_at' => 'immutable_datetime',
            'latest_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'claimed_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
