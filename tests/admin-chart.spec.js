const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

test('daily chart labels sit above and centered on their bars', async ({ page }) => {
    const template = fs.readFileSync(path.join(__dirname, '../backend/resources/views/admin/dashboard.blade.php'), 'utf8');
    const styles = template.match(/<style>([\s\S]*?)<\/style>/)[1];

    for (const width of [1200, 360]) {
        await page.setViewportSize({ width, height: 800 });
        await page.setContent(`
            <style>${styles}</style>
            <main><div class="panel daily-stats">
                <div class="chart">
                    <div class="bar-group"><div class="bars">
                        <div class="bar regular" style="height: 100%"><span class="bar-label">100</span></div>
                        <div class="bar hard" style="height: 1%"><span class="bar-label">1</span></div>
                    </div><div>0</div></div>
                    <div class="bar-group"><div class="bars">
                        <div class="bar regular" style="height: 50%"><span class="bar-label">50</span></div>
                        <div class="bar hard" style="height: 0%"></div>
                    </div><div>1</div></div>
                    ${Array.from({ length: 9 }, (_, i) => `
                        <div class="bar-group"><div class="bars">
                            <div class="bar regular" style="height: 0%"></div>
                            <div class="bar hard" style="height: 0%"></div>
                        </div><div>${i + 2}</div></div>
                    `).join('')}
                </div>
            </div></main>
        `);

        const chart = await page.locator('.chart').boundingBox();
        const labels = page.locator('.bar-label');
        await expect(labels).toHaveCount(3);
        for (const label of await labels.all()) {
            const labelBox = await label.boundingBox();
            const barBox = await label.locator('..').boundingBox();
            expect(labelBox.y).toBeGreaterThanOrEqual(chart.y);
            expect(labelBox.y + labelBox.height).toBeLessThanOrEqual(barBox.y);
            expect(Math.abs(labelBox.x + labelBox.width / 2 - (barBox.x + barBox.width / 2))).toBeLessThan(1);
        }
    }
});
