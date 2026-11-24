<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Agent\ConversationService;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Jobs\HandleCallbackQuery;
use App\Jobs\ImportStickerSet;
use App\Jobs\OfferStickerSet;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\TranscribeVoiceMessage;
use App\Models\TelegramUpdate;
use App\Stickers\StickerCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Entry point for every Telegram update (webhook or polling). Responsibilities:
 * dedupe by update_id, private-chat + allowlist gate, per-user rate limit, then hand off to
 * queued jobs. Never does LLM work inline so the webhook responds fast.
 */
final class TelegramUpdateRouter
{
    public function __construct(
        private readonly UserResolver $users,
        private readonly ConversationService $conversations,
        private readonly CommandHandler $commands,
        private readonly StickerCatalog $stickers,
    ) {}

    /** @param array<string, mixed> $update  @return string outcome code */
    public function route(array $update): string
    {
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId)) {
            return 'invalid';
        }
        $type = match (true) {
            isset($update['message']) => 'message',
            isset($update['callback_query']) => 'callback_query',
            default => 'other',
        };

        try {
            $ledger = DB::transaction(fn () => TelegramUpdate::create([
                'update_id' => $updateId,
                'type' => $type,
                'status' => 'received',
                'correlation_id' => (string) Context::get('correlation_id'),
            ]));
        } catch (QueryException) {
            Log::info('telegram.duplicate_update', ['update_id' => $updateId]);

            return 'duplicate';
        }

        $outcome = match ($type) {
            'message' => $this->message($update['message'], $updateId),
            'callback_query' => $this->callback($update['callback_query'], $updateId),
            default => 'ignored',
        };
        $ledger->update(['status' => $outcome]);

        return $outcome;
    }

    /** @param array<string, mixed> $msg */
    private function message(array $msg, int $updateId): string
    {
        if (($msg['chat']['type'] ?? null) !== 'private' || ! isset($msg['from'], $msg['message_id'])) {
            return 'ignored';
        }
        $chatId = (int) $msg['chat']['id'];
        $user = $this->users->resolve($msg['from'], $chatId);
        if ($user === null) {
            Log::notice('telegram.unauthorized_user');

            return 'rejected';
        }
        TelegramUpdate::query()->where('update_id', $updateId)->update(['user_id' => $user->id]);
        Context::add('user_id', $user->id);

        if (RateLimiter::tooManyAttempts('inbound:'.$user->id, (int) config('companion.agent.inbound_rate_per_minute'))) {
            return 'rate_limited';
        }
        RateLimiter::hit('inbound:'.$user->id, 60);

        $dedup = 'tg:'.$chatId.':'.$msg['message_id'];
        $common = ['telegram_update_id' => $updateId, 'telegram_message_id' => (int) $msg['message_id']];

        if (isset($msg['text']) && is_string($msg['text'])) {
            if (CommandHandler::isCommand($msg['text'])) {
                $this->commands->handle($user, $msg['text'], $updateId, (int) $msg['message_id']);

                return 'dispatched';
            }
            if (preg_match('#^\s*(?:https?://)?t\.me/(?:addstickers|addemoji)/[A-Za-z0-9_]{1,64}/?\s*$#i', $msg['text'])) {
                // A bare sticker-set link: import it in the background instead of running an agent turn.
                [$message, $created] = $this->conversations->recordInbound($user, $dedup, MessageSource::TelegramText, $msg['text'], $common + ['processing_state' => ProcessingState::Processed]);
                if ($created) {
                    ImportStickerSet::dispatch($user->id, trim($msg['text']), $message->id);
                }

                return $created ? 'dispatched' : 'duplicate';
            }
            [$message, $created] = $this->conversations->recordInbound($user, $dedup, MessageSource::TelegramText, $msg['text'], $common);
            if ($created) {
                ProcessInboundMessage::dispatch($message->id, $user->id);
            }

            return $created ? 'dispatched' : 'duplicate';
        }

        $sticker = $msg['sticker'] ?? null;
        if (is_array($sticker) && isset($sticker['file_id'])) {
            $emoji = isset($sticker['emoji']) && is_string($sticker['emoji']) ? mb_substr((string) preg_replace('/[\p{C}\s]+/u', '', $sticker['emoji']), 0, 16) : '';
            [$message, $created] = $this->conversations->recordInbound($user, $dedup, MessageSource::TelegramSticker, $emoji !== '' ? "[sticker {$emoji}]" : '[sticker]', $common);
            if ($created) {
                $setName = isset($sticker['set_name']) && is_string($sticker['set_name']) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $sticker['set_name']) ? $sticker['set_name'] : null;
                if ($setName !== null && ($sticker['type'] ?? 'regular') !== 'custom_emoji' && $this->stickers->shouldOffer($user, $setName)) {
                    OfferStickerSet::dispatch($message->id, $user->id, $setName);
                } else {
                    ProcessInboundMessage::dispatch($message->id, $user->id);
                }
            }

            return $created ? 'dispatched' : 'duplicate';
        }

        $voice = $msg['voice'] ?? $msg['audio'] ?? null;
        if (is_array($voice) && isset($voice['file_id'])) {
            [$message, $created] = $this->conversations->recordInbound($user, $dedup, MessageSource::TelegramVoice, null, $common + [
                'voice_file_id' => (string) $voice['file_id'],
                'voice_file_unique_id' => isset($voice['file_unique_id']) ? (string) $voice['file_unique_id'] : null,
                'voice_duration_seconds' => (int) ($voice['duration'] ?? 0),
                'voice_file_size' => isset($voice['file_size']) ? (int) $voice['file_size'] : null,
                'voice_mime_type' => isset($voice['mime_type']) ? mb_substr((string) $voice['mime_type'], 0, 64) : null,
                'processing_state' => ProcessingState::Transcribing,
            ]);
            if ($created) {
                TranscribeVoiceMessage::dispatch($message->id);
            }

            return $created ? 'dispatched' : 'duplicate';
        }

        return 'ignored';
    }

    /** @param array<string, mixed> $cb */
    private function callback(array $cb, int $updateId): string
    {
        $chat = $cb['message']['chat'] ?? null;
        if (! isset($cb['id'], $cb['from']) || ! is_string($cb['data'] ?? null) || ($chat['type'] ?? 'private') !== 'private') {
            return 'ignored';
        }
        $user = $this->users->resolve($cb['from'], (int) ($chat['id'] ?? $cb['from']['id']));
        if ($user === null) {
            return 'rejected';
        }
        HandleCallbackQuery::dispatch($user->id, [
            'id' => (string) $cb['id'],
            'data' => mb_substr($cb['data'], 0, 64),
            'chat_id' => (int) ($chat['id'] ?? $user->telegram_chat_id),
            'message_id' => isset($cb['message']['message_id']) ? (int) $cb['message']['message_id'] : null,
            'message_date' => isset($cb['message']['date']) ? (int) $cb['message']['date'] : null,
            'update_id' => $updateId,
        ]);

        return 'dispatched';
    }
}
