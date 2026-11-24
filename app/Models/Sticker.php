<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sticker extends Model
{
    use HasFactory, StoresUtc;

    protected $fillable = [
        'sticker_set_id', 'user_id', 'file_id', 'file_unique_id', 'emoji', 'emoji_key', 'is_animated', 'is_video', 'position', 'invalid_at',
    ];

    protected function casts(): array
    {
        return [
            'is_animated' => 'boolean',
            'is_video' => 'boolean',
            'position' => 'integer',
            'invalid_at' => 'immutable_datetime',
        ];
    }

    public function stickerSet(): BelongsTo
    {
        return $this->belongsTo(StickerSet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not known to be broken, and its set is active and enabled. @param Builder<Sticker> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('invalid_at')->whereHas('stickerSet', fn (Builder $s) => $s->usable());
    }
}
