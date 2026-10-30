// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Page } from '@playwright/test';
import { test, expect, waitForBackend, dashboardWidgetsLoaded } from '../fixtures/backend';

/**
 * The "KI-Assistent" dashboard: every action that concerns a page opens the
 * page module with the chat as a column beside it (chat-start.js), and a
 * guided tour starts there.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): assistant-widgets-seed.sql
 * gives the administrator `e2e-widgets` a dashboard with the widgets of the
 * preset, a process skill under the slug `seo-optimieren`, the enabled tool
 * that creates pages, and page 9100 without a meta description, changed last.
 * No model is called: a tour started on a page shows its start message, and
 * its run then fails against the unreachable provider.
 */

const TYPO3_USER = 'e2e-widgets';
const TYPO3_PASSWORD = 'Joh316!!';

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

async function routerEndpoint(page: Page): Promise<string> {
    return page.evaluate(() => document.querySelector('typo3-backend-module-router')?.getAttribute('endpoint') ?? '');
}

/**
 * The dashboard with every widget rendered. A new user starts on the
 * dashboard: its widgets load first, as a click on the module that reloads it
 * would cut their requests off.
 */
async function openDashboard(page: Page): Promise<FrameLocator> {
    await dashboardWidgetsLoaded(page);
    if (await routerModule(page) !== 'dashboard') {
        await page.locator('[data-modulemenu-identifier="dashboard"]').first().click();
    }
    await expect.poll(() => routerModule(page)).toMatch(/^dashboard$/);
    const frame = page.frameLocator('iframe[name="list_frame"]');
    await expect(frame.locator('.nr-mcp-agent-improve')).toBeVisible({ timeout: 15000 });
    await expect(frame.locator('.nr-mcp-agent-tile-descriptions')).toBeVisible({ timeout: 15000 });
    await dashboardWidgetsLoaded(page);

    return frame;
}

test.describe('The "AI Assistant" dashboard', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('invites with one action per widget, rows with a chevron and number tiles', async ({ page }) => {
        const frame = await openDashboard(page);

        await expect(frame.locator('.nr-mcp-agent-edit-page').getByRole('link', { name: 'Choose a page to edit' })).toBeVisible();
        await expect(frame.locator('.nr-mcp-agent-create').getByRole('link')).toHaveText(['New page']);
        await expect(frame.locator('.nr-mcp-agent-improve').getByRole('listitem')).toHaveText(['Optimise SEO']);
        await expect(frame.locator('.nr-mcp-agent-website-check').getByRole('link')).toHaveText(['Missing alt texts', 'Pages without a meta description']);
        await expect(frame.locator('.nr-mcp-agent-recommendations .nr-mcp-agent-intro'))
            .toHaveText('I’ve found a few things on your website that need work. Let’s tackle them together.');
        // The action's name says the number and what it counts.
        await expect(frame.locator('.nr-mcp-agent-tile-descriptions').getByRole('link'))
            .toHaveAccessibleName(/^Start now: \d+ Pages without a meta description, with the SEO tour, starting with “E2E Without description”$/);
    });

    test('the meta-description tile opens the page in the page module with the tour in the chat column beside it', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');

        await frame.locator('.nr-mcp-agent-tile-descriptions').getByRole('link', { name: /^Start now/ }).click();

        await expect.poll(() => routerModule(page)).toMatch(/^web_layout$/);
        await expect.poll(() => routerEndpoint(page)).toContain('id=9100');
        await expect(panel).toHaveAttribute('layout', 'column');
        await expect(panel).not.toHaveAttribute('state', 'hidden');
        // The tour's start, sent for the editor (ADR-023), is the first message.
        await expect(panel.locator('.panel-messages')).toContainText('Check this page', { timeout: 15000 });
        await expect.poll(() => panel.evaluate((el: any) => el.chat.tour?.pageUid ?? 0)).toBe(9100);
    });

    test('"Improve a page" opens the page module and the tour asks for the page there, by keyboard', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');

        await frame.locator('.nr-mcp-agent-improve').getByRole('link', { name: 'Optimise SEO' }).focus();
        await page.keyboard.press('Enter');

        await expect.poll(() => routerModule(page)).toMatch(/^web_layout$/);
        await expect(panel).toHaveAttribute('layout', 'column');
        await expect(panel.locator('#nr-chat-guided-choice')).toHaveText('Choose a page');
        await expect.poll(() => panel.evaluate((el: any) => el.chat.tourStart?.skill ?? '')).toBe('9100:seo-optimieren');
    });

    test('"Edit a page" opens the page module with a new chat beside it', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');
        const before = await panel.evaluate((el: any) => el.chat.activeUid ?? 0);

        await frame.locator('.nr-mcp-agent-edit-page').getByRole('link', { name: 'Choose a page to edit' }).click();

        await expect.poll(() => routerModule(page)).toMatch(/^web_layout$/);
        await expect(panel).toHaveAttribute('layout', 'column');
        await expect.poll(() => panel.evaluate((el: any) => el.chat.activeUid ?? 0)).not.toBe(before);
        expect(await panel.evaluate((el: any) => el.chat.messages.length)).toBe(0);
    });

    test('"New page" puts the request into the chat\'s input without sending it, and the dashboard stays', async ({ page }) => {
        const frame = await openDashboard(page);
        const panel = page.locator('ai-chat-panel');

        await frame.locator('.nr-mcp-agent-create').getByRole('link', { name: 'New page' }).click();

        await expect(panel.locator('textarea').first()).toHaveValue('I would like to create a new page.');
        expect(await panel.evaluate((el: any) => el.chat.messages.length)).toBe(0);
        expect(await routerModule(page)).toBe('dashboard');
    });
});
