<?php

namespace Database\Factories;

use App\Enums\CheckpointKind;
use App\Enums\CheckpointState;
use App\Models\Checkpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Checkpoint> */
class CheckpointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => CheckpointKind::TaskFollowUp,
            'reason' => 'Check progress',
            'earliest_at' => now(),
            'latest_at' => now()->addHours(4),
            'state' => CheckpointState::Pending,
            'idempotency_key' => 'test:'.Str::uuid(),
        ];
    }
}
