const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function renderDashboard(query = '') {
    const php = `
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        Illuminate\\Support\\Facades\\Artisan::call('migrate', ['--force' => true]);
        view()->share('errors', new Illuminate\\Support\\ViewErrorBag());
        $gameDate = Illuminate\\Support\\Carbon::now('UTC')->subHours(6)->startOfDay();
        $cards = array_fill(0, 20, [
            'name' => 'an-extraordinarily-long-card-name-without-spaces',
            'img' => 'https://example.test/card.jpg', 'popularity' => 50,
        ]);
        foreach (range(-6, 5) as $offset) {
            Illuminate\\Support\\Facades\\DB::table('daily_challenges')->insert([
                'date' => $gameDate->copy()->addDays($offset)->toDateString(),
                'cards' => json_encode($cards), 'seeded' => true,
                'perfect_score_emojis' => json_encode(str_repeat('😀', 10)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([-1, -2] as $offset) {
            Illuminate\\Support\\Facades\\DB::table('game_results')->insert([
                'submission_id' => (string) Illuminate\\Support\\Str::uuid(),
                'date' => $gameDate->copy()->addDays($offset)->toDateString(),
                'hard_mode' => false, 'score' => $offset === -1 ? 10 : 9,
                'first_ten' => $offset === -1, 'score_copied' => true,
                'duration_ms' => $offset === -1 ? 42000 : 65000,
                'answers' => json_encode(array_fill(0, 10, true)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $request = Illuminate\\Http\\Request::create('http://admin.test/admin'.($argv[1] ? '?'.$argv[1] : ''));
        $app->instance('request', $request);
        $response = $app->make(App\\Http\\Controllers\\AdminController::class)->dashboard($request, $app->make(App\\Services\\GameService::class));
        echo $response->getContent();
    `;
    return execFileSync('php', ['-r', php, query], {
        cwd: path.join(__dirname, '../backend'),
        env: {
            ...process.env, APP_ENV: 'testing', APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'array',
        },
        encoding: 'utf8',
    });
}

