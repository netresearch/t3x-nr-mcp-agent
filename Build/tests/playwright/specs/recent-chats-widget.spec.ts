// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * The "Recent chats" dashboard widget, rendered by the dashboard and clicked
 * as an editor clicks it.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): recent-chats-seed.sql
 * puts the widget on the admin's dashboard and seeds an interrupted guided
 * process on page 3 ("E2E Recent chat page", point 2 of 4) and a guided
 * process on page 4, which has been deleted. Their last message is dated 1 January 2099,
 * so they stay among the five newest. No model is called.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
    }
    await waitForBackend(page);
    await page.waitForFunction(() => (document.querySelector('ai-chat-panel') as any)?.chat?.loading === false, null, { timeout: 15000 });
}

async function routerModule(page: Page): Promise<string> {
    return page.evaluate(() => (document.querySelector('typo3-backend-module-router') as any)?.module ?? '');
}

/** The dashboard, with the widget's list rendered. */
async function openDashboard(page: Page): Promise<FrameLocator> {
    await page.locator('[data-modulemenu-identifier="dashboard"]').first().click();
    await expect.poll(() => routerModule(page)).toMatch(/^dashboard$/);
    const frame = page.frameLocator('iframe[name="list_frame"]');
    await expect(frame.locator('.nr-recent-chats')).toBeVisible({ timeout: 15000 });

    return frame;
}

test.describe('The "Recent chats" dashboard widget', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('names each chat by title, context, progress and time', async ({ page }) => {
        const frame = await openDashboard(page);

        await expect(frame.getByText('Look at earlier conversations or continue where you left off.')).toBeVisible();
        await expect(frame.getByRole('link', { name: 'Show all chats' })).toBeVisible();
        expect(await frame.locator('.nr-recent-chats > li').count()).toBeLessThanOrEqual(5);

        // One link per chat, its visible text its accessible name: title,
        // context, progress and time, in that order.
        await expect(frame.getByRole('link', { name: /^E2E recent tour/ }))
            .toHaveAccessibleName(/^E2E recent tour\s*,\s*Page “E2E Recent chat page” · Guided tour\s*,\s*Point 2 of 4\s*,\s*Jan 1$/);
        await expect(frame.getByRole('link', { name: /^E2E recent deleted page/ }))
            .toHaveAccessibleName(/^E2E recent deleted page\s*,\s*Deleted page · Guided tour\s*,\s*Jan 1$/);
    });

    test('a chat about a page opens in the chat and shows its page in the page module', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');

        await frame.getByRole('link', { name: /^E2E recent tour/ }).click();

        await expect(panel).not.toHaveAttribute('state', 'hidden');
        await expect.poll(() => panel.evaluate((el: any) => el.chat.activeUid)).toBeGreaterThan(0);
        await expect(panel.locator('.panel-messages')).toContainText('Here is what I found.');
        await expect.poll(() => routerModule(page)).toMatch(/^web_layout$/);
        await expect.poll(() => page.evaluate(() => document.querySelector('typo3-backend-module-router')?.getAttribute('endpoint') ?? ''))
            .toContain('id=3');
        await expect(page.frameLocator('iframe[name="list_frame"]').getByText('E2E Recent chat page').first()).toBeVisible();
    });

    test('a tour on a deleted page opens read-only, by keyboard, without leaving the dashboard, and can be ended', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');

        await frame.getByRole('link', { name: /^E2E recent deleted page/ }).focus();
        await page.keyboard.press('Enter');

        const notice = panel.locator('.read-only-notice');
        await expect(notice.locator('.read-only-text')).toHaveText(
            'The page this guided tour is on has been deleted. The chat can be read but not continued. End the tour to go on as an ordinary chat.',
        );
        await expect(panel.locator('.panel-messages')).toContainText('Here is what I found.');
        await expect(panel.locator('.panel-input textarea')).toHaveCount(0);
        expect(await routerModule(page)).toBe('dashboard');

        // Ending the tour is the way out: an ordinary chat that can be continued.
        await notice.getByRole('button', { name: 'End tour' }).click();
        await expect(panel.locator('.read-only-notice')).toHaveCount(0);
        await expect(panel.locator('.panel-input textarea')).toBeEnabled();
    });
});
