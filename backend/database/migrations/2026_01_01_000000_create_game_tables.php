<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('scryfall_id')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('image_url');
            $table->text('art_url');
            $table->text('flavor_text')->nullable();
            $table->unsignedBigInteger('inclusion')->nullable();
            $table->unsignedBigInteger('potential_decks')->nullable();
            $table->boolean('legal')->default(true);
            $table->timestamp('edhrec_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_challenges', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->json('cards');
            $table->timestamps();
        });

        Schema::create('practice_games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('cards');
            $table->timestamps();
        });

        Schema::create('game_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_id')->unique();
            $table->date('date')->index();
            $table->boolean('hard_mode');
            $table->unsignedTinyInteger('score');
            $table->boolean('first_ten')->default(false);
            $table->json('answers');
            $table->timestamps();
        });

        Schema::create('practice_plays', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_plays');
        Schema::dropIfExists('game_results');
        Schema::dropIfExists('practice_games');
        Schema::dropIfExists('daily_challenges');
        Schema::dropIfExists('cards');
    }
};
