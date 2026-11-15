<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Memory\MemoryProvider;
use App\Memory\MemoryScope;
use App\Models\DataDeletionRequest;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retries a full Mem0 scope purge until the provider confirms. */
class PurgeProviderScope implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public function __construct(public int $userId, public int $deletionRequestId)
    {
        $this->onQueue('memory');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(MemoryProvider $provider): void
    {
        $user = User::query()->findOrFail($this->userId);
        $deleted = $provider->deleteAll(MemoryScope::for($user));
        $request = DataDeletionRequest::query()->findOrFail($this->deletionRequestId);
        $request->update(['status' => 'completed', 'completed_at' => now(), 'details' => array_merge($request->details ?? [], ['provider_deleted' => $deleted])]);
    }

    public function failed(\Throwable $e): void
    {
        DataDeletionRequest::query()->whereKey($this->deletionRequestId)->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
