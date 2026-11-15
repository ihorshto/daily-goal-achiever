<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\TelegramApiClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:webhook {action=set : set|info|delete} {--url= : Public base URL (defaults to APP_URL)}';

    protected $description = 'Register, inspect or remove the Telegram webhook (uses TELEGRAM_WEBHOOK_SECRET)';

    public function handle(TelegramApiClient $telegram): int
    {
        $action = $this->argument('action');
        if ($action === 'info') {
            $info = $telegram->getWebhookInfo();
            $r = (array) $info->result;
            unset($r['ip_address']);
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $info->ok ? self::SUCCESS : self::FAILURE;
        }
        if ($action === 'delete') {
            return $telegram->deleteWebhook()->ok ? self::SUCCESS : self::FAILURE;
        }
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->error('Telegram requires an HTTPS webhook URL. Set APP_URL or pass --url=https://...');

            return self::FAILURE;
        }
        $secret = (string) config('companion.telegram.webhook_secret');
        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET must be 16-256 chars of A-Z a-z 0-9 _ -');

            return self::FAILURE;
        }
        $result = $telegram->setWebhook($base.'/api/telegram/webhook', $secret, ['message', 'callback_query']);
        $result->ok ? $this->info('Webhook set to '.$base.'/api/telegram/webhook') : $this->error('Failed: '.$result->description);

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }
}
