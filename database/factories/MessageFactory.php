<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Enums\MessageSource;
use App\Enums\ProcessingState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'conversation_id' => fn (array $attrs) => Conversation::query()->firstOrCreate(
                ['user_id' => $attrs['user_id'], 'telegram_chat_id' => User::find($attrs['user_id'])?->telegram_chat_id ?? 1],
            )->id,
            'role' => MessageRole::User,
            'source' => MessageSource::TelegramText,
            'text' => fake()->sentence(),
            'dedup_key' => 'test:'.Str::uuid(),
            'processing_state' => ProcessingState::Processed,
        ];
    }
}
