<?php

namespace Database\Seeders;

use App\Services\GameService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed Codespace development environment with initial data.
 * This seeder creates an admin account and seeds past daily runs.
 * Only runs when CODESPACES environment variable is true.
 */
class CodespaceSeeder extends Seeder
{
    public function run(): void
    {
        // Only run in development environments (codespace or local testing)
        if (! app()->environment(['local', 'testing']) && env('CODESPACES') !== 'true') {
            $this->command->warn('Skipping CodespaceSeeder - only runs in local or testing environments with CODESPACES=true');
            return;
        }

        // Create admin account with default credentials (bypassing complexity rules for dev)
        $this->seedAdminAccount();

        // Seed game results for past days with card selections
        $this->seedGameResults();
    }

    private function seedAdminAccount(): void
    {
        $existing = DB::table('admin_accounts')
            ->where('username', 'admin')
            ->first();

        if ($existing) {
            $this->command->info('Admin account already exists, skipping creation.');
            return;
        }

        DB::table('admin_accounts')->insert([
            'username' => 'admin',
            'password' => Hash::make('admin'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('✓ Admin account created (username: admin, password: admin)');
    }

    private function seedGameResults(): void
    {
        $existingCount = DB::table('game_results')->count();

        if ($existingCount > 0) {
            $this->command->info("Game results already seeded ({$existingCount} records), skipping.");
            return;
        }

        $this->command->info('Seeding game results for the past 364 days (~75 runs per day, excluding current game day)...');

        $output = $this->command->getOutput();
        $bar = $output->createProgressBar(364);
        $bar->start();

        $nowUtc = Carbon::now('UTC');
        // Calculate today's game date (accounting for 06:00 UTC offset)
        $todayGameDate = $nowUtc->copy()->subHours(6)->toDateString();
        $batchSize = 500;
        $runsPerDay = 75;

        // Get a GameService instance for card selection
        $gameService = app(GameService::class);

        // Seed approximately 75 results per day for the past 364 days, excluding the current game day
        // This ensures today's runs are created fresh by real users, while providing comprehensive
        // historical data (364 days) for features like viewing previous day runs and statistics.
        // 
        // Important: Game days run from 06:00 UTC to 05:59 UTC the next day.
        // For a game date like "2026-11-23", records should have created_at between:
        // 2026-11-23 06:00:00 UTC and 2026-11-24 05:59:59 UTC
        for ($daysAgo = 364; $daysAgo >= 1; $daysAgo--) {
            // Calculate the game date for this iteration
            // Create a fresh copy each time to avoid mutation
            $gameDate = Carbon::parse($todayGameDate, 'UTC')->copy()->subDays($daysAgo)->toDateString();
            
            // Create daily challenge with card selection if it doesn't exist
            $existing = DB::table('daily_challenges')->where('date', $gameDate)->first();
            if (!$existing) {
                // Select 20 random cards for this day's challenge
                $cards = $gameService->selectCards($gameDate);
                DB::table('daily_challenges')->insert([
                    'date' => $gameDate,
                    'cards' => json_encode($cards, JSON_THROW_ON_ERROR),
                    'seeded' => true,
                    'started_at' => Carbon::parse($gameDate, 'UTC')->addHours(6),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            // Calculate the UTC time window for this game date
            // Game dates run from 06:00 UTC to 05:59:59 UTC the next day
            $dayStart = Carbon::parse($gameDate, 'UTC')->addHours(6);
            $dayEnd = $dayStart->copy()->addDay();
            $dayDuration = $dayStart->diffInSeconds($dayEnd);

            $batch = [];
            for ($run = 0; $run < $runsPerDay; $run++) {
                // Random timestamp within the game day (06:00 UTC to 05:59:59 UTC next day)
                $randomSeconds = rand(0, $dayDuration - 1);
                $timestamp = $dayStart->copy()->addSeconds($randomSeconds);

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

                $scoreCopied = rand(0, 100) < 70;

                $batch[] = [
                    'submission_id' => (string) Str::uuid(),
                    'date' => $gameDate,
                    'hard_mode' => $hardMode,
                    'score' => $score,
                    'first_ten' => $firstTen,
                    'score_copied' => $scoreCopied,
                    'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($batch) >= $batchSize) {
                    DB::table('game_results')->insertOrIgnore($batch);
                    $batch = [];
                }
            }

            // Insert any remaining records for this day
            if (count($batch) > 0) {
                DB::table('game_results')->insertOrIgnore($batch);
            }

            $bar->advance();
        }

        $bar->finish();

        $this->command->getOutput()->newLine();
        $totalInserted = DB::table('game_results')->count();
        $this->command->info("✓ Seeded {$totalInserted} game results (~75 per day for past 364 days, excluding today)");
        
        $dailyChallengesCount = DB::table('daily_challenges')->where('seeded', true)->count();
        $this->command->info("✓ Seeded {$dailyChallengesCount} daily challenges with card selections (one per historical day)");
    }
}
