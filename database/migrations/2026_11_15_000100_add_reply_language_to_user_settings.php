<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            // English language name ("Ukrainian"); null = auto (mirror the language of the user's latest message).
            $table->string('reply_language', 40)->nullable()->after('min_gap_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('reply_language');
        });
    }
};
