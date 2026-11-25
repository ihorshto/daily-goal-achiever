<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sticker_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_id', 255);
            $table->string('file_unique_id', 64);
            $table->string('emoji', 16)->nullable();
            $table->string('emoji_key', 16)->nullable();
            $table->boolean('is_animated')->default(false);
            $table->boolean('is_video')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('invalid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['sticker_set_id', 'file_unique_id']);
            $table->index(['user_id', 'emoji_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stickers');
    }
};
