<?php

use App\Services\GameService;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/config', function (GameService $games) {
        $date = $games->gameDate();
        return response()->json([
            'fastModeAvailable' => app()->environment('local'),
            'logo' => $games->logoForDate($date),
            'welcomeText' => DB::table('daily_challenges')->where('date', $date)->where('seeded', true)->value('welcome_text'),
        ])->header('Cache-Control', 'no-store');
    });

    Route::post('/games', function (Request $request, GameService $games) {
        $data = $request->validate(['mode' => 'required|in:daily,practice']);
        $date = $games->gameDate();
        $startedAt = now()->format('Y-m-d\TH:i:s.v\Z');
        if ($data['mode'] === 'daily') {
            $games->daily($date);

            $emojis = DB::table('daily_challenges')->where('date', $date)->value('perfect_score_emojis');

            return ['id' => $date, 'date' => $date, 'logo' => $games->logoForDate($date), 'perfectScoreEmojis' => $emojis ? json_decode($emojis, true, flags: JSON_THROW_ON_ERROR) : null, 'startedAt' => $startedAt];
        }

        return ['id' => $games->practice(), 'date' => $date, 'logo' => $games->logoForDate($date), 'startedAt' => $startedAt];
    });

    Route::get('/games/{id}/rounds/{round}', function (string $id, int $round, GameService $games) {
        abort_unless($round >= 0 && $round < 10, 404);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $id)) {
            abort_unless($id === $games->gameDate(), 404);
            $cards = $games->daily($id);
        } else {
            $game = DB::table('practice_games')->where('id', $id)->first();
            abort_unless($game, 404);
            $cards = json_decode($game->cards, true, flags: JSON_THROW_ON_ERROR);
        }

        return ['cards' => array_slice($cards, $round * 2, 2)];
    });

    Route::get('/community', function (GameService $games) {
        $results = DB::table('game_results')->where('date', $games->gameDate())->get();
        $counts = array_fill(0, 11, 0);
        $hardCounts = array_fill(0, 11, 0);
        $questions = [array_fill(0, 10, 0), array_fill(0, 10, 0)];
        foreach ($results as $result) {
            if ($result->hard_mode) {
                $hardCounts[$result->score]++;
            } else {
                $counts[$result->score]++;
            }
            foreach (json_decode($result->answers, true, flags: JSON_THROW_ON_ERROR) as $index => $correct) {
                $questions[(int) $result->hard_mode][$index] += (int) $correct;
            }
        }

        return ['counts' => $counts, 'hardModeCounts' => $hardCounts, 'questions' => $questions];
    });

    Route::post('/results', function (Request $request, GameService $games, DeviceService $deviceService) {
        $data = $request->validate([
            'mode' => 'required|in:daily,practice',
            'date' => 'required|date_format:Y-m-d',
            'hardMode' => 'required|boolean',
            'answers' => 'required|array|size:10',
            'answers.*' => 'required|boolean',
            'submissionId' => 'required|uuid',
            'durationMs' => 'nullable|integer|min:0|max:2147483647',
            'startedAt' => 'required|date_format:Y-m-d\TH:i:s.v\Z',
        ]);
        if ($data['mode'] === 'practice') {
            DB::table('practice_plays')->insert([
                'created_at' => now(),
                'duration_ms' => $data['durationMs'] ?? null,
            ]);

            return ['firstTen' => false];
        }
        abort_unless($data['date'] === $games->gameDate(), 409, 'The daily challenge has changed.');

        $deviceId = $deviceService->getDeviceId($request);

        return DB::transaction(function () use ($data, $deviceId) {
            $challenge = DB::table('daily_challenges')->where('date', $data['date'])->lockForUpdate()->first();
            abort_unless($challenge, 409, 'The daily challenge has not been started.');
            
            $existing = DB::table('game_results')
                ->where('submission_id', $data['submissionId'])
                ->lockForUpdate()
                ->first();
            
            if ($existing) {
                abort_unless(
                    $existing->date === $data['date']
                    && (bool) $existing->hard_mode === (bool) $data['hardMode']
                    && json_decode($existing->answers, true, flags: JSON_THROW_ON_ERROR) === array_map(boolval(...), $data['answers']),
                    409,
                    'This submission ID belongs to a different result.'
                );

                return ['firstTen' => (bool) $existing->first_ten];
            }
            
            $score = count(array_filter($data['answers']));
            
            // Lock game_results to prevent race condition on first 10 check
            $perfectExists = DB::table('game_results')
                ->where('date', $data['date'])
                ->where('score', 10)
                ->lockForUpdate()
                ->exists();
            
            $firstTen = $score === 10 && !$perfectExists;
            
            DB::table('game_results')->insert([
                'submission_id' => $data['submissionId'],
                'device_id' => $deviceId,
                'date' => $data['date'],
                'hard_mode' => $data['hardMode'],
                'score' => $score,
                'first_ten' => $firstTen,
                'answers' => json_encode($data['answers'], JSON_THROW_ON_ERROR),
                'duration_ms' => $data['durationMs'] ?? null,
                'started_at' => \Illuminate\Support\Carbon::parse($data['startedAt']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['firstTen' => $firstTen];
        }, 3);
    });

    Route::post('/results/{submissionId}/mark-copied', function (string $submissionId) {
        $result = DB::table('game_results')
            ->where('submission_id', $submissionId)
            ->first();
        
        abort_unless($result, 404);

        DB::table('game_results')
            ->where('submission_id', $submissionId)
            ->update(['score_copied' => true]);

        return ['success' => true];
    });

    Route::get('/results/today', function (Request $request, GameService $games, DeviceService $deviceService) {
        $deviceId = $deviceService->getDeviceId($request);
        abort_unless($deviceId, 404, 'No device ID provided.');

        $result = DB::table('game_results')
            ->where('date', $games->gameDate())
            ->where('device_id', $deviceId)
            ->first();

        abort_unless($result, 404, 'No result found for today on this device.');

        $elapsedSeconds = null;
        if ($result->started_at && $result->created_at) {
            $start = \Illuminate\Support\Carbon::parse($result->started_at);
            $end = \Illuminate\Support\Carbon::parse($result->created_at);
            $elapsedSeconds = round(abs($end->diffInMilliseconds($start)) / 1000, 1);
        }

        return [
            'score' => (int) $result->score,
            'hardMode' => (bool) $result->hard_mode,
            'firstTen' => (bool) $result->first_ten,
            'scoreCopied' => (bool) $result->score_copied,
            'answers' => json_decode($result->answers, true),
            'submissionId' => $result->submission_id,
            'date' => $result->date,
            'elapsedSeconds' => $elapsedSeconds,
        ];
    });

    Route::post('/results/lookup', function (Request $request) {
        $data = $request->validate([
            'submissionId' => 'required|uuid',
        ]);

        $result = DB::table('game_results')
            ->where('submission_id', $data['submissionId'])
            ->first();

        abort_unless($result, 404, 'Result not found.');

        $elapsedSeconds = null;
        if ($result->started_at && $result->created_at) {
            $start = \Illuminate\Support\Carbon::parse($result->started_at);
            $end = \Illuminate\Support\Carbon::parse($result->created_at);
            $elapsedSeconds = round(abs($end->diffInMilliseconds($start)) / 1000, 1);
        }

        return [
            'score' => (int) $result->score,
            'hardMode' => (bool) $result->hard_mode,
            'firstTen' => (bool) $result->first_ten,
            'scoreCopied' => (bool) $result->score_copied,
            'answers' => json_decode($result->answers, true),
            'date' => $result->date,
            'elapsedSeconds' => $elapsedSeconds,
        ];
    });
});
