<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Browser test data is not allowed in production.');
        }

        $juneCards = [
            'chandra-torch-of-defiance', 'nissa-who-shakes-the-world', 'ral-zarek',
            'tomik-wielder-of-law', 'rowan-kenrith', 'saheeli-rai',
            'felothar-dawn-of-the-abzan', 'alesha-who-smiles-at-death',
            'heiko-yamazaki-the-general', 'ashiok-nightmare-muse', 'joined-researchers',
            'rapturous-moment', 'hallar-the-firefletcher', 'kynaios-and-tiro-of-meletis',
            'tivit-seller-of-secrets', 'karn-the-great-creator', 'niko-light-of-hope',
            'kirol-attentive-first-year', 'nissa-vastwood-seer', 'chandra-dressed-to-kill',
        ];
        $slugs = array_merge($juneCards, array_map(fn ($i) => "test-card-{$i}", range(0, 24)));
        foreach ($slugs as $i => $slug) {
            DB::table('cards')->insert([
                'scryfall_id' => "test-id-{$i}",
                'slug' => $slug,
                'name' => "Test card {$i}",
                'image_url' => 'http://localhost:3000/images/Logo-Play.png',
                'art_url' => 'http://localhost:3000/images/Logo-Play.png',
                'inclusion' => $i + 1,
                'potential_decks' => 100,
                'legal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed codespace test data
        $this->call(CodespaceSeeder::class);
    }
}
