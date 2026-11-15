<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\NotificationLog;
use App\Models\User;
use App\Telegram\OutboundMessenger;
use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramResult;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class TelegramDeliveryTest extends TestCase
{
    public function test_idempotent_delivery(): void
    {
        $user = $this->makeUser();
        $m = app(OutboundMessenger::class);
        $this->assertTrue($m->deliver($user, 'Hello', null, 'reply', 'k1')->delivered());
        $this->assertSame('duplicate', $m->deliver($user, 'Hello', null, 'reply', 'k1')->status);
        $this->assertCount(1, $this->telegram->sent());
    }

    public function test_parse_error_falls_back_to_plain_text(): void
    {
        $user = $this->makeUser();
        $this->telegram->fail('sendMessage', new TelegramResult(false, null, 400, "Bad Request: can't parse entities"));
        $outcome = app(OutboundMessenger::class)->deliver($user, 'Use **bold** & <tags>', null, 'reply', 'k2');
        $this->assertTrue($outcome->delivered());
        $sent = $this->telegram->sent();
        $this->assertSame('Use <b>bold</b> &amp; &lt;tags&gt;', $sent[0]['text']);
        $this->assertArrayNotHasKey('parse_mode', $sent[1]);
        $this->assertSame('Use bold & <tags>', $sent[1]['text']);
    }

    public function test_rate_limited_then_retry_succeeds_and_permanent_errors_do_not_retry(): void
    {
        $user = $this->makeUser();
        $m = app(OutboundMessenger::class);
        $this->telegram->fail('sendMessage', new TelegramResult(false, null, 429, 'Too Many Requests', 7));
        $first = $m->deliver($user, 'Hi', null, 'reply', 'k3');
        $this->assertTrue($first->shouldRetry());
        $this->assertSame(7, $first->retryAfter);
        $this->assertTrue($m->deliver($user, 'Hi', null, 'reply', 'k3')->delivered());
        $this->assertSame(2, NotificationLog::where('idempotency_key', 'k3')->value('attempts'));

        $this->telegram->fail('sendMessage', new TelegramResult(false, null, 403, 'Forbidden: bot was blocked by the user'));
        $this->assertSame('failed', $m->deliver($user, 'Hi', null, 'reply', 'k4')->status);
        $this->assertSame('duplicate', $m->deliver($user, 'Hi', null, 'reply', 'k4')->status, 'permanent failures are not re-sent');
    }

    public function test_long_messages_are_split_with_keyboard_on_last_part(): void
    {
        $user = $this->makeUser();
        $text = str_repeat("Paragraph with some words in it.\n\n", 300);
        $kb = ['inline_keyboard' => [[['text' => 'OK', 'callback_data' => 'x']]]];
        app(OutboundMessenger::class)->deliver($user, $text, $kb, 'reply', 'k5');
        $sent = $this->telegram->sent();
        $this->assertGreaterThan(1, count($sent));
        foreach ($sent as $i => $part) {
            $this->assertLessThanOrEqual(4096, mb_strlen($part['text']));
            $this->assertSame($i === count($sent) - 1, isset($part['reply_markup']));
        }
    }

    public function test_commands(): void
    {
        $this->webhook($this->textUpdate('/start'))->assertOk();
        $this->assertStringContainsString('09:00', $this->telegram->lastText());
        $this->webhook($this->textUpdate('/settings'))->assertOk();
        $this->assertStringContainsString('Quiet hours: 22:00–08:00', $this->telegram->lastText());
        $this->webhook($this->textUpdate('/pause 3'))->assertOk();
        $this->assertStringContainsString('3h', $this->telegram->lastText());
        $this->webhook($this->textUpdate('/resume'))->assertOk();
        $this->assertTrue(User::first()->settingsOrDefault()->proactive_enabled);
        $this->assertSame([], $this->llm->requestsFor('agent'), 'commands never hit the LLM');
    }

    public function test_missing_bot_token_fails_permanently_without_breaking_the_turn(): void
    {
        config(['companion.telegram.bot_token' => '']);
        $this->llm->push('agent', FakeLlmClient::text('Reply that cannot be delivered'));
        $real = app(TelegramApiClient::class);
        $this->app->instance(TelegramApiClient::class, new TelegramApiClient);

        $this->webhook($this->textUpdate('hello with no token configured'))->assertOk();

        $this->assertSame('processed', Message::where('role', 'user')->first()->processing_state->value);
        $log = NotificationLog::firstOrFail();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('perm:401', $log->error_code);
        $this->assertNotNull($real);
    }
}
