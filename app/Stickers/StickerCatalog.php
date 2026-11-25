<?php

declare(strict_types=1);

namespace App\Stickers;

use App\Enums\StickerSetStatus;
use App\Models\Sticker;
use App\Models\StickerSet;
use App\Models\User;
use App\Support\EmojiKey;
use App\Telegram\TelegramApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** A user's sticker catalog: importing sets from Telegram, the offer state machine and listing. */
final class StickerCatalog
{
    public function __construct(private readonly TelegramApiClient $telegram) {}

    /** Accepts a bare set name or a t.me/addstickers link; returns the set name. */
    public function parseSetName(string $input): string
    {
        $input = trim($input);
        if (preg_match('#^(?:https?://)?t\.me/addemoji/#i', $input)) {
            throw StickerSetUnavailable::unsupportedType();
        }
        if (preg_match('#^(?:https?://)?t\.me/addstickers/([A-Za-z0-9_]{1,64})/?$#i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $input)) {
            return $input;
        }

        throw StickerSetUnavailable::invalidName();
    }

    /** Fetches a set from Telegram. @return array<string, mixed> Bot API StickerSet */
    public function fetch(string $name): array
    {
        $result = $this->telegram->getStickerSet($name);
        if (! $result->ok) {
            throw ($result->errorCode === 429 || $result->errorCode === 0 || $result->errorCode >= 500)
                ? StickerSetUnavailable::retryLater()
                : StickerSetUnavailable::notFound();
        }
        $set = is_array($result->result) ? $result->result : [];
        if (($set['sticker_type'] ?? 'regular') === 'custom_emoji') {
            throw StickerSetUnavailable::unsupportedType();
        }
        if (! is_array($set['stickers'] ?? null) || $set['stickers'] === []) {
            throw StickerSetUnavailable::empty();
        }

        return $set;
    }

    /** Imports (or re-syncs) a set and makes it active. */
    public function import(User $user, string $name): StickerImport
    {
        $api = $this->fetch($name);
        $canonical = is_string($api['name'] ?? null) && $api['name'] !== '' ? $api['name'] : $name;

        $max = (int) config('companion.stickers.max_sets');
        if ($user->stickerSets()->active()->where('name', '!=', $canonical)->count() >= $max) {
            throw StickerSetUnavailable::limitReached($max);
        }

        $rows = [];
        foreach ($api['stickers'] as $i => $s) {
            if (! is_array($s) || ! is_string($s['file_id'] ?? null) || ! is_string($s['file_unique_id'] ?? null)) {
                continue;
            }
            $emoji = is_string($s['emoji'] ?? null) ? mb_substr($s['emoji'], 0, 16) : null;
            $rows[$s['file_unique_id']] = [
                'user_id' => $user->id,
                'file_id' => mb_substr($s['file_id'], 0, 255),
                'file_unique_id' => mb_substr($s['file_unique_id'], 0, 64),
                'emoji' => $emoji,
                'emoji_key' => EmojiKey::normalize($emoji),
                'is_animated' => (bool) ($s['is_animated'] ?? false),
                'is_video' => (bool) ($s['is_video'] ?? false),
                'position' => (int) $i,
                'invalid_at' => null,
            ];
        }
        if ($rows === []) {
            throw StickerSetUnavailable::empty();
        }

        $title = $this->title($api['title'] ?? null, $canonical);
        $attributes = [
            'title' => $title,
            'sticker_type' => (string) ($api['sticker_type'] ?? 'regular'),
            'status' => StickerSetStatus::Active,
            'enabled' => true,
            'sticker_count' => count($rows),
            'synced_at' => CarbonImmutable::now(),
            'dismissed_at' => null,
        ];

        return DB::transaction(function () use ($user, $canonical, $attributes, $rows) {
            $existing = $user->stickerSets()->where('name', $canonical)->first();
            $created = $existing === null;
            $set = $this->saveSet($user, $canonical, $attributes);

            Sticker::query()->upsert(
                array_values(array_map(fn (array $r) => $r + ['sticker_set_id' => $set->id], $rows)),
                ['sticker_set_id', 'file_unique_id'],
                ['file_id', 'emoji', 'emoji_key', 'is_animated', 'is_video', 'position', 'invalid_at'],
            );
            $set->stickers()->whereNotIn('file_unique_id', array_keys($rows))->delete();

            return new StickerImport($set, count($rows), $created);
        });
    }

    /** True when a just-received sticker's set should be offered to the user now. */
    public function shouldOffer(User $user, string $setName): bool
    {
        $set = $user->stickerSets()->where('name', $setName)->first();
        if ($set === null) {
            return true;
        }
        $cooldown = CarbonImmutable::now()->subDays((int) config('companion.stickers.offer_cooldown_days'));

        return match ($set->status) {
            StickerSetStatus::Active => false,
            StickerSetStatus::Offered => $set->offered_at === null || $set->offered_at->lessThan($cooldown),
            StickerSetStatus::Dismissed => $set->dismissed_at === null || $set->dismissed_at->lessThan($cooldown),
        };
    }

    /** @param array<string, mixed> $api Bot API StickerSet */
    public function recordOffer(User $user, array $api): StickerSet
    {
        $name = (string) $api['name'];
        $existing = $user->stickerSets()->where('name', $name)->first();

        return $this->saveSet($user, $name, [
            'title' => $this->title($api['title'] ?? null, $name),
            'sticker_type' => (string) ($api['sticker_type'] ?? 'regular'),
            'status' => $existing?->status === StickerSetStatus::Active ? StickerSetStatus::Active : StickerSetStatus::Offered,
            'sticker_count' => is_array($api['stickers'] ?? null) ? count($api['stickers']) : 0,
            'offered_at' => CarbonImmutable::now(),
        ]);
    }

    public function dismiss(StickerSet $set): void
    {
        if ($set->status === StickerSetStatus::Offered) {
            $set->update(['status' => StickerSetStatus::Dismissed, 'dismissed_at' => CarbonImmutable::now()]);
        }
    }

    /** Active sets in a stable order; the position in this list is the number shown in /stickers. @return Collection<int, StickerSet> */
    public function listFor(User $user): Collection
    {
        return $user->stickerSets()->active()->orderBy('id')->get();
    }

    public function setByPosition(User $user, int $position): ?StickerSet
    {
        return $this->listFor($user)->values()->get($position - 1);
    }

    public function remove(StickerSet $set): void
    {
        $set->delete();
    }

    public function setEnabled(StickerSet $set, bool $enabled): void
    {
        $set->update(['enabled' => $enabled]);
    }

    public function hasUsable(User $user): bool
    {
        return $user->stickers()->usable()->exists();
    }

    /** Compact catalog description for the model: emoji counts (most common first) and set titles. */
    public function summary(User $user): ?string
    {
        $counts = $user->stickers()->usable()->whereNotNull('emoji_key')
            ->selectRaw('emoji_key, count(*) as total')
            ->groupBy('emoji_key')
            ->orderByDesc('total')
            ->orderBy('emoji_key')
            ->limit((int) config('companion.stickers.catalog_max_emojis'))
            ->get();
        if ($counts->isEmpty()) {
            return null;
        }
        $titles = $user->stickerSets()->usable()->orderBy('id')->pluck('title')->all();

        return 'sets: '.implode(' | ', $titles)."\nemojis: ".$counts->map(fn ($r) => $r->emoji_key.'×'.$r->total)->implode(', ');
    }

    /** One line, bounded: set titles reach the model and the user's chat. */
    private function title(mixed $title, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', is_string($title) ? $title : ''));

        return mb_substr($clean !== '' ? $clean : $fallback, 0, 64);
    }

    /** @param array<string, mixed> $attributes */
    private function saveSet(User $user, string $name, array $attributes): StickerSet
    {
        try {
            return DB::transaction(fn () => StickerSet::query()->updateOrCreate(['user_id' => $user->id, 'name' => $name], $attributes));
        } catch (QueryException) {
            // A concurrent request (double tap) created it first: update that row instead.
            $set = $user->stickerSets()->where('name', $name)->firstOrFail();
            $set->update($attributes);

            return $set;
        }
    }
}
