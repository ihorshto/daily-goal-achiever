<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use StoresUtc;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_enabled' => 'boolean',
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'last_user_message_at' => 'immutable_datetime',
            'last_proactive_at' => 'immutable_datetime',
            'data_purged_at' => 'immutable_datetime',
        ];
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /** Returns persisted settings, creating defaults on first access. */
    public function settingsOrDefault(): UserSetting
    {
        $settings = $this->settings()->first();
        if ($settings !== null) {
            return $settings;
        }

        $defaults = config('companion.defaults');

        return $this->settings()->firstOrCreate([], [
            'morning_time' => $defaults['morning_time'],
            'evening_time' => $defaults['evening_time'],
            'quiet_start' => $defaults['quiet_start'],
            'quiet_end' => $defaults['quiet_end'],
            'max_checkins_per_day' => $defaults['max_checkins_per_day'],
            'min_gap_minutes' => $defaults['min_gap_minutes'],
            'stickers_enabled' => true,
            'emoji_enabled' => true,
        ]);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingActionConfirmation::class);
    }

    public function stickerSets(): HasMany
    {
        return $this->hasMany(StickerSet::class);
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(Sticker::class);
    }

    public function stickerSends(): HasMany
    {
        return $this->hasMany(StickerSend::class);
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->telegram_username ?: 'there');
    }
}
