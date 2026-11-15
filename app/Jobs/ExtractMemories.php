<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\OpenAI\LlmException;
use App\Memory\MemoryExtractor;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractMemories implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
        $this->onQueue('memory');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MemoryExtractor $extractor): void
    {
        $message = Message::query()->with('user')->find($this->messageId);
        if ($message === null) {
            return;
        }
        try {
            $outcomes = $extractor->extract($message);
            Log::info('memory.extracted', ['message_id' => $message->id, 'outcomes' => array_count_values($outcomes)]);
        } catch (LlmException $e) {
            if ($e->retryable) {
                throw $e;
            }
            Log::warning('memory.extraction_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }
}
