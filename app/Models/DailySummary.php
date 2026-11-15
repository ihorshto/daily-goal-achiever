<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySummary extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'planned' => 'array',
            'confirmed' => 'array',
            'blockers' => 'array',
            'open_loops' => 'array',
            'source_event_ids' => 'array',
            'source_message_ids' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
