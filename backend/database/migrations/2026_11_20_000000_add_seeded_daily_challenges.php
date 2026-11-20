<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_challenges', function (Blueprint $table) {
            $table->boolean('seeded')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->json('perfect_score_emojis')->nullable();
        });

        DB::table('daily_challenges')->update(['started_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('daily_challenges', function (Blueprint $table) {
            $table->dropColumn(['seeded', 'started_at', 'perfect_score_emojis']);
        });
    }
};
