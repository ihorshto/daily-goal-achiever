<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar events the agent produced for a user. Delivered as an .ics file in Telegram;
 * the row is the source of truth (and the future basis for a subscription feed).
 * Timed events use UTC instants; all-day events use local DATE columns (end exclusive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('uid', 128)->unique();
            $table->string('title', 200);
            $table->boolean('all_day')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable(); // exclusive, per RFC 5545
            $table->string('timezone', 64);
            $table->string('location', 300)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('alert_minutes_before')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'starts_at']);
            $table->index(['source_message_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
