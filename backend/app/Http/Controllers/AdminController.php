<?php

namespace App\Http\Controllers;

use App\Services\GameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
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

    public function dashboard(GameService $games): Response
    {
        $date = $games->gameDate();
        $start = Carbon::parse($date, 'UTC')->addHours(6);
        $end = $start->copy()->addDay();
        $results = DB::table('game_results')->where('date', $date)->get();
        $practiceToday = DB::table('practice_plays')->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)->count();
        $totalGames = DB::table('game_results')->count() + DB::table('practice_plays')->count();
        $challenge = DB::table('daily_challenges')->where('date', $date)->first();
        $cards = $challenge ? json_decode($challenge->cards, true, flags: JSON_THROW_ON_ERROR) : [];
        $counts = [array_fill(0, 11, 0), array_fill(0, 11, 0)];
        $correct = [array_fill(0, 10, 0), array_fill(0, 10, 0)];
        $modeTotals = [0, 0];

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
        ));
    }
}
