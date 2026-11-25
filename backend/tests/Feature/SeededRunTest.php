<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'logo' => 'pride',
        ];
    }

    public function test_schedule_edit_copy_delete_and_play_in_order(): void
    {
        $data = $this->runData();
        $this->post('/admin/seeded-runs', $data)->assertRedirect('/admin/login');
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertRedirect('/admin')->assertSessionHasNoErrors();
        $this->withSession($this->admin)->get('/admin?edit=2026-03-16')->assertOk()
            ->assertSee('Edit seeded run')->assertSee('card-0')->assertSee('card-1')
            ->assertSee('<option value="pride" selected>Pride</option>', false)
            ->assertSee('Site logo');

        $data['cards'][0] = 'card-20';
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-16', $data)->assertSessionHasNoErrors();
        $this->withSession($this->admin)->get('/admin?copy=2026-03-16')->assertOk()
            ->assertSee('value="card-20"', false)->assertSee('value="🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧"', false)
            ->assertSee('<option value="pride" selected>Pride</option>', false);
        $data['date'] = '2026-03-17';
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();
        $this->withSession($this->admin)->delete('/admin/seeded-runs/2026-03-17')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('daily_challenges', ['date' => '2026-03-17']);

        DB::table('cards')->where('slug', 'card-20')->update(['inclusion' => 99]);
        Carbon::setTestNow('2026-03-16 05:59:59 UTC');
        $this->getJson('/api/config')->assertOk()->assertJsonPath('logo', 'standard');
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->getJson('/api/config')->assertOk()->assertJsonPath('logo', 'pride')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull(DB::table('daily_challenges')->where('date', '2026-03-16')->value('started_at'));
        $this->postJson('/api/games', ['mode' => 'practice'])->assertOk()->assertJsonPath('logo', 'pride');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk()
            ->assertJsonPath('logo', 'pride')
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
        Carbon::setTestNow('2026-03-17 06:00:00 UTC');
        $this->getJson('/api/config')->assertOk()->assertJsonPath('logo', 'standard');
    }

    public function test_manage_runs_paginates_sorts_and_locks_against_current_game_date(): void
    {
        $cards = array_map(fn ($i) => [
            'name' => "card-$i", 'img' => "https://example.test/$i.jpg", 'popularity' => $i,
        ], range(0, 19));
        foreach (range(10, 22) as $day) {
            DB::table('daily_challenges')->insert([
                'date' => "2026-03-$day", 'cards' => json_encode($cards), 'seeded' => true,
                'started_at' => $day === 15 ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->withSession($this->admin)->get('/admin?date=2026-03-10')->assertOk()
            ->assertViewHas('seededRuns', fn ($runs) => $runs->total() === 13 && $runs->count() === 10
                && $runs->first()->date === '2026-03-22' && $runs->last()->date === '2026-03-13')
            ->assertSee('class="manage-runs-row is-locked" data-date="2026-03-13"', false)
            ->assertSee('class="manage-runs-row is-locked" data-date="2026-03-15"', false)
            ->assertSee('class="manage-runs-row " data-date="2026-03-16"', false)
            ->assertSee('Page 1 of 2')
            ->assertSee('date=2026-03-10&amp;runs_page=2#manage-runs', false);

        $this->withSession($this->admin)->get('/admin?date=2026-03-10&runs_page=2')->assertOk()
            ->assertViewHas('seededRuns', fn ($runs) => $runs->count() === 3
                && $runs->first()->date === '2026-03-12' && $runs->last()->date === '2026-03-10')
            ->assertSee('Page 2 of 2');

        $this->withSession($this->admin)->get('/admin?date=2026-03-10&runs_order=asc')->assertOk()
            ->assertViewHas('seededRuns', fn ($runs) => $runs->first()->date === '2026-03-10'
                && $runs->last()->date === '2026-03-19')
            ->assertSee('runs_order=asc&amp;runs_page=2#manage-runs', false);
        $this->withSession($this->admin)->get('/admin?runs_order=asc&runs_page=2')->assertOk()
            ->assertViewHas('seededRuns', fn ($runs) => $runs->first()->date === '2026-03-20'
                && $runs->last()->date === '2026-03-22');

        $this->withSession($this->admin)->get('/admin?copy=2026-03-10')->assertOk()
            ->assertViewHas('source', fn ($source) => $source->date === '2026-03-10');
        $this->withSession($this->admin)->get('/admin?date=2026-03-10&edit=2026-03-12')->assertStatus(409);
        $this->withSession($this->admin)->get('/admin?runs_order=asc&edit=2026-03-22')->assertOk()
            ->assertViewHas('source', fn ($source) => $source->date === '2026-03-22');
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
        $data['logo'] = 'invalid';
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)
            ->assertSessionHasErrors(['cards.0', 'date', 'perfect_score_emojis', 'logo']);
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
        $data['logo'] = 'standard';
        $data['perfect_score_emojis'] = str_repeat('🇨🇦', 10);
        $this->withSession($this->admin)->put('/admin/seeded-runs/2026-03-15', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('daily_challenges', ['date' => '2026-03-15']);
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertJsonPath('perfectScoreEmojis', str_repeat('🇨🇦', 10))
            ->assertJsonPath('logo', 'standard');
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
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk()->assertJsonPath('perfectScoreEmojis', null)
            ->assertJsonPath('logo', 'standard');
        $this->withSession($this->admin)->post('/admin/seeded-runs', $this->runData('2026-03-15'))->assertSessionHasErrors('date');
        $this->withSession($this->admin)->delete('/admin/seeded-runs/2026-03-15')->assertNotFound();
        $this->assertDatabaseHas('daily_challenges', ['date' => '2026-03-15', 'seeded' => false]);
    }

    public function test_logo_is_optional_for_existing_and_new_runs(): void
    {
        $data = $this->runData('2026-03-15');
        unset($data['logo']);
        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();
        $this->getJson('/api/config')->assertJsonPath('logo', 'standard');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertJsonPath('logo', 'standard');
    }

    public function test_custom_printings_are_saved_and_served_only_for_their_cards(): void
    {
        $printing = [
            'scryfall_id' => '11111111-2222-4333-8444-555555555555',
            'img' => 'https://cards.scryfall.io/normal/front/a.jpg',
            'artImg' => 'https://cards.scryfall.io/art_crop/front/a.jpg',
        ];
        $data = $this->runData('2026-03-15');
        $data['printings'] = array_fill(0, 20, null);
        $data['printings'][3] = $printing;

        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();

        $cards = json_decode(DB::table('daily_challenges')->where('date', '2026-03-15')->value('cards'), true);
        $this->assertSame($printing, $cards[3]['printing']);
        $this->assertArrayNotHasKey('printing', $cards[0]);
        $this->getJson('/api/games/2026-03-15/rounds/1')->assertOk()
            ->assertJsonPath('cards.1.printing', $printing);
        $this->getJson('/api/games/2026-03-15/rounds/0')->assertOk()
            ->assertJsonMissingPath('cards.0.printing');
    }

    public function test_custom_printings_must_use_scryfall_images_and_valid_ids(): void
    {
        $base = $this->runData('2026-03-15');
        $valid = [
            'scryfall_id' => '11111111-2222-4333-8444-555555555555',
            'img' => 'https://cards.scryfall.io/normal/front/a.jpg',
            'artImg' => 'https://cards.scryfall.io/art_crop/front/a.jpg',
        ];
        $badPrintings = [
            ['printings' => array_fill(0, 19, null)],
            ['printings' => array_fill(0, 20, [...$valid, 'img' => 'https://evil.example/a.jpg'])],
            ['printings' => array_fill(0, 20, [...$valid, 'artImg' => 'http://cards.scryfall.io/a.jpg'])],
            ['printings' => array_fill(0, 20, [...$valid, 'scryfall_id' => 'not-a-uuid'])],
        ];
        foreach ($badPrintings as $override) {
            $this->withSession($this->admin)->post('/admin/seeded-runs', [...$base, ...$override])
                ->assertSessionHasErrors();
        }
        $this->assertDatabaseMissing('daily_challenges', ['date' => '2026-03-15']);
    }

    public function test_blank_picker_entries_save_as_default_printings(): void
    {
        $blank = ['scryfall_id' => '', 'img' => '', 'artImg' => ''];
        $printing = [
            'scryfall_id' => '11111111-2222-4333-8444-555555555555',
            'img' => 'https://cards.scryfall.io/normal/front/a.jpg',
            'artImg' => 'https://cards.scryfall.io/art_crop/front/a.jpg',
        ];
        $data = $this->runData('2026-03-15');
        $data['printings'] = array_fill(0, 20, $blank);
        $data['printings'][5] = $printing;

        $this->withSession($this->admin)->post('/admin/seeded-runs', $data)->assertSessionHasNoErrors();

        $cards = json_decode(DB::table('daily_challenges')->where('date', '2026-03-15')->value('cards'), true);
        $this->assertSame($printing, $cards[5]['printing']);
        $this->assertArrayNotHasKey('printing', $cards[4]);
    }

    public function test_card_printings_lists_every_scryfall_print_for_the_card(): void
    {
        $oracleId = '6a3c1b2e-4f5d-4e6a-9b7c-8d9e0f1a2b3c';
        DB::table('cards')->where('slug', 'card-0')->update(['scryfall_id' => $oracleId]);

        Http::fake(['api.scryfall.com/cards/search*' => Http::response([
            'has_more' => false,
            'data' => [
                [
                    'id' => 'print-a', 'set_name' => 'Alpha', 'collector_number' => '1',
                    'image_uris' => ['normal' => 'https://cards.scryfall.io/normal/a.jpg', 'art_crop' => 'https://cards.scryfall.io/art_crop/a.jpg'],
                ],
                [
                    'id' => 'print-b', 'set_name' => 'Beta', 'collector_number' => '22',
                    'card_faces' => [['image_uris' => ['normal' => 'https://cards.scryfall.io/normal/b.jpg', 'art_crop' => 'https://cards.scryfall.io/art_crop/b.jpg']]],
                ],
            ],
        ])]);

        $this->withSession($this->admin)->getJson('/admin/cards/card-0/printings')->assertOk()
            ->assertExactJson(['printings' => [
                ['scryfall_id' => 'print-a', 'set_name' => 'Alpha', 'collector_number' => '1',
                    'img' => 'https://cards.scryfall.io/normal/a.jpg', 'artImg' => 'https://cards.scryfall.io/art_crop/a.jpg'],
                ['scryfall_id' => 'print-b', 'set_name' => 'Beta', 'collector_number' => '22',
                    'img' => 'https://cards.scryfall.io/normal/b.jpg', 'artImg' => 'https://cards.scryfall.io/art_crop/b.jpg'],
            ]]);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'q=oracle_id:'.$oracleId));
        $this->withSession($this->admin)->getJson('/admin/cards/missing-card/printings')->assertNotFound();
    }

    public function test_card_printings_skips_scryfall_for_placeholder_ids(): void
    {
        Http::fake();

        $this->withSession($this->admin)->getJson('/admin/cards/card-0/printings')->assertOk()
            ->assertExactJson(['printings' => []]);
        Http::assertNothingSent();
    }
}
