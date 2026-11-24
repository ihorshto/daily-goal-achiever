<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enums\Actor;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\ReplyLanguage;
use App\Support\ReplyTone;
use Carbon\CarbonImmutable;

/** Validates and applies notification preferences; keeps scheduled rituals in sync. */
final class SettingsService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CheckpointService $checkpoints,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  validated keys only
     * @return array<string, mixed> the new effective settings
     */
    public function update(User $user, array $changes, Actor $actor, ?int $sourceMessageId): array
    {
        $settings = $user->settingsOrDefault();
        $changes = array_filter($changes, fn ($v) => $v !== null);

        if (isset($changes['timezone']) && ! LocalTime::isValidTimezone($changes['timezone'])) {
            throw new DomainException("Unknown timezone [{$changes['timezone']}]. Use an IANA name like Europe/Paris.");
        }
        foreach (['morning_time', 'evening_time', 'quiet_start', 'quiet_end'] as $clock) {
            if (isset($changes[$clock]) && ! LocalTime::isValidClock($changes[$clock])) {
                throw new DomainException("Invalid time for {$clock}; use HH:MM (24h).");
            }
        }

        if (array_key_exists('reply_language', $changes)) {
            // "auto" (→ null) survives the null filter above because it arrives as a string.
            $changes['reply_language'] = ReplyLanguage::normalize((string) $changes['reply_language']);
        }

        if (array_key_exists('tone', $changes)) {
            if (trim((string) $changes['tone']) === '') {
                // An empty string means "not changed", so a stray "" never wipes a chosen tone; reset needs "default".
                unset($changes['tone']);
            } else {
                // "default" (→ null) survives the null filter above because it arrives as a string.
                $changes['tone'] = ReplyTone::normalize((string) $changes['tone']);
            }
        }

        $pauseHours = $changes['pause_hours'] ?? null;
        unset($changes['pause_hours']);
        $tz = $changes['timezone'] ?? $user->timezone;
        unset($changes['timezone']);

        $next = array_merge($settings->only(['morning_time', 'evening_time', 'quiet_start', 'quiet_end']), array_intersect_key($changes, array_flip(['morning_time', 'evening_time', 'quiet_start', 'quiet_end'])));
        $probeDate = LocalTime::today($tz);
        foreach (['morning_time' => 'morning check-in', 'evening_time' => 'evening reflection'] as $key => $label) {
            $at = LocalTime::at($probeDate, $next[$key], $tz);
            if (LocalTime::inQuietHours($at, $tz, $next['quiet_start'], $next['quiet_end'])) {
                throw new DomainException("The {$label} at {$next[$key]} would fall inside quiet hours ({$next['quiet_start']}–{$next['quiet_end']}). Adjust one of them.");
            }
        }

        $old = $settings->only(array_keys($changes)) + ['timezone' => $user->timezone];
        if ($pauseHours !== null) {
            $changes['paused_until'] = (int) $pauseHours > 0 ? CarbonImmutable::now()->addHours((int) $pauseHours) : null;
        }
        $settings->fill($changes)->save();
        if ($tz !== $user->timezone) {
            $user->forceFill(['timezone' => $tz])->save();
        }

        $this->activity->record($user, 'settings', $settings->id, 'preferences_changed', $actor, $sourceMessageId, $old, $changes + ['timezone' => $tz]);
        $this->checkpoints->resyncRituals($user->refresh());

        return $this->describe($user);
    }

    public function describe(User $user): array
    {
        $s = $user->settingsOrDefault()->refresh();

        return [
            'timezone' => $user->timezone,
            'morning_time' => $s->morning_enabled ? $s->morning_time : 'off',
            'evening_time' => $s->evening_enabled ? $s->evening_time : 'off',
            'quiet_hours' => "{$s->quiet_start}–{$s->quiet_end}",
            'max_checkins_per_day' => $s->max_checkins_per_day,
            'min_gap_minutes' => $s->min_gap_minutes,
            'proactive_enabled' => $s->proactive_enabled,
            'reply_language' => ReplyLanguage::describe($s->reply_language),
            'tone' => ReplyTone::describe($s->tone),
            'paused_until' => $s->paused_until !== null && $s->paused_until->isFuture() ? LocalTime::human($s->paused_until, $user->timezone) : null,
        ];
    }
}
