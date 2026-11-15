<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core companion schema. All instants are timestamptz (stored as UTC);
 * local calendar concepts use DATE columns + the user's IANA timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('morning_time', 5)->default('09:00');   // local HH:MM
            $table->string('evening_time', 5)->default('20:00');
            $table->string('quiet_start', 5)->default('22:00');
            $table->string('quiet_end', 5)->default('08:00');
            $table->unsignedSmallInteger('max_checkins_per_day')->default(4);
            $table->unsignedSmallInteger('min_gap_minutes')->default(90);
            $table->boolean('proactive_enabled')->default(true);
            $table->boolean('morning_enabled')->default(true);
            $table->boolean('evening_enabled')->default(true);
            $table->timestampTz('paused_until')->nullable();
            $table->timestamps();
        });

        // Raw inbound Telegram update ledger: dedupes webhook retries by update_id.
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('update_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 16)->default('received'); // received|dispatched|ignored|rejected|failed
            $table->string('correlation_id', 36);
            $table->string('error', 255)->nullable();
            $table->timestampsTz();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'telegram_chat_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('source', 24);
            $table->text('text')->nullable();
            $table->bigInteger('telegram_update_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('dedup_key', 96)->unique();
            $table->string('voice_file_id', 255)->nullable();
            $table->string('voice_file_unique_id', 128)->nullable();
            $table->unsignedInteger('voice_duration_seconds')->nullable();
            $table->unsignedInteger('voice_file_size')->nullable();
            $table->string('voice_mime_type', 64)->nullable();
            $table->string('processing_state', 16)->default('received');
            $table->string('processing_error', 255)->nullable();
            $table->unsignedInteger('token_estimate')->nullable();
            $table->json('reply_markup')->nullable();          // outbound inline keyboard
            $table->foreignId('checkpoint_id')->nullable(); // proactive messages: which checkpoint produced it
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('priority', 16)->default('normal');
            $table->date('target_date')->nullable();
            $table->foreignId('created_from_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('todo');
            $table->string('commitment', 16)->default('intention');
            $table->string('priority', 16)->default('normal');
            $table->timestampTz('due_at')->nullable();          // exact deadline instant (UTC)
            $table->date('due_date')->nullable();               // local date when deadline has no explicit time
            $table->date('planned_for_date')->nullable();       // local date the user intends to work on it
            $table->text('blocker')->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->foreignId('created_from_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->timestampTz('last_progress_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('completion_confirmed_at')->nullable();
            $table->foreignId('completion_source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'planned_for_date']);
            $table->index(['user_id', 'due_at']);
        });

        // Append-only history. Rows are never updated or deleted by application code.
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 32);   // task|goal|settings|memory|checkpoint|confirmation
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event_type', 48);     // created|status_changed|progress|blocked|completed|deadline_changed|...
            $table->string('actor', 16);
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();
            $table->index(['user_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 500)->nullable();
            $table->date('local_date')->nullable();                 // for daily rituals
            $table->timestampTz('earliest_at');
            $table->timestampTz('latest_at')->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('claimed_by', 64)->nullable();
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('outcome_reason', 255)->nullable();
            $table->string('created_by', 16)->default('system');
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['state', 'earliest_at']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->string('timezone', 64);
            $table->json('planned')->nullable();
            $table->json('confirmed')->nullable();
            $table->json('blockers')->nullable();
            $table->json('open_loops')->nullable();
            $table->text('narrative')->nullable();
            $table->json('source_event_ids')->nullable();
            $table->json('source_message_ids')->nullable();
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['user_id', 'local_date']);
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mem0_id', 64)->nullable()->unique();
            $table->text('content')->nullable();                 // wiped on deletion (tombstone keeps hash)
            $table->string('content_hash', 64);
            $table->string('category', 24);
            $table->decimal('confidence', 4, 3)->default(0.8);
            $table->string('verification', 16)->default('stated');
            $table->string('status', 16)->default('pending_sync');
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('source_event_id')->nullable()->constrained('activity_events')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('memories')->nullOnDelete();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->string('last_sync_error', 255)->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->string('deletion_reason', 64)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'content_hash']);
        });

        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16); // memory|all
            $table->unsignedBigInteger('memory_id')->nullable();
            $table->string('status', 16)->default('pending'); // pending|completed|failed
            $table->json('details')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('pending_action_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('target_type', 16)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('proposed_changes');
            $table->string('summary', 500);
            $table->string('nonce', 32)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->string('decided_via', 16)->nullable(); // button|chat
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32); // reply|proactive|callback_ack|system
            $table->string('idempotency_key', 128)->unique();
            $table->string('status', 16)->default('sending');
            $table->bigInteger('telegram_message_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'kind', 'sent_at']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 24); // message|proactive|memory_extraction|summary|consolidation
            $table->string('correlation_id', 36)->index();
            $table->string('model', 96)->nullable();
            $table->string('status', 16)->default('running'); // running|completed|failed|budget_exceeded
            $table->unsignedInteger('iterations')->default(0);
            $table->unsignedInteger('tool_call_count')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name', 64);
            $table->string('idempotency_key', 96)->unique();
            $table->string('status', 16); // ok|rejected|error
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'tool_name']);
        });
    }

    public function down(): void
    {
        foreach ([
            'tool_executions', 'agent_runs', 'notification_log', 'pending_action_confirmations',
            'data_deletion_requests', 'memories', 'daily_summaries', 'checkpoints', 'activity_events',
            'tasks', 'goals', 'projects', 'messages', 'conversations', 'telegram_updates', 'user_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
