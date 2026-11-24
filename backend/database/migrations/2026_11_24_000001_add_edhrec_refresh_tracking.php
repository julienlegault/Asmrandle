<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->timestamp('edhrec_missing_since')->nullable();
        });

        Schema::create('edhrec_refresh_samples', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->json('card_ids');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edhrec_refresh_samples');
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('edhrec_missing_since');
        });
    }
};
