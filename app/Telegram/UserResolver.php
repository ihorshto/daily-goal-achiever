<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Models\User;

/**
 * Maps a Telegram identity to a local user. Access is allowlist-gated for the MVP;
 * the resulting user id is the only identity used downstream (never model-supplied).
 */
final class UserResolver
{
    public function isAllowed(int $telegramUserId): bool
    {
        $allowed = config('companion.telegram.allowed_user_ids', []);

        return in_array((string) $telegramUserId, array_map('strval', $allowed), true);
    }

    /** @param array<string, mixed> $from Telegram "from" object */
    public function resolve(array $from, int $chatId): ?User
    {
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId <= 0 || ($from['is_bot'] ?? false) || ! $this->isAllowed($telegramId)) {
            return null;
        }
        $user = User::query()->firstOrCreate(
            ['telegram_user_id' => $telegramId],
            [
                'name' => mb_substr((string) ($from['first_name'] ?? ''), 0, 100) ?: null,
                'telegram_chat_id' => $chatId,
                'telegram_username' => $from['username'] ?? null,
                'locale' => mb_substr((string) ($from['language_code'] ?? config('companion.defaults.locale')), 0, 16),
                'timezone' => config('companion.defaults.timezone'),
                'is_enabled' => true,
            ],
        );
        if ($user->telegram_chat_id !== $chatId || $user->telegram_username !== ($from['username'] ?? null)) {
            $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_username' => $from['username'] ?? null])->save();
        }
        $user->settingsOrDefault();

        return $user;
    }
}
