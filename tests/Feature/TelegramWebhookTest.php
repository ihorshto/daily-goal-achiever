<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\TelegramUpdate;
use App\Models\User;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    public function test_rejects_missing_or_wrong_secret(): void
    {
        $this->webhook($this->textUpdate('hi'), null)->assertStatus(401);
        $this->webhook($this->textUpdate('hi'), 'wrong-secret')->assertStatus(401);
        $this->assertSame(0, TelegramUpdate::count());
    }

    public function test_fails_closed_when_secret_not_configured(): void
    {
        config(['companion.telegram.webhook_secret' => null]);
        $this->webhook($this->textUpdate('hi'))->assertStatus(503);
    }

    public function test_text_message_runs_agent_and_replies_once(): void
    {
        $this->llm->push('agent', FakeLlmClient::text('Hello Alex! What are you working on today?'));

        $this->webhook($this->textUpdate('Hello there, planning my day'))->assertOk()->assertJson(['outcome' => 'dispatched']);

        $user = User::where('telegram_user_id', 111)->firstOrFail();
        $this->assertSame('Europe/Paris', $user->timezone);
        $this->assertSame(2, Message::where('user_id', $user->id)->count()); // inbound + reply
        $sent = $this->telegram->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(111, $sent[0]['chat_id']);
        $this->assertStringContainsString('What are you working on today?', $sent[0]['text']);
        $this->assertSame('processed', Message::where('role', 'user')->first()->processing_state->value);
    }

    public function test_duplicate_webhook_delivery_is_processed_once(): void
    {
        $this->llm->push('agent', FakeLlmClient::text('Reply one'), FakeLlmClient::text('Reply two'));
        $update = $this->textUpdate('Same update delivered twice');

        $this->webhook($update)->assertOk()->assertJson(['outcome' => 'dispatched']);
        $this->webhook($update)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(1, Message::where('role', 'user')->count());
        $this->assertCount(1, $this->telegram->sent());
        $this->assertCount(1, $this->llm->requestsFor('agent'));
    }

    public function test_same_telegram_message_with_new_update_id_is_deduplicated(): void
    {
        $this->llm->push('agent', FakeLlmClient::text('Only once'));
        $this->webhook($this->textUpdate('hello world', 111, 42))->assertOk();
        $this->webhook($this->textUpdate('hello world', 111, 42))->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->assertCount(1, $this->telegram->sent());
    }

    public function test_non_allowlisted_user_is_rejected_without_reply(): void
    {
        $this->webhook($this->textUpdate('let me in', 999))->assertOk()->assertJson(['outcome' => 'rejected']);
        $this->assertSame(0, User::count());
        $this->assertSame([], $this->telegram->sent());
        $this->assertSame([], $this->llm->requests);
    }

    public function test_group_chats_are_ignored(): void
    {
        $update = $this->textUpdate('hi group');
        $update['message']['chat']['type'] = 'group';
        $this->webhook($update)->assertOk()->assertJson(['outcome' => 'ignored']);
        $this->assertSame([], $this->telegram->sent());
    }

    public function test_inbound_rate_limit(): void
    {
        config(['companion.agent.inbound_rate_per_minute' => 2]);
        $this->webhook($this->textUpdate('one message here'))->assertJson(['outcome' => 'dispatched']);
        $this->webhook($this->textUpdate('two message here'))->assertJson(['outcome' => 'dispatched']);
        $this->webhook($this->textUpdate('three message here'))->assertJson(['outcome' => 'rate_limited']);
    }
}
