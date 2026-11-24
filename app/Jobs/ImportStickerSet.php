<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\ConversationService;
use App\Enums\MessageSource;
use App\Models\Message;
use App\Models\User;
use App\Stickers\StickerCatalog;
use App\Stickers\StickerSetUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Imports or re-syncs a sticker set named by a t.me/addstickers link or /stickers command, then
 * tells the user the result. Runs in the queue so the polling loop never waits on Telegram.
 */
class ImportStickerSet implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public string $rawInput, public int $sourceMessageId)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('agent-user:'.$this->userId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(StickerCatalog $catalog, ConversationService $conversations): void
    {
        $user = User::query()->find($this->userId);
        $source = Message::query()->find($this->sourceMessageId);
        if ($user === null || $source === null) {
            return;
        }
        if ($user->data_purged_at !== null && $source->created_at->lessThanOrEqualTo($user->data_purged_at)) {
            return;
        }

        try {
            $import = $catalog->import($user, $catalog->parseSetName($this->rawInput));
            $text = $import->message($user->settingsOrDefault()->stickers_enabled);
        } catch (StickerSetUnavailable $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release(min(60, 15 * $this->attempts()));

                return;
            }
            $text = $e->getMessage();
        }

        $reply = $conversations->recordAssistant($user, 'reply:'.$source->id, $text, MessageSource::AgentReply);
        SendTelegramMessage::dispatch($reply->id);
    }
}
