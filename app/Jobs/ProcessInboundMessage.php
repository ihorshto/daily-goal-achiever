<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\AgentOrchestrator;
use App\Agent\ConversationService;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Models\CalendarEvent;
use App\Models\Message;
use App\Models\StickerSend;
use App\Telegram\TelegramApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Runs the agent for one inbound user message and queues the reply.
 * Serialized per user (WithoutOverlapping) so turns never interleave.
 */
class ProcessInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 150;

    public function __construct(public int $messageId, public int $userId)
    {
        $this->onQueue('high');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('agent-user:'.$this->userId))->releaseAfter(5)->expireAfter(180)];
    }

    public function handle(AgentOrchestrator $agent, ConversationService $conversations, TelegramApiClient $telegram): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null || in_array($message->processing_state, [ProcessingState::Processed, ProcessingState::Ignored], true)) {
            return;
        }
        $user = $message->user;
        if ($user->data_purged_at !== null && $message->created_at->lessThanOrEqualTo($user->data_purged_at)) {
            $message->update(['processing_state' => ProcessingState::Ignored]);

            return;
        }

        // A previous attempt may have produced the reply before crashing: don't re-run the model.
        $existing = Message::query()->where('dedup_key', 'reply:'.$message->id)->first();
        if ($existing === null) {
            $message->update(['processing_state' => ProcessingState::Processing]);
            $telegram->sendChatAction((int) $user->telegram_chat_id);
            $reply = $agent->respond($user, $message);
            if ($reply->status !== 'completed') {
                // A sticker queued earlier in a turn that ended in an apology or fallback would be out of place.
                StickerSend::query()->where('source_message_id', $message->id)->whereNull('sent_at')->delete();
            }
            $markup = $reply->keyboard !== [] ? ['inline_keyboard' => $reply->keyboard] : null;
            $existing = $conversations->recordAssistant($user, 'reply:'.$message->id, $reply->text, MessageSource::AgentReply, $markup);
        }

        $message->update(['processing_state' => ProcessingState::Processed]);
        $invites = CalendarEvent::query()->where('source_message_id', $message->id)->whereNull('sent_at')->orderBy('id')->pluck('id');
        $stickers = StickerSend::query()->where('source_message_id', $message->id)->whereNull('sent_at')->whereNull('failed_at')->orderBy('id')->pluck('id');
        if ($invites->isEmpty() && $stickers->isEmpty()) {
            SendTelegramMessage::dispatch($existing->id);
        } else {
            // The reply goes first, then each .ics file, then the sticker.
            Bus::chain([
                new SendTelegramMessage($existing->id),
                ...$invites->map(fn (int $id) => new SendCalendarInvite($id))->all(),
                ...$stickers->map(fn (int $id) => new SendSticker($id))->all(),
            ])->dispatch();
        }
        ExtractMemories::dispatch($message->id);
    }

    public function failed(\Throwable $e): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null) {
            return;
        }
        $message->update(['processing_state' => ProcessingState::Failed, 'processing_error' => mb_substr($e->getMessage(), 0, 255)]);
        Log::error('agent.message_failed', ['message_id' => $message->id]);
        $reply = app(ConversationService::class)->recordAssistant(
            $message->user, 'reply:'.$message->id.':error',
            'Sorry — I hit a problem processing that message. It is saved; please try again in a few minutes.',
        );
        SendTelegramMessage::dispatch($reply->id);
    }
}
