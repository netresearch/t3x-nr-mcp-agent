// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Locator, Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * A run that waits for a remote agent (nr-llm ADR-281), in the AI Chat module.
 *
 * The conversations come from fixtures/agent-wait-seed.sql, which
 * seed-and-test.sh loads for the `configured` variant: one whose run still
 * waits, and one whose run nr-llm's worker has completed without keeping an
 * answer. Nothing here needs a model or a remote agent: the chat reads the
 * run, and the stop goes through nr-llm's own cancel.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const WAITING = 'E2E remote agent waits';
const ANSWERED = 'E2E remote agent answered';

/** The module link href pattern in the TYPO3 module menu. */
const MODULE_HREF_PATTERN = '/nr/mcp/agent/chat';

async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await waitForBackend(page);
    }
    await page.waitForFunction(() => !!(window as any).TYPO3?.settings?.ajaxUrls?.ai_chat_conversations, null, { timeout: 10000 });
}

async function openConversation(page: Page, title: string): Promise<Locator> {
    await page.locator(`a[href*="${MODULE_HREF_PATTERN}"]`).click();
    const iframe: FrameLocator = page.frameLocator('iframe[name="list_frame"]');
    // The module opens its overview; the chat is its sub-route (ADR-031).
    await iframe.getByRole('link', { name: 'The chat as a full page' }).click();
    const chatApp = iframe.locator('nr-chat-app');
    await expect(chatApp).toBeVisible({ timeout: 15000 });
    // A string name matches a part of the accessible name, which also holds
    // the status and the time.
    await chatApp.getByRole('option', { name: title }).click();

    return chatApp;
}

test.describe('A run that waits for a remote agent', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('says what it waits for, sends nothing new, and can be stopped', async ({ page }) => {
        const chatApp = await openConversation(page, WAITING);

        const wait = chatApp.locator('.agent-wait');
        await expect(wait).toBeVisible({ timeout: 10000 });
        await expect(wait).toContainText('waits for its answer');
        await expect(wait).toHaveAttribute('role', 'status');

        // A message would send the same task to the agent again.
        await chatApp.locator('textarea').fill('Is it done yet?');
        await expect(chatApp.locator('.btn-send')).toBeDisabled();

        await wait.getByRole('button', { name: 'Stop waiting' }).click();

        await expect(chatApp.locator('.agent-wait')).toHaveCount(0, { timeout: 10000 });
        await expect(chatApp).toContainText('The wait for the remote agent was stopped.');
        await expect(chatApp.locator('.btn-send')).toBeEnabled();
    });

    test('a run that finished without a kept answer says so instead of waiting', async ({ page }) => {
        const chatApp = await openConversation(page, ANSWERED);

        await expect(chatApp).toContainText('its answer was not kept', { timeout: 10000 });
        await expect(chatApp.locator('.agent-wait')).toHaveCount(0);
    });
});
