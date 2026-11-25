<?php

namespace Database\Factories;

use App\Models\Sticker;
use App\Models\StickerSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sticker> */
class StickerFactory extends Factory
{
    protected $model = Sticker::class;

    public function definition(): array
    {
        return [
            'sticker_set_id' => StickerSet::factory(),
            'user_id' => fn (array $attrs) => StickerSet::query()->findOrFail($attrs['sticker_set_id'])->user_id,
            'file_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{40}'),
            'file_unique_id' => fake()->unique()->regexify('[A-Za-z0-9]{16}'),
            'emoji' => '😀',
            'emoji_key' => '😀',
            'is_animated' => false,
            'is_video' => false,
            'position' => 0,
        ];
    }

    public function invalid(): static
    {
        return $this->state(['invalid_at' => now()]);
    }
}
