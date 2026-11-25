<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stickers the agent chose to send: queued, delivered or failed. One per source message. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('sticker_id')->nullable()->constrained('stickers')->nullOnDelete();
            $table->string('emoji', 16);
            $table->string('file_id', 255);
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique('source_message_id');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_sends');
    }
};
