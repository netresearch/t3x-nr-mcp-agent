// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * The "AI Assistant" prompt widget on the dashboard: a question typed there
 * opens the chat as a column on a new conversation, with the question as its
 * first message, exactly as typed. Operated by keyboard only.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): prompt-widget-seed.sql
 * puts the widget on the admin's only dashboard, beside "Recent chats". The E2E provider is
 * never reachable (nr-llm-task-seed.sql), so no spec waits for an answer: the
 * question is read back from the conversation the server stored.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const OPEN_AREA = '[data-nr-chat-column="open"]';

/** Two lines, markup and Markdown characters: none of it may change on the way. */
const LINES = ['Wie heißt die Seite <b>Über uns</b>?', 'Und was steht in **Absatz 2** & "danach"?'];
const QUESTION = LINES.join('\n');

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

async function storeLayout(page: Page, layout: string): Promise<void> {
    await page.evaluate(async (layout) => {
        const { default: Persistent } = await import('@typo3/backend/storage/persistent.js' as string);
        await Persistent.set('nrMcpAgent.chatPanel.layout', layout);
    }, layout);
}

/**
 * The Dashboard, the backend's start module. A click on it while it is open
 * loads it again, and a field found before that reload is gone after it: it
 * is clicked only where another module is open.
 */
async function openDashboard(page: Page): Promise<FrameLocator> {
    const module = () => page.evaluate(() => (document.querySelector('typo3-backend-module-router') as any)?.module ?? '');
    if (await module() !== 'dashboard') {
        await page.locator('[data-modulemenu-identifier="dashboard"]').first().click();
        await expect.poll(module).toBe('dashboard');
        await waitForBackend(page);
    }
    return page.frameLocator('iframe[name="list_frame"]');
}

/** The messages the server stored for a conversation. */
async function storedMessages(page: Page, uid: number): Promise<{ role: string; content: unknown }[]> {
    return page.evaluate(async (uid) => {
        const url = new URL((window as any).TYPO3.settings.ajaxUrls.ai_chat_conversation_messages, location.href);
        url.searchParams.set('conversationUid', String(uid));
        url.searchParams.set('after', '0');
        const data = await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
        return data.messages;
    }, uid);
}

test.describe('The prompt widget on the dashboard', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
        // The chat starts floating: the widget is what docks it.
        await storeLayout(page, 'floating');
        await page.reload();
        await login(page);
    });

    test.afterEach(async ({ page }) => {
        // The other specs expect the floating panel.
        await storeLayout(page, 'floating');
    });

    test('a question opens the chat as a column, with the question as the first message', async ({ page }) => {
        const dashboard = await openDashboard(page);
        const field = dashboard.getByRole('textbox', { name: 'What would you like to do?' });
        const send = dashboard.getByRole('button', { name: 'Send' });
        await expect(field).toBeVisible({ timeout: 15000 });
        await expect(field).toHaveAttribute('placeholder', 'Ask the assistant …');
        await expect(send).toBeDisabled();

        // Shift+Enter is a new line; the question is sent with Enter.
        await field.focus();
        await page.keyboard.type(LINES[0]);
        await page.keyboard.press('Shift+Enter');
        await page.keyboard.type(LINES[1]);
        await expect(field).toHaveValue(QUESTION);
        await expect(send).toBeEnabled();

        const sendUrl = await page.evaluate(() => new URL((window as any).TYPO3.settings.ajaxUrls.ai_chat_conversation_send, location.href).pathname);
        const sent = page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname === sendUrl);
        await page.keyboard.press('Enter');
        const request = await sent;
        expect(JSON.parse(request.postData() ?? '{}').content).toBe(QUESTION);

        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'expanded');
        await expect(panel).toHaveAttribute('layout', 'column');
        await expect(page.locator(OPEN_AREA)).toHaveCount(1);
        await expect(panel.locator('.message-row.user')).toHaveCount(1);
        // Docked for this opening only: the floating layout stays the user's choice.
        expect(await page.evaluate(async () => {
            const { default: Persistent } = await import('@typo3/backend/storage/persistent.js' as string);
            return Persistent.get('nrMcpAgent.chatPanel.layout');
        })).toBe('floating');

        const uid = await panel.evaluate((el: any) => el.chat.activeUid as number);
        expect(uid).toBeGreaterThan(0);
        const messages = await storedMessages(page, uid);
        expect(messages[0]).toMatchObject({ role: 'user', content: QUESTION });
        expect(messages.filter((m) => m.role === 'user')).toHaveLength(1);

        // The widget is ready for the next question.
        await expect(field).toHaveValue('');
        await expect(send).toBeDisabled();
    });

    test('the send button is reached and pressed by keyboard', async ({ page }) => {
        const dashboard = await openDashboard(page);
        const field = dashboard.getByRole('textbox', { name: 'What would you like to do?' });
        const send = dashboard.getByRole('button', { name: 'Send' });
        await expect(field).toBeVisible({ timeout: 15000 });

        await field.focus();
        await page.keyboard.type('Was kann ich hier tun?');
        await expect(field).toHaveValue('Was kann ich hier tun?');
        await page.keyboard.press('Tab');
        await expect(send).toBeFocused();
        await page.keyboard.press('Space');

        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'expanded');
        await expect(panel.locator('.message-row.user')).toHaveCount(1);
        await expect(panel.locator('.message-row.user')).toContainText('Was kann ich hier tun?');
    });

    test('an empty question cannot be sent', async ({ page }) => {
        const dashboard = await openDashboard(page);
        const field = dashboard.getByRole('textbox', { name: 'What would you like to do?' });
        const send = dashboard.getByRole('button', { name: 'Send' });
        await expect(field).toBeVisible({ timeout: 15000 });

        const posts: { path: string; body: string }[] = [];
        page.on('request', (r) => {
            if (r.method() === 'POST' && r.url().includes('/ajax/ai-chat/')) {
                posts.push({ path: new URL(r.url()).pathname, body: r.postData() ?? '' });
            }
        });
        const urls = await page.evaluate(() => {
            const ajax = (window as any).TYPO3.settings.ajaxUrls;
            const path = (name: string) => new URL(ajax[name], location.href).pathname;
            return { create: path('ai_chat_conversation_create'), send: path('ai_chat_conversation_send') };
        });

        await field.focus();
        await page.keyboard.type('   ');
        await expect(field).toHaveValue('   ');
        await expect(send).toBeDisabled();
        await page.keyboard.press('Enter');
        await expect(page.locator('ai-chat-panel')).not.toHaveAttribute('state', 'expanded');

        // A real question afterwards is the signal that the blank one had its
        // chance to be sent: everything the server got belongs to the real one.
        await page.keyboard.press('Control+A');
        await page.keyboard.type('Echte Frage');
        const sent = page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname === urls.send);
        await page.keyboard.press('Enter');
        await sent;

        expect(posts.filter((p) => p.path === urls.create)).toHaveLength(1);
        expect(posts.filter((p) => p.path === urls.send).map((p) => JSON.parse(p.body).content)).toEqual(['Echte Frage']);
    });
});
