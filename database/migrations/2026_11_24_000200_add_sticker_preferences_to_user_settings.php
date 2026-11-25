<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('stickers_enabled')->default(true)->after('tone');
            $table->boolean('emoji_enabled')->default(true)->after('stickers_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['stickers_enabled', 'emoji_enabled']);
        });
    }
};
