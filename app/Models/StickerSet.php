<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StickerSetStatus;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StickerSet extends Model
{
    use HasFactory, StoresUtc;

    protected $fillable = [
        'user_id', 'name', 'title', 'sticker_type', 'status', 'enabled', 'sticker_count', 'offered_at', 'dismissed_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StickerSetStatus::class,
            'enabled' => 'boolean',
            'sticker_count' => 'integer',
            'offered_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(Sticker::class);
    }

    /** @param Builder<StickerSet> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', StickerSetStatus::Active->value);
    }

    /** Active and switched on by the user: eligible for sending. @param Builder<StickerSet> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', StickerSetStatus::Active->value)->where('enabled', true);
    }
}
