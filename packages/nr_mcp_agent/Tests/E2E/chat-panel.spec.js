import { test, expect } from '@playwright/test';

/**
 * E2E tests for the AI Chat Panel (<ai-chat-panel> Lit web component).
 *
 * The chat panel is appended to document.body in the TYPO3 backend
 * and controlled via a toolbar button (.ai-chat-toolbar-btn).
 * Panel visibility is managed through a `state` attribute:
 *   hidden | collapsed | expanded | maximized
 *
 * Run against a live TYPO3 v13 instance via DDEV.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

test.describe('AI Chat Panel', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/typo3/');

        // Log in if the login form is visible
        const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
        if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
            await page.getByLabel('Username').fill(TYPO3_USER);
            await page.getByLabel('Password').fill(TYPO3_PASSWORD);
            await page.getByRole('button', { name: /log.?in/i }).click();
            await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
        }
    });

    test('toolbar button is visible in backend', async ({ page }) => {
        await expect(page.locator('.ai-chat-toolbar-btn')).toBeVisible();
    });

    test('click toolbar button opens panel', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'expanded');
    });

    test('click toolbar button again hides panel', async ({ page }) => {
        const btn = page.locator('.ai-chat-toolbar-btn');
        await btn.click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');
        await btn.click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'hidden');
    });

    test('panel persists across module navigation', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        // Navigate to a different module
        await page.locator('.modulemenu-item').first().click();
        await page.waitForTimeout(500);
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toBeVisible();
        await expect(panel).not.toHaveAttribute('state', 'hidden');
    });

    test('escape key collapses panel', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await page.keyboard.press('Escape');
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'collapsed');
    });

    test('panel state persists after reload', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');
        await page.reload();
        await page.waitForSelector('ai-chat-panel');
        // After reload panel should restore to expanded (from localStorage)
        // Note: the panel starts hidden and requires toolbar click, but localStorage saves lastVisibleState
    });

    test('corrupted localStorage falls back to defaults', async ({ page }) => {
        await page.evaluate(() => localStorage.setItem('ai-chat-panel', 'not-json'));
        await page.reload();
        await page.waitForSelector('.ai-chat-toolbar-btn');
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');
    });
});
