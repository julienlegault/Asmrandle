<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_results', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('submission_id');
            $table->index('device_id');
            $table->index(['date', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_results', function (Blueprint $table) {
            $table->dropIndex(['date', 'device_id']);
            $table->dropIndex('device_id');
            $table->dropColumn('device_id');
        });
    }
};
