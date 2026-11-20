<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Offline development data: every card in the bundled slug list with fake,
 * deterministic EDHRec popularity. No Scryfall or EDHRec requests are made at
 * seed time; the browser lazily loads images from Scryfall's named-card endpoint.
 */
class DevelopmentSeeder extends Seeder
{
    private const POTENTIAL_DECKS = 100000;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Development data is only allowed in the local environment.');
        }

        $list = file_get_contents(base_path('data/formatted_card_list.js'));
        if ($list === false) {
            throw new RuntimeException('Could not read the bundled card list.');
        }
        preg_match_all('/^\s*"([^"]+)"\s*,?\s*$/m', $list, $matches);
        $slugs = array_unique($matches[1]);
        if (count($slugs) < 20) {
            throw new RuntimeException('The bundled card list contains too few cards.');
        }

        DB::transaction(function () use ($slugs) {
            foreach (array_chunk($slugs, 1000) as $chunk) {
                DB::table('cards')->insertOrIgnore(array_map(fn ($slug) => $this->row($slug), $chunk));
            }
        });
    }

    private function row(string $slug): array
    {
        $query = http_build_query(['fuzzy' => str_replace('-', ' ', $slug), 'format' => 'image']);
        $image = "https://api.scryfall.com/cards/named?{$query}&version=normal";
        $art = "https://api.scryfall.com/cards/named?{$query}&version=art_crop";
        // Cubing a stable pseudo-random fraction skews most cards towards low popularity, like real data.
        $fraction = (crc32($slug) % 100000) / 100000;

        return [
            'scryfall_id' => "dev-{$slug}",
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'image_url' => $image,
            'art_url' => $art,
            'inclusion' => 1 + (int) floor($fraction ** 3 * (self::POTENTIAL_DECKS - 1)),
            'potential_decks' => self::POTENTIAL_DECKS,
            'legal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
