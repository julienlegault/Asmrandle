<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeededRunTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 12:00:00 UTC');
        $hash = Hash::make('a-long-password');
        $id = DB::table('admin_accounts')->insertGetId([
            'username' => 'admin', 'password' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = ['admin' => ['id' => $id, 'password_hash' => $hash]];
        for ($i = 0; $i < 21; $i++) {
            DB::table('cards')->insert([
                'scryfall_id' => "id-$i", 'slug' => "card-$i", 'name' => "Card $i",
                'image_url' => "https://example.test/$i.jpg", 'art_url' => "https://example.test/$i-art.jpg",
                'inclusion' => $i + 1, 'potential_decks' => 100, 'legal' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runData(string $date = '2026-03-16'): array
    {
        return [
            'date' => $date,
            'cards' => array_map(fn ($i) => "card-$i", range(0, 19)),
            'perfect_score_emojis' => '🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧',
        ];
    }

    public function test_schedule_edit_copy_delete_and_play_in_order(): void
    {
        $data = $this->runData();
        $this->post('/admin/seeded-runs', $data)->assertRedirect('/admin/login');
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertRedirect('/admin')->assertSessionHasNoErrors();
        $this->withSession($this->admin)->get('/admin?edit=2026-03-16')->assertOk()
            ->assertSee('Edit seeded run')->assertSee('card-0 / card-1');

        $data['cards'][0] = 'card-20';
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-16', $data)->assertSessionHasNoErrors();
        $this->withSession($this->admin)->get('/admin?copy=2026-03-16')->assertOk()
            ->assertSee('value="card-20"', false)->assertSee('value="🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧"', false);
        $data['date'] = '2026-03-17';
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();
        $this->withSession($this->admin)->delete('/admin/seeded-runs/2026-03-17')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('daily_challenges', ['date' => '2026-03-17']);

        DB::table('cards')->where('slug', 'card-20')->update(['inclusion' => 99]);
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk()
            ->assertJsonPath('perfectScoreEmojis', $data['perfect_score_emojis']);
        $this->getJson('/api/games/2026-03-16/rounds/0')->assertOk()
            ->assertJsonPath('cards.0.name', 'card-20')->assertJsonPath('cards.0.popularity', 21)
            ->assertJsonPath('cards.1.name', 'card-1');
        $this->getJson('/api/games/2026-03-16/rounds/9')->assertJsonPath('cards.1.name', 'card-19');
        $data['date'] = '2026-03-16';
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-16', $data)->assertSessionHasErrors('date');
        $this->withSession($this->admin)->delete('/admin/seeded-runs/2026-03-16')->assertSessionHasErrors('date');
        $this->withSession($this->admin)->get('/admin?edit=2026-03-16')->assertStatus(409);
        $this->assertDatabaseHas('daily_challenges', ['date' => '2026-03-16', 'seeded' => true]);
    }

    public function test_validation_rejects_bad_cards_dates_and_emoji_patterns(): void
    {
        $data = $this->runData();
        $data['cards'][1] = $data['cards'][0];
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasErrors('cards.1');
        $data = $this->runData();
        $data['cards'][0] = 'missing';
        $data['date'] = '2026-03-14';
        $data['perfect_score_emojis'] = '😀😀😀😀😀😀😀😀😀x';
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)
            ->assertSessionHasErrors(['cards.0', 'date', 'perfect_score_emojis']);
        DB::table('cards')->where('slug', 'card-0')->update(['legal' => false]);
        $this->withSession($this->admin)->post('/admin/seeded-runs', $this->runData())->assertSessionHasErrors('cards.0');
        DB::table('cards')->where('slug', 'card-0')->update(['legal' => true]);
        $data = $this->runData();
        $data['perfect_score_emojis'] = str_repeat('👩‍💻', 10);
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasErrors('date');
        $this->assertSame(1, DB::table('daily_challenges')->count());
    }

    public function test_today_can_be_scheduled_before_it_starts_and_moved(): void
    {
        $data = $this->runData('2026-03-15');
        $data['perfect_score_emojis'] = null;
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();
        $data['date'] = '2026-03-16';
        $data['perfect_score_emojis'] = str_repeat('🇨🇦', 10);
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-15', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('daily_challenges', ['date' => '2026-03-15']);
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertJsonPath('perfectScoreEmojis', str_repeat('🇨🇦', 10));
    }

    public function test_moving_a_run_conflicts_without_losing_either_run(): void
    {
        $this->withSession($this->admin)->post('/admin/seeded-runs', $this->runData())->assertSessionHasNoErrors();
        $this->withSession($this->admin)->post('/admin/seeded-runs', $this->runData('2026-03-17'))->assertSessionHasNoErrors();
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-16', $this->runData('2026-03-17'))
            ->assertSessionHasErrors('date');
        $this->assertSame(2, DB::table('daily_challenges')->count());
    }

    public function test_started_random_challenge_cannot_be_replaced_or_deleted(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk()->assertJsonPath('perfectScoreEmojis', null);
        $this->withSession($this->admin)->post('/admin/seeded-runs', $this->runData('2026-03-15'))->assertSessionHasErrors('date');
        $this->withSession($this->admin)->delete('/admin/seeded-runs/2026-03-15')->assertNotFound();
        $this->assertDatabaseHas('daily_challenges', ['date' => '2026-03-15', 'seeded' => false]);
    }
}
