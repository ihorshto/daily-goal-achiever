<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MemoryStatus;
use App\Memory\MemoryProvider;
use App\Memory\MemoryScope;
use App\Memory\MemoryUnavailableException;
use App\Models\Memory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a locally recorded memory into Mem0. Retries with backoff while Mem0 is down;
 * `memory:retry-failed` re-dispatches anything that exhausted its retries.
 */
class SyncMemoryToProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $memoryId)
    {
        $this->onQueue('memory');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600, 1800];
    }

    public function handle(MemoryProvider $provider): void
    {
        $memory = Memory::query()->with('user')->find($this->memoryId);
        if ($memory === null || ! in_array($memory->status, [MemoryStatus::PendingSync, MemoryStatus::SyncFailed], true) || $memory->mem0_id !== null) {
            return; // deleted, superseded or already synced
        }
        $user = $memory->user;
        if ($user->data_purged_at !== null && $memory->created_at->lessThanOrEqualTo($user->data_purged_at)) {
            return;
        }

        try {
            $mem0Id = $provider->add(MemoryScope::for($user), (string) $memory->content, [
                'local_id' => $memory->id,
                'category' => $memory->category->value,
                'verification' => $memory->verification->value,
                'source_message_id' => $memory->source_message_id,
                'recorded_at' => $memory->created_at->toIso8601String(),
            ]);
        } catch (MemoryUnavailableException $e) {
            $memory->forceFill([
                'status' => MemoryStatus::SyncFailed,
                'sync_attempts' => $memory->sync_attempts + 1,
                'last_sync_error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();
            Log::warning('memory.sync_failed', ['memory_id' => $memory->id, 'attempt' => $this->attempts()]);
            throw $e;
        }

        // Guarded: only attach the provider id if the row was not deleted meanwhile.
        $affected = Memory::query()->whereKey($memory->id)
            ->whereIn('status', [MemoryStatus::PendingSync->value, MemoryStatus::SyncFailed->value])
            ->update(['mem0_id' => $mem0Id, 'status' => MemoryStatus::Synced->value, 'synced_at' => now(), 'last_sync_error' => null, 'updated_at' => now()]);

        if ($affected === 0) {
            // Deleted/superseded while we were writing: remove the copy we just created.
            $provider->delete(MemoryScope::for($user), $mem0Id);
            Log::info('memory.sync_raced_with_delete', ['memory_id' => $memory->id]);
        }
    }
}
