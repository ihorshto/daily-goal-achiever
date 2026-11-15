<?php

declare(strict_types=1);

namespace App\Memory;

use App\Domain\ActivityLogger;
use App\Enums\Actor;
use App\Enums\MemoryStatus;
use App\Jobs\DeleteProviderMemory;
use App\Jobs\SyncMemoryToProvider;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Periodic hygiene: merges near-duplicate memories (newer supersedes older) using Mem0
 * similarity, re-queues failed syncs, and removes provider copies of non-live rows.
 * Deterministic by design — contradictions are resolved at extraction time (supersedes_memory_ref).
 */
final class MemoryConsolidationService
{
    public function __construct(
        private readonly MemoryProvider $provider,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array<string, int> */
    public function consolidate(User $user): array
    {
        $stats = ['merged' => 0, 'requeued' => 0, 'orphans_removed' => 0];
        $scope = MemoryScope::for($user);
        $threshold = (float) config('companion.memory.duplicate_similarity');

        // 1) Retry failed/pending syncs.
        $user->memories()->whereIn('status', [MemoryStatus::PendingSync->value, MemoryStatus::SyncFailed->value])
            ->whereNull('mem0_id')
            ->where('sync_attempts', '<', (int) config('companion.memory.max_sync_attempts'))
            ->each(function (Memory $m) use (&$stats) {
                SyncMemoryToProvider::dispatch($m->id);
                $stats['requeued']++;
            });

        // 2) Near-duplicate merge among synced memories.
        try {
            $live = $user->memories()->where('status', MemoryStatus::Synced->value)->orderBy('id')->get();
            $retired = [];
            foreach ($live as $memory) {
                if (isset($retired[$memory->id])) {
                    continue;
                }
                foreach ($this->provider->search($scope, (string) $memory->content, 4) as $hit) {
                    if ($hit->id === $memory->mem0_id || ($hit->score ?? 0) < $threshold) {
                        continue;
                    }
                    $other = $live->firstWhere('mem0_id', $hit->id);
                    if ($other === null || isset($retired[$other->id]) || $other->category !== $memory->category) {
                        continue;
                    }
                    [$older, $newer] = $memory->id < $other->id ? [$memory, $other] : [$other, $memory];
                    $older->update(['status' => MemoryStatus::Superseded, 'superseded_by_id' => $newer->id, 'valid_until' => now()]);
                    $this->activity->record($user, 'memory', $older->id, 'superseded', Actor::System, null, null, ['superseded_by' => $newer->id, 'reason' => 'near_duplicate']);
                    DeleteProviderMemory::dispatch($user->id, (string) $older->mem0_id, null);
                    $retired[$older->id] = true;
                    $stats['merged']++;
                }
            }

            // 3) Provider rows that no longer map to a live local row (e.g. a crashed delete) are removed.
            $known = $user->memories()->live()->whereNotNull('mem0_id')->pluck('mem0_id')->flip();
            foreach ($this->provider->list($scope, 500) as $row) {
                // Skip very recent rows: a sync job may be between provider add and local commit.
                $created = $row->createdAt !== null ? strtotime($row->createdAt) : false;
                if (! isset($known[$row->id]) && $created !== false && $created < time() - 3600) {
                    $this->provider->delete($scope, $row->id);
                    $stats['orphans_removed']++;
                }
            }
        } catch (MemoryUnavailableException $e) {
            Log::warning('memory.consolidation_degraded', ['user_id' => $user->id]);
        }

        return $stats;
    }
}
