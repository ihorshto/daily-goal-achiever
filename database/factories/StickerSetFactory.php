<?php

namespace Database\Factories;

use App\Enums\StickerSetStatus;
use App\Models\StickerSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StickerSet> */
class StickerSetFactory extends Factory
{
    protected $model = StickerSet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'set_'.fake()->unique()->lexify('????????').'_by_bot',
            'title' => fake()->words(2, true),
            'sticker_type' => 'regular',
            'status' => StickerSetStatus::Active,
            'enabled' => true,
            'sticker_count' => 0,
            'synced_at' => now(),
        ];
    }

    public function offered(): static
    {
        return $this->state(['status' => StickerSetStatus::Offered, 'offered_at' => now(), 'synced_at' => null]);
    }

    public function dismissed(): static
    {
        return $this->state(['status' => StickerSetStatus::Dismissed, 'dismissed_at' => now()]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
