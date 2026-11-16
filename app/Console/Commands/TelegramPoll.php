<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramUpdateRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Long-polling alternative to the webhook: outbound-only, so no public HTTPS is needed
 * (local development, or a home server with TELEGRAM_MODE=polling). Never run alongside a webhook.
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Process one batch and exit}';

    protected $description = 'Long-poll Telegram getUpdates and route updates through the same pipeline as the webhook';

    private bool $stopping = false;

    public function handle(TelegramApiClient $telegram, TelegramUpdateRouter $router): int
    {
        if (app()->isProduction() && config('companion.telegram.mode') !== 'polling') {
            $this->error('In production, polling requires TELEGRAM_MODE=polling (otherwise the webhook is used).');

            return self::FAILURE;
        }
        // Finish the current batch on docker stop / Ctrl+C instead of dying mid-update.
        $this->trap([SIGTERM, SIGINT], function () {
            $this->stopping = true;
        });

        $telegram->deleteWebhook();
        $offset = (int) Cache::get('telegram:poll_offset', 0);
        $this->info('Polling… (Ctrl+C to stop)');
        do {
            $result = $telegram->getUpdates($offset);
            foreach ((array) ($result->result ?? []) as $update) {
                Context::add('correlation_id', (string) Str::uuid());
                $outcome = $router->route($update);
                // Updates are deduplicated by update_id, so a lost offset only causes harmless replays.
                $offset = max($offset, (int) $update['update_id'] + 1);
                Cache::forever('telegram:poll_offset', $offset);
                $this->line("update {$update['update_id']}: {$outcome}");
            }
            if (! $result->ok && ! $this->stopping) {
                sleep(3);
            }
        } while (! $this->option('once') && ! $this->stopping);

        return self::SUCCESS;
    }
}
