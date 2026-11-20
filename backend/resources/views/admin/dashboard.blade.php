<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin dashboard - Asmrandle</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #171922; color: #f4f4f6; font: 16px system-ui, sans-serif; margin: 0; }
        main { max-width: 1100px; margin: auto; padding: 1.5rem; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        button { padding: .6rem 1rem; cursor: pointer; font: inherit; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .stat, section { background: #242735; border-radius: .6rem; padding: 1rem; }
        .stat strong { display: block; font-size: 2rem; }
        section { margin-top: 1.5rem; }
        .muted { color: #c3c6d1; }
        .chart { display: flex; height: 190px; gap: .5rem; align-items: end; border-bottom: 1px solid #aaa; }
        .bar-group { flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; justify-content: end; text-align: center; font-size: .75rem; }
        .bars { display: flex; justify-content: center; align-items: end; height: 145px; gap: 2px; }
        .bar { width: 40%; max-width: 24px; min-height: 0; }
        .regular { background: #8bb9ff; } .hard { background: #efad72; }
        .legend span { display: inline-block; width: .8rem; height: .8rem; margin-left: .8rem; }
        .pairs { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 1rem; }
        .pair { border: 1px solid #555a70; border-radius: .5rem; padding: .8rem; }
        .images { display: flex; gap: .5rem; }
        .card { position: relative; width: 85px; }
        .card img { display: block; width: 100%; border-radius: .3rem; }
        .card span { position: absolute; bottom: .2rem; left: .2rem; right: .2rem; background: rgba(0,0,0,.85); text-align: center; font-size: .8rem; padding: .15rem; }
        .pair p { margin: .4rem 0; }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Admin dashboard</h1><p class="muted">Game day {{ $date }} (06:00 UTC to 06:00 UTC)</p></div>
        <form method="post" action="{{ route('admin.logout', absolute: false) }}">@csrf<button type="submit">Log out</button></form>
    </header>
    <div class="summary">
        <div class="stat">Completed games, all time<strong>{{ number_format($totalGames) }}</strong></div>
        <div class="stat">Daily games today<strong>{{ number_format($results->count()) }}</strong></div>
        <div class="stat">Practice games today<strong>{{ number_format($practiceToday) }}</strong></div>
    </div>
    <section>
        <h2>Today's daily results</h2>
        <p class="muted">Completed games by score. Practice scores are not recorded.</p>
        <p class="legend"><span class="regular"></span> Regular <span class="hard"></span> Hard</p>
        @php
            $maxCount = max(1, max($counts[0]), max($counts[1]));
        @endphp
        <div class="chart" role="img" aria-label="Daily scores: {{ collect(range(0, 10))->map(fn ($score) => $score . ': ' . $counts[0][$score] . ' regular, ' . $counts[1][$score] . ' hard')->implode('; ') }}">
            @foreach (range(0, 10) as $score)
                <div class="bar-group">
                    <div class="bars">
                        <div class="bar regular" title="{{ $counts[0][$score] }} regular" style="height: {{ $counts[0][$score] / $maxCount * 100 }}%"></div>
                        <div class="bar hard" title="{{ $counts[1][$score] }} hard" style="height: {{ $counts[1][$score] / $maxCount * 100 }}%"></div>
                    </div>
                    <div>{{ $score }}</div>
                </div>
            @endforeach
        </div>
    </section>
    <section>
        <h2>Today's daily pairs</h2>
        <p class="muted">Card overlays show EDHRec inclusion at the time the challenge was created. Correct percentages use completed daily games only.</p>
        @if (count($cards) === 0)
            <p>The daily challenge has not been started yet.</p>
        @else
            <div class="pairs">
                @foreach (array_chunk($cards, 2) as $index => $pair)
                    <div class="pair">
                        <h3>Round {{ $index + 1 }}</h3>
                        <div class="images">
                            @foreach ($pair as $card)
                                <div class="card">
                                    <img src="{{ $card['img'] }}" alt="{{ $card['name'] }}" loading="lazy">
                                    <span>{{ number_format($card['popularity'], 1) }}%</span>
                                </div>
                            @endforeach
                        </div>
                        @foreach (['Regular', 'Hard', 'Combined'] as $mode => $label)
                            @php
                                $total = $mode === 2 ? array_sum($modeTotals) : $modeTotals[$mode];
                                $right = $mode === 2 ? $correct[0][$index] + $correct[1][$index] : $correct[$mode][$index];
                            @endphp
                            <p>{{ $label }}: {{ $total ? number_format($right / $total * 100, 1).'%' : 'No results' }} ({{ $right }}/{{ $total }})</p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</main>
</body>
</html>
