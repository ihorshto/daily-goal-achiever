<?php

declare(strict_types=1);

namespace App\Memory;

use App\Domain\ActivityLogger;
use App\Domain\DomainException;
use App\Enums\Actor;
use App\Enums\MemoryCategory;
use App\Enums\MemoryStatus;
use App\Enums\MemoryVerification;
use App\Jobs\DeleteProviderMemory;
use App\Jobs\SyncMemoryToProvider;
use App\Models\DataDeletionRequest;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL is the system of record for memories; Mem0 is a derived semantic index.
 * Every write goes: local row (pending_sync) → async sync job → Mem0 id stored.
 * Deletion tombstones the local row first, so in-flight jobs can never resurrect it.
 */
final class MemoryLedger
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param  bool  $explicitUserRequest  true when the user literally asked to remember this
     * @return array{0: Memory|null, 1: string} memory (null when refused) + outcome code
     */
    public function record(
        User $user,
        string $content,
        MemoryCategory $category,
        float $confidence,
        MemoryVerification $verification,
        ?int $sourceMessageId,
        bool $explicitUserRequest = false,
        ?int $supersedesId = null,
    ): array {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
        if (mb_strlen($content) < 8 || mb_strlen($content) > 600) {
            return [null, 'invalid_length'];
        }
        $hash = Memory::hashContent($content);

        $existing = $user->memories()->where('content_hash', $hash)->orderByDesc('id')->first();
        if ($existing !== null && $existing->isLive()) {
            return [$existing, 'duplicate'];
        }
        // Never reintroduce a fact the user deleted, unless they explicitly ask again.
        if ($existing !== null && $existing->status === MemoryStatus::Deleted && ! $explicitUserRequest) {
            return [null, 'previously_deleted'];
        }

        $supersedes = null;
        if ($supersedesId !== null) {
            $supersedes = $user->memories()->live()->whereKey($supersedesId)->first();
        }

        $memory = DB::transaction(function () use ($user, $content, $hash, $category, $confidence, $verification, $sourceMessageId, $supersedes) {
            $memory = $user->memories()->create([
                'content' => $content,
                'content_hash' => $hash,
                'category' => $category,
                'confidence' => max(0, min(1, $confidence)),
                'verification' => $verification,
                'status' => MemoryStatus::PendingSync,
                'source_message_id' => $sourceMessageId,
                'valid_from' => now(),
            ]);
            if ($supersedes !== null) {
                $supersedes->update(['status' => MemoryStatus::Superseded, 'superseded_by_id' => $memory->id, 'valid_until' => now()]);
                $this->activity->record($user, 'memory', $supersedes->id, 'superseded', Actor::Agent, $sourceMessageId, null, ['superseded_by' => $memory->id]);
            }
            $this->activity->record($user, 'memory', $memory->id, 'saved', Actor::Agent, $sourceMessageId, null, ['category' => $category->value]);

            return $memory;
        });

        SyncMemoryToProvider::dispatch($memory->id)->afterCommit();
        if ($supersedes?->mem0_id !== null) {
            DeleteProviderMemory::dispatch($user->id, $supersedes->mem0_id, null)->afterCommit();
        }

        return [$memory, 'saved'];
    }

    /** User-requested deletion of one memory: tombstone locally, propagate to Mem0, audit. */
    public function forget(User $user, Memory $memory, string $reason = 'user_request'): DataDeletionRequest
    {
        if ($memory->user_id !== $user->id) {
            throw new DomainException('Memory not found.');
        }

        return DB::transaction(function () use ($user, $memory, $reason) {
            $request = DataDeletionRequest::create([
                'user_id' => $user->id,
                'scope' => 'memory',
                'memory_id' => $memory->id,
                'status' => $memory->mem0_id ? 'pending' : 'completed',
                'requested_at' => now(),
                'completed_at' => $memory->mem0_id ? null : now(),
                'details' => ['category' => $memory->category->value, 'had_provider_copy' => $memory->mem0_id !== null],
            ]);
            $mem0Id = $memory->mem0_id;
            $memory->update([
                'status' => MemoryStatus::Deleted,
                'content' => null, // wipe content; keep hash so extraction won't re-add it
                'deleted_at' => now(),
                'deletion_reason' => $reason,
                'valid_until' => now(),
            ]);
            $this->activity->record($user, 'memory', $memory->id, 'deleted', Actor::User, null, null, null, $reason);
            if ($mem0Id !== null) {
                DeleteProviderMemory::dispatch($user->id, $mem0Id, $request->id)->afterCommit();
            }

            return $request;
        });
    }
}
