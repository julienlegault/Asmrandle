const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.ASMRANDLE_BASE_URL || 'http://localhost:8000';
const username = process.env.ASMRANDLE_ADMIN_TEST_USERNAME;
const password = process.env.ASMRANDLE_ADMIN_TEST_PASSWORD;

test('organizes, saves, edits and copies seeded cards', async ({ page }) => {
    test.skip(!username || !password, 'Requires a disposable browser-test database and admin credentials');
    await page.route('**/images/Logo-Play.png', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="140"/>',
    }));
    await page.goto(`${BASE_URL}/admin/login`);
    await page.locator('[name="username"]').fill(username);
    await page.locator('[name="password"]').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: 'Admin dashboard' })).toBeVisible();
    expect(await page.locator('main > section > h2').allTextContents()).toEqual([
        'Daily Results', 'Daily run', 'Schedule a seeded run', 'Manage seeded runs',
    ]);

    const slots = page.locator('.seeded-slot');
    const input = page.getByLabel('Add a card');
    const message = page.locator('#seeded-message');
    await page.evaluate(() => {
        for (let i = 0; i < 51; i++) playableCards.push({ ...playableCards[0], name: `Suggestion ${i}` });
    });
    await input.fill('suggestion');
    await expect(page.locator('#playable-cards option')).toHaveCount(50);
    await input.fill('suggestion 50');
    await expect(page.locator('#playable-cards option')).toHaveAttribute('value', 'Suggestion 50');
    await page.evaluate(() => playableCards.splice(-51));
    await input.fill('Test card 20');
    await expect(page.locator('#playable-cards option')).toHaveAttribute('value', 'Test card 20');
    for (let i = 0; i < 20; i++) {
        await input.fill(`Test card ${i + 20}`);
        await input.press('Enter');
    }
    await expect(page.locator('#playable-cards option')).toHaveCount(0);
    await expect(message).toHaveText('20 of 20 cards selected.');
    await expect(slots.nth(0).locator('img')).toHaveAttribute('alt', 'Test card 20');
    await expect.poll(() => slots.nth(0).locator('img').evaluate(image => image.naturalWidth)).toBe(100);
    await expect(slots.nth(0).locator('.popularity')).toHaveText('21.0%');
    await input.fill('Test card 20');
    await page.getByRole('button', { name: 'Add card' }).click();
    await expect(message).toHaveText('That card is already selected.');
    await input.fill('Test card 40');
    await page.getByRole('button', { name: 'Add card' }).click();
    await expect(message).toHaveText('All 20 slots are filled. Remove a card first.');

    await slots.nth(0).scrollIntoViewIfNeeded();
    const from = await slots.nth(0).locator('.seeded-drag').boundingBox();
    const to = await slots.nth(3).locator('.seeded-drag').boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 10 });
    await page.mouse.up();
    await expect(slots.nth(3).locator('input')).toHaveValue('test-card-0');
    await expect(slots.nth(0).locator('input')).toHaveValue('test-card-3');

    const touchFrom = await slots.nth(3).locator('.seeded-drag').boundingBox();
    const touchTo = await slots.nth(2).locator('.seeded-drag').boundingBox();
    const client = await page.context().newCDPSession(page);
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: touchFrom.x + touchFrom.width / 2, y: touchFrom.y + touchFrom.height / 2 }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: touchTo.x + touchTo.width / 2, y: touchTo.y + touchTo.height / 2 }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect(slots.nth(2).locator('input')).toHaveValue('test-card-0');
    await slots.nth(2).getByRole('button', { name: 'Move later Test card 20' }).click();
    await slots.nth(3).getByRole('button', { name: 'Move earlier Test card 20' }).click();
    await expect(slots.nth(2).locator('input')).toHaveValue('test-card-0');
    await slots.nth(1).getByRole('button', { name: 'Remove Test card 21' }).click();
    await expect(message).toHaveText('19 of 20 cards selected.');
    await input.fill('Test card 40');
    await input.press('Enter');
    await expect(slots.nth(1).locator('input')).toHaveValue('test-card-20');

    const date = '2099-12-30';
    await page.locator('[name="date"]').fill(date);
    await page.locator('[name="perfect_score_emojis"]').fill('invalid');
    await page.getByRole('button', { name: 'Save seeded run' }).click();
    await expect(page.getByRole('alert')).toContainText('Enter exactly 10 emojis');
    await expect(slots.nth(2).locator('input')).toHaveValue('test-card-0');
    await expect(slots.nth(2).locator('.popularity')).toHaveText('21.0%');
    await page.locator('[name="perfect_score_emojis"]').fill('🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧');
    await page.getByRole('button', { name: 'Save seeded run' }).click();
    const run = page.locator('details').filter({ hasText: date });
    await run.locator('summary').click();
    await expect(run.locator('li').first()).toHaveText('test-card-3 / test-card-20');
    await run.getByRole('link', { name: 'Edit' }).click();
    await expect(slots.nth(2).locator('input')).toHaveValue('test-card-0');
    await expect(slots.nth(2).locator('img')).toHaveAttribute('alt', 'Test card 20');
    await slots.nth(2).getByRole('button', { name: 'Remove Test card 20' }).click();
    await page.getByRole('link', { name: 'Cancel' }).click();
    await expect(page.getByRole('heading', { name: 'Schedule a seeded run' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Manage seeded runs' })).toBeVisible();
    await expect(message).toHaveText('0 of 20 cards selected.');
    await expect(page.getByRole('button', { name: 'Save seeded run' })).toBeEnabled();
    await expect(slots.locator('input')).toHaveCount(20);
    await page.locator('details').filter({ hasText: date }).locator('summary').click();
    await expect(page.locator('details').filter({ hasText: date }).locator('li').first()).toHaveText('test-card-3 / test-card-20');
    await page.getByRole('link', { name: 'Copy to a new run' }).click();
    await expect(page.locator('[name="date"]')).toHaveValue('');
    await expect(slots.nth(2).locator('input')).toHaveValue('test-card-0');
    await expect(page.locator('[name="perfect_score_emojis"]')).toHaveValue('🟧🟨🟩🟦🟪🟪🟦🟩🟨🟧');

    await page.getByRole('button', { name: 'Fill missing' }).click();
    await expect(message).toHaveText('All 20 slots are already filled.');
    await slots.nth(1).getByRole('button', { name: 'Remove Test card 40' }).click();
    await slots.nth(3).getByRole('button', { name: 'Remove Test card 22' }).click();
    const before = await slots.locator('input').evaluateAll(inputs => inputs.map(input => input.value));
    const candidates = await page.evaluate(
        selected => playableCards.map(card => card.slug).filter(slug => !selected.includes(slug)), before,
    );
    await page.evaluate(() => { Math.random = () => 0.999999; });
    await page.getByRole('button', { name: 'Fill missing' }).click();
    await expect(message).toHaveText('Filled 2 missing slots with random cards.');
    const filled = await slots.locator('input').evaluateAll(inputs => inputs.map(input => input.value));
    expect(filled[1]).toBe(candidates.at(-1));
    expect(new Set(filled).size).toBe(20);
    for (let i = 0; i < 20; i++) {
        if (before[i]) expect(filled[i]).toBe(before[i]);
    }
    await expect(slots.nth(1).locator('img')).toBeVisible();
    await expect(slots.nth(1).locator('.popularity')).toContainText('%');
    await page.locator('[name="date"]').fill('2099-12-31');
    await page.getByRole('button', { name: 'Save seeded run' }).click();
    const copied = page.locator('details').filter({ hasText: '2099-12-31' });
    await copied.locator('summary').click();
    await expect(copied.locator('li').first()).toHaveText(`${filled[0]} / ${filled[1]}`);
    page.once('dialog', dialog => dialog.accept());
    await copied.getByRole('button', { name: 'Delete' }).click();
    await expect(copied).toHaveCount(0);
    page.once('dialog', dialog => dialog.accept());
    await page.locator('details').filter({ hasText: date }).locator('summary').click();
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator('details').filter({ hasText: date })).toHaveCount(0);

    await page.evaluate(() => playableCards.splice(1));
    await page.getByRole('button', { name: 'Fill missing' }).click();
    await expect(message).toHaveText('Not enough distinct playable cards are available to fill the missing slots.');
    expect(await slots.locator('input').evaluateAll(inputs => inputs.filter(input => input.value).length)).toBe(0);
    await page.reload();
    await page.getByRole('button', { name: 'Fill missing' }).click();
    await expect(message).toHaveText('Filled 20 missing slots with random cards.');
    const allFilled = await slots.locator('input').evaluateAll(inputs => inputs.map(input => input.value));
    expect(new Set(allFilled).size).toBe(20);
    expect(allFilled.every(Boolean)).toBe(true);
});
