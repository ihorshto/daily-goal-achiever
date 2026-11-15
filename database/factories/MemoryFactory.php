<?php

namespace Database\Factories;

use App\Enums\MemoryCategory;
use App\Enums\MemoryStatus;
use App\Enums\MemoryVerification;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Memory> */
class MemoryFactory extends Factory
{
    public function definition(): array
    {
        $content = fake()->sentence(8);

        return [
            'user_id' => User::factory(),
            'content' => $content,
            'content_hash' => Memory::hashContent($content),
            'category' => MemoryCategory::Fact,
            'confidence' => 0.9,
            'verification' => MemoryVerification::Stated,
            'status' => MemoryStatus::Synced,
            'mem0_id' => fake()->uuid(),
        ];
    }
}
