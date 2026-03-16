import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for the AI Chat bottom panel (toolbar integration).
 *
 * The panel is a <ai-chat-panel> Lit web component appended to document.body
 * in the outer backend frame (outside the module iframe).
 * The toolbar button has class .ai-chat-toolbar-btn.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

test.describe('AI Chat Panel', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/typo3/');

        const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
        if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
            await page.getByLabel('Username').fill(TYPO3_USER);
            await page.getByLabel('Password').fill(TYPO3_PASSWORD);
            await page.getByRole('button', { name: /log.?in/i }).click();
            await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
        }
    });

    test('toolbar button is visible in backend', async ({ page }) => {
        const btn = page.locator('.ai-chat-toolbar-btn');
        await expect(btn).toBeVisible({ timeout: 10000 });
    });

    test('click toolbar button opens panel', async ({ page }) => {
        const btn = page.locator('.ai-chat-toolbar-btn');
        await expect(btn).toBeVisible({ timeout: 10000 });
        await btn.click();

        const panel = page.locator('ai-chat-panel');
        await expect(panel).toBeVisible({ timeout: 5000 });
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
        const btn = page.locator('.ai-chat-toolbar-btn');
        await btn.click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');

        // Click any module menu item to trigger navigation
        const menuItems = page.locator('.modulemenu-item a');
        const count = await menuItems.count();
        if (count > 1) {
            await menuItems.nth(1).click();
            await page.waitForTimeout(1000);
        }

        // Panel should still be visible
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toBeVisible();
        await expect(panel).not.toHaveAttribute('state', 'hidden');
    });

    test('escape key collapses panel', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');

        await page.keyboard.press('Escape');
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'collapsed');
    });

    test('resize panel by dragging top edge', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');

        // Get the resize handle inside the shadow DOM
        const panel = page.locator('ai-chat-panel');
        const resizeHandle = panel.locator('.resize-handle');

        // If resize handle is in shadow DOM, we need to use evaluate
        const panelBox = await panel.boundingBox();
        if (!panelBox) {
            test.skip();
            return;
        }

        const startY = panelBox.y + 2; // Top edge of panel (resize handle area)
        const startX = panelBox.x + panelBox.width / 2;

        // Drag upward to make panel larger
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX, startY - 100, { steps: 10 });
        await page.mouse.up();

        // Panel should now be taller
        const newBox = await panel.boundingBox();
        if (newBox) {
            expect(newBox.height).toBeGreaterThan(panelBox.height);
        }
    });

    test('resize snaps to collapsed when dragged very small', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');

        const panel = page.locator('ai-chat-panel');
        const panelBox = await panel.boundingBox();
        if (!panelBox) {
            test.skip();
            return;
        }

        const startY = panelBox.y + 2;
        const startX = panelBox.x + panelBox.width / 2;
        const viewportHeight = page.viewportSize()?.height || 720;

        // Drag down almost to the bottom of viewport (making panel very small)
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX, viewportHeight - 20, { steps: 10 });
        await page.mouse.up();

        await expect(panel).toHaveAttribute('state', 'collapsed');
    });

    test('corrupted localStorage falls back to defaults', async ({ page }) => {
        await page.evaluate(() => localStorage.setItem('ai-chat-panel', '{broken'));
        await page.reload();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });

        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded');
    });

    test('panel has ARIA complementary role', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toBeVisible();

        const role = await panel.getAttribute('role');
        expect(role).toBe('complementary');
    });
});
