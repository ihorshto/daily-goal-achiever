<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageRole;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory, StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'source' => MessageSource::class,
            'processing_state' => ProcessingState::class,
            'sent_at' => 'immutable_datetime',
            'reply_markup' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
