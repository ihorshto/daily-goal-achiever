<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Commitment;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory, StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'commitment' => Commitment::class,
            'priority' => Priority::class,
            'due_at' => 'immutable_datetime',
            'due_date' => 'immutable_date',
            'planned_for_date' => 'immutable_date',
            'last_progress_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'completion_confirmed_at' => 'immutable_datetime',
            'progress_percent' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }

    /** @param Builder<Task> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::openValues());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function hasDeadline(): bool
    {
        return $this->due_at !== null || $this->due_date !== null;
    }
}
