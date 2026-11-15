<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        $telegramId = fake()->unique()->numberBetween(100_000_000, 999_999_999);

        return [
            'name' => fake()->firstName(),
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'telegram_username' => fake()->userName(),
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
            'is_enabled' => true,
        ];
    }
}
