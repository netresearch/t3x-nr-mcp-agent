import { test, expect } from '@playwright/test';

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

test('debug: inspect toolbar button and panel DOM', async ({ page }) => {
    const consoleMessages: string[] = [];
    page.on('console', msg => consoleMessages.push(`[${msg.type()}] ${msg.text()}`));

    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
    }

    // Wait a bit for JS modules to load
    await page.waitForTimeout(2000);

    // Check importmap
    const importMap = await page.evaluate(() => {
        const el = document.querySelector('script[type="importmap"]');
        if (!el) return 'NO IMPORTMAP';
        const map = JSON.parse(el.textContent || '{}');
        const nrEntries = Object.entries(map.imports || {}).filter(([k]) => k.includes('nr-mcp'));
        return JSON.stringify(nrEntries);
    });
    console.log('Import map nr-mcp entries:', importMap);

    // Check panel
    const panelExists = await page.evaluate(() => !!document.querySelector('ai-chat-panel'));
    console.log('Panel element exists:', panelExists);

    // Check all console messages
    console.log('Console messages:', consoleMessages.filter(m => m.includes('chat') || m.includes('error') || m.includes('Error')).join('\n'));

    // Try clicking
    await page.locator('.ai-chat-toolbar-btn').click();
    await page.waitForTimeout(1000);
    const stateAfterClick = await page.evaluate(() => {
        const panel = document.querySelector('ai-chat-panel');
        return panel ? panel.getAttribute('state') : 'NO PANEL';
    });
    console.log('Panel state after click:', stateAfterClick);

    expect(true).toBe(true);
});
