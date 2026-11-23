<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GameService
{
    public function gameDate(): string
    {
        return Carbon::now('UTC')->subHours(6)->toDateString();
    }

    public function logoForDate(string $date): string
    {
        return DB::table('daily_challenges')->where('date', $date)->value('logo') ?? 'standard';
    }

    public function selectCards(?string $date = null): array
    {
        $query = DB::table('cards')
            ->where('legal', true)
            ->whereNotNull('inclusion')
            ->where('potential_decks', '>', 0);

        if ($date && substr($date, 5) === '06-01') {
            $slugs = [
                'chandra-torch-of-defiance', 'nissa-who-shakes-the-world', 'ral-zarek',
                'tomik-wielder-of-law', 'rowan-kenrith', 'saheeli-rai',
                'felothar-dawn-of-the-abzan', 'alesha-who-smiles-at-death',
                'heiko-yamazaki-the-general', 'ashiok-nightmare-muse', 'joined-researchers',
                'rapturous-moment', 'hallar-the-firefletcher', 'kynaios-and-tiro-of-meletis',
                'tivit-seller-of-secrets', 'karn-the-great-creator', 'niko-light-of-hope',
                'kirol-attentive-first-year', 'nissa-vastwood-seer', 'chandra-dressed-to-kill',
            ];
            $bySlug = (clone $query)->whereIn('slug', $slugs)->get()->keyBy('slug');
            $cards = collect($slugs)->filter(fn ($slug) => $bySlug->has($slug))->map(fn ($slug) => $bySlug[$slug]);
            if ($cards->count() < 20) {
                Log::warning('Some June 1 challenge cards are unavailable; filling remaining slots from the card pool.', [
                    'missing' => array_values(array_diff($slugs, $bySlug->keys()->all())),
                ]);
                $cards = $cards->concat((clone $query)->whereNotIn('slug', $slugs)
                    ->inRandomOrder()->limit(20 - $cards->count())->get());
            }
        } else {
            $cards = $query->inRandomOrder()->limit(20)->get();
        }

        if ($cards->count() !== 20) {
            throw new RuntimeException('At least 20 playable cards are required.');
        }

        return $this->snapshot($cards);
    }

    public function snapshot(Collection $cards): array
    {
        return $cards->map(fn ($card) => [
            'name' => $card->slug,
            'popularity' => $card->inclusion / $card->potential_decks * 100,
            'img' => $card->image_url,
            'artImg' => $card->art_url,
            'flavorText' => $card->flavor_text ?: 'No flavor text available',
        ])->all();
    }

    public function daily(string $date): array
    {
        return DB::transaction(function () use ($date) {
            $challenge = DB::table('daily_challenges')->where('date', $date)->lockForUpdate()->first();
            if (! $challenge) {
                DB::table('daily_challenges')->insertOrIgnore([
                    'date' => $date,
                    'cards' => json_encode($this->selectCards($date), JSON_THROW_ON_ERROR),
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $challenge = DB::table('daily_challenges')->where('date', $date)->lockForUpdate()->firstOrFail();
            }
            if ($challenge->started_at === null) {
                DB::table('daily_challenges')->where('date', $date)->update(['started_at' => now()]);
            }

            return json_decode($challenge->cards, true, flags: JSON_THROW_ON_ERROR);
        }, attempts: 3);
    }

    public function practice(): string
    {
        $id = (string) Str::uuid();
        DB::table('practice_games')->insert([
            'id' => $id,
            'cards' => json_encode($this->selectCards(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
