<?php

namespace Tests;

use App\Agent\OpenAI\LlmClient;
use App\Memory\MemoryProvider;
use App\Models\User;
use App\Telegram\TelegramApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeMemoryProvider;
use Tests\Fakes\FakeTelegramApiClient;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected FakeLlmClient $llm;

    protected FakeMemoryProvider $memory;

    protected FakeTelegramApiClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'companion.telegram.bot_token' => 'test-token',
            'companion.telegram.webhook_secret' => 'test-webhook-secret-123',
            'companion.telegram.allowed_user_ids' => ['111', '222'],
            'companion.openai.api_key' => 'sk-test',
            'companion.openai.chat_model' => 'test-chat-model',
            'companion.openai.utility_model' => 'test-utility-model',
            'companion.openai.transcription_model' => 'test-transcribe-model',
            'companion.memory.mem0.api_key' => 'mem0-test-key',
            'companion.proactive.enabled' => true,
        ]);

        $this->llm = new FakeLlmClient;
        $this->memory = new FakeMemoryProvider;
        $this->telegram = new FakeTelegramApiClient;
        $this->app->instance(LlmClient::class, $this->llm);
        $this->app->instance(MemoryProvider::class, $this->memory);
        $this->app->instance(TelegramApiClient::class, $this->telegram);
    }

    protected function makeUser(int $telegramId = 111, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['telegram_user_id' => $telegramId, 'telegram_chat_id' => $telegramId, 'name' => 'Alex'], $attrs));
        $user->settingsOrDefault();

        return $user->refresh();
    }

    /** Freeze time at a local wall-clock moment (stored as a UTC instant, like production). */
    protected function travelToLocal(string $local, string $tz = 'Europe/Paris'): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $tz)->utc());
    }

    /** POST a Telegram update to the webhook with the valid secret. */
    protected function webhook(array $update, ?string $secret = 'test-webhook-secret-123'): TestResponse
    {
        return $this->postJson('/api/telegram/webhook', $update, $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    protected static int $updateSeq = 5000;

    protected function textUpdate(string $text, int $fromId = 111, ?int $messageId = null): array
    {
        $id = ++static::$updateSeq;

        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $messageId ?? $id,
                'date' => time(),
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Alex', 'username' => 'alex', 'language_code' => 'en'],
                'chat' => ['id' => $fromId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    protected function callbackUpdate(string $data, int $fromId = 111, ?int $messageDate = null): array
    {
        $id = ++static::$updateSeq;

        return [
            'update_id' => $id,
            'callback_query' => [
                'id' => 'cbq'.$id,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Alex'],
                'data' => $data,
                'message' => ['message_id' => 77, 'date' => $messageDate ?? time(), 'chat' => ['id' => $fromId, 'type' => 'private']],
            ],
        ];
    }
}
