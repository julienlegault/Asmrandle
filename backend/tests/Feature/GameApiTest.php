<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    protected function getTestResultData(array $overrides = []): array
    {
        $time = now()->subSeconds(30);
        // Format to match JavaScript's toISOString(): 2026-03-15T11:59:30.000Z
        $startedAt = $time->format('Y-m-d\TH:i:s.000\Z');
        
        return array_merge([
            'mode' => 'daily',
            'date' => '2026-03-15',
            'hardMode' => false,
            'answers' => array_fill(0, 10, false),
            'submissionId' => (string) Str::uuid(),
            'startedAt' => $startedAt,
        ], $overrides);
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
        $data = $this->getTestResultData(['hardMode' => true, 'answers' => array_fill(0, 10, true)]);
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', true);
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', true);
        $data = $this->getTestResultData(['hardMode' => true, 'answers' => array_fill(0, 10, true)]);
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', false);
        $this->getJson('/api/community')->assertOk()
            ->assertJsonPath('hardModeCounts.10', 2)
            ->assertJsonPath('counts.10', 0)
            ->assertJsonPath('questions.1.0', 2);
        $data = $this->getTestResultData(['hardMode' => true, 'answers' => array_fill(0, 10, true)]);
        $data['answers'][0] = false;
        $this->postJson('/api/results', $data)->assertOk()->assertJsonPath('firstTen', false);
        $this->getJson('/api/community')->assertOk()
            ->assertJsonPath('hardModeCounts.9', 1)
            ->assertJsonPath('questions.1.0', 2)
            ->assertJsonPath('questions.1.1', 3)
            ->assertJsonPath('questions.0.1', 0);
        $data = $this->getTestResultData(['hardMode' => true]);
        $data['answers'] = [true];
        $this->postJson('/api/results', $data)->assertUnprocessable();
    }

    public function test_daily_submission_id_cannot_be_reused_for_different_results(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $data = $this->getTestResultData(['answers' => array_fill(0, 10, true)]);
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
        $this->postJson('/api/results', $this->getTestResultData([
            'mode' => 'practice',
            'date' => '2026-03-15',
            'answers' => array_fill(0, 10, true),
        ]))->assertOk();
        $this->assertSame(1, DB::table('practice_plays')->count());
    }

    public function test_result_durations_are_validated_and_saved_for_both_modes(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $data = $this->getTestResultData(['answers' => array_fill(0, 10, true), 'durationMs' => 59999]);
        $this->postJson('/api/results', $data)->assertOk();
        $data['durationMs'] = 60000;
        $this->postJson('/api/results', $data)->assertOk();
        $this->assertEquals(59999, DB::table('game_results')->value('duration_ms'));
        $this->assertNotNull(DB::table('game_results')->value('started_at'));
        $data['mode'] = 'practice';
        $this->postJson('/api/results', $data)->assertOk();
        $this->assertEquals(60000, DB::table('practice_plays')->value('duration_ms'));
        $data['durationMs'] = -1;
        $this->postJson('/api/results', $data)->assertUnprocessable();
        $data['durationMs'] = 2147483648;
        $this->postJson('/api/results', $data)->assertUnprocessable();
        unset($data['durationMs']);
        $this->postJson('/api/results', $data)->assertOk();
        $this->assertSame(1, DB::table('practice_plays')->whereNull('duration_ms')->count());
    }

    public function test_fast_mode_is_unavailable_outside_local_environment(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('fastModeAvailable', false);
    }

    public function test_daily_preview_is_local_only_and_does_not_start_the_scheduled_run(): void
    {
        $cards = app(\App\Services\GameService::class)->selectCards();
        DB::table('daily_challenges')->insert([
            'date' => '2026-03-20',
            'cards' => json_encode($cards, JSON_THROW_ON_ERROR),
            'seeded' => true,
            'logo' => 'pride',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/games/preview', ['date' => '2026-03-20'])->assertNotFound();
        $this->app['env'] = 'local';
        $this->getJson('/api/config')->assertJsonPath('fastModeAvailable', true);
        $this->postJson('/api/games/preview', ['date' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/games/preview', ['date' => '2026-03-21'])->assertNotFound();
        $response = $this->postJson('/api/games/preview', ['date' => '2026-03-20'])
            ->assertOk()->assertJsonPath('date', '2026-03-20')->assertJsonPath('logo', 'pride');
        $id = $response->json('id');
        $cards = json_decode(json_encode($cards, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->getJson("/api/games/{$id}/rounds/0")->assertOk()
            ->assertJsonPath('cards', array_slice($cards, 0, 2));
        $this->getJson("/api/games/{$id}/rounds/9")->assertOk()
            ->assertJsonPath('cards', array_slice($cards, 18, 2));
        $this->postJson('/api/games/preview', ['date' => '2026-03-20'])->assertOk();
        $this->getJson('/api/games/2026-03-20/rounds/0')->assertNotFound();
        $this->assertNull(DB::table('daily_challenges')->where('date', '2026-03-20')->value('started_at'));
        $this->assertSame(1, DB::table('daily_challenges')->count());
        $this->assertSame(0, DB::table('game_results')->count());
        $this->assertSame(0, DB::table('practice_plays')->count());
    }

    public function test_preview_welcome_is_local_only_and_does_not_start_a_run(): void
    {
        DB::table('daily_challenges')->insert([
            'date' => '2026-03-20', 'cards' => '[]', 'seeded' => true,
            'welcome_text' => 'Future welcome', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/preview/welcome?date=2026-03-20')->assertNotFound();
        $this->app['env'] = 'local';
        $this->getJson('/api/preview/welcome?date=invalid')->assertUnprocessable();
        $this->getJson('/api/preview/welcome?date=2026-03-20')->assertOk()
            ->assertJsonPath('welcomeText', 'Future welcome')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/preview/welcome?date=2026-03-21')->assertOk()->assertJsonPath('welcomeText', null);
        $this->assertNull(DB::table('daily_challenges')->where('date', '2026-03-20')->value('started_at'));
        $this->assertSame(1, DB::table('daily_challenges')->count());
        $this->assertSame(0, DB::table('practice_games')->count());
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
        $this->assertSame(2, DB::table('cards')->where('slug', 'card-1')->value('inclusion'));
        $this->assertNull(DB::table('cards')->where('slug', 'card-1')->value('edhrec_checked_at'));
        $this->assertNull(DB::table('cards')->where('slug', 'card-1')->value('edhrec_missing_since'));
    }

    public function test_failed_request_for_missing_card_starts_the_month_cutoff(): void
    {
        Carbon::setTestNow('2026-03-16 07:00:00 UTC');
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        DB::table('cards')->where('id', 1)->update(['inclusion' => null, 'potential_decks' => null]);
        Http::fake(['*' => Http::response('Temporary failure', 503)]);

        try {
            $this->artisan('cards:refresh-edhrec', ['--partial' => true]);
            $this->fail('The refresh should fail on a server error.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        $missingSince = DB::table('cards')->where('id', 1)->value('edhrec_missing_since');
        $this->assertNotNull($missingSince);
        $this->assertNotNull(DB::table('cards')->where('id', 1)->value('edhrec_checked_at'));

        Carbon::setTestNow('2026-04-16 07:00:00 UTC');
        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertSentCount(1);
        try {
            $this->artisan('cards:refresh-edhrec');
            $this->fail('The full refresh should retry the missing card.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        Http::assertSentCount(2);
        $this->assertSame($missingSince, DB::table('cards')->where('id', 1)->value('edhrec_missing_since'));
    }

    public function test_connection_failure_for_missing_card_is_tracked(): void
    {
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        DB::table('cards')->where('id', 1)->update(['inclusion' => null, 'potential_decks' => null]);
        Http::fake(['*' => Http::failedConnection('Connection failed')]);

        try {
            $this->artisan('cards:refresh-edhrec', ['--partial' => true]);
            $this->fail('The refresh should fail on a connection error.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('Connection failed', $exception->getMessage());
        }
        $this->assertNotNull(DB::table('cards')->where('id', 1)->value('edhrec_missing_since'));
    }

    public function test_missing_response_preserves_previously_available_card(): void
    {
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('cards:refresh-edhrec')->assertFailed();
        $card = DB::table('cards')->where('id', 1)->first();
        $this->assertSame(1, $card->inclusion);
        $this->assertNull($card->edhrec_missing_since);
        $this->assertNull($card->edhrec_checked_at);
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

    public function test_partial_edhrec_refresh_samples_five_percent_and_retries_recent_missing_cards(): void
    {
        Carbon::setTestNow('2026-03-16 07:00:00 UTC');
        DB::table('cards')->where('slug', 'card-20')->update(['legal' => false]);
        DB::table('cards')->whereIn('slug', ['card-21', 'card-22'])->update([
            'inclusion' => null, 'potential_decks' => null,
        ]);
        DB::table('cards')->where('slug', 'card-23')->update([
            'inclusion' => null, 'potential_decks' => null,
            'edhrec_missing_since' => now()->subMonth(),
        ]);
        Http::fake([
            '*/card-21.json' => Http::response('', 404),
            '*/card-22.json' => Http::response('', 404),
            '*' => Http::response(['container' => ['json_dict' => ['card' => [
                'num_decks' => 40, 'potential_decks' => 200,
            ]]]]),
        ]);

        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();

        $sample = json_decode(DB::table('edhrec_refresh_samples')->value('card_ids'), true);
        $this->assertCount(2, $sample);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/card-23.json'));
        $this->assertNotNull(DB::table('cards')->where('slug', 'card-21')->value('edhrec_missing_since'));

        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertSentCount(4);
        $this->assertSame($sample, json_decode(DB::table('edhrec_refresh_samples')->value('card_ids'), true));
    }

    public function test_missing_cards_stop_partial_retries_after_a_month_but_full_refresh_checks_them(): void
    {
        Carbon::setTestNow('2026-03-16 07:00:00 UTC');
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        DB::table('cards')->where('id', 1)->update(['inclusion' => null, 'potential_decks' => null]);
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertSentCount(1);
        $missingSince = DB::table('cards')->where('id', 1)->value('edhrec_missing_since');

        Carbon::setTestNow('2026-04-15 07:00:00 UTC');
        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertSame($missingSince, DB::table('cards')->where('id', 1)->value('edhrec_missing_since'));

        Carbon::setTestNow('2026-04-16 07:00:00 UTC');
        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertSentCount(2);

        $this->artisan('cards:refresh-edhrec')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertSame($missingSince, DB::table('cards')->where('id', 1)->value('edhrec_missing_since'));
    }

    public function test_successful_full_refresh_clears_the_missing_since_date(): void
    {
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        DB::table('cards')->where('id', 1)->update([
            'inclusion' => null, 'potential_decks' => null, 'edhrec_missing_since' => now()->subMonths(2),
        ]);
        Http::fake(['*' => Http::response(['container' => ['json_dict' => ['card' => [
            'num_decks' => 40, 'potential_decks' => 200,
        ]]]])]);

        $this->artisan('cards:refresh-edhrec')->assertSuccessful();
        $this->assertNull(DB::table('cards')->where('id', 1)->value('edhrec_missing_since'));
        $this->assertSame(40, DB::table('cards')->where('id', 1)->value('inclusion'));
    }

    public function test_missing_card_retry_cutoff_handles_shorter_months(): void
    {
        Carbon::setTestNow('2026-02-28 07:00:00 UTC');
        DB::table('cards')->where('id', '>', 1)->update(['legal' => false]);
        DB::table('cards')->where('id', 1)->update([
            'inclusion' => null, 'potential_decks' => null, 'edhrec_missing_since' => '2026-01-31 07:00:00',
        ]);
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_partial_refresh_retry_keeps_the_same_random_sample(): void
    {
        $attempts = 0;
        $fail = true;
        Http::fake(function () use (&$attempts, &$fail) {
            $attempts++;
            if ($fail && $attempts === 2) {
                return Http::response('Temporary failure', 503);
            }

            return Http::response(['container' => ['json_dict' => ['card' => [
                'num_decks' => 40, 'potential_decks' => 200,
            ]]]]);
        });

        try {
            $this->artisan('cards:refresh-edhrec', ['--partial' => true]);
            $this->fail('The refresh should fail on a server error.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        $sample = DB::table('edhrec_refresh_samples')->value('card_ids');
        $fail = false;
        $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertSuccessful();

        $this->assertSame($sample, DB::table('edhrec_refresh_samples')->value('card_ids'));
        Http::assertSentCount(3);
        $this->assertSame(2, DB::table('cards')->whereNotNull('edhrec_checked_at')->count());
    }

    public function test_edhrec_refresh_fails_if_another_refresh_is_running(): void
    {
        $lock = Cache::lock('edhrec-refresh', 86400);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->artisan('cards:refresh-edhrec', ['--partial' => true])->assertFailed();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_scryfall_import_fails_if_another_import_is_running(): void
    {
        $lock = Cache::lock('scryfall-import', 86400);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->artisan('cards:import')->assertFailed();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_development_id_resolution_preserves_card_data_and_is_repeatable(): void
    {
        DB::table('cards')->where('slug', 'card-0')->update(['scryfall_id' => 'dev-card-0']);
        DB::table('cards')->where('slug', 'card-1')->update(['scryfall_id' => 'dev-card-1']);
        $before = DB::table('cards')->where('slug', 'card-0')->first();
        $oracleId = '4457ed35-7c10-48c8-9776-456485fdf070';
        $cards = [
            ['name' => 'Card 0 // Other', 'oracle_id' => '2b93787d-47ea-4b8b-912d-ffc5d5440171'],
            ['name' => 'Card 0', 'oracle_id' => $oracleId],
            ['name' => 'Card 2', 'oracle_id' => '6a3c1b2e-4f5d-4e6a-9b7c-8d9e0f1a2b3c'],
        ];
        for ($i = 0; $i < 18; $i++) {
            $cards[] = ['name' => "Other $i"];
        }
        $this->fakeScryfallBulk($cards);

        $this->app['env'] = 'local';
        try {
            $this->artisan('cards:import', ['--resolve-development-ids' => true])
                ->expectsOutput('Resolved 1 development card IDs.')
                ->expectsOutput('1 development card IDs could not be resolved.')
                ->assertSuccessful();
            $after = DB::table('cards')->where('slug', 'card-0')->first();
            $this->assertSame($oracleId, $after->scryfall_id);
            $this->assertSame($before->id, $after->id);
            $this->assertSame($before->inclusion, $after->inclusion);
            $this->assertSame($before->image_url, $after->image_url);
            $this->assertSame('dev-card-1', DB::table('cards')->where('slug', 'card-1')->value('scryfall_id'));
            $this->assertSame('id-2', DB::table('cards')->where('slug', 'card-2')->value('scryfall_id'));

            $this->artisan('cards:import', ['--resolve-development-ids' => true])
                ->expectsOutput('Resolved 0 development card IDs.')
                ->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_development_id_resolution_rolls_back_incomplete_imports(): void
    {
        DB::table('cards')->where('slug', 'card-0')->update(['scryfall_id' => 'dev-card-0']);
        $this->fakeScryfallBulk([['name' => 'Card 0', 'oracle_id' => '4457ed35-7c10-48c8-9776-456485fdf070']]);
        $this->app['env'] = 'local';
        try {
            try {
                $this->artisan('cards:import', ['--resolve-development-ids' => true])->run();
                $this->fail('An incomplete import should fail.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('incomplete', $exception->getMessage());
            }
            $this->assertSame('dev-card-0', DB::table('cards')->where('slug', 'card-0')->value('scryfall_id'));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_development_id_resolution_requires_local_environment(): void
    {
        Http::fake();
        $this->artisan('cards:import', ['--resolve-development-ids' => true])->assertFailed();
        Http::assertNothingSent();
    }

    private function fakeScryfallBulk(array $cards): void
    {
        $lines = implode("\n", array_map(fn ($card) => json_encode($card, JSON_THROW_ON_ERROR), $cards))."\n";
        $gzip = gzencode($lines);
        Http::fake(fn ($request) => str_contains($request->url(), '/bulk-data')
            ? Http::response(['data' => [[
                'type' => 'oracle_cards',
                'jsonl_download_uri' => 'https://data.example.test/oracle.jsonl.gz',
                'compressed_size' => strlen($gzip),
            ]]])
            : Http::response($gzip));
    }

    public function test_first_ten_can_be_marked_as_copied(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $submissionId = (string) Str::uuid();
        $this->postJson('/api/results', $this->getTestResultData([
            'submissionId' => $submissionId,
            'answers' => array_fill(0, 10, true),
        ]))->assertOk()->assertJsonPath('firstTen', true);

        $this->assertSame(0, DB::table('game_results')->where('submission_id', $submissionId)->value('score_copied'));

        $this->postJson("/api/results/{$submissionId}/mark-copied")->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, DB::table('game_results')->where('submission_id', $submissionId)->value('score_copied'));
        $this->postJson('/api/results/lookup', ['submissionId' => $submissionId])
            ->assertOk()->assertJsonPath('scoreCopied', true)->assertJsonMissingPath('firstTenCopied');
    }

    public function test_non_first_ten_can_be_marked_as_copied_idempotently(): void
    {
        $this->postJson('/api/games', ['mode' => 'daily'])->assertOk();
        $submissionId = (string) Str::uuid();
        $deviceId = (string) Str::uuid();
        $this->withHeader('X-Device-ID', $deviceId);
        $this->postJson('/api/results', $this->getTestResultData([
            'submissionId' => $submissionId,
            'answers' => [true, true, true, true, true, true, true, true, true, false],
        ]))->assertOk()->assertJsonPath('firstTen', false);

        $this->postJson("/api/results/{$submissionId}/mark-copied")->assertOk();
        $this->postJson("/api/results/{$submissionId}/mark-copied")->assertOk();
        $this->assertSame(1, DB::table('game_results')->where('submission_id', $submissionId)->value('score_copied'));
        $this->postJson('/api/results/lookup', ['submissionId' => $submissionId])
            ->assertOk()->assertJsonPath('scoreCopied', true);
        $this->getJson('/api/results/today')->assertOk()->assertJsonPath('scoreCopied', true)
            ->assertJsonMissingPath('firstTenCopied');
        $this->postJson('/api/results/'.Str::uuid().'/mark-copied')->assertNotFound();
    }
}
