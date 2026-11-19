const { test, expect } = require('@playwright/test');
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
        await page.uncheck('#fast-mode');
        await page.click('#play-random');
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
