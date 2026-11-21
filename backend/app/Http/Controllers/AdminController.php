<?php

namespace App\Http\Controllers;

use App\Services\GameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    private const LOGOS = ['standard' => 'Standard', 'pride' => 'Pride'];
    private const COMPLETED_GAMES_OFFSET = 17966;

    public function loginForm(): Response
    {
        return response()->view('admin.login')->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:72',
        ]);
        if ($validator->fails()) {
            return redirect()->away(route('admin.login', absolute: false))
                ->withErrors($validator)->onlyInput('username');
        }

        $credentials = $validator->validated();
        $admin = DB::table('admin_accounts')->where('username', $credentials['username'])->first();

        if (strlen($credentials['password']) > 72 || ! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            return redirect()->away(route('admin.login', absolute: false))
                ->withErrors(['username' => 'Invalid username or password.'])->onlyInput('username');
        }

        $request->session()->regenerate();
        $request->session()->put('admin', ['id' => $admin->id, 'password_hash' => $admin->password]);

        return redirect()->away(route('admin.dashboard', absolute: false));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away(route('admin.login', absolute: false));
    }

    public function dashboard(Request $request, GameService $games): Response
    {
        $date = $games->gameDate();
        $start = Carbon::parse($date, 'UTC')->addHours(6);
        $end = $start->copy()->addDay();
        $results = DB::table('game_results')->where('date', $date)->get();
        $practiceToday = DB::table('practice_plays')->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)->count();
        $totalGames = DB::table('game_results')->count() + DB::table('practice_plays')->count() + self::COMPLETED_GAMES_OFFSET;
        $challenge = DB::table('daily_challenges')->where('date', $date)->first();
        $cards = $challenge ? json_decode($challenge->cards, true, flags: JSON_THROW_ON_ERROR) : [];
        $counts = [array_fill(0, 11, 0), array_fill(0, 11, 0)];
        $correct = [array_fill(0, 10, 0), array_fill(0, 10, 0)];
        $modeTotals = [0, 0];
        $seededRuns = DB::table('daily_challenges')->where('seeded', true)->orderByDesc('date')->get();
        $source = null;
        $editing = false;
        if ($request->filled('edit') || $request->filled('copy')) {
            $editing = $request->filled('edit');
            $source = $seededRuns->firstWhere('date', $request->query($editing ? 'edit' : 'copy'));
            abort_unless($source, 404);
            if ($editing && ($source->started_at !== null || $source->date < $date)) {
                abort(409, 'This run has already started or its date has passed.');
            }
        }
        $sourceCards = $source ? json_decode($source->cards, true, flags: JSON_THROW_ON_ERROR) : [];
        $sourceEmojis = $source && $source->perfect_score_emojis
            ? json_decode($source->perfect_score_emojis, true, flags: JSON_THROW_ON_ERROR) : '';
        $eligibleCards = DB::table('cards')->where('legal', true)->whereNotNull('inclusion')
            ->where('potential_decks', '>', 0)->orderBy('name')
            ->get(['slug', 'name', 'image_url', 'inclusion', 'potential_decks']);
        $logos = self::LOGOS;

        foreach ($results as $result) {
            $mode = (int) $result->hard_mode;
            $modeTotals[$mode]++;
            $counts[$mode][$result->score]++;
            foreach (json_decode($result->answers, true, flags: JSON_THROW_ON_ERROR) as $index => $answer) {
                $correct[$mode][$index] += (int) $answer;
            }
        }

        return response()->view('admin.dashboard', compact(
            'date', 'totalGames', 'practiceToday', 'results', 'cards', 'counts', 'correct', 'modeTotals',
            'seededRuns', 'source', 'sourceCards', 'sourceEmojis', 'editing', 'eligibleCards', 'logos',
        ));
    }

    public function saveSeededRun(Request $request, GameService $games, ?string $originalDate = null): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$games->gameDate()],
            'cards' => ['required', 'array', 'size:20'],
            'cards.*' => ['required', 'string', 'distinct', Rule::exists('cards', 'slug')->where(
                fn ($query) => $query->where('legal', true)->whereNotNull('inclusion')->where('potential_decks', '>', 0)
            )],
            'logo' => ['nullable', Rule::in(array_keys(self::LOGOS))],
            'perfect_score_emojis' => ['nullable', 'string', 'max:512', function ($attribute, $value, $fail) {
                $base = '(?![\p{Regional_Indicator}\x{1F3FB}-\x{1F3FF}])[\p{Extended_Pictographic}\p{Emoji_Presentation}]\x{FE0F}?[\x{1F3FB}-\x{1F3FF}]?';
                $emoji = '(?:\p{Regional_Indicator}{2}|[0-9#*]\x{FE0F}?\x{20E3}|'.$base.'(?:\x{200D}'.$base.')*(?:[\x{E0020}-\x{E007E}]+\x{E007F})?)';
                if (! preg_match('/^(?:'.$emoji.'){10}$/u', $value)) {
                    $fail('Enter exactly 10 emojis without spaces, or leave this blank.');
                }
            }],
        ]);
        if (array_keys($data['cards']) !== range(0, 19)) {
            throw ValidationException::withMessages(['cards' => 'Select exactly 20 cards in order.']);
        }

        DB::transaction(function () use ($data, $originalDate, $games) {
            $original = null;
            if ($originalDate !== null) {
                $original = DB::table('daily_challenges')->where('date', $originalDate)->lockForUpdate()->first();
                abort_unless($original && $original->seeded, 404);
                if ($original->started_at !== null || $originalDate < $games->gameDate()) {
                    throw ValidationException::withMessages(['date' => 'This run has already started or its date has passed.']);
                }
            }

            $cardsBySlug = DB::table('cards')->whereIn('slug', $data['cards'])->where('legal', true)
                ->whereNotNull('inclusion')->where('potential_decks', '>', 0)->lockForUpdate()->get()->keyBy('slug');
            if ($cardsBySlug->count() !== 20) {
                throw ValidationException::withMessages(['cards' => 'One or more selected cards are no longer playable.']);
            }
            $snapshot = $games->snapshot(collect($data['cards'])->map(fn ($slug) => $cardsBySlug[$slug]));
            $values = [
                'cards' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'logo' => $data['logo'] ?? null,
                'perfect_score_emojis' => isset($data['perfect_score_emojis']) ? json_encode($data['perfect_score_emojis'], JSON_THROW_ON_ERROR) : null,
                'updated_at' => now(),
            ];
            if ($original && $originalDate === $data['date']) {
                DB::table('daily_challenges')->where('date', $originalDate)->update($values);
            } else {
                $inserted = DB::table('daily_challenges')->insertOrIgnore($values + [
                    'date' => $data['date'], 'seeded' => true, 'created_at' => now(),
                ]);
                if (! $inserted) {
                    throw ValidationException::withMessages(['date' => 'A daily run already exists for this date.']);
                }
                if ($original) {
                    DB::table('daily_challenges')->where('date', $originalDate)->delete();
                }
            }
        }, attempts: 3);

        return redirect()->away(route('admin.dashboard', absolute: false))->with('status', 'Seeded run saved.');
    }

    public function deleteSeededRun(string $date, GameService $games): RedirectResponse
    {
        DB::transaction(function () use ($date, $games) {
            $run = DB::table('daily_challenges')->where('date', $date)->lockForUpdate()->first();
            abort_unless($run && $run->seeded, 404);
            if ($run->started_at !== null || $date < $games->gameDate()) {
                throw ValidationException::withMessages(['date' => 'This run has already started or its date has passed.']);
            }
            DB::table('daily_challenges')->where('date', $date)->delete();
        }, attempts: 3);

        return redirect()->away(route('admin.dashboard', absolute: false))->with('status', 'Seeded run deleted.');
    }
}
