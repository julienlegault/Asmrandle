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
            ->assertViewHas('totalGames', 7)->assertViewHas('practiceToday', 2)
            ->assertViewHas('counts', [[0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1]])
            ->assertSee('Regular: 50.0% (1/2)')->assertSee('Hard: 100.0% (1/1)')
            ->assertSee('Combined: 66.7% (2/3)')->assertSee('0.3%')
            ->assertSee('https://example.test/0.jpg');
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->withSession($session)->get('/admin')->assertOk()
            ->assertViewHas('practiceToday', 1)->assertSee('The daily challenge has not been started yet.');
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
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
}
