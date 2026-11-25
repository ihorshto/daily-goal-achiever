<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\ConversationService;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Models\Message;
use App\Stickers\StickerCatalog;
use App\Stickers\StickerSetUnavailable;
use App\Telegram\Keyboards;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * A user sent a sticker from a set we don't know: offer to add the set (no LLM turn). If the set
 * cannot be offered (not found, custom emoji, Telegram down), the sticker is handled as a normal message.
 */
class OfferStickerSet implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId, public int $userId, public string $setName)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('agent-user:'.$this->userId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(StickerCatalog $catalog, ConversationService $conversations): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null || $message->processing_state !== ProcessingState::Received) {
            return;
        }
        $user = $message->user;
        if ($user->data_purged_at !== null && $message->created_at->lessThanOrEqualTo($user->data_purged_at)) {
            $message->update(['processing_state' => ProcessingState::Ignored]);

            return;
        }

        try {
            $api = $catalog->fetch($this->setName);
        } catch (StickerSetUnavailable $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(min(60, 10 * $this->attempts()));

                return;
            }
            ProcessInboundMessage::dispatch($message->id, $user->id);

            return;
        }

        $set = $catalog->recordOffer($user, $api);
        $reply = $conversations->recordAssistant(
            $user,
            'reply:'.$message->id,
            "Add the sticker set «{$set->title}» ({$set->sticker_count} stickers)? I'll sometimes use them in replies.",
            MessageSource::AgentReply,
            Keyboards::markup([Keyboards::stickerOfferRow($set)]),
        );
        $message->update(['processing_state' => ProcessingState::Processed]);
        SendTelegramMessage::dispatch($reply->id);
    }

    public function failed(Throwable $e): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null && $message->processing_state === ProcessingState::Received) {
            ProcessInboundMessage::dispatch($message->id, $message->user_id);
        }
    }
}
