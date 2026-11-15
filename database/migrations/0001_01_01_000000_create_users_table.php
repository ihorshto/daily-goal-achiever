<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            // Telegram identity mapping (authoritative identity for the MVP).
            $table->bigInteger('telegram_user_id')->nullable()->unique();
            $table->bigInteger('telegram_chat_id')->nullable()->index();
            $table->string('telegram_username')->nullable();
            $table->string('locale', 16)->default('en');
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('last_user_message_at')->nullable();
            $table->timestampTz('last_proactive_at')->nullable();
            // Set when the user asks to erase their data; jobs created before it are discarded.
            $table->timestampTz('data_purged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
