<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sticker sets in a user's catalog, together with their offer state (offered / active / dismissed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('title', 64);
            $table->string('sticker_type', 16)->default('regular');
            $table->string('status', 16);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sticker_count')->default(0);
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_sets');
    }
};
