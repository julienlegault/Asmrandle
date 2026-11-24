<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['game_results', 'practice_plays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedInteger('duration_ms')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['game_results', 'practice_plays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('duration_ms');
            });
        }
    }
};
