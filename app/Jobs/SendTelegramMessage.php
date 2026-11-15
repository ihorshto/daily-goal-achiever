<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Message;
use App\Telegram\OutboundMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers a persisted assistant message. Idempotent via notification_log key "msg:{id}". */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public int $messageId)
    {
        $this->onQueue('high');
    }

    public function handle(OutboundMessenger $messenger): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null || $message->sent_at !== null || $message->text === null) {
            return;
        }
        $outcome = $messenger->deliver($message->user, $message->text, $message->reply_markup, 'reply', 'msg:'.$message->id, $message->checkpoint_id);
        if ($outcome->delivered()) {
            $message->forceFill(['telegram_message_id' => $outcome->telegramMessageId, 'sent_at' => now()])->save();

            return;
        }
        if ($outcome->shouldRetry()) {
            $this->release($outcome->retryAfter ?? min(300, 5 * 2 ** $this->attempts()));
        }
    }
}
