<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'proactive_enabled' => 'boolean',
            'morning_enabled' => 'boolean',
            'evening_enabled' => 'boolean',
            'paused_until' => 'immutable_datetime',
            'max_checkins_per_day' => 'integer',
            'min_gap_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
