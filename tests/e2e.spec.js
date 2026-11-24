const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const BASE_URL = process.env.ASMRANDLE_BASE_URL || 'http://localhost:8000';

test.describe('Asmrandle E2E Tests', () => {

    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelector('#fast-mode').checked = true;
            });
        });
        // Avoid depending on the image CDN in browser tests.
        await page.route('**/cards.scryfall.io/**', route =>
            route.fulfill({
                status: 200,
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="140"/>',
            })
        );
        await page.route('**/api/community', route =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    counts: Array(11).fill(5),
                    hardModeCounts: Array(11).fill(5),
                    questions: [Array(10).fill(5), Array(10).fill(5)],
                }),
            })
        );

    });

    test('Fast Mode is optional and changes the card delay', async ({ page }) => {
        await page.goto(BASE_URL);
        await expect(page.locator('#fast-mode-setting')).toBeVisible();
        await expect(page.getByRole('switch', { name: 'Fast Mode (development)' })).toBeChecked();
        expect(await page.locator('#main-menu #fast-mode-setting').count()).toBe(0);
        const bounds = await page.locator('#fast-mode-setting').boundingBox();
        expect(bounds.y).toBeLessThan(30);
        expect(bounds.x).toBeLessThan(30);
        await page.uncheck('#fast-mode');
        await page.click('#play-random');
        await expect(page.locator('#fast-mode-setting')).toBeVisible();
        await page.waitForSelector('.card img');

        let started = Date.now();
        await page.click('.card:first-child');
        await page.waitForSelector('.overlay', { state: 'hidden' });
        expect(Date.now() - started).toBeGreaterThanOrEqual(2600);

        await page.reload();
        await expect(page.locator('#fast-mode-setting')).toBeVisible();
        await page.check('#fast-mode');
        await page.click('#play-random');
        await page.waitForSelector('.card img');
        started = Date.now();
        await page.click('.card:first-child');
        await page.waitForSelector('.overlay', { state: 'hidden' });
        const fastElapsed = Date.now() - started;
        expect(fastElapsed).toBeGreaterThanOrEqual(150);
        expect(fastElapsed).toBeLessThan(1500);
    });

    test('Fast Mode is hidden when unavailable', async ({ page }) => {
        await page.route('**/api/config', route => route.fulfill({ json: { fastModeAvailable: false, logo: 'standard' } }));
        await page.goto(BASE_URL);
        await expect(page.locator('#fast-mode-setting')).toBeHidden();
    });

    test.afterEach(async ({ page }, testInfo) => {
        if (testInfo.status !== testInfo.expectedStatus) {
            // Get a unique place for the screenshot.
            const screenshotPath = testInfo.outputPath(`failure.png`);
            // Add it to the report.
            testInfo.attachments.push({ name: 'screenshot', path: screenshotPath, contentType: 'image/png' });
            // Take the screenshot itself.
            await page.screenshot({ path: screenshotPath, timeout: 5000 });
        }
    });

    test('Homepage loads correctly', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Check that main menu buttons are present
        await expect(page.locator('#start-daily')).toBeVisible();
        await expect(page.locator('#play-random')).toBeVisible();
        
        // Check footer elements
        await expect(page.locator('.footer')).toBeVisible();
        await expect(page.locator('#linkedin-link img')).toBeVisible(); // LinkedIn icon
        await expect(page.locator('#github-link img')).toBeVisible(); // Github icon
    });

    test('Game-day logo is used on the menu and in practice, and updates at rollover', async ({ page }) => {
        await page.clock.install({ time: new Date('2026-03-16T05:59:58Z') });
        let logo = 'pride';
        await page.route('**/api/config', route => route.fulfill({ json: { fastModeAvailable: false, logo } }));
        await page.route('**/api/games', route => route.fulfill({ json: { id: 'practice', date: '2026-03-15', logo } }));
        await page.route('**/api/games/*/rounds/*', route => route.fulfill({ json: { cards: [
            { name: 'first', popularity: 80, img: '/images/Logo-Play.png', artImg: '', flavorText: '' },
            { name: 'second', popularity: 20, img: '/images/Logo-Play.png', artImg: '', flavorText: '' },
        ] } }));
        await page.goto(BASE_URL);
        const image = page.locator('#main-logo');
        await expect(image).toHaveAttribute('src', '/images/Logo-Splash-Pride.png');
        await page.click('#play-random');
        await expect(image).toHaveAttribute('src', '/images/Logo-Play-Pride.png');
        await expect(page.locator('#game')).toBeVisible();
        logo = 'standard';
        await page.clock.fastForward(3000);
        await expect(image).toHaveAttribute('src', '/images/Logo-Play.png');
    });

    test('Daily game applies a logo scheduled after the page was opened', async ({ page }) => {
        const date = new Date(Date.now() - 6 * 60 * 60 * 1000).toISOString().slice(0, 10);
        await page.route('**/api/config', route => route.fulfill({ json: { fastModeAvailable: false, logo: 'standard' } }));
        await page.route('**/api/games', route => route.fulfill({ json: { id: date, date, logo: 'pride', perfectScoreEmojis: null } }));
        await page.route('**/api/games/*/rounds/*', route => route.fulfill({ json: { cards: [
            { name: 'first', popularity: 80, img: '/images/Logo-Play.png', artImg: '', flavorText: '' },
            { name: 'second', popularity: 20, img: '/images/Logo-Play.png', artImg: '', flavorText: '' },
        ] } }));
        await page.goto(BASE_URL);
        const image = page.locator('#main-logo');
        await expect(image).toHaveAttribute('src', '/images/Logo-Splash.png');
        await page.click('#start-daily');
        await expect(image).toHaveAttribute('src', '/images/Logo-Play-Pride.png');
        await expect(page.locator('#game')).toBeVisible();
    });

    test('Daily game button functionality', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Click daily game button
        await page.click('#start-daily');
        
        // Wait for game to load
        await page.waitForSelector('#game', { timeout: 10000 });
        
        // Check that game area is visible and main menu is hidden
        await expect(page.locator('#main-menu')).toBeHidden();
        await expect(page.locator('#game')).toBeVisible();
        
        // Check that scoreboard dots are present
        await expect(page.locator('.scoreboard .dot')).toHaveCount(10);
    });

    test('Custom perfect score emojis are shared for first ten and replay, but not imperfect scores', async ({ page }) => {
        const pattern = '🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧';
        const date = new Date(Date.now() - 6 * 60 * 60 * 1000).toISOString().slice(0, 10);
        await page.route('**/api/games', route => route.fulfill({ json: { id: date, date, logo: 'standard', perfectScoreEmojis: pattern } }));
        await page.route('**/api/games/*/rounds/*', route => route.fulfill({ json: { cards: [
            { name: 'first', popularity: 80, img: '/images/test.png', artImg: '/images/test.png', flavorText: '' },
            { name: 'second', popularity: 20, img: '/images/test.png', artImg: '/images/test.png', flavorText: '' },
        ] } }));
        await page.route('**/api/results', route => route.fulfill({ json: { firstTen: true } }));
        await page.goto(BASE_URL);
        await page.click('#start-daily');
        for (let i = 0; i < 10; i++) {
            await page.locator('.card').first().click();
            await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        }
        await expect(page.locator('#result-text')).toContainText("Today's first 10");
        expect(await page.evaluate(() => resultToCopy)).toContain(pattern);

        await page.reload();
        await page.click('#start-daily');
        await expect(page.locator('#result')).toBeVisible();
        await expect(page.locator('#result-text')).toContainText("Today's first 10");
        expect(await page.evaluate(() => resultToCopy)).toContain(pattern);

        await page.context().addCookies([{
            name: date.replaceAll('-', ''), value: 'false,true,true,true,true,true,true,true,true,true|9',
            url: BASE_URL,
        }]);
        await page.reload();
        await page.click('#start-daily');
        await expect(page.locator('#result')).toBeVisible();
        expect(await page.evaluate(() => resultToCopy)).toContain('🟥🟩🟩🟩🟩🟩🟩🟩🟩🟩');
    });

    test('Practice game button functionality', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Click practice button
        await page.click('#play-random');
        
        // Wait for game to load
        await page.waitForSelector('#game', { timeout: 10000 });
        
        // Check that game area is visible and main menu is hidden
        await expect(page.locator('#main-menu')).toBeHidden();
        await expect(page.locator('#game')).toBeVisible();
        
        // Check that scoreboard dots are present
        await expect(page.locator('.scoreboard .dot')).toHaveCount(10);
    });

    test('Game cards load and are clickable', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Start a practice game
        await page.click('#play-random');
        
        // Wait for cards to load
        await page.waitForSelector('.card img', { timeout: 15000 });
        
        // Check that exactly 2 cards are present
        await expect(page.locator('.card')).toHaveCount(2);
        
        // Check that cards have images
        const cardImages = page.locator('.card img');
        await expect(cardImages).toHaveCount(2);
        
        // Verify images have src attributes (cards loaded)
        const firstCard = cardImages.first();
        const firstCardSrc = await firstCard.getAttribute('src');
        expect(firstCardSrc).toBeTruthy();
        expect(firstCardSrc).toContain('http'); // Should be a valid URL
    });

    test('Card click functionality and game progression', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Start a practice game
        await page.click('#play-random');
        
        // Wait for cards to load
        await page.waitForSelector('.card img', { timeout: 15000 });
        
        // Click on the first card
        await page.click('.card:first-child');
        
        // Wait for overlay to appear and disappear
        await page.waitForSelector('.overlay', { timeout: 5000 });
        await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        
        // Check that first dot in scoreboard has changed color (red or green)
        const firstDot = page.locator('.scoreboard .dot:first-child');
        const backgroundColor = await firstDot.evaluate(el => getComputedStyle(el).backgroundColor);
        expect(backgroundColor).not.toBe('rgb(128, 128, 128)'); // Should not be gray anymore
    });

    test('Cookie retrieved correctly and used', async ({ page }) => {
        await page.goto(BASE_URL);

            // Use a UTC date offset by 6 hours so the daily challenge resets at 6:00 UTC
    const now = new Date();
    const adjustedNow = new Date(now.getTime() - 6 * 60 * 60 * 1000);
    const yyyy = adjustedNow.getUTCFullYear().toString();
    const mm = String(adjustedNow.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(adjustedNow.getUTCDate()).padStart(2, '0');
    const todaysDate = `${yyyy}${mm}${dd}`;

        // Set daily cookie to a known value
        await page.context().addCookies([{
            name: todaysDate,
            value: 'true,true,true,true,true,false,false,false,false,false|5',
            domain: 'localhost',
            path: '/'
        }]);

        // Reload page to pick up cookie
        await page.reload();

        // Start daily game
        await page.click('#start-daily');

        await page.waitForSelector('#result', { timeout: 20000 });

        // Check that results reflect cookie values
        const resultsText = await page.locator('#result').innerText();
        expect(resultsText).toContain("You have already played today's Asmrandle! Your score was 5/10.");
    });

    test('Play full game', async ({ page }) => {
        test.setTimeout(2 * 60 * 1000); // Extend timeout to 2 minutes
        await page.goto(BASE_URL);
        
        // Start a practice game
        await page.click('#play-random');
        
        // Wait for game to load
        await page.waitForSelector('#game', { timeout: 10000 });
        
        for (let i = 0; i < 10; i++) {
            // Wait for cards to load
            await page.waitForSelector('.card img', { timeout: 15000 });
            
            // Click on the first card
            await page.click('.card:first-child');
            
            // Wait for overlay to appear and disappear
            await page.waitForSelector('.overlay', { state: 'visible', timeout: 2000 });
            await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        }
        
        // After 10 cards, check that results are shown
        await page.waitForSelector('#result', { timeout: 20000 });
        const resultsText = await page.locator('#result').innerText();
        expect(resultsText).toMatch(/\d+\/10/); // Should show score out of 10
    });

    test('Daily results are saved and included in community totals', async ({ page }) => {
        await page.unroute('**/api/community');
        await page.goto(BASE_URL);
        const before = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        const total = data => [...data.counts, ...data.hardModeCounts].reduce((sum, count) => sum + count, 0);
        await page.click('#start-daily');
        for (let i = 0; i < 10; i++) {
            await page.waitForSelector('.card img');
            await page.click('.card:first-child');
            await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        }
        await expect(page.locator('#result')).toBeVisible();
        await expect(page.locator('#community-results')).toBeVisible();
        const after = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        expect(total(after)).toBe(total(before) + 1);
    });

    test('Hard mode results are recorded and shown after finishing and replaying', async ({ page }) => {
        await page.unroute('**/api/community');
        await page.goto(BASE_URL);
        const game = await (await page.request.post(`${BASE_URL}/api/games`, { data: { mode: 'daily' } })).json();
        const oldSubmissionId = await page.evaluate(() => {
            const id = crypto.randomUUID();
            sessionStorage.setItem(`submission-${dateStrSanatized}`, id);
            return id;
        });
        const oldResult = await page.request.post(`${BASE_URL}/api/results`, {
            data: {
                mode: 'daily', date: game.date, hardMode: false,
                answers: Array(10).fill(true), submissionId: oldSubmissionId,
            },
        });
        expect(oldResult.ok()).toBeTruthy();
        const before = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        await page.check('#hard-mode');
        await page.click('#start-daily');
        for (let i = 0; i < 10; i++) {
            await page.waitForSelector('.card img');
            const choice = await page.evaluate(() =>
                cardData[currentPair * 2].popularity >= cardData[currentPair * 2 + 1].popularity ? 0 : 1
            );
            await page.locator('.card').nth(choice).click();
            await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        }

        await expect(page.locator('#result-text')).toContainText('10');
        const after = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        expect(after.hardModeCounts[10]).toBe(before.hardModeCounts[10] + 1);
        expect(after.questions[1]).toEqual(before.questions[1].map(count => count + 1));
        const hardPlayers = after.hardModeCounts.reduce((sum, count) => sum + count, 0);
        const expected = `${Math.min(100, (after.questions[1][0] / hardPlayers * 100).toFixed(1))}% of hard mode players got this question correct`;
        await expect(page.locator('.question-correct-pct').first()).toHaveText(expected);
        await page.click('.community-header');
        await expect(page.locator('.bar-item').nth(10).locator('.bar-fill-hard-user')).toBeVisible();

        await page.reload();
        await page.click('#start-daily');
        await expect(page.locator('.question-correct-pct').first()).toHaveText(expected);
        await page.click('.community-header');
        await expect(page.locator('.bar-item').nth(10).locator('.bar-fill-hard-user')).toBeVisible();
    });

    test('Retrying a lost result response does not duplicate the hard-mode play', async ({ page }) => {
        await page.unroute('**/api/community');
        await page.goto(BASE_URL);
        const before = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        const submissions = [];
        await page.route('**/api/results', async route => {
            submissions.push(route.request().postDataJSON().submissionId);
            if (submissions.length === 1) {
                await route.fetch();
                await route.abort();
            } else {
                await route.continue();
            }
        });
        await page.check('#hard-mode');
        await page.click('#start-daily');
        for (let i = 0; i < 10; i++) {
            await page.waitForSelector('.card img');
            await page.locator('.card').first().click();
            await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
        }
        await page.getByRole('button', { name: 'Retry' }).click();
        await expect(page.locator('#result')).toBeVisible();
        expect(submissions).toHaveLength(2);
        expect(submissions[1]).toBe(submissions[0]);
        const after = await (await page.request.get(`${BASE_URL}/api/community`)).json();
        const total = counts => counts.reduce((sum, count) => sum + count, 0);
        expect(total(after.hardModeCounts)).toBe(total(before.hardModeCounts) + 1);
    });

    test('Hard mode cookie retrieved correctly and used', async ({ page, context }) => {
        // Grant clipboard permissions
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto(BASE_URL);

        // Use a UTC date offset by 6 hours so the daily challenge resets at 6:00 UTC
        const now = new Date();
        const adjustedNow = new Date(now.getTime() - 6 * 60 * 60 * 1000);
        const yyyy = adjustedNow.getUTCFullYear().toString();
        const mm = String(adjustedNow.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(adjustedNow.getUTCDate()).padStart(2, '0');
        const todaysDate = `${yyyy}${mm}${dd}`;

        // Set daily cookie to a known value
        await page.context().addCookies([{
            name: todaysDate,
            value: 'true,true,true,true,true,false,false,false,false,false|5|HM',
            domain: 'localhost',
            path: '/'
        }]);

        // Reload page to pick up cookie
        await page.reload();

        // Start daily game
        await page.click('#start-daily');

        await page.waitForSelector('#result', { timeout: 10000 });

        // Check that results reflect cookie values
        const resultsText = await page.locator('#result').innerText();
        expect(resultsText).toContain("You have already played today's Asmrandle! Your score was 5/10.");
        // Check the copy indicates hard mode
        await page.click('#copy-results');
        const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
        expect(clipboardText).toContain('| Hard Mode |');
    });

    test('copying a non-first daily score records only successful clipboard writes', async ({ page }) => {
        const date = new Date(Date.now() - 6 * 60 * 60 * 1000).toISOString().slice(0, 10);
        const submissionId = 'c0d0ab90-6ed0-491b-8447-000000000001';
        let marks = 0;
        await page.addInitScript(() => {
            window.failCopy = true;
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: async () => { if (window.failCopy) throw new Error('denied'); } },
            });
        });
        await page.route(BASE_URL + '/', route => route.fulfill({
            contentType: 'text/html',
            body: fs.readFileSync(path.join(__dirname, '../frontend/index.html'), 'utf8'),
        }));
        await page.route('**/api/**', route => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === '/api/config') return route.fulfill({ json: { fastModeAvailable: true, logo: 'standard' } });
            if (pathname === '/api/games') return route.fulfill({ json: { id: date, date, logo: 'standard' } });
            if (pathname === '/api/results/today') return route.fulfill({ json: {
                firstTen: false, hardMode: false, answers: Array(10).fill(false), submissionId,
            } });
            if (pathname.includes('/rounds/')) return route.fulfill({ json: { cards: [
                { name: 'one', popularity: 80, img: '/images/Logo-Play.png' },
                { name: 'two', popularity: 20, img: '/images/Logo-Play.png' },
            ] } });
            if (pathname === '/api/community') return route.fulfill({ json: {
                counts: Array(11).fill(0), hardModeCounts: Array(11).fill(0), questions: [Array(10).fill(0), Array(10).fill(0)],
            } });
            if (pathname === `/api/results/${submissionId}/mark-copied`) {
                marks++;
                return marks === 1 ? route.fulfill({ status: 500 }) : route.fulfill({ json: { success: true } });
            }
            return route.fulfill({ status: 404 });
        });
        await page.goto(BASE_URL);
        await page.click('#start-daily');
        await expect(page.locator('#result')).toBeVisible();
        await page.click('#copy-results');
        await expect(page.locator('#copy-error')).toContainText('Could not copy');
        expect(marks).toBe(0);
        await page.evaluate(() => { window.failCopy = false; });
        await page.click('#copy-results');
        await expect(page.locator('#copy-error')).toContainText('sharing could not be recorded');
        await page.click('#copy-results');
        await expect(page.locator('#copy-error')).toHaveText('');
        await expect.poll(() => marks).toBe(2);
    });

    test('Community results page loads correctly', async ({ page }) => {
        await page.goto(BASE_URL);

        // Use a UTC date offset by 6 hours so the daily challenge resets at 6:00 UTC
        const now = new Date();
        const adjustedNow = new Date(now.getTime() - 6 * 60 * 60 * 1000);
        const yyyy = adjustedNow.getUTCFullYear().toString();
        const mm = String(adjustedNow.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(adjustedNow.getUTCDate()).padStart(2, '0');
        const todaysDate = `${yyyy}${mm}${dd}`;

        // Set daily cookie to a known value
        await page.context().addCookies([{
            name: todaysDate,
            value: 'true,true,true,true,true,false,false,false,false,false|5',
            domain: 'localhost',
            path: '/'
        }]);

        // Reload page to pick up cookie
        await page.reload();

        // Start daily game
        await page.click('#start-daily');

        await page.waitForSelector('#result', { timeout: 10000 });

        // Check that community results section is visible
        const communitySection = page.locator('#community-results');
        await expect(communitySection).toBeVisible({ timeout: 15000 });

        // Scroll until community results section is in view
        const totalPlayers = communitySection.locator('.total-players');
        await totalPlayers.scrollIntoViewIfNeeded();
        await expect(totalPlayers).toBeVisible();

        // Check that at least one bar on the graph is present
        const bars = communitySection.locator('.bar-item');
        const barCount = await bars.count();
        expect(barCount).toBeGreaterThan(0);
        // Check that user's score is shown (either normal or hard mode)
        const userNormal = communitySection.locator('.bar-fill-user');
        const userHard = communitySection.locator('.bar-fill-hard-user');
        
        // Check if either user normal or user hard bar is visible
        const userNormalCount = await userNormal.count();
        const userHardCount = await userHard.count();
        
        if (userNormalCount > 0) {
            await expect(userNormal).toBeVisible();
        } else if (userHardCount > 0) {
            await expect(userHard).toBeVisible();
        } else {
            throw new Error('Neither normal nor hard mode user bar is present');
        }
    });

    test('Page performance and loading', async ({ page }) => {
        const startTime = Date.now();
        await page.goto(BASE_URL);
        
        // Wait for page to be fully loaded
        await page.waitForLoadState('networkidle');
        const loadTime = Date.now() - startTime;
        
        // Page should load within 5 seconds
        expect(loadTime).toBeLessThan(5000);
        
        // Check that critical CSS is loaded
        const bodyBg = await page.locator('body').evaluate(el => getComputedStyle(el).backgroundColor);
        expect(bodyBg).toBe('rgb(17, 17, 17)'); // Should be dark background
    });
    });
