<?php

declare(strict_types=1);

namespace App\Agent;

use App\Enums\MessageRole;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Persists the conversation transcript (one rolling conversation per user/chat). */
final class ConversationService
{
    public function conversationFor(User $user): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['user_id' => $user->id, 'telegram_chat_id' => (int) $user->telegram_chat_id],
        );
    }

    /**
     * Idempotent on dedup_key (e.g. "tg:{chat}:{message_id}"), so webhook retries never duplicate.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Message, 1: bool} message + whether it was newly created
     */
    public function recordInbound(User $user, string $dedupKey, MessageSource $source, ?string $text, array $attributes = []): array
    {
        if ($existing = Message::query()->where('dedup_key', $dedupKey)->first()) {
            return [$existing, false];
        }
        $conversation = $this->conversationFor($user);
        try {
            // Own transaction (a savepoint when nested) so a unique conflict can't abort the caller's PG transaction.
            $message = DB::transaction(fn () => $conversation->messages()->create(array_merge([
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'source' => $source,
                'text' => $text,
                'dedup_key' => $dedupKey,
                'processing_state' => ProcessingState::Received,
            ], $attributes)));
        } catch (QueryException $e) {
            if ($existing = Message::query()->where('dedup_key', $dedupKey)->first()) {
                return [$existing, false];
            }
            throw $e;
        }
        $conversation->update(['last_message_at' => now()]);
        $user->forceFill(['last_user_message_at' => now()])->save();

        return [$message, true];
    }

    /** @param array<string, mixed>|null $replyMarkup */
    public function recordAssistant(User $user, string $dedupKey, string $text, MessageSource $source = MessageSource::AgentReply, ?array $replyMarkup = null, ?int $checkpointId = null): Message
    {
        if ($existing = Message::query()->where('dedup_key', $dedupKey)->first()) {
            return $existing;
        }
        $conversation = $this->conversationFor($user);
        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => MessageRole::Assistant,
            'source' => $source,
            'text' => $text,
            'dedup_key' => $dedupKey,
            'processing_state' => ProcessingState::Processed,
            'reply_markup' => $replyMarkup,
            'checkpoint_id' => $checkpointId,
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /** Most recent messages (oldest first), excluding $beforeId and later. @return Collection<int, Message> */
    public function recent(User $user, int $limit, ?int $beforeId = null): Collection
    {
        $q = $user->messages()->whereNotNull('text')->whereIn('processing_state', [ProcessingState::Processed->value, ProcessingState::Processing->value]);
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }

        return $q->orderByDesc('id')->limit($limit)->get()->reverse()->values();
    }
}
