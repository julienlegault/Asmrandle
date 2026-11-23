<?php

namespace Database\Seeders;

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
        // Create admin account with default credentials (bypassing complexity rules for dev)
        $this->seedAdminAccount();

        // Seed game results for past days
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

        $this->command->info('Seeding game results for the past year (~75 runs per day, excluding current 24 hours)...');

        $output = $this->command->getOutput();
        $bar = $output->createProgressBar(365);
        $bar->start();

        $today = Carbon::now('UTC');
        $batch = [];
        $batchSize = 1000;
        $runsPerDay = 75;

        // Seed approximately 75 results per day for the past 365 days, excluding the current 24 hours
        // This ensures today's runs are created fresh by real users, while providing comprehensive
        // historical data (364 days) for features like viewing previous day runs and statistics.
        for ($daysAgo = 365; $daysAgo >= 1; $daysAgo--) {
            $dayStart = $today->copy()->subDays($daysAgo)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();
            $dayDuration = $dayEnd->diffInSeconds($dayStart);

            for ($run = 0; $run < $runsPerDay; $run++) {
                // Random timestamp within the day
                $randomSeconds = rand(0, $dayDuration);
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

                // If first_ten, occasionally mark as copied
                $firstTenCopied = $firstTen && rand(0, 100) < 70;

                $batch[] = [
                    'submission_id' => (string) Str::uuid(),
                    'date' => $dayStart->toDateString(),
                    'hard_mode' => $hardMode,
                    'score' => $score,
                    'first_ten' => $firstTen,
                    'first_ten_copied' => $firstTenCopied,
                    'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($batch) >= $batchSize) {
                    DB::table('game_results')->insertOrIgnore($batch);
                    $batch = [];
                }
            }

            $bar->advance();
        }

        // Insert any remaining records
        if (count($batch) > 0) {
            DB::table('game_results')->insertOrIgnore($batch);
        }

        $bar->finish();

        $this->command->getOutput()->newLine();
        $totalInserted = DB::table('game_results')->count();
        $this->command->info("✓ Seeded {$totalInserted} game results (~75 per day for past year, excluding today)");
    }
}
