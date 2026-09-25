import { test, expect } from '@playwright/test';

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

// The wiring the other panel specs take for granted: the import map carries
// the extension's modules, the toolbar item renders the panel element, the
// panel loads without a console error, and the toolbar button expands it.
test('the toolbar button loads and expands the chat panel', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', msg => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });

    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
    }

    const nrMcpImports = await page.evaluate(() => {
        const el = document.querySelector('script[type="importmap"]');
        const map = JSON.parse(el?.textContent || '{}');
        return Object.keys(map.imports || {}).filter(k => k.includes('nr-mcp'));
    });
    expect(nrMcpImports.length).toBeGreaterThan(0);

    await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });

    await page.locator('.ai-chat-toolbar-btn').click();
    await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });

    expect(consoleErrors.filter(m => /chat|nr-mcp/i.test(m))).toEqual([]);
});
