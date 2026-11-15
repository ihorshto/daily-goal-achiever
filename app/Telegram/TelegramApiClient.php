<?php

declare(strict_types=1);

namespace App\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Thin Telegram Bot API client. Never logs message text or tokens. */
class TelegramApiClient
{
    /** @param array<string, mixed>|null $replyMarkup */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = 'HTML'): TelegramResult
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]];
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendMessage', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): TelegramResult
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $text !== null ? mb_substr($text, 0, 190) : null]));
    }

    public function removeKeyboard(int $chatId, int $messageId): TelegramResult
    {
        return $this->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
    }

    public function sendChatAction(int $chatId, string $action = 'typing'): TelegramResult
    {
        return $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    public function getFile(string $fileId): TelegramResult
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /** Stream a file to $destination, aborting if it exceeds $maxBytes. */
    public function downloadFile(string $filePath, string $destination, int $maxBytes): int
    {
        if (! preg_match('#^[A-Za-z0-9_\-./]+$#', $filePath) || str_contains($filePath, '..')) {
            throw new RuntimeException('Unexpected Telegram file path');
        }
        $url = rtrim((string) config('companion.telegram.api_base'), '/').'/file/bot'.$this->token().'/'.$filePath;
        $response = Http::timeout(60)->withOptions(['stream' => true])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Telegram file download failed ('.$response->status().')');
        }
        $body = $response->toPsrResponse()->getBody();
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot open temp file');
        }
        $total = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $total += strlen($chunk);
                if ($total > $maxBytes) {
                    throw new RuntimeException('Voice file exceeds size limit');
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        return $total;
    }

    /** @param list<string> $allowedUpdates */
    public function setWebhook(string $url, string $secret, array $allowedUpdates): TelegramResult
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => false,
            'max_connections' => 10,
        ]);
    }

    public function deleteWebhook(): TelegramResult
    {
        return $this->call('deleteWebhook', []);
    }

    public function getWebhookInfo(): TelegramResult
    {
        return $this->call('getWebhookInfo', []);
    }

    public function getMe(): TelegramResult
    {
        return $this->call('getMe', []);
    }

    public function getUpdates(int $offset, int $timeout = 25): TelegramResult
    {
        return $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message', 'callback_query']], $timeout + 10);
    }

    /** @param array<string, mixed> $payload */
    protected function call(string $method, array $payload, ?int $timeout = null): TelegramResult
    {
        if ((string) config('companion.telegram.bot_token') === '') {
            // Misconfiguration is a permanent failure (401), never an exception that kills a turn.
            Log::error('telegram.not_configured', ['method' => $method]);

            return new TelegramResult(false, null, 401, 'TELEGRAM_BOT_TOKEN is not configured');
        }
        try {
            $response = $this->http($timeout)->post('/'.$method, $payload);
        } catch (ConnectionException) {
            Log::warning('telegram.connection_failed', ['method' => $method]);

            return new TelegramResult(false, null, 0, 'connection_failed', null);
        }
        $json = $response->json() ?? [];
        $ok = (bool) ($json['ok'] ?? false);
        $result = new TelegramResult(
            ok: $ok,
            result: $json['result'] ?? null,
            errorCode: (int) ($json['error_code'] ?? ($ok ? 0 : $response->status())),
            description: $ok ? null : (string) ($json['description'] ?? 'unknown'),
            retryAfter: isset($json['parameters']['retry_after']) ? (int) $json['parameters']['retry_after'] : null,
        );
        if (! $ok) {
            Log::warning('telegram.api_error', ['method' => $method, 'error_code' => $result->errorCode, 'retry_after' => $result->retryAfter]);
        }

        return $result;
    }

    private function http(?int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('companion.telegram.api_base'), '/').'/bot'.$this->token())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? (int) config('companion.telegram.timeout_seconds'))
            ->connectTimeout(5);
    }

    private function token(): string
    {
        $token = (string) config('companion.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured');
        }

        return $token;
    }
}
