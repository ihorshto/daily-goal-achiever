<?php

declare(strict_types=1);

namespace App\Stickers;

use App\Models\Sticker;
use App\Models\User;

/** Chooses a concrete sticker (file_id) in code; the model only ever names an emoji. */
final class StickerPicker
{
    private const AVOID_RECENT = 5;

    public function pick(User $user, string $emojiKey): ?Sticker
    {
        $candidates = $user->stickers()->usable()->where('emoji_key', $emojiKey)->get();
        if ($candidates->isEmpty()) {
            return null;
        }
        $recent = $user->stickerSends()->whereNotNull('sticker_id')->latest('id')->limit(self::AVOID_RECENT)->pluck('sticker_id')->all();
        $fresh = $candidates->reject(fn (Sticker $s) => in_array($s->id, $recent, true));

        return ($fresh->isNotEmpty() ? $fresh : $candidates)->random();
    }

    /** @return list<string> emoji keys available in the catalog, most common first */
    public function availableEmojis(User $user, int $limit = 30): array
    {
        return $user->stickers()->usable()->whereNotNull('emoji_key')
            ->selectRaw('emoji_key, count(*) as total')
            ->groupBy('emoji_key')
            ->orderByDesc('total')
            ->orderBy('emoji_key')
            ->limit($limit)
            ->pluck('emoji_key')
            ->all();
    }
}
