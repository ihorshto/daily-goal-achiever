<?php

namespace Tests\Feature;

use App\Agent\AgentOrchestrator;
use App\Agent\ConversationService;
use App\Agent\OpenAI\ToolCall;
use App\Agent\ToolContext;
use App\Agent\ToolExecutor;
use App\Agent\UsageTracker;
use App\Domain\ProgressService;
use App\Enums\Actor;
use App\Enums\MemoryCategory;
use App\Enums\MemoryStatus;
use App\Enums\MemoryVerification;
use App\Enums\MessageSource;
use App\Enums\TaskStatus;
use App\Jobs\DeleteProviderMemory;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\SyncMemoryToProvider;
use App\Memory\DailySummaryService;
use App\Memory\MemoryExtractor;
use App\Memory\MemoryLedger;
use App\Memory\MemoryRetriever;
use App\Memory\MemoryScope;
use App\Memory\MemoryUnavailableException;
use App\Models\DataDeletionRequest;
use App\Models\Memory;
use App\Models\Message;
use App\Models\PendingActionConfirmation;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeMemoryProvider;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    private function ledger(): MemoryLedger
    {
        return app(MemoryLedger::class);
    }

    public function test_extraction_saves_durable_facts_and_syncs_to_provider(): void
    {
        $user = $this->makeUser();
        [$msg] = app(ConversationService::class)->recordInbound($user, 'tg:1', MessageSource::TelegramText, 'The deploy failed because the Postgres password had a $ sign; I fixed it by quoting it in .env');
        $this->llm->push('memory_extraction', FakeLlmClient::json(['memories' => [
            ['content' => 'The user fixed a Postgres deploy failure caused by a "$" in the password by quoting it in .env (2026-10-14).', 'category' => 'resolution', 'confidence' => 0.92, 'verification' => 'stated', 'supersedes_memory_ref' => null],
            ['content' => 'The user seems tired today.', 'category' => 'fact', 'confidence' => 0.3, 'verification' => 'inferred', 'supersedes_memory_ref' => null],
        ]]));

        $outcomes = app(MemoryExtractor::class)->extract($msg);

        $this->assertSame(['saved', 'low_confidence'], $outcomes);
        $memory = Memory::firstOrFail();
        $this->assertSame(MemoryStatus::Synced, $memory->status);
        $this->assertNotNull($memory->mem0_id);
        $this->assertSame($msg->id, $memory->source_message_id);
        $this->assertSame(1, $this->memory->count(MemoryScope::for($user)));
    }

    public function test_trivial_messages_are_not_sent_for_extraction(): void
    {
        $user = $this->makeUser();
        [$msg] = app(ConversationService::class)->recordInbound($user, 'tg:2', MessageSource::TelegramText, 'thanks!');
        $this->assertSame(['skipped_trivial'], app(MemoryExtractor::class)->extract($msg));
        $this->assertSame([], $this->llm->requests);
    }

    /** Scenario 7: month-old deployment problem retrieved from Mem0 with provenance. */
    public function test_retrieves_month_old_memory_with_date_and_source(): void
    {
        $user = $this->makeUser();
        $this->travelToLocal('2026-10-14 15:00');
        [$m] = $this->ledger()->record($user, 'The production deployment was delayed because the Postgres migration locked the users table; resolved by running it with CONCURRENTLY.', MemoryCategory::Resolution, 0.9, MemoryVerification::Stated, null);
        $this->travelToLocal('2026-11-14 10:00');

        $hits = app(MemoryRetriever::class)->retrieve($user, 'why was the deployment delayed last month?');
        $this->assertCount(1, $hits);
        $this->assertSame($m->id, $hits[0]->localId);
        $this->assertSame('2026-10-14', $hits[0]->recordedAt);
        $this->assertSame('semantic', $hits[0]->via);
    }

    public function test_insufficient_records_are_reported_honestly(): void
    {
        $user = $this->makeUser();
        $run = app(UsageTracker::class)->start($user, 'message');
        $ctx = new ToolContext($user, null, $run, 'user_message', CarbonImmutable::now());
        $exec = app(ToolExecutor::class);
        $memory = $exec->execute(new ToolCall('1', 'search_memory', json_encode(['query' => 'deployment problem', 'limit' => null])), $ctx);
        $history = $exec->execute(new ToolCall('2', 'get_task_history', json_encode(['task_id' => null, 'query' => 'deploy', 'days' => 45])), $ctx);
        $this->assertFalse($memory->data['found']);
        $this->assertFalse($history->data['records_found']);
    }

    /** Scenario 12: Mem0 down → chat works, writes queue & retry, failure visible. */
    public function test_mem0_downtime_does_not_break_chat_and_writes_retry(): void
    {
        // Production queues are async; fake only the memory sync job so we can run it like a worker would.
        Queue::fake([SyncMemoryToProvider::class]);
        $this->memory->down = true;
        $this->llm->push('agent',
            FakeLlmClient::tools(['name' => 'create_task', 'args' => ['title' => 'Renew TLS cert', 'commitment' => 'intention', 'priority' => null, 'due' => null, 'planned_for_date' => null, 'notes' => null, 'goal_id' => null, 'project' => null]]),
            FakeLlmClient::tools(['name' => 'save_memory', 'args' => ['content' => 'The user hosts the app on a Hetzner VPS in Falkenstein.', 'category' => 'fact']]),
            FakeLlmClient::text('Noted, and added the TLS task.'),
        );
        $this->webhook($this->textUpdate('Remember my app is on a Hetzner VPS in Falkenstein. Also renew the TLS cert.'))->assertOk();

        $this->assertSame('Noted, and added the TLS task.', $this->telegram->lastText());
        $this->assertSame(1, Task::count());
        $memory = Memory::firstOrFail();
        $this->assertSame(MemoryStatus::PendingSync, $memory->status);
        Queue::assertPushed(SyncMemoryToProvider::class);

        // Worker attempt while Mem0 is down: throws (→ queue backoff/retry) and records the failure.
        try {
            (new SyncMemoryToProvider($memory->id))->handle($this->memory);
            $this->fail('expected MemoryUnavailableException');
        } catch (MemoryUnavailableException) {
        }
        $memory->refresh();
        $this->assertSame(MemoryStatus::SyncFailed, $memory->status);
        $this->assertSame(1, $memory->sync_attempts);
        $this->assertNotNull($memory->last_sync_error, 'failure is visible for inspection');
        $this->artisan('companion:memory status')->expectsOutputToContain('sync_failed')->assertSuccessful();

        // Degraded retrieval still finds it via local fallback.
        $hits = app(MemoryRetriever::class)->retrieve($memory->user, 'where is my Hetzner server?');
        $this->assertSame('local_fallback', $hits[0]->via);

        // Recovery: provider back, scheduled retry syncs it.
        $this->memory->down = false;
        $this->travel(10)->minutes();
        $this->artisan('companion:memory retry-failed')->expectsOutputToContain('requeued=1')->assertSuccessful();
        (new SyncMemoryToProvider($memory->id))->handle($this->memory);
        $this->assertSame(MemoryStatus::Synced, $memory->refresh()->status);
        $this->assertNotNull($memory->mem0_id);
    }

    /** Scenario 14: inspect & delete; deletes propagate and cannot be resurrected. */
    public function test_forget_memory_propagates_and_is_audited(): void
    {
        $user = $this->makeUser();
        [$m] = $this->ledger()->record($user, 'The user is allergic to peanuts and avoids them.', MemoryCategory::Fact, 0.95, MemoryVerification::Stated, null);
        $mem0Id = $m->refresh()->mem0_id;
        $this->assertNotNull($mem0Id);

        $this->ledger()->forget($user, $m);

        $m->refresh();
        $this->assertSame(MemoryStatus::Deleted, $m->status);
        $this->assertNull($m->content, 'content wiped locally');
        $this->assertContains($mem0Id, $this->memory->deleted);
        $this->assertSame(0, $this->memory->count(MemoryScope::for($user)));
        $this->assertSame('completed', DataDeletionRequest::firstOrFail()->status);

        // Background extraction proposing the same fact again is refused.
        [$again, $outcome] = $this->ledger()->record($user, 'The user is allergic to peanuts and avoids them.', MemoryCategory::Fact, 0.9, MemoryVerification::Stated, null);
        $this->assertNull($again);
        $this->assertSame('previously_deleted', $outcome);
    }

    public function test_pending_sync_job_cannot_resurrect_deleted_memory(): void
    {
        Queue::fake([SyncMemoryToProvider::class, DeleteProviderMemory::class]);
        $user = $this->makeUser();
        [$m] = $this->ledger()->record($user, 'The user works at a fintech startup in Lyon.', MemoryCategory::Fact, 0.9, MemoryVerification::Stated, null);
        $this->ledger()->forget($user, $m); // deleted before the queued sync job ran

        (new SyncMemoryToProvider($m->id))->handle($this->memory);
        $this->assertSame(0, $this->memory->adds);
    }

    public function test_delete_racing_with_in_flight_sync_removes_provider_copy(): void
    {
        $user = $this->makeUser();
        $m = Memory::factory()->for($user)->create(['status' => MemoryStatus::PendingSync, 'mem0_id' => null]);
        $provider = new class($this->memory, $m) extends FakeMemoryProvider
        {
            public function __construct(private FakeMemoryProvider $inner, private Memory $m) {}

            public function add(string $scope, string $text, array $metadata): string
            {
                $id = $this->inner->add($scope, $text, $metadata);
                // User deletes while the provider write is in flight.
                Memory::whereKey($this->m->id)->update(['status' => 'deleted', 'content' => null]);

                return $id;
            }

            public function delete(string $scope, string $id): void
            {
                $this->inner->delete($scope, $id);
            }
        };
        (new SyncMemoryToProvider($m->id))->handle($provider);
        $this->assertSame(0, $this->memory->count(MemoryScope::for($user)));
        $this->assertNull($m->refresh()->mem0_id);
    }

    public function test_correction_supersedes_old_memory(): void
    {
        $user = $this->makeUser();
        [$old] = $this->ledger()->record($user, 'The user deploys with Laravel Forge.', MemoryCategory::Fact, 0.9, MemoryVerification::Stated, null);
        $oldMem0 = $old->refresh()->mem0_id;
        [$msg] = app(ConversationService::class)->recordInbound($user, 'tg:9', MessageSource::TelegramText, 'Correction: I no longer use Forge, I deploy with Docker Compose on my VPS');
        $this->llm->push('memory_extraction', FakeLlmClient::json(['memories' => [
            ['content' => 'The user deploys with Docker Compose on a VPS (no longer Laravel Forge).', 'category' => 'fact', 'confidence' => 0.95, 'verification' => 'stated', 'supersedes_memory_ref' => $old->id],
        ]]));
        app(MemoryExtractor::class)->extract($msg);

        $this->assertSame(MemoryStatus::Superseded, $old->refresh()->status);
        $this->assertNotNull($old->superseded_by_id);
        $this->assertContains($oldMem0, $this->memory->deleted);
        $hits = app(MemoryRetriever::class)->retrieve($user, 'how does the user deploy');
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('Docker Compose', $hits[0]->content);
    }

    public function test_full_data_erasure_via_confirmation_and_stale_jobs_are_discarded(): void
    {
        $this->travelToLocal('2026-11-12 10:00');
        $user = $this->makeUser();
        Task::factory()->for($user)->count(2)->create();
        $this->ledger()->record($user, 'The user lives in Paris with two cats.', MemoryCategory::Fact, 0.9, MemoryVerification::Stated, null);
        [$queuedMsg] = app(ConversationService::class)->recordInbound($user, 'tg:old', MessageSource::TelegramText, 'An older message still in the queue');

        $this->webhook($this->textUpdate('/deletemydata'))->assertOk();
        $pending = PendingActionConfirmation::firstOrFail();
        $this->travel(1)->seconds();
        $this->webhook($this->callbackUpdate("c:{$pending->nonce}:y"))->assertOk();

        $user->refresh();
        $this->assertNotNull($user->data_purged_at);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, $this->memory->count(MemoryScope::for($user)));
        $this->assertSame(0, Memory::whereNotNull('content')->count());
        $this->assertTrue(DataDeletionRequest::where('scope', 'all')->where('status', 'completed')->exists());
        $this->assertSame(0, Message::where('id', $queuedMsg->id)->count());

        // A job referencing pre-erasure data is a no-op.
        (new ProcessInboundMessage($queuedMsg->id, $user->id))->handle(app(AgentOrchestrator::class), app(ConversationService::class), $this->telegram);
        $this->assertSame([], $this->llm->requestsFor('agent'));
    }

    public function test_list_memories_command(): void
    {
        $user = $this->makeUser();
        $this->ledger()->record($user, 'The user prefers concise answers in the morning.', MemoryCategory::Preference, 0.9, MemoryVerification::Stated, null);
        $this->webhook($this->textUpdate('/memories'))->assertOk();
        $this->assertStringContainsString('prefers concise answers', $this->telegram->lastText());
    }

    /** Scenario 6: "What did I do yesterday?" distinguishes confirmed vs planned. */
    public function test_yesterday_summary_separates_confirmed_from_planned(): void
    {
        $user = $this->makeUser();
        $this->travelToLocal('2026-11-12 10:00');
        $done = Task::factory()->for($user)->create(['title' => 'Fix login bug', 'planned_for_date' => '2026-11-12']);
        $planned = Task::factory()->for($user)->create(['title' => 'Write docs', 'planned_for_date' => '2026-11-12']);
        $this->travelToLocal('2026-11-12 16:00');
        app(ProgressService::class)->complete($user, $done, Actor::User, null, 'button');
        app(ProgressService::class)->updateProgress($user, $planned, TaskStatus::InProgress, 'half way', 50, null, Actor::User, null);

        $this->travelToLocal('2026-11-13 09:30');
        $summary = app(DailySummaryService::class)->compute($user, '2026-11-12');
        $this->assertSame(['Fix login bug'], array_column($summary['confirmed_completed'], 'title'));
        $this->assertSame(['Write docs'], array_column($summary['open_loops'], 'title'));
        $this->assertSame(['Write docs'], array_column($summary['progress_updates'], 'title'));
        $this->assertTrue($summary['records_complete']);

        // Nightly persisted summary + episode memory.
        $this->llm->push('daily_summary', FakeLlmClient::text('On 2026-11-12 the user completed "Fix login bug"; "Write docs" was planned and reached 50% but is not confirmed done.'));
        $stored = app(DailySummaryService::class)->generate($user, '2026-11-12');
        $this->assertSame('2026-11-12', $stored->local_date->toDateString());
        $this->assertNotEmpty($stored->source_event_ids);
        $this->assertTrue(Memory::where('category', 'episode')->exists());
    }
}
