<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\TelegramUpdate;
use App\Telegram\TelegramResult;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramPollingTest extends TestCase
{
    public function test_production_refuses_polling_unless_mode_is_polling(): void
    {
        $this->app['env'] = 'production';
        config(['companion.telegram.mode' => 'webhook']);

        $this->artisan('telegram:poll', ['--once' => true])->assertFailed();
        $this->assertSame([], $this->telegram->calls, 'must not delete a live webhook');
    }

    public function test_polling_mode_in_production_routes_updates_through_the_webhook_pipeline_once(): void
    {
        $this->app['env'] = 'production';
        config(['companion.telegram.mode' => 'polling']);
        $update = $this->textUpdate('/settings');
        $this->telegram->fail('getUpdates', new TelegramResult(true, [$update], 0, null));

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame('deleteWebhook', $this->telegram->calls[0]['method']);
        $this->assertTrue(TelegramUpdate::where('update_id', $update['update_id'])->exists());
        $this->assertStringContainsString('Your settings', (string) $this->telegram->lastText());
        $this->assertSame($update['update_id'] + 1, Cache::get('telegram:poll_offset'));

        // A replay after a lost offset is deduplicated, not processed twice.
        Cache::forget('telegram:poll_offset');
        $this->telegram->fail('getUpdates', new TelegramResult(true, [$update], 0, null));
        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();
        $this->assertSame(1, Message::where('text', '/settings')->count());
    }
}