test('changing dates updates daily results without losing editor state and supports history', async ({ page }) => {
    const todayPage = renderDashboard();
    const today = todayPage.match(/id="date-selector" value="([^"]+)"/)[1];
    const earlier = offset => {
        const date = new Date(`${today}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() - offset);
        return date.toISOString().slice(0, 10);
    };
    const yesterday = earlier(1);
    const older = earlier(2);
    const pages = { [yesterday]: renderDashboard(`date=${yesterday}`), [older]: renderDashboard(`date=${older}`) };
    let fail = false;
    let hold = false;
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    await page.route('http://admin.test/admin**', async route => {
        if (hold) {
            hold = false;
            await pending;
        }
        if (fail) {
            fail = false;
            return route.fulfill({ status: 500, body: 'Server error' });
        }
        const date = new URL(route.request().url()).searchParams.get('date');
        return route.fulfill({ contentType: 'text/html', body: pages[date] || todayPage });
    });
    await page.goto('http://admin.test/admin');
    await page.setViewportSize({ width: 1200, height: 800 });
    const daily = page.locator('.daily-stats');
    const editor = page.locator('#seeded-editor');
    const cards = daily.locator('.daily-summary .stat');
    const labels = ['Daily games', 'Practice games', 'First 10 copied', 'Scores Shared', 'Speedy Runs', 'Fastest Run', 'Average Run Duration'];
    await expect(cards.locator('.stat-label')).toHaveText(labels);
    await expect(cards.locator('strong')).toHaveText(['0', '0', '—', '0', '0', '—', '—']);
    expect((await daily.locator('.daily-summary').boundingBox()).y).toBeLessThan((await daily.locator('.chart').boundingBox()).y);
    await expect(page.locator('.all-time-stats .stat-label')).toHaveText([
        'Completed games, all time', 'Scores Shared', 'Speedy Runs', 'Fastest Run', 'Average Run Duration', 'Days with no perfect 10s',
        'Daily plays per day', 'All-time average score',
    ]);
    await expect(page.locator('.all-time-stats .stat').filter({ hasText: 'Fastest Run' }).locator('strong')).toHaveText('42.000 s');
    await expect(page.locator('.all-time-stats .stat').filter({ hasText: 'Average Run Duration' }).locator('strong')).toHaveText('53.500 s');
    await expect(daily.locator('.chart > .bar-group')).toHaveCount(11);
    await expect(daily.locator('.chart > .bar-group > .bars > .bar.regular')).toHaveCount(11);
    await expect(daily.locator('.chart > .bar-group > .bars > .bar.hard')).toHaveCount(11);
    await expect(daily.locator('.daily-content .pairs .pair')).toHaveCount(10);
    await expect(page.locator('.content-row > .daily-stats + #seeded-editor')).toBeVisible();
    const dailyBox = await daily.boundingBox();
    const editorBox = await editor.boundingBox();
    expect(editorBox.x).toBeGreaterThan(dailyBox.x);
    expect(Math.abs(editorBox.y - dailyBox.y)).toBeLessThan(1);
    await page.locator('#seeded-card-input').fill('unfinished selection');
    await page.evaluate(() => { window.dashboardMarker = true; });
    await page.locator('#date-selector').fill(yesterday);
    await expect(cards.locator('.stat-label')).toHaveText(labels);
    await expect(cards.locator('strong')).toHaveText(['1', '0', '✓', '1', '1', '42.000 s', '42.000 s']);
    await expect(page.locator('.all-time-stats .stat').filter({ hasText: 'Average Run Duration' }).locator('strong')).toHaveText('53.500 s');
    await expect(page.locator('#current-date')).toHaveText(yesterday);
    await expect(page.locator('#seeded-card-input')).toHaveValue('unfinished selection');
    await expect(editor).toBeVisible();
    await expect(daily.locator('.chart > .bar-group')).toHaveCount(11);
    await expect(daily.locator('.daily-content .pairs .pair')).toHaveCount(10);
    expect(await page.evaluate(() => window.dashboardMarker)).toBe(true);

    await page.locator('#date-selector').fill(older);
    await expect(cards.locator('.stat-label')).toHaveText(labels);
    await expect(cards.locator('strong')).toHaveText(['1', '0', '—', '1', '0', '65.000 s', '65.000 s']);
    await page.goBack();
    await expect(page.locator('#date-selector')).toHaveValue(yesterday);
    await expect(cards.locator('strong')).toHaveText(['1', '0', '✓', '1', '1', '42.000 s', '42.000 s']);
    await page.goForward();
    await expect(page.locator('#date-selector')).toHaveValue(older);
    await expect(cards.locator('strong')).toHaveText(['1', '0', '—', '1', '0', '65.000 s', '65.000 s']);

    fail = true;
    await page.locator('#date-selector').fill(today);
    await expect(page.locator('#daily-load-error')).toHaveText('Could not load daily results. Please try again.');
    await expect(page.locator('#date-selector')).toHaveValue(older);
    await expect(page.locator('#seeded-card-input')).toHaveValue('unfinished selection');

    await page.goBack();
    await expect(page.locator('#date-selector')).toHaveValue(yesterday);
    hold = true;
    const request = page.waitForRequest(request => new URL(request.url()).searchParams.get('date') === older);
    await page.locator('#date-selector').fill(older);
    await request;
    try {
        await page.goBack();
        await expect(page.locator('#date-selector')).toHaveValue(today);
        await expect(cards.locator('strong')).toHaveText(['0', '0', '—', '0', '0', '—', '—']);
    } finally {
        release();
    }
    await expect(page.locator('#current-date')).toHaveText(today);
});

test('seeded runs fit on mobile, including expanded matchups', async ({ page }) => {
    const html = renderDashboard();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.setContent(html);
    const rows = page.locator('.manage-runs-row');
    await expect(rows).toHaveCount(10);
    await expect(page.locator('.runs-sort-mobile')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 1 of 2');
    const next = page.getByRole('link', { name: 'Next page' });
    await expect(next).toHaveText('→');
    await expect(next).toHaveCSS('text-decoration-line', 'none');
    await expect(rows.first()).toHaveCSS('border-bottom-width', '2px');
    const locked = page.locator('.manage-runs-row.is-locked').first();
    const scheduled = page.locator('.manage-runs-row:not(.is-locked)').first();
    expect(await locked.evaluate(row => getComputedStyle(row).backgroundColor))
        .not.toBe(await scheduled.evaluate(row => getComputedStyle(row).backgroundColor));
    for (const row of [locked, scheduled]) {
        expect(await row.locator('td').evaluateAll(cells => cells.map(cell => ({
            display: getComputedStyle(cell).display,
            label: getComputedStyle(cell, '::before').content,
        })))).toEqual(Array(6).fill({ display: 'block', label: 'none' }));
        await expect(row.locator('.run-emojis')).toHaveText('😀'.repeat(10));
        await expect(row.locator('.run-logo')).toBeVisible();
        await expect(row.locator('a[title="Copy"]')).toBeVisible();
    }
    await expect(scheduled.locator('a[title="Edit"]')).toBeVisible();

    await locked.getByRole('button', { name: 'View all matchups' }).click();
    await expect(locked.getByRole('button', { name: 'View all matchups' })).toHaveAttribute('aria-expanded', 'true');
    await expect(locked.locator('.run-matchups-list')).toBeVisible();
    await page.addStyleTag({ content: '#date-selector { min-width: 180px; }' });

    await page.setViewportSize({ width: 1200, height: 800 });
    await expect(page.locator('.manage-runs-table thead')).toBeVisible();
    await expect(page.locator('.manage-runs-table th')).toHaveText([
        /Date/, 'Matchups', 'Perfect score', 'Site logo', 'Status', 'Actions',
    ]);
});

test('pagination updates only seeded runs and supports browser history', async ({ page }) => {
    const firstPage = renderDashboard();
    const secondPage = renderDashboard('runs_page=2');
    const ascendingPage = renderDashboard('runs_order=asc&runs_page=1');
    let failNext = false;
    let holdNext = false;
    let releaseNext;
    const pending = new Promise(resolve => { releaseNext = resolve; });
    await page.route('http://admin.test/admin**', async route => {
        const url = new URL(route.request().url());
        if (holdNext) {
            holdNext = false;
            await pending;
        }
        if (failNext) {
            failNext = false;
            await route.fulfill({ status: 500, body: 'Server error' });
        } else {
            await route.fulfill({
                contentType: 'text/html',
                body: url.searchParams.get('runs_order') === 'asc'
                    ? ascendingPage : url.searchParams.get('runs_page') === '2' ? secondPage : firstPage,
            });
        }
    });
    await page.goto('http://admin.test/admin');
    await page.locator('#seeded-card-input').fill('unfinished selection');
    await page.evaluate(() => { window.dashboardMarker = true; });
    const firstDate = await page.locator('.manage-runs-row').first().getAttribute('data-date');

    holdNext = true;
    await page.getByRole('link', { name: 'Next page' }).click();
    try {
        await expect(page.getByRole('status').filter({ hasText: 'Loading seeded runs…' })).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: 'Loading seeded runs…' })).toBeInViewport();
        await expect(page.locator('.runs-content')).toHaveAttribute('aria-busy', 'true');
        await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 1 of 2');
    } finally {
        releaseNext();
    }
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 2 of 2');
    await expect(page.locator('#runs-loading')).toBeHidden();
    await expect(page.locator('.runs-content')).not.toHaveAttribute('aria-busy');
    await expect(page.locator('.manage-runs-row')).toHaveCount(2);
    await expect(page.locator('#seeded-card-input')).toHaveValue('unfinished selection');
    expect(await page.evaluate(() => window.dashboardMarker)).toBe(true);
    expect(await page.locator('.manage-runs-row').first().getAttribute('data-date')).not.toBe(firstDate);
    const toggle = page.locator('.manage-runs-row').first().getByRole('button', { name: 'View all matchups' });
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');

    await page.goBack();
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 1 of 2');
    await expect(page.locator('.manage-runs-row').first()).toHaveAttribute('data-date', firstDate);
    await page.goForward();
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 2 of 2');
    await expect(page.locator('#seeded-card-input')).toHaveValue('unfinished selection');

    failNext = true;
    await page.getByRole('link', { name: 'Previous page' }).click();
    await expect(page.getByRole('alert')).toHaveText('Could not load seeded runs. Please try again.');
    await expect(page.locator('#runs-loading')).toBeHidden();
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 2 of 2');
    await page.getByRole('link', { name: 'Previous page' }).click();
    await expect(page.getByRole('navigation', { name: 'Seeded runs pages' })).toContainText('Page 1 of 2');
    await expect(page.getByRole('alert')).toBeHidden();
    await page.locator('#sort-date').click();
    await expect(page.locator('.manage-runs-table th.sortable')).toHaveAttribute('aria-sort', 'ascending');
    expect(await page.locator('.manage-runs-row').first().getAttribute('data-date')).not.toBe(firstDate);
    await expect(page.locator('#seeded-card-input')).toHaveValue('unfinished selection');
});
