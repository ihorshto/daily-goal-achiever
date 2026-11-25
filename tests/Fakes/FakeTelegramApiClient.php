<?php

namespace Tests\Fakes;

use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramResult;

/** Records Telegram API calls; failures can be scripted per method. */
class FakeTelegramApiClient extends TelegramApiClient
{
    /** @var list<array{method: string, payload: array}> */
    public array $calls = [];

    /** @var array<string, list<TelegramResult>> */
    public array $failures = [];

    /** @var array<string, array<string, mixed>> sticker set name => Bot API StickerSet object */
    public array $stickerSets = [];

    public int $nextMessageId = 1000;

    public string $downloadBytes = "OggS\x00\x02fake-opus-audio";

    public function fail(string $method, TelegramResult ...$results): static
    {
        foreach ($results as $r) {
            $this->failures[$method][] = $r;
        }

        return $this;
    }

    protected function call(string $method, array $payload, ?int $timeout = null): TelegramResult
    {
        $this->calls[] = ['method' => $method, 'payload' => $payload];
        if (! empty($this->failures[$method])) {
            return array_shift($this->failures[$method]);
        }

        return match ($method) {
            'sendMessage', 'sendSticker' => new TelegramResult(true, ['message_id' => $this->nextMessageId++], 0, null),
            'getStickerSet' => isset($this->stickerSets[$payload['name']])
                ? new TelegramResult(true, $this->stickerSets[$payload['name']], 0, null)
                : new TelegramResult(false, null, 400, 'Bad Request: STICKERSET_INVALID'),
            'getFile' => new TelegramResult(true, ['file_id' => $payload['file_id'], 'file_path' => 'voice/file_1.oga', 'file_size' => strlen($this->downloadBytes)], 0, null),
            default => new TelegramResult(true, true, 0, null),
        };
    }

    protected function upload(string $method, array $fields, string $field, string $filename, string $contents, string $mimeType): TelegramResult
    {
        $this->calls[] = ['method' => $method, 'payload' => $fields + [$field => ['filename' => $filename, 'contents' => $contents, 'mime' => $mimeType]]];
        if (! empty($this->failures[$method])) {
            return array_shift($this->failures[$method]);
        }

        return new TelegramResult(true, ['message_id' => $this->nextMessageId++], 0, null);
    }

    /** @return list<array> */
    public function documents(): array
    {
        return array_values(array_map(fn ($c) => $c['payload'], array_filter($this->calls, fn ($c) => $c['method'] === 'sendDocument')));
    }

    /** @return list<array> */
    public function stickers(): array
    {
        return array_values(array_map(fn ($c) => $c['payload'], array_filter($this->calls, fn ($c) => $c['method'] === 'sendSticker')));
    }

    public function downloadFile(string $filePath, string $destination, int $maxBytes): int
    {
        $this->calls[] = ['method' => 'downloadFile', 'payload' => ['file_path' => $filePath]];
        if (strlen($this->downloadBytes) > $maxBytes) {
            throw new \RuntimeException('Voice file exceeds size limit');
        }
        file_put_contents($destination, $this->downloadBytes);

        return strlen($this->downloadBytes);
    }

    /** @return list<array> */
    public function sent(): array
    {
        return array_values(array_map(fn ($c) => $c['payload'], array_filter($this->calls, fn ($c) => $c['method'] === 'sendMessage')));
    }

    public function lastText(): ?string
    {
        $sent = $this->sent();

        return $sent === [] ? null : end($sent)['text'];
    }
}
