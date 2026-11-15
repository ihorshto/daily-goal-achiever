<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemoryCategory;
use App\Enums\MemoryStatus;
use App\Enums\MemoryVerification;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    use HasFactory, StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => MemoryCategory::class,
            'status' => MemoryStatus::class,
            'verification' => MemoryVerification::class,
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** Live = not deleted and not superseded. @param Builder<Memory> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotIn('status', [MemoryStatus::Deleted->value, MemoryStatus::Superseded->value]);
    }

    public function isLive(): bool
    {
        return ! in_array($this->status, [MemoryStatus::Deleted, MemoryStatus::Superseded], true);
    }

    public static function hashContent(string $content): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));

        return hash('sha256', $normalized);
    }
}
