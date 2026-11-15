<?php

namespace Database\Factories;

use App\Enums\GoalStatus;
use App\Enums\Priority;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'status' => GoalStatus::Active,
            'priority' => Priority::Normal,
        ];
    }
}
