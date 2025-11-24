const { test, expect } = require('@playwright/test');

test.describe('Asmrandle E2E Tests', () => {
    
    test('Homepage loads correctly', async ({ page }) => {
        await page.goto('http://localhost:3000');
        
        // Check page title
        await expect(page).toHaveTitle(/Asmrandle/);
        
        // Check that main menu buttons are present
        await expect(page.locator('#start-daily')).toBeVisible();
        await expect(page.locator('#play-random')).toBeVisible();
        
        // Check footer elements
        await expect(page.locator('.footer')).toBeVisible();
        await expect(page.locator('#linkedin-link img')).toBeVisible(); // LinkedIn icon
        await expect(page.locator('#github-link img')).toBeVisible(); // Github icon
    });

    test('Daily game button functionality', async ({ page }) => {
        await page.goto('http://localhost:3000');
        
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
        await page.goto('http://localhost:3000');
        
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
        await page.goto('http://localhost:3000');
        
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
        await page.goto('http://localhost:3000');
        
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
        await page.goto('http://localhost:3000');

            // Use the current date (YYYYMMDD) in Central Time as a seed
    // We use Intl.DateTimeFormat with the "America/Chicago" timezone so the daily seed follows Central Time (CST/CDT)
    const now = new Date();
    const centralFormatter = new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/Chicago',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    });
    const parts = centralFormatter.formatToParts(now);
    const getPart = (type) => parts.find(p => p.type === type)?.value || '';
    const yyyy = getPart('year');
    const mm = getPart('month');
    const dd = getPart('day');
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

        // Check that results reflect cookie values
        const resultsText = await page.locator('#result').innerText();
        expect(resultsText).toContain('🟩🟩🟩🟩🟩🟥🟥🟥🟥🟥 5/10');
    });

    // test('Play full game', async ({ page }) => {
    //     test.setTimeout(2 * 60 * 1000); // Extend timeout to 2 minutes
    //     await page.goto('http://localhost:3000');
        
    //     // Start a practice game
    //     await page.click('#play-random');
        
    //     // Wait for game to load
    //     await page.waitForSelector('#game', { timeout: 10000 });
        
    //     // Play through all 10 cards
    //     for (let i = 0; i < 10; i++) {
    //         // Wait for cards to load
    //         await page.waitForSelector('.card img', { timeout: 15000 });
            
    //         // Click on the first card
    //         await page.click('.card:first-child');
            
    //         // Wait for overlay to appear and disappear
    //         await page.waitForSelector('.overlay', { timeout: 5000 });
    //         await page.waitForSelector('.overlay', { state: 'hidden', timeout: 5000 });
    //     }
        
    //     // After 10 cards, check that results are shown
    //     await page.waitForSelector('#result', { timeout: 10000 });
    //     const resultsText = await page.locator('#result').innerText();
    //     const resultsBreakdown = await page.locator('#result-breakdown');
    //     expect(resultsText).toMatch(/\d+\/10/); // Should show score out of 10
    //     expect(resultsBreakdown).toBeVisible(); // Should show breakdown of results
    //     const resultItem = await page.locator('.result-item').first();
    //     expect(resultItem).toBeVisible();
    // });

    test('Page performance and loading', async ({ page }) => {
        const startTime = Date.now();
        await page.goto('http://localhost:3000');
        
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
