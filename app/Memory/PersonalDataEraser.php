<?php

declare(strict_types=1);

namespace App\Memory;

use App\Enums\MemoryStatus;
use App\Jobs\PurgeProviderScope;
use App\Models\DataDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Delete my data". Order matters:
 *  1. stamp users.data_purged_at so every queued job created before now discards itself;
 *  2. tombstone/wipe local rows inside a transaction;
 *  3. purge the Mem0 scope (retried by a job until confirmed).
 * The deletion request row is the audit trail (it stores counts, never content).
 */
final class PersonalDataEraser
{
    public function __construct(private readonly MemoryProvider $provider) {}

    /** @param 'all'|'memories' $scope @return array<string, int|string> */
    public function eraseAll(User $user, string $scope = 'all'): array
    {
        $scope = $scope === 'memories' ? 'memories' : 'all';
        $request = DataDeletionRequest::create([
            'user_id' => $user->id,
            'scope' => $scope,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $counts = DB::transaction(function () use ($user, $scope) {
            $user->forceFill(['data_purged_at' => now()])->save();
            $counts = [
                'memories' => $user->memories()->where('status', '!=', MemoryStatus::Deleted->value)->update([
                    'status' => MemoryStatus::Deleted->value,
                    'content' => null,
                    'deleted_at' => now(),
                    'deletion_reason' => 'erase_'.$scope,
                    'updated_at' => now(),
                ]),
            ];
            if ($scope === 'all') {
                // Activity events are append-only for normal operation; user-requested erasure is the
                // one sanctioned exception, executed via the query builder (bypasses model guards).
                $counts['activity_events'] = DB::table('activity_events')->where('user_id', $user->id)->delete();
                $counts['checkpoints'] = DB::table('checkpoints')->where('user_id', $user->id)->delete();
                $counts['pending_actions'] = DB::table('pending_action_confirmations')->where('user_id', $user->id)->where('status', '!=', 'applied')->delete();
                $counts['daily_summaries'] = DB::table('daily_summaries')->where('user_id', $user->id)->delete();
                $counts['tasks'] = DB::table('tasks')->where('user_id', $user->id)->delete();
                $counts['goals'] = DB::table('goals')->where('user_id', $user->id)->delete();
                $counts['projects'] = DB::table('projects')->where('user_id', $user->id)->delete();
                $counts['tool_executions'] = DB::table('tool_executions')->where('user_id', $user->id)->delete();
                $counts['messages'] = DB::table('messages')->where('user_id', $user->id)->delete();
                // Keep memory tombstones (hash only) so extraction never re-adds deleted facts.
            }

            return $counts;
        });

        try {
            $counts['provider_deleted'] = $this->provider->deleteAll(MemoryScope::for($user));
            $request->update(['status' => 'completed', 'completed_at' => now(), 'details' => $counts]);
        } catch (MemoryUnavailableException $e) {
            Log::warning('memory.erase_provider_deferred', ['user_id' => $user->id]);
            $request->update(['details' => $counts]);
            PurgeProviderScope::dispatch($user->id, $request->id);
            $counts['provider'] = 'queued';
        }

        return $counts;
    }
}
