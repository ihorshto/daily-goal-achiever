<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoalStatus;
use App\Enums\Priority;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use HasFactory, StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'priority' => Priority::class,
            'target_date' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
