<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Memory\MemoryProvider;
use App\Memory\MemoryScope;
use App\Models\DataDeletionRequest;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Propagates a local tombstone to Mem0, retrying until the provider confirms. */
class DeleteProviderMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public int $userId, public string $mem0Id, public ?int $deletionRequestId)
    {
        $this->onQueue('memory');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(MemoryProvider $provider): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        $provider->delete(MemoryScope::for($user), $this->mem0Id);

        if ($this->deletionRequestId !== null) {
            DataDeletionRequest::query()->whereKey($this->deletionRequestId)
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->deletionRequestId !== null) {
            DataDeletionRequest::query()->whereKey($this->deletionRequestId)->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
