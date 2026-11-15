<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a message exactly-once-per-idempotency-key (at-most-once on crashes):
 *  - a unique notification_log row is claimed (status=sending) before calling Telegram;
 *  - duplicates (retries, concurrent workers) see the row and do not send again;
 *  - only rows that failed with a known, retryable error can be re-claimed.
 */
class OutboundMessenger
{
    public function __construct(private readonly TelegramApiClient $telegram) {}

    /** @param array<string, mixed>|null $replyMarkup */
    public function deliver(User $user, string $text, ?array $replyMarkup, string $kind, string $idempotencyKey, ?int $checkpointId = null): DeliveryOutcome
    {
        if ($user->telegram_chat_id === null) {
            return new DeliveryOutcome('failed', null, null, 'no_chat');
        }
        $log = $this->claim($user, $kind, $idempotencyKey, $checkpointId);
        if ($log === null) {
            return new DeliveryOutcome('duplicate', null);
        }

        $parts = TelegramFormatter::split($text);
        $lastMessageId = null;
        foreach ($parts as $i => $part) {
            $markup = $i === array_key_last($parts) ? $replyMarkup : null;
            $result = $this->telegram->sendMessage((int) $user->telegram_chat_id, TelegramFormatter::toHtml($part), $markup);
            if (! $result->ok && $result->isParseError()) {
                $result = $this->telegram->sendMessage((int) $user->telegram_chat_id, TelegramFormatter::toPlain($part), $markup, null);
            }
            if (! $result->ok) {
                $retryable = ! $result->isPermanent() || $result->isRateLimited();
                $log->update([
                    'status' => NotificationStatus::Failed,
                    'error_code' => ($retryable ? 'retry:' : 'perm:').$result->errorCode,
                ]);
                Log::warning('telegram.delivery_failed', ['kind' => $kind, 'error_code' => $result->errorCode, 'part' => $i]);

                return new DeliveryOutcome($result->isRateLimited() ? 'rate_limited' : ($retryable ? 'retryable' : 'failed'), null, $result->retryAfter, (string) $result->errorCode);
            }
            $lastMessageId = $result->messageId();
        }

        $log->update(['status' => NotificationStatus::Sent, 'telegram_message_id' => $lastMessageId, 'sent_at' => now(), 'error_code' => null]);
        Log::info('telegram.delivered', ['kind' => $kind, 'parts' => count($parts)]);

        return new DeliveryOutcome('sent', $lastMessageId);
    }

    private function claim(User $user, string $kind, string $key, ?int $checkpointId): ?NotificationLog
    {
        try {
            return DB::transaction(fn () => NotificationLog::create([
                'user_id' => $user->id,
                'checkpoint_id' => $checkpointId,
                'kind' => $kind,
                'idempotency_key' => $key,
                'status' => NotificationStatus::Sending,
                'attempts' => 1,
            ]));
        } catch (QueryException) {
            // Existing row: only a retryable failure may be re-claimed (guarded update = atomic).
            $affected = NotificationLog::query()
                ->where('idempotency_key', $key)
                ->where('status', NotificationStatus::Failed->value)
                ->where('error_code', 'like', 'retry:%')
                ->update(['status' => NotificationStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

            return $affected === 1 ? NotificationLog::query()->where('idempotency_key', $key)->first() : null;
        }
    }

    /** Rows stuck in "sending" (worker died mid-call) are marked unknown and never auto-resent. */
    public static function markStaleAsUnknown(int $minutes = 10): int
    {
        return NotificationLog::query()
            ->where('status', NotificationStatus::Sending->value)
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update(['status' => NotificationStatus::Unknown->value, 'updated_at' => now()]);
    }
}
