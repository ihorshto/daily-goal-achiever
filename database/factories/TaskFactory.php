<?php

namespace Database\Factories;

use App\Enums\Commitment;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'status' => TaskStatus::Todo,
            'commitment' => Commitment::Intention,
            'priority' => Priority::Normal,
        ];
    }

    public function committed(): static
    {
        return $this->state(['commitment' => Commitment::Commitment]);
    }

    public function done(): static
    {
        return $this->state(['status' => TaskStatus::Done, 'completed_at' => now(), 'completion_confirmed_at' => now()]);
    }
}
