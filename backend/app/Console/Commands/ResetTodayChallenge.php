<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\GameService;

class ResetTodayChallenge extends Command
{
    protected $signature = 'challenge:reset-today';
    protected $description = 'Clear all results for today\'s challenge and reset it for testing';

    public function handle(GameService $games)
    {
        $today = $games->gameDate();
        
        $this->info("Resetting challenge for: {$today}");
        
        // Delete all results for today
        $deletedResults = DB::table('game_results')
            ->where('date', $today)
            ->delete();
        
        $this->line("Deleted <info>{$deletedResults}</info> results");
        
        // Delete the daily challenge so it gets regenerated
        $deletedChallenge = DB::table('daily_challenges')
            ->where('date', $today)
            ->delete();
        
        $this->line("Deleted daily challenge: " . ($deletedChallenge ? 'yes' : 'no'));
        
        // Regenerate the challenge
        $challenge = $games->daily($today);
        
        $this->line("Regenerated challenge with <info>" . count($challenge) . "</info> cards");
        
        $this->info("\n✓ Challenge reset successfully!");
        $this->line("All results for {$today} have been cleared.");
    }
}
