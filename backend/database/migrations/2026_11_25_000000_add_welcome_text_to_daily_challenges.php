<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_challenges', function (Blueprint $table) {
            $table->string('welcome_text', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_challenges', function (Blueprint $table) {
            $table->dropColumn('welcome_text');
        });
    }
};
