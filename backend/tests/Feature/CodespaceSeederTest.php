<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CodespaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_data_respects_6_hour_game_day_offset(): void
    {
        // Set test time to 06:00 UTC on a known date
        Carbon::setTestNow('2026-11-23 06:00:00 UTC');

        // The gameDate at 06:00 UTC should be 2026-11-23
        $gameDate = Carbon::now('UTC')->subHours(6)->toDateString();
        $this->assertEquals('2026-11-23', $gameDate);

        // Seed cards first, then seeder
        $this->seed(\Database\Seeders\BrowserTestSeeder::class);

        // Get records for a seeded date from a week ago (not today, since today is excluded)
        // A week ago's game date would be 2026-11-16
        $weekAgoGameDate = '2026-11-16';
        $records = DB::table('game_results')
            ->where('date', $weekAgoGameDate)
            ->orderBy('created_at')
            ->get(['started_at', 'created_at', 'duration_ms']);

        // Verify records exist
        $this->assertGreaterThan(0, $records->count());

        // Check that all timestamps are within the correct game day boundaries
        // Game day 2026-11-16 runs from 06:00 UTC on 2026-11-16 through 05:59:59 UTC on 2026-11-17
        $dayStartStr = '2026-11-16 06:00:00';
        $dayEndStr = '2026-11-17 06:00:00';

        foreach ($records as $record) {
            $timestampStr = $record->created_at;
            // Simple string comparison for timestamps
            $this->assertGreaterThanOrEqual($dayStartStr, $timestampStr, 
                "Timestamp {$timestampStr} should be >= {$dayStartStr}");
            $this->assertLessThan($dayEndStr, $timestampStr,
                "Timestamp {$timestampStr} should be < {$dayEndStr}");
            $this->assertNotNull($record->started_at);
            $this->assertGreaterThanOrEqual($dayStartStr, $record->started_at);
            $this->assertLessThan($record->created_at, $record->started_at);
            $this->assertGreaterThan(0, $record->duration_ms);
            $this->assertSame(
                (int) $record->duration_ms,
                (int) Carbon::parse($record->started_at, 'UTC')->diffInMilliseconds(Carbon::parse($record->created_at, 'UTC')),
            );
        }
        $this->assertGreaterThan(0, $records->where('duration_ms', '<', 60000)->count());
        $this->assertGreaterThan(0, $records->where('duration_ms', '>=', 60000)->count());

        $admin = DB::table('admin_accounts')->where('username', 'admin')->first();
        $this->withSession(['admin' => ['id' => $admin->id, 'password_hash' => $admin->password]])
            ->get('/admin?date='.$weekAgoGameDate)->assertOk()
            ->assertViewHas('dailyDurations', [
                'speedy' => $records->where('duration_ms', '<', 60000)->count(),
                'fastest' => (int) $records->min('duration_ms'),
                'average' => $records->avg('duration_ms'),
            ]);
    }

    public function test_seeded_data_excludes_current_24_hours(): void
    {
        // Set test time to middle of the day
        Carbon::setTestNow('2026-11-23 15:30:00 UTC');

        // Seed cards first, then seeder
        $this->seed(\Database\Seeders\BrowserTestSeeder::class);

        // Current gameDate is 2026-11-23 (since 15:30 >= 06:00)
        $currentGameDate = Carbon::now('UTC')->subHours(6)->toDateString();
        $this->assertEquals('2026-11-23', $currentGameDate);

        // No data should be seeded for the current 24 hours
        $currentRecords = DB::table('game_results')
            ->where('date', $currentGameDate)
            ->count();

        $this->assertEquals(0, $currentRecords, 
            'No data should be seeded for the current game day');
    }

    public function test_seeded_data_includes_364_days_of_history(): void
    {
        Carbon::setTestNow('2026-11-23 06:00:00 UTC');

        // Seed cards first, then seeder
        $this->seed(\Database\Seeders\BrowserTestSeeder::class);

        // Count distinct dates in seeded data
        $distinctDates = DB::table('game_results')
            ->distinct()
            ->count('date');

        // Should have 364 days (all days in the past year except today)
        $this->assertEquals(364, $distinctDates);
    }

    public function test_seeded_data_has_approximately_75_runs_per_day(): void
    {
        Carbon::setTestNow('2026-11-23 06:00:00 UTC');

        // Seed cards first, then seeder
        $this->seed(\Database\Seeders\BrowserTestSeeder::class);

        // Get count per date
        $countsPerDay = DB::table('game_results')
            ->selectRaw('date, COUNT(*) as count')
            ->groupBy('date')
            ->get();

        // Should have multiple dates with ~75 runs each
        $this->assertGreaterThan(0, $countsPerDay->count());

        // Check that most days have approximately 75 runs (within reasonable variance)
        $averageCount = $countsPerDay->avg('count');
        $this->assertGreaterThan(70, $averageCount);
        $this->assertLessThan(80, $averageCount);
    }
}


