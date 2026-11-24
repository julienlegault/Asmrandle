<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RefreshEdhrec extends Command
{
    protected $signature = 'cards:refresh-edhrec {--partial : Refresh a daily sample and recently missing cards}';

    protected $description = 'Refresh EDHRec card popularity, resuming after interrupted runs';

    public function handle(): int
    {
        if (app()->environment('local')) {
            $this->error('Live EDHRec refresh is disabled in local development.');

            return self::FAILURE;
        }

        if (app()->environment('production') && config('cache.default') !== 'database') {
            $this->error('EDHRec refresh requires the shared database cache in production.');

            return self::FAILURE;
        }

        $lock = Cache::lock('edhrec-refresh', 86400);
        if (! $lock->get()) {
            $this->error('Another EDHRec refresh is already running.');

            return self::FAILURE;
        }

        try {
            return $this->refresh();
        } finally {
            $lock->release();
        }
    }

    private function refresh(): int
    {
        $today = now('UTC')->startOfDay();
        $count = 0;
        $failures = 0;
        $checkedMissing = false;
        $query = DB::table('cards')->where('legal', true)
            ->where(fn ($query) => $query->whereNull('edhrec_checked_at')->orWhere('edhrec_checked_at', '<', $today))
            ->orderBy('id');

        if ($this->option('partial')) {
            $sample = $this->dailySample($today->toDateString());
            $query->where(function ($query) use ($sample) {
                $query->whereIn('id', $sample)
                    ->orWhere(function ($query) {
                        $query->whereNull('inclusion')
                            ->orWhereNull('potential_decks')->orWhere('potential_decks', '<=', 0);
                    });
            });
        }

        $query->chunkById(200, function ($cards) use (&$count, &$failures, &$checkedMissing) {
            foreach ($cards as $card) {
                if ($this->option('partial') && $card->edhrec_missing_since !== null &&
                    ($card->inclusion === null || $card->potential_decks === null || $card->potential_decks <= 0) &&
                    Carbon::parse($card->edhrec_missing_since, 'UTC')->addMonthNoOverflow()->lte(now('UTC'))) {
                    continue;
                }
                $hasData = $card->inclusion !== null && $card->potential_decks !== null && $card->potential_decks > 0;
                try {
                    $response = Http::timeout(20)
                        ->withHeaders(['User-Agent' => 'Asmrandle/1.0 (https://asmrandle.com)', 'Accept' => 'application/json'])
                        ->get("https://json.edhrec.com/pages/cards/{$card->slug}.json");
                    usleep(200000);
                    $missingOnS3 = $response->status() === 403 && $response->header('Server') === 'AmazonS3' &&
                        str_contains($response->body(), '<Code>AccessDenied</Code>');
                    if (($missingOnS3 || $response->status() === 404) && $hasData) {
                        $this->error("EDHRec could not find previously available card {$card->slug}; preserving its data.");
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
                        $values = [
                            'inclusion' => null,
                            'potential_decks' => null,
                            'edhrec_missing_since' => $card->edhrec_missing_since ?? now(),
                        ];
                    } else {
                        $response->throw();
                        $info = $response->json('container.json_dict.card');
                        if (! is_array($info) || ! isset($info['num_decks'], $info['potential_decks']) ||
                            ! is_numeric($info['num_decks']) || ! is_numeric($info['potential_decks']) ||
                            $info['num_decks'] < 0 || $info['potential_decks'] <= 0) {
                            throw new RuntimeException("Invalid EDHRec data for {$card->slug}.");
                        }
                        $values = [
                            'inclusion' => (int) $info['num_decks'],
                            'potential_decks' => (int) $info['potential_decks'],
                            'edhrec_missing_since' => null,
                        ];
                    }
                } catch (HttpClientException|RuntimeException $exception) {
                    if (! $hasData) {
                        DB::table('cards')->where('id', $card->id)->update([
                            'edhrec_missing_since' => $card->edhrec_missing_since ?? now(),
                            'edhrec_checked_at' => now(),
                        ]);
                    }

                    throw $exception;
                }
                DB::table('cards')->where('id', $card->id)->update($values + ['edhrec_checked_at' => now()]);
                $count++;
            }
        });
        $this->info("Refreshed {$count} cards.");

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function dailySample(string $date): array
    {
        $sample = DB::table('edhrec_refresh_samples')->where('date', $date)->value('card_ids');
        if ($sample !== null) {
            return json_decode($sample, true, flags: JSON_THROW_ON_ERROR);
        }

        $size = (int) ceil(DB::table('cards')->where('legal', true)->count() / 20);
        $ids = DB::table('cards')->where('legal', true)->whereNotNull('inclusion')
            ->where('potential_decks', '>', 0)->inRandomOrder()->limit($size)->pluck('id')->all();
        DB::table('edhrec_refresh_samples')->insert(['date' => $date, 'card_ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);
        DB::table('edhrec_refresh_samples')->where('date', '<', $date)->delete();

        return $ids;
    }
}
