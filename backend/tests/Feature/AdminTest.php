<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_command_creates_and_updates_credentials(): void
    {
        $this->artisan('admin:set', ['username' => 'admin'])
            ->expectsQuestion('Password (12-72 bytes)', 'a-long-password')
            ->expectsQuestion('Confirm password', 'a-long-password')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('a-long-password', DB::table('admin_accounts')->value('password')));

        $this->artisan('admin:set', ['username' => 'admin'])
            ->expectsQuestion('Password (12-72 bytes)', 'another-password')
            ->expectsQuestion('Confirm password', 'another-password')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('admin_accounts')->count());
        $this->assertTrue(Hash::check('another-password', DB::table('admin_accounts')->value('password')));

        $this->artisan('admin:set', ['username' => 'admin'])
            ->expectsQuestion('Password (12-72 bytes)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();
        $this->assertTrue(Hash::check('another-password', DB::table('admin_accounts')->value('password')));
    }

    public function test_login_protects_dashboard_and_password_change_revokes_session(): void
    {
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => Hash::make('a-long-password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'a-long-password'])
            ->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('admin_accounts')->where('id', $id)->update(['password' => Hash::make('another-password')]);
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'another-password'])->assertRedirect('/admin');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_pages_show_admin_branding(): void
    {
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $favicon = '<link rel="icon" type="image/png" sizes="96x96" href="/favicons/admin-favicon.png">';

        $this->get('/admin/login')->assertOk()->assertSee($favicon, false);
        $this->withSession(['admin' => ['id' => $id, 'password_hash' => $hash]])
            ->get('/admin')->assertOk()->assertSee($favicon, false)
            ->assertSee('<h1><img src="/images/Logo-Play.png" alt="Admin dashboard"></h1>', false);
        $this->get('/favicons/admin-favicon.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/images/Logo-Play.png')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_dashboard_counts_completed_games_at_rollover_and_calculates_results(): void
    {
        Carbon::setTestNow('2026-03-16 05:59:59 UTC');
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cards = [];
        for ($i = 0; $i < 20; $i++) {
            $cards[] = ['name' => "card-{$i}", 'img' => "https://example.test/{$i}.jpg", 'popularity' => $i + 0.25];
        }
        DB::table('daily_challenges')->insert([
            'date' => '2026-03-15', 'cards' => json_encode($cards), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[false, true], [false, false], [true, true]] as [$hard, $first]) {
            $answers = array_fill(0, 10, true);
            $answers[0] = $first;
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => '2026-03-15', 'hard_mode' => $hard,
                'score' => count(array_filter($answers)), 'first_ten' => false,
                'answers' => json_encode($answers), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('practice_plays')->insert([
            ['created_at' => '2026-03-15 05:59:59'],
            ['created_at' => '2026-03-15 06:00:00'],
            ['created_at' => '2026-03-16 05:59:59'],
            ['created_at' => '2026-03-16 06:00:00'],
        ]);
        $session = ['admin' => ['id' => $id, 'password_hash' => $hash]];
        $this->withSession($session)->get('/admin')->assertOk()
            ->assertViewHas('totalGames', 7 + 17966)->assertViewHas('practiceToday', 2)
            ->assertViewHas('counts', [[0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1]])
            ->assertSee('Regular correct: 50.0%')->assertSee('Hard correct: 100.0%')
            ->assertSee('Combined correct: 66.7%')->assertSee('0.2500%')
            ->assertDontSee('class="bar combined"')
            ->assertDontSee('<span class="combined"></span> Combined', false)
            ->assertSee('https://example.test/0.jpg');
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->withSession($session)->get('/admin')->assertOk()
            ->assertViewHas('practiceToday', 1)->assertSee('The daily challenge has not been started yet.');
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/admin/login', ['username' => 'nobody', 'password' => 'wrong'])->assertRedirect();
        }
        $this->post('/admin/login', ['username' => 'nobody', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_admin_navigation_stays_on_the_forwarded_origin(): void
    {
        DB::table('admin_accounts')->insert([
            'username' => 'admin', 'password' => Hash::make('a-long-password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders([
            'Host' => 'container:8000',
            'X-Forwarded-Host' => 'example-8000.app.github.dev',
            'X-Forwarded-Proto' => 'https',
        ]);
        $this->get('/admin')->assertHeader('Location', '/admin/login');
        $this->get('/admin/login')->assertSee('action="/admin/login"', false);
        $this->post('/admin/login', [])->assertHeader('Location', '/admin/login')->assertSessionHasErrors();
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertHeader('Location', '/admin/login')->assertSessionHasErrors('username');
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'a-long-password'])
            ->assertHeader('Location', '/admin');
        $this->get('/admin')->assertOk()->assertSee('action="/admin/logout"', false);
        $this->post('/admin/logout')->assertHeader('Location', '/admin/login');
    }

    public function test_dashboard_displays_new_statistics(): void
    {
        Carbon::setTestNow('2026-03-20 05:59:59 UTC');
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cards = [];
        for ($i = 0; $i < 20; $i++) {
            $cards[] = ['name' => "card-{$i}", 'img' => "https://example.test/{$i}.jpg", 'popularity' => $i + 0.25];
        }
        DB::table('daily_challenges')->insert([
            'date' => '2026-03-19', 'cards' => json_encode($cards), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Add game results for different days
        // Day 1 (2026-03-19 - today): 5 games, all with perfect 10, 1 with first_ten_copied
        for ($i = 0; $i < 5; $i++) {
            $answers = array_fill(0, 10, true);
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => '2026-03-19', 'hard_mode' => false,
                'score' => 10, 'first_ten' => $i === 0, 'first_ten_copied' => $i === 0,
                'answers' => json_encode($answers), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Day 2 (2026-03-18 - yesterday): 3 games, none with perfect 10
        for ($i = 0; $i < 3; $i++) {
            $answers = array_fill(0, 10, true);
            $answers[0] = false;
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => '2026-03-18', 'hard_mode' => false,
                'score' => 9, 'first_ten' => false, 'first_ten_copied' => false,
                'answers' => json_encode($answers), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $session = ['admin' => ['id' => $id, 'password_hash' => $hash]];
        $response = $this->withSession($session)->get('/admin')->assertOk();

        // Check view data
        $response->assertViewHas('todayFirstTenCopied', true);
        $response->assertViewHas('perfectScoreStats', [
            'noPerfects' => 1,
            'totalDays' => 1,
            'percentage' => 100.0,
        ]);
        $response->assertViewHas('dailyPlaysPerDay', 3.0);
        $response->assertViewHas('allTimeAverageScore', 9.625);

        // Check displayed text
        $response->assertSee('First 10 copied today');
        $response->assertSee('Days with no perfect 10s');
        $response->assertSee('1');
        $response->assertSee('100.0');
        $response->assertSee('Daily plays per day');
        $response->assertSee('3.0');
        $response->assertSee('All-time average score');
        $response->assertSee('9.63');
    }

    public function test_all_time_day_statistics_only_include_completed_game_days(): void
    {
        Carbon::setTestNow('2026-03-20 05:59:59 UTC');
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = ['admin' => ['id' => $id, 'password_hash' => $hash]];

        $insertResult = function (string $date, int $score, string $createdAt): void {
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => $date, 'hard_mode' => false,
                'score' => $score, 'first_ten' => false, 'answers' => json_encode(array_fill(0, 10, true)),
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        };
        $insertResult('2026-03-19', 9, '2026-03-19 06:00:00');
        $insertResult('2026-03-19', 9, '2026-03-20 05:59:59');

        $this->withSession($session)->get('/admin')->assertOk()
            ->assertViewHas('perfectScoreStats', ['noPerfects' => 0, 'totalDays' => 0, 'percentage' => 0])
            ->assertViewHas('dailyPlaysPerDay', 0.0);

        $insertResult('2026-03-17', 10, '2026-03-17 12:00:00');
        $insertResult('2026-03-17', 8, '2026-03-17 13:00:00');
        $insertResult('2026-03-18', 10, '2026-03-19 05:59:59');
        $insertResult('2026-03-20', 10, '2026-03-20 06:00:00');

        $this->withSession($session)->get('/admin?date=2026-03-17')->assertOk()
            ->assertViewHas('perfectScoreStats', ['noPerfects' => 0, 'totalDays' => 2, 'percentage' => 0.0])
            ->assertViewHas('dailyPlaysPerDay', 1.5)
            ->assertSee('0/2 <span class="muted">(0.0%)</span>', false);

        Carbon::setTestNow('2026-03-20 06:00:00 UTC');
        $this->withSession($session)->get('/admin')->assertOk()
            ->assertViewHas('perfectScoreStats', [
                'noPerfects' => 1, 'totalDays' => 3, 'percentage' => (1 / 3) * 100,
            ])
            ->assertViewHas('dailyPlaysPerDay', 5 / 3);
    }

    public function test_dashboard_allows_viewing_historical_dates(): void
    {
        Carbon::setTestNow('2026-03-20 06:00:00 UTC');
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        
        // Insert game results for two different dates
        $cards = [];
        for ($i = 0; $i < 20; $i++) {
            $cards[] = ['name' => "card-{$i}", 'img' => "https://example.test/{$i}.jpg", 'popularity' => $i + 0.25];
        }
        DB::table('daily_challenges')->insertOrIgnore([
            'date' => '2026-03-18', 'cards' => json_encode($cards), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_challenges')->insertOrIgnore([
            'date' => '2026-03-19', 'cards' => json_encode($cards), 'created_at' => now(), 'updated_at' => now(),
        ]);
        
        // Add game results for past dates
        for ($i = 0; $i < 5; $i++) {
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => '2026-03-18', 'hard_mode' => false,
                'score' => 5, 'first_ten' => false, 'answers' => json_encode(array_fill(0, 10, false)),
                'created_at' => '2026-03-18 12:00:00', 'updated_at' => '2026-03-18 12:00:00',
            ]);
        }
        
        for ($i = 0; $i < 8; $i++) {
            DB::table('game_results')->insert([
                'submission_id' => (string) Str::uuid(), 'date' => '2026-03-19', 'hard_mode' => false,
                'score' => 7, 'first_ten' => false, 'answers' => json_encode(array_fill(0, 10, false)),
                'created_at' => '2026-03-19 12:00:00', 'updated_at' => '2026-03-19 12:00:00',
            ]);
        }
        
        $session = ['admin' => ['id' => $id, 'password_hash' => $hash]];
        
        // Test viewing today's date (default) - gameDate() will be 2026-03-20
        $this->withSession($session)->get('/admin')
            ->assertOk()
            ->assertViewHas('date', '2026-03-20')
            ->assertViewHas('gameDate', '2026-03-20')
            ->assertSee('date-selector', false)
            ->assertSee('max="2026-03-20"', false);
        
        // Test viewing historical date - yesterday
        $this->withSession($session)->get('/admin?date=2026-03-19')
            ->assertOk()
            ->assertViewHas('date', '2026-03-19')
            ->assertViewHas('gameDate', '2026-03-20')
            ->assertSee('(historical)', false)
            ->assertViewHas('results', function ($results) {
                return $results->count() === 8;
            });
        
        // Test viewing historical date - two days ago
        $this->withSession($session)->get('/admin?date=2026-03-18')
            ->assertOk()
            ->assertViewHas('date', '2026-03-18')
            ->assertViewHas('results', function ($results) {
                return $results->count() === 5;
            });
        
        // Test that future dates are rejected (redirects or ignores the parameter)
        $this->withSession($session)->get('/admin?date=2026-03-21')
            ->assertOk()
            ->assertViewHas('date', '2026-03-20'); // Should default back to today
        
        // Test invalid date format is handled gracefully
        $this->withSession($session)->get('/admin?date=invalid-date')
            ->assertOk()
            ->assertViewHas('date', '2026-03-20'); // Should default back to today
    }
}
