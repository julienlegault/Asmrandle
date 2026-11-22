<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedGameResults extends Command
{
    protected $signature = 'seed:game-results {count=10000 : Number of game results to generate}';

    protected $description = 'Seed random game results across the past 2 years';

    public function handle(): int
    {
        $count = (int) $this->argument('count');
        $this->info("Generating {$count} random game results across the past 2 years...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $twoyearsAgo = Carbon::now('UTC')->subYears(2);
        $today = Carbon::now('UTC');

        $batch = [];
        $batchSize = 100;

        for ($i = 0; $i < $count; $i++) {
            // Random date within the past 2 years
            $randomDate = $twoyearsAgo->copy()->addSeconds(
                rand(0, (int) $today->diffInSeconds($twoyearsAgo))
            );

            // Random score 0-10
            $score = rand(0, 10);

            // Random mode
            $hardMode = (bool) rand(0, 1);

            // Random answers for 10 questions
            $answers = array_fill(0, 10, false);
            for ($j = 0; $j < $score; $j++) {
                $answers[$j] = true;
            }
            shuffle($answers);

            // Occasionally mark first_ten
            $firstTen = $score === 10 && rand(0, 100) < 20;

            // If first_ten, occasionally mark as copied
            $firstTenCopied = $firstTen && rand(0, 100) < 70;

            $batch[] = [
                'submission_id' => (string) Str::uuid(),
                'date' => $randomDate->toDateString(),
                'hard_mode' => $hardMode,
                'score' => $score,
                'first_ten' => $firstTen,
                'first_ten_copied' => $firstTenCopied,
                'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                'created_at' => $randomDate,
                'updated_at' => $randomDate,
            ];

            if (count($batch) >= $batchSize) {
                DB::table('game_results')->insertOrIgnore($batch);
                $batch = [];
            }

            $bar->advance();
        }

        // Insert any remaining records
        if (count($batch) > 0) {
            DB::table('game_results')->insertOrIgnore($batch);
        }

        $bar->finish();

        $this->newLine();
        $totalInserted = DB::table('game_results')->count();
        $this->info("✓ Game results seeding complete!");
        $this->info("Total game results in database: {$totalInserted}");

        return self::SUCCESS;
    }
}
