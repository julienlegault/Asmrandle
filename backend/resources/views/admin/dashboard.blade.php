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
        .bar { width: 30%; max-width: 24px; min-height: 0; }
        .regular { color: #8bb9ff; } .hard { color: #efad72; } .combined { color: #bde89f; }
        .bar, .legend span { background: currentColor; }
        .legend span { display: inline-block; width: .8rem; height: .8rem; margin-left: .8rem; }
        .pairs { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .pair { border: 1px solid #555a70; border-radius: .5rem; padding: .8rem; }
        .images { display: flex; gap: .5rem; }
        .card { position: relative; width: 85px; padding: 0; border: 0; background: transparent; color: white; touch-action: manipulation; }
        .card > img { display: block; width: 100%; border-radius: .3rem; }
        .card .popularity { position: absolute; bottom: .2rem; left: .2rem; right: .2rem; background: rgba(0,0,0,.85); text-align: center; font-size: .8rem; padding: .15rem; }
        .card .preview { display: none; position: fixed; z-index: 10; top: 50%; left: 50%; transform: translate(-50%, -50%); width: min(320px, 75vw); max-height: 85vh; object-fit: contain; border-radius: .6rem; box-shadow: 0 0 0 100vmax rgba(0,0,0,.65); pointer-events: none; }
        .card:focus-visible .preview, .card.is-pressed .preview { display: block; }
        @media (hover: hover) { .card:hover .preview { display: block; } }
        .percentages { display: flex; gap: 1rem; margin-top: .6rem; font-weight: 600; }
        input { font: inherit; max-width: 100%; padding: .4rem; }
        .seeded-form label { display: block; margin: .5rem 0; }
        .seeded-form input[type="text"] { width: min(100%, 32rem); }
        .seeded-picker { display: flex; align-items: end; gap: .5rem; flex-wrap: wrap; }
        .seeded-picker label { flex: 1 1 15rem; }
        .seeded-picker input { width: 100%; }
        .seeded-slots { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .seeded-slot { min-height: 135px; border: 1px dashed #70758c; border-radius: .3rem; padding: .25rem; }
        .seeded-slot.is-target { outline: 3px solid #8bb9ff; }
        .seeded-drag { position: relative; cursor: grab; touch-action: none; user-select: none; }
        .seeded-drag:active { cursor: grabbing; }
        .seeded-drag img { display: block; width: 100%; border-radius: .3rem; pointer-events: none; }
        .seeded-drag .popularity { position: absolute; bottom: .2rem; left: .2rem; right: .2rem; background: rgba(0,0,0,.85); text-align: center; font-size: .8rem; padding: .15rem; }
        .seeded-ghost { position: fixed; z-index: 20; pointer-events: none; opacity: .8; }
        .seeded-controls { display: flex; justify-content: center; gap: .2rem; margin-top: .3rem; }
        .seeded-controls button { padding: .2rem .35rem; font-size: .8rem; }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }
        a { color: #a9caff; }
        .errors { color: #ffb4a9; }
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
    @if (session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors" role="alert">
            <p>Could not save your changes:</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section>
        <h2>Daily Results</h2>
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
        <h2>Daily run</h2>
        @if (count($cards) === 0)
            <p>The daily challenge has not been started yet.</p>
        @else
            <div class="pairs">
                @foreach (array_chunk($cards, 2) as $index => $pair)
                    <div class="pair">
                        <h3>Round {{ $index + 1 }}</h3>
                        <div class="images">
                            @foreach ($pair as $card)
                                <button class="card" type="button" aria-label="{{ $card['name'] }}, EDHRec inclusion {{ number_format($card['popularity'], 1) }}%. Preview card">
                                    <img src="{{ $card['img'] }}" alt="" loading="lazy">
                                    <span class="popularity" aria-hidden="true">{{ number_format($card['popularity'], 1) }}%</span>
                                    <img class="preview" src="{{ $card['img'] }}" alt="" aria-hidden="true" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                        <div class="percentages">
                            @foreach (['Regular', 'Hard', 'Combined'] as $mode => $label)
                                @php
                                    $total = $mode === 2 ? array_sum($modeTotals) : $modeTotals[$mode];
                                    $right = $mode === 2 ? $correct[0][$index] + $correct[1][$index] : $correct[$mode][$index];
                                    $percentage = $total ? number_format($right / $total * 100, 1).'%' : 'N/A';
                                @endphp
                                <span class="{{ strtolower($label) }}" aria-label="{{ $label }} correct: {{ $total ? $percentage : 'No results' }}" title="{{ $label }} correct: {{ $total ? $percentage : 'No results' }}">{{ $percentage }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
    <section id="seeded-editor">
        <h2>{{ $editing ? 'Edit seeded run' : 'Schedule a seeded run' }}</h2>
        <p class="muted">Game dates run from 06:00 UTC to 06:00 UTC. Choose 20 distinct playable cards. Drag cards to swap positions, or use the move buttons. Saved runs cannot be changed after they start.</p>
        <form class="seeded-form" method="post" action="{{ $editing ? route('admin.seeded-runs.update', $source->date, absolute: false) : route('admin.seeded-runs.store', absolute: false) }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <label>Game date <input type="date" name="date" min="{{ $date }}" value="{{ old('date', $editing ? $source->date : '') }}" required></label>
            <div class="seeded-picker">
                <label>Add a card
                    <input id="seeded-card-input" type="text" list="playable-cards" autocomplete="off" placeholder="Search by card name">
                </label>
                <button type="button" id="seeded-add-card">Add card</button>
                <button type="button" id="seeded-fill-missing">Fill missing</button>
            </div>
            <datalist id="playable-cards"></datalist>
            <p id="seeded-message" role="status" aria-live="polite"></p>
            <noscript><p class="errors">JavaScript is required to select and organize cards.</p></noscript>
            <div class="pairs" id="seeded-pairs">
                @foreach (range(0, 9) as $round)
                    <div class="pair">
                        <h3>Round {{ $round + 1 }}</h3>
                        <div class="seeded-slots">
                            @foreach (range(0, 1) as $side)
                                @php $index = $round * 2 + $side; @endphp
                                <div class="seeded-slot" data-index="{{ $index }}">
                                    <input type="hidden" name="cards[{{ $index }}]" value="{{ old('cards.'.$index, $sourceCards[$index]['name'] ?? '') }}">
                                    <div class="seeded-slot-contents"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <label>Optional perfect score: 10 emojis, without spaces
                <input type="text" name="perfect_score_emojis" value="{{ old('perfect_score_emojis', $sourceEmojis) }}" autocomplete="off">
            </label>
            <div class="actions">
                <button type="submit" id="seeded-save" disabled>{{ $editing ? 'Save changes' : 'Save seeded run' }}</button>
                @if ($source) <a href="{{ route('admin.dashboard', absolute: false) }}">Cancel</a> @endif
            </div>
        </form>
    </section>
    <section>
        <h2>Manage seeded runs</h2>
        @forelse ($seededRuns as $run)
            @php
                $runCards = json_decode($run->cards, true, flags: JSON_THROW_ON_ERROR);
                $runEmojis = $run->perfect_score_emojis ? json_decode($run->perfect_score_emojis, true, flags: JSON_THROW_ON_ERROR) : null;
                $editable = $run->started_at === null && $run->date >= $date;
            @endphp
            <details>
                <summary>{{ $run->date }}{{ $editable ? ' (scheduled)' : ' (locked)' }}</summary>
                <ol>
                    @foreach (array_chunk($runCards, 2) as $pair)
                        <li>{{ $pair[0]['name'] }} / {{ $pair[1]['name'] }}</li>
                    @endforeach
                </ol>
                <p>Perfect score: {{ $runEmojis ?? 'Default' }}</p>
                <div class="actions">
                    @if ($editable)
                        <a href="{{ route('admin.dashboard', ['edit' => $run->date], absolute: false) }}#seeded-editor">Edit</a>
                        <form method="post" action="{{ route('admin.seeded-runs.delete', $run->date, absolute: false) }}" onsubmit="return confirm('Delete this seeded run?')">
                            @csrf @method('DELETE')
                            <button type="submit">Delete</button>
                        </form>
                    @endif
                    <a href="{{ route('admin.dashboard', ['copy' => $run->date], absolute: false) }}#seeded-editor">Copy to a new run</a>
                </div>
            </details>
        @empty
            <p>No seeded runs saved.</p>
        @endforelse
    </section>
</main>
<script>
    const seededSlots = [...document.querySelectorAll('.seeded-slot')];
    const seededInput = document.getElementById('seeded-card-input');
    const seededMessage = document.getElementById('seeded-message');
    const seededSuggestions = document.getElementById('playable-cards');
    const playableCards = @json($eligibleCards).map(card => ({
        slug: card.slug,
        name: card.name,
        image: card.image_url,
        popularity: Number(card.inclusion) / Number(card.potential_decks) * 100,
    }));
    const playableBySlug = new Map(playableCards.map(card => [card.slug, card]));
    const savedCards = new Map(@json($sourceCards).map(card => [card.name, card]));
    const seededValues = seededSlots.map(slot => slot.querySelector('input').value);

    function renderSeededCards() {
        seededSlots.forEach((slot, index) => {
            const slug = seededValues[index];
            const contents = slot.querySelector('.seeded-slot-contents');
            slot.querySelector('input').value = slug;
            contents.replaceChildren();
            if (!slug) {
                contents.textContent = 'Empty';
                return;
            }
            const saved = savedCards.get(slug);
            const card = playableBySlug.get(slug) || (saved && {
                name: slug, image: saved.img, popularity: saved.popularity,
            });
            const name = card ? card.name : slug;
            if (card) {
                const drag = document.createElement('div');
                drag.className = 'seeded-drag';
                drag.title = `Drag ${name} to swap positions`;
                const image = document.createElement('img');
                image.src = card.image;
                image.alt = name;
                image.draggable = false;
                const popularity = document.createElement('span');
                popularity.className = 'popularity';
                popularity.textContent = `${card.popularity.toFixed(1)}%`;
                popularity.setAttribute('aria-label', `EDHRec inclusion ${card.popularity.toFixed(1)}%`);
                drag.append(image, popularity);
                contents.append(drag);
            } else {
                contents.textContent = name;
            }
            if (!playableBySlug.has(slug)) {
                const warning = document.createElement('div');
                warning.className = 'errors';
                warning.textContent = 'No longer playable; remove this card.';
                contents.append(warning);
            }
            const controls = document.createElement('div');
            controls.className = 'seeded-controls';
            for (const [label, action, disabled] of [
                ['←', 'earlier', index === 0],
                ['→', 'later', index === seededSlots.length - 1],
                ['×', 'remove', false],
            ]) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.setAttribute('aria-label', `${action === 'remove' ? 'Remove' : `Move ${action}`} ${name}`);
                button.addEventListener('click', () => {
                    if (action === 'remove') {
                        seededValues[index] = '';
                        renderSeededCards();
                        seededInput.focus();
                    } else {
                        const target = index + (action === 'earlier' ? -1 : 1);
                        swapSeededCards(index, target);
                        seededSlots[target].querySelector('.seeded-controls button:not([disabled])')?.focus();
                    }
                });
                controls.append(button);
            }
            contents.append(controls);
        });
        seededMessage.textContent = `${seededValues.filter(Boolean).length} of 20 cards selected.`;
    }

    function swapSeededCards(from, to) {
        [seededValues[from], seededValues[to]] = [seededValues[to], seededValues[from]];
        renderSeededCards();
    }

    function addSeededCard() {
        const search = seededInput.value.trim().toLowerCase();
        const matches = playableCards.filter(card => card.name.toLowerCase() === search);
        if (matches.length !== 1) {
            seededMessage.textContent = 'Choose a playable card from the suggestions.';
        } else if (seededValues.includes(matches[0].slug)) {
            seededMessage.textContent = 'That card is already selected.';
        } else if (!seededValues.includes('')) {
            seededMessage.textContent = 'All 20 slots are filled. Remove a card first.';
        } else {
            seededValues[seededValues.indexOf('')] = matches[0].slug;
            seededInput.value = '';
            seededSuggestions.replaceChildren();
            renderSeededCards();
        }
        seededInput.focus();
    }

    seededInput.addEventListener('input', () => {
        const search = seededInput.value.trim().toLowerCase();
        seededSuggestions.replaceChildren();
        if (!search) return;
        const matches = playableCards.filter(card => card.name.toLowerCase().includes(search)).slice(0, 50);
        for (const card of matches) {
            const option = document.createElement('option');
            option.value = card.name;
            seededSuggestions.append(option);
        }
    });
    document.getElementById('seeded-add-card').addEventListener('click', addSeededCard);
    document.getElementById('seeded-fill-missing').addEventListener('click', () => {
        const missing = seededValues.filter(value => !value).length;
        if (!missing) {
            seededMessage.textContent = 'All 20 slots are already filled.';
            return;
        }
        const selected = new Set(seededValues);
        const candidates = playableCards.filter(card => !selected.has(card.slug));
        if (candidates.length < missing) {
            seededMessage.textContent = 'Not enough distinct playable cards are available to fill the missing slots.';
            return;
        }
        for (let i = 0; i < missing; i++) {
            const randomIndex = i + Math.floor(Math.random() * (candidates.length - i));
            [candidates[i], candidates[randomIndex]] = [candidates[randomIndex], candidates[i]];
            seededValues[seededValues.indexOf('')] = candidates[i].slug;
        }
        renderSeededCards();
        seededMessage.textContent = `Filled ${missing} missing ${missing === 1 ? 'slot' : 'slots'} with random cards.`;
    });
    seededInput.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addSeededCard();
        }
    });
    document.querySelector('.seeded-form').addEventListener('submit', event => {
        if (seededValues.some(value => !value || !playableBySlug.has(value)) || new Set(seededValues).size !== 20) {
            event.preventDefault();
            seededMessage.textContent = 'Select 20 distinct playable cards before saving.';
            seededInput.focus();
        }
    });

    let seededDrag = null;
    document.getElementById('seeded-pairs').addEventListener('pointerdown', event => {
        const image = event.target.closest('.seeded-drag');
        if (!image || seededDrag || event.button !== 0) return;
        event.preventDefault();
        const rect = image.getBoundingClientRect();
        seededDrag = {
            pointerId: event.pointerId, image, from: Number(image.closest('.seeded-slot').dataset.index),
            x: event.clientX, y: event.clientY, width: rect.width, target: null, ghost: null, frame: null,
        };
        image.setPointerCapture(event.pointerId);
    });
    function updateSeededDragTarget() {
        seededDrag.target?.classList.remove('is-target');
        seededDrag.target = document.elementFromPoint(seededDrag.x, seededDrag.y)?.closest('.seeded-slot');
        seededDrag.target?.classList.add('is-target');
    }
    function scrollSeededDrag() {
        if (!seededDrag || !seededDrag.ghost) return;
        const edge = 64;
        const speed = seededDrag.y < edge ? -12 : seededDrag.y > window.innerHeight - edge ? 12 : 0;
        if (speed) {
            window.scrollBy(0, speed);
            updateSeededDragTarget();
        }
        seededDrag.frame = requestAnimationFrame(scrollSeededDrag);
    }
    document.getElementById('seeded-pairs').addEventListener('pointermove', event => {
        if (!seededDrag || event.pointerId !== seededDrag.pointerId) return;
        if (!seededDrag.ghost && Math.hypot(event.clientX - seededDrag.x, event.clientY - seededDrag.y) > 5) {
            seededDrag.ghost = seededDrag.image.cloneNode(true);
            seededDrag.ghost.classList.add('seeded-ghost');
            seededDrag.ghost.style.width = `${seededDrag.width}px`;
            document.body.append(seededDrag.ghost);
            seededDrag.frame = requestAnimationFrame(scrollSeededDrag);
        }
        if (!seededDrag.ghost) return;
        seededDrag.x = event.clientX;
        seededDrag.y = event.clientY;
        seededDrag.ghost.style.left = `${seededDrag.x - seededDrag.width / 2}px`;
        seededDrag.ghost.style.top = `${seededDrag.y - 30}px`;
        updateSeededDragTarget();
    });
    function finishSeededDrag(event, cancelled = false) {
        if (!seededDrag || event.pointerId !== seededDrag.pointerId) return;
        const from = seededDrag.from;
        const to = seededDrag.ghost && seededDrag.target ? Number(seededDrag.target.dataset.index) : null;
        seededDrag.target?.classList.remove('is-target');
        seededDrag.ghost?.remove();
        cancelAnimationFrame(seededDrag.frame);
        seededDrag = null;
        if (!cancelled && to !== null && from !== to) swapSeededCards(from, to);
    }
    document.getElementById('seeded-pairs').addEventListener('pointerup', finishSeededDrag);
    document.getElementById('seeded-pairs').addEventListener('pointercancel', event => finishSeededDrag(event, true));
    document.getElementById('seeded-pairs').addEventListener('lostpointercapture', event => finishSeededDrag(event, true));
    renderSeededCards();
    document.getElementById('seeded-save').disabled = false;

    document.querySelectorAll('.card').forEach(card => {
        let timer;
        let start;
        const cancel = () => {
            clearTimeout(timer);
            timer = null;
            start = null;
            card.classList.remove('is-pressed');
        };
        card.addEventListener('pointerdown', event => {
            if (event.pointerType !== 'touch') return;
            start = { x: event.clientX, y: event.clientY };
            card.setPointerCapture(event.pointerId);
            timer = setTimeout(() => card.classList.add('is-pressed'), 450);
        });
        card.addEventListener('pointermove', event => {
            if (start && Math.hypot(event.clientX - start.x, event.clientY - start.y) > 10) cancel();
        });
        card.addEventListener('pointerup', cancel);
        card.addEventListener('pointercancel', cancel);
        card.addEventListener('lostpointercapture', cancel);
        card.addEventListener('contextmenu', event => event.preventDefault());
    });
</script>
</body>
</html>
