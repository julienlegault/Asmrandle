<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RefreshEdhrec extends Command
{
    protected $signature = 'cards:refresh-edhrec';

    protected $description = 'Refresh EDHRec card popularity, resuming after interrupted runs';

    public function handle(): int
    {
        if (app()->environment('local')) {
            $this->error('Live EDHRec refresh is disabled in local development.');

            return self::FAILURE;
        }

        $today = now('UTC')->startOfDay();
        $count = 0;
        $failures = 0;
        $checkedMissing = false;
        DB::table('cards')->where('legal', true)
            ->where(fn ($query) => $query->whereNull('edhrec_checked_at')->orWhere('edhrec_checked_at', '<', $today))
            ->orderBy('id')->chunkById(200, function ($cards) use (&$count, &$failures, &$checkedMissing) {
                foreach ($cards as $card) {
                    $response = Http::timeout(20)
                        ->withHeaders(['User-Agent' => 'Asmrandle/1.0 (https://asmrandle.com)', 'Accept' => 'application/json'])
                        ->get("https://json.edhrec.com/pages/cards/{$card->slug}.json");
                    usleep(200000);
                    $missingOnS3 = $response->status() === 403 && $response->header('Server') === 'AmazonS3' &&
                        str_contains($response->body(), '<Code>AccessDenied</Code>');
                    if ($missingOnS3 && $card->inclusion !== null) {
                        $this->error("EDHRec denied access to previously available card {$card->slug}; preserving its data.");
                        $failures++;

                        continue;
                    }
                    if ($missingOnS3 && ! $checkedMissing) {
                        $probe = Http::timeout(20)
                            ->withHeaders(['User-Agent' => 'Asmrandle/1.0 (https://asmrandle.com)', 'Accept' => 'application/json'])
                            ->get('https://json.edhrec.com/pages/cards/sol-ring.json')->throw();
                        usleep(200000);
                        if (! is_numeric($probe->json('container.json_dict.card.potential_decks'))) {
                            throw new RuntimeException('EDHRec availability check returned invalid data.');
                        }
                        $checkedMissing = true;
                    }
                    if ($response->status() === 404 || $missingOnS3) {
                        $values = ['inclusion' => null, 'potential_decks' => null];
                    } else {
                        $response->throw();
                        $info = $response->json('container.json_dict.card');
                        if (! is_array($info) || ! isset($info['num_decks'], $info['potential_decks']) ||
                            ! is_numeric($info['num_decks']) || ! is_numeric($info['potential_decks']) ||
                            $info['num_decks'] < 0 || $info['potential_decks'] <= 0) {
                            throw new RuntimeException("Invalid EDHRec data for {$card->slug}.");
                        }
                        $values = ['inclusion' => (int) $info['num_decks'], 'potential_decks' => (int) $info['potential_decks']];
                    }
                    DB::table('cards')->where('id', $card->id)->update($values + ['edhrec_checked_at' => now()]);
                    $count++;
                }
            });
        $this->info("Refreshed {$count} cards.");

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
