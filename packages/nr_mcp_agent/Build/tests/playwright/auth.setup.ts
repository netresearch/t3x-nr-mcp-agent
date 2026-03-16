import { test as setup, expect } from '@playwright/test';

const authFile = 'Build/tests/playwright/.auth/admin.json';

/**
 * Authenticate as TYPO3 backend admin and store session state.
 *
 * Requires TYPO3_ADMIN_USER and TYPO3_ADMIN_PASSWORD environment variables,
 * or defaults to admin/password.
 */
setup('authenticate as admin', async ({ page }) => {
    const baseURL = process.env.TYPO3_BASE_URL || 'https://nr-mcp-agent.ddev.site';
    const user = process.env.TYPO3_ADMIN_USER || 'admin';
    const password = process.env.TYPO3_ADMIN_PASSWORD || 'password';

    await page.goto(`${baseURL}/typo3`);

    // TYPO3 v13 backend login form
    await page.getByLabel('Username').fill(user);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /log ?in/i }).click();

    // Wait for backend to load
    await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });

    await page.context().storageState({ path: authFile });
});
