<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StickerSend extends Model
{
    use StoresUtc;

    protected $fillable = [
        'user_id', 'source_message_id', 'sticker_id', 'emoji', 'file_id', 'telegram_message_id', 'sent_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(Sticker::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }
}
