<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramUpdateRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/** Local-development alternative to the webhook (no public HTTPS needed). Do not run alongside a webhook. */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Process one batch and exit}';

    protected $description = 'Long-poll Telegram getUpdates and route updates through the same pipeline as the webhook';

    public function handle(TelegramApiClient $telegram, TelegramUpdateRouter $router): int
    {
        if (app()->isProduction()) {
            $this->error('Polling is for local development; production uses the webhook.');

            return self::FAILURE;
        }
        $telegram->deleteWebhook();
        $offset = (int) Cache::get('telegram:poll_offset', 0);
        $this->info('Polling… (Ctrl+C to stop)');
        do {
            $result = $telegram->getUpdates($offset);
            foreach ((array) ($result->result ?? []) as $update) {
                Context::add('correlation_id', (string) Str::uuid());
                $outcome = $router->route($update);
                $offset = max($offset, (int) $update['update_id'] + 1);
                Cache::forever('telegram:poll_offset', $offset);
                $this->line("update {$update['update_id']}: {$outcome}");
            }
            if (! $result->ok) {
                sleep(3);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
