<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImportScryfall extends Command
{
    protected $signature = 'cards:import {--seed-random : Seed missing popularity values for local development}';

    protected $description = 'Import Scryfall oracle cards into the database';

    public function handle(): int
    {
        if ($this->option('seed-random') && ! app()->environment('local')) {
            $this->error('Random popularity data is only allowed in the local environment.');

            return self::FAILURE;
        }

        if (app()->environment('production') && config('cache.default') !== 'database') {
            $this->error('Scryfall import requires the shared database cache in production.');

            return self::FAILURE;
        }

        $lock = Cache::lock('scryfall-import', 86400);
        if (! $lock->get()) {
            $this->error('Another Scryfall import is already running.');

            return self::FAILURE;
        }

        try {
            return $this->downloadAndImport();
        } finally {
            $lock->release();
        }
    }

    private function downloadAndImport(): int
    {
        $headers = ['User-Agent' => 'Asmrandle/1.0 (https://asmrandle.com)', 'Accept' => 'application/json'];
        $bulk = Http::withHeaders($headers)->timeout(30)->get('https://api.scryfall.com/bulk-data')->throw()->json('data');
        $oracle = collect($bulk)->firstWhere('type', 'oracle_cards');
        if (empty($oracle['jsonl_download_uri'])) {
            throw new RuntimeException('Scryfall did not provide an oracle cards JSONL download.');
        }

        $path = tempnam(sys_get_temp_dir(), 'asmrandle-cards-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary card download.');
        }
        try {
            Http::withHeaders($headers)->timeout(600)->sink($path)->get($oracle['jsonl_download_uri'])->throw();
            if (isset($oracle['compressed_size']) && filesize($path) !== (int) $oracle['compressed_size']) {
                throw new RuntimeException('The Scryfall download has an unexpected size.');
            }
            $stream = gzopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the Scryfall download.');
            }
            try {
                $this->import($stream);
            } finally {
                gzclose($stream);
            }
        } finally {
            unlink($path);
        }
        $this->info('Scryfall card import complete.');

        return self::SUCCESS;
    }

    private function slug(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\- ]+/', '', $name);

        return preg_replace('/\s+/', '-', trim($name));
    }

    private function import($stream): void
    {
        $list = file_get_contents(base_path('data/formatted_card_list.js'));
        if ($list === false) {
            throw new RuntimeException('Could not read the existing card list.');
        }
        preg_match_all('/^\s*"([^"]+)"\s*,?\s*$/m', $list, $matches);
        $known = array_fill_keys($matches[1], true);
        $excluded = array_fill_keys(['cleanse', 'crusade', 'jihad', 'imprison', 'invoke-prejudice', 'pradesh-gypsies', 'stone-throwing-devils'], true);
        $seen = [];
        $count = 0;
        DB::transaction(function () use ($stream, $known, $excluded, &$seen, &$count) {
            DB::table('cards')->update(['legal' => false]);
            $batch = [];
            while (($line = gzgets($stream)) !== false) {
                $card = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $name = $card['name'] ?? '';
                $firstName = trim(explode('//', $name)[0]);
                $first = $this->slug($firstName);
                $combined = $this->slug(str_replace('//', '', $name));
                $slug = isset($known[$combined]) ? $combined : $first;
                $image = $card['image_uris']['normal'] ?? $card['card_faces'][0]['image_uris']['normal'] ?? null;
                $art = $card['image_uris']['art_crop'] ?? $card['card_faces'][0]['image_uris']['art_crop'] ?? $image;
                if (($card['legalities']['commander'] ?? null) !== 'legal' || ! $image || ! $slug || isset($excluded[$slug]) || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $batch[] = [
                    'scryfall_id' => $card['oracle_id'] ?? $card['id'],
                    'slug' => $slug,
                    'name' => $name,
                    'image_url' => $image,
                    'art_url' => $art,
                    'flavor_text' => $card['flavor_text'] ?? $card['card_faces'][0]['flavor_text'] ?? null,
                    'legal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $count++;
                if (count($batch) === 500) {
                    $this->saveBatch($batch);
                    $batch = [];
                }
            }
            if (! gzeof($stream) || $count < 20) {
                throw new RuntimeException('Scryfall download was incomplete or contained too few playable cards.');
            }
            $this->saveBatch($batch);
            if ($this->option('seed-random')) {
                DB::table('cards')->where('legal', true)->whereNull('inclusion')->orderBy('id')->chunkById(500, function ($cards) {
                    foreach ($cards as $card) {
                        DB::table('cards')->where('id', $card->id)->update([
                            'inclusion' => random_int(1, 100000),
                            'potential_decks' => 100000,
                        ]);
                    }
                });
            }
        }, 1);
        $this->info("Imported {$count} legal cards.");
    }

    private function saveBatch(array $batch): void
    {
        if ($batch) {
            DB::table('cards')->upsert($batch, ['scryfall_id'], ['slug', 'name', 'image_url', 'art_url', 'flavor_text', 'legal', 'updated_at']);
        }
    }
}
