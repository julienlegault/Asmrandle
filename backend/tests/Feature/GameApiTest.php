<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 12:00:00 UTC');
        for ($i = 0; $i < 25; $i++) {
            DB::table('cards')->insert([
                'scryfall_id' => "id-{$i}",
                'slug' => "card-{$i}",
                'name' => "Card {$i}",
                'image_url' => "https://example.test/{$i}.jpg",
                'art_url' => "https://example.test/{$i}-art.jpg",
                'inclusion' => $i + 1,
                'potential_decks' => 100,
                'legal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_rounds_are_shared_and_unchanged_after_card_refresh(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk()->assertJsonPath('id', '2026-03-15');
        $first = $this->getJson('/api/games/2026-03-15/rounds/0')->assertOk()->json('cards');
        $this->assertCount(2, $first);
        DB::table('cards')->update(['inclusion' => 99, 'legal' => false]);
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $this->getJson('/api/games/2026-03-15/rounds/0')->assertOk()->assertJsonPath('cards', $first);
        $this->getJson('/api/games/2026-03-15/rounds/10')->assertNotFound();
        Carbon::setTestNow('2026-03-16 05:59:59 UTC');
        $this->getJson('/api/games/2026-03-15/rounds/0')->assertOk();
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->getJson('/api/games/2026-03-15/rounds/0')->assertNotFound();
    }

    public function test_practice_games_return_only_one_pair_per_round(): void
    {
        $id = $this->postJson('/api/games', ['mode' => 'practice'])->assertOk()->json('id');
        $first = $this->getJson("/api/games/{$id}/rounds/0")->assertOk()->json('cards');
        $second = $this->getJson("/api/games/{$id}/rounds/1")->assertOk()->json('cards');
        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertNotEquals($first[0]['name'], $second[0]['name']);
    }

    public function test_each_practice_game_selects_a_fresh_snapshot(): void
    {
        DB::table('cards')->where('id', '>', 20)->update(['legal' => false]);
        $firstId = $this->postJson('/api/games', ['mode' => 'practice'])->assertOk()->json('id');
        $first = $this->getJson("/api/games/{$firstId}/rounds/0")->assertOk()->json('cards');

        DB::table('cards')->where('slug', $first[0]['name'])->update(['legal' => false]);
        DB::table('cards')->where('id', 21)->update(['legal' => true]);
        $secondId = $this->postJson('/api/games', ['mode' => 'practice'])->assertOk()->json('id');

        $this->assertNotSame($firstId, $secondId);
        $this->getJson("/api/games/{$firstId}/rounds/0")->assertOk()->assertJsonPath('cards', $first);
        $cards = json_decode(DB::table('practice_games')->where('id', $secondId)->value('cards'), true);
        $this->assertCount(20, $cards);
        $this->assertNotContains($first[0]['name'], array_column($cards, 'name'));
        $this->assertContains('card-20', array_column($cards, 'name'));
    }

    public function test_june_challenge_can_fill_unavailable_special_cards(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00 UTC');
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $this->getJson('/api/games/2026-06-01/rounds/9')->assertOk()->assertJsonCount(2, 'cards');
    }

    public function test_daily_results_are_idempotent_and_counted_by_mode(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $data = [
            'mode' => 'daily',
            'date' => '2026-03-15',
            'hardMode' => true,
            'answers' => array_fill(0, 10, true),
            'submissionId' => (string) Str::uuid(),
        ];
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', true);
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', true);
        $data['submissionId'] = (string) Str::uuid();
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', false);
        $this->getJson('/api/community')->assertOk()
            ->assertJsonPath('hardModeCounts.10', 2)
            ->assertJsonPath('counts.10', 0)
            ->assertJsonPath('questions.1.0', 2);
        $data['submissionId'] = (string) Str::uuid();
        $data['answers'][0] = false;
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', false);
        $this->getJson('/api/community')->assertOk()
            ->assertJsonPath('hardModeCounts.9', 1)
            ->assertJsonPath('questions.1.0', 2)
            ->assertJsonPath('questions.1.1', 3)
            ->assertJsonPath('questions.0.1', 0);
        $data['answers'] = [true];
        $this->postJson('/api/results', $data)->assertUnprocessable();
    }

    public function test_daily_submission_id_cannot_be_reused_for_different_results(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $data = [
            'mode' => 'daily',
            'date' => '2026-03-15',
            'hardMode' => false,
            'answers' => array_fill(0, 10, true),
            'submissionId' => (string) Str::uuid(),
        ];
        $this->postJson('/api/results', $data)->assertOk();
        $data['hardMode'] = true;
        $this->postJson('/api/results', $data)->assertStatus(409);
        $data['hardMode'] = false;
        $data['answers'][0] = false;
        $this->postJson('/api/results', $data)->assertStatus(409);
    }

    public function test_practice_results_can_be_saved_after_the_daily_rollover(): void
    {
        Carbon::setTestNow('2026-03-16 06:00:00 UTC');
        $this->postJson('/api/results', [
            'mode' => 'practice',
            'date' => '2026-03-15',
            'hardMode' => false,
            'answers' => array_fill(0, 10, true),
            'submissionId' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame(1, DB::table('practice_plays')->count());
    }

    public function test_fast_mode_is_unavailable_outside_local_environment(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('fastModeAvailable', false);
    }

    public function test_edhrec_refresh_preserves_completed_work_when_a_request_fails(): void
    {
        DB::table('cards')->where('id', '>', 2)->update(['edhrec_checked_at' => now()]);
        Http::fake([
            '*/card-0.json' => Http::response(['container' => ['json_dict' => ['card' => [
                'num_decks' => 40, 'potential_decks' => 200,
            ]]]]),
            '*/card-1.json' => Http::response('Temporary failure', 503),
        ]);
        try {
            $this->artisan('cards:refresh-edhrec');
            $this->fail('The refresh should fail on a server error.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        $this->assertSame(40, DB::table('cards')->where('slug', 'card-0')->value('inclusion'));
        $this->assertNull(DB::table('cards')->where('slug', 'card-1')->value('edhrec_checked_at'));
    }

    public function test_edhrec_access_denial_does_not_erase_existing_data(): void
    {
        DB::table('cards')->where('id', '>', 1)->update(['edhrec_checked_at' => now()]);
        Http::fake([
            '*/card-0.json' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403, ['Server' => 'AmazonS3']),
        ]);
        $this->artisan('cards:refresh-edhrec')->assertFailed();
        $this->assertSame(1, DB::table('cards')->where('slug', 'card-0')->value('inclusion'));
        $this->assertNull(DB::table('cards')->where('slug', 'card-0')->value('edhrec_checked_at'));
    }

    public function test_edhrec_missing_new_card_is_checked_only_after_service_probe(): void
    {
        DB::table('cards')->where('id', '>', 1)->update(['edhrec_checked_at' => now()]);
        DB::table('cards')->where('id', 1)->update(['inclusion' => null, 'potential_decks' => null]);
        Http::fake([
            '*/card-0.json' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403, ['Server' => 'AmazonS3']),
            '*/sol-ring.json' => Http::response(['container' => ['json_dict' => ['card' => ['potential_decks' => 100]]]]),
        ]);
        $this->artisan('cards:refresh-edhrec')->assertSuccessful();
        $this->assertNotNull(DB::table('cards')->where('id', 1)->value('edhrec_checked_at'));
    }
}
