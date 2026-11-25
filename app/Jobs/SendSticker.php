<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\ConversationService;
use App\Enums\MessageSource;
use App\Models\Sticker;
use App\Models\StickerSend;
use App\Telegram\OutboundMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers a sticker the agent chose, chained after the text reply. Idempotent via notification_log
 * key "sticker:{id}". After delivery it is recorded in the conversation so the agent sees it in its history.
 */
class SendSticker implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public int $stickerSendId)
    {
        $this->onQueue('high');
    }

    public function handle(OutboundMessenger $messenger, ConversationService $conversations): void
    {
        $send = StickerSend::query()->with('user')->find($this->stickerSendId);
        if ($send === null || $send->sent_at !== null || $send->failed_at !== null) {
            return;
        }
        $user = $send->user;
        $purged = $user->data_purged_at !== null && $user->data_purged_at->greaterThan($send->created_at);
        if ($purged || ! $user->settingsOrDefault()->stickers_enabled) {
            $send->forceFill(['failed_at' => now()])->save();

            return;
        }

        $outcome = $messenger->deliverSticker($user, $send->file_id, 'sticker', 'sticker:'.$send->id);
        if ($outcome->delivered()) {
            $send->forceFill(['telegram_message_id' => $outcome->telegramMessageId, 'sent_at' => now()])->save();
            $conversations->recordAssistant($user, 'sticker:'.$send->id, '[sticker '.$send->emoji.']', MessageSource::AgentSticker);

            return;
        }
        if ($outcome->shouldRetry()) {
            $this->release($outcome->retryAfter ?? min(300, 5 * 2 ** $this->attempts()));

            return;
        }
        if ($outcome->status === 'failed') {
            $send->forceFill(['failed_at' => now()])->save();
            if ($outcome->errorCode === '400' && $send->sticker_id !== null) {
                // The file_id is no longer accepted: never pick this sticker again.
                Sticker::query()->whereKey($send->sticker_id)->update(['invalid_at' => now()]);
            }
        }
    }
}
