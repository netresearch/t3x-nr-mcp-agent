import { test, expect, Page } from '@playwright/test';

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
        // Wait for panel JS module to load and create the panel element
        await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });
    });

    test('toolbar button is visible', async ({ page }) => {
        await expect(page.locator('.ai-chat-toolbar-btn')).toBeVisible();
    });

    test('click toolbar button opens panel', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
    });

    test('click toolbar button again hides panel', async ({ page }) => {
        const btn = page.locator('.ai-chat-toolbar-btn');
        await btn.click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
        await btn.click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'hidden', { timeout: 3000 });
    });

    test('panel persists across module navigation', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
        // Navigate to a different module
        const menuItems = page.locator('.modulemenu-item a');
        const count = await menuItems.count();
        if (count > 1) {
            await menuItems.nth(1).click();
            await page.waitForTimeout(1000);
        }
        await expect(page.locator('ai-chat-panel')).not.toHaveAttribute('state', 'hidden');
    });

    test('escape key collapses panel', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
        await page.keyboard.press('Escape');
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'collapsed', { timeout: 3000 });
    });

    test('resize panel by keyboard', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });

        const panel = page.locator('ai-chat-panel');
        const box = await panel.boundingBox();
        if (!box) { test.skip(); return; }

        // Focus the resize handle and use keyboard to resize
        await page.evaluate(() => {
            const panel = document.querySelector('ai-chat-panel');
            const handle = panel?.shadowRoot?.querySelector('.resize-handle');
            if (handle instanceof HTMLElement) handle.focus();
        });
        // Press ArrowUp 3 times to increase height by 150px
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowUp');
        await page.waitForTimeout(200);

        const newBox = await panel.boundingBox();
        if (newBox) {
            expect(newBox.height).toBeGreaterThan(box.height + 100);
        }
    });

    test('resize panel by dragging downward snaps to collapsed', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });

        const panel = page.locator('ai-chat-panel');
        const box = await panel.boundingBox();
        if (!box) { test.skip(); return; }

        const x = box.x + box.width / 2;
        const y = box.y + 4;
        const viewportH = page.viewportSize()?.height || 720;

        // Drag down to near bottom
        await page.mouse.move(x, y);
        await page.mouse.down();
        await page.mouse.move(x, viewportH - 10, { steps: 10 });
        await page.mouse.up();
        await page.waitForTimeout(200);

        await expect(panel).toHaveAttribute('state', 'collapsed', { timeout: 3000 });
    });

    test('panel has ARIA complementary role', async ({ page }) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('role', 'complementary');
    });

    test('corrupted localStorage falls back to defaults', async ({ page }) => {
        await page.evaluate(() => localStorage.setItem('ai-chat-panel', '{broken'));
        await page.reload();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
        await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });
    });
});
