<?php

declare(strict_types=1);

namespace App\Stickers;

use App\Enums\MessageRole;
use App\Enums\MessageSource;
use App\Models\Message;
use App\Models\StickerSend;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Deterministic sticker limits. The prompt only asks the model to be sparing; this is what
 * actually enforces it. Returns a denial reason (safe to show the model), or null when allowed.
 */
final class StickerLimiter
{
    public function denialReason(User $user, Message $source, CarbonImmutable $now): ?string
    {
        if (! $user->settingsOrDefault()->stickers_enabled) {
            return 'Stickers are turned off by the user.';
        }
        if (StickerSend::query()->where('source_message_id', $source->id)->exists()) {
            return 'Only one sticker per reply.';
        }

        $last = $user->stickerSends()->latest('id')->first();
        if ($last !== null) {
            $minutes = (int) $last->created_at->diffInMinutes($now, true);
            if ($minutes < (int) config('companion.stickers.min_gap_minutes')) {
                return "Sticker cooldown: the last one was {$minutes} min ago; no sticker now.";
            }
            if ($last->source_message_id !== null && $this->turnsBetween($user, $last->source_message_id, $source->id) < (int) config('companion.stickers.min_replies_between')) {
                return 'Sticker cooldown: too few replies since the last sticker; no sticker now.';
            }
        }

        $tz = $user->timezone;
        $dayStart = LocalTime::startOfLocalDay(LocalTime::localDate($now, $tz), $tz);
        if ($user->stickerSends()->where('created_at', '>=', $dayStart)->count() >= (int) config('companion.stickers.max_per_day')) {
            return 'Daily sticker limit reached.';
        }

        return null;
    }

    /** Inbound user turns strictly between the previous sticker's turn and the current one. */
    private function turnsBetween(User $user, int $afterMessageId, int $beforeMessageId): int
    {
        return $user->messages()
            ->where('role', MessageRole::User->value)
            ->whereIn('source', [MessageSource::TelegramText->value, MessageSource::TelegramVoice->value, MessageSource::TelegramSticker->value])
            ->where('id', '>', $afterMessageId)
            ->where('id', '<', $beforeMessageId)
            ->count();
    }
}
