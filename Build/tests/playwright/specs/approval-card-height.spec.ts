// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Locator, Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * The approval card keeps its height when the transcript overflows.
 *
 * The card (`.approval-frame`) is a flex item of the scrolling message column
 * and clips its content with `overflow: hidden`. Without `flex-shrink: 0` the
 * browser shrinks it to its 2px border as soon as the messages need more room
 * than the column has, and the buttons disappear with it. jsdom computes no
 * layout, so only a real browser can see this. Both surfaces carry the rule:
 * the AI Chat module (chat-app.js) and the toolbar panel (ai-chat-panel.js).
 *
 * The conversation comes from fixtures/approval-card-seed.sql, which
 * seed-and-test.sh loads for the `configured` variant: a run waiting for an
 * approval of one `update_page_metadata` call, and 24 messages.
 *
 * The height is asserted before the buttons: a button clipped inside a 2px
 * box with `overflow: hidden` still counts as visible for toBeVisible().
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

/** The title of the seeded conversation (approval-card-seed.sql). */
const CONVERSATION_TITLE = 'E2E approval card';

/** The module link href pattern in the TYPO3 module menu. */
const MODULE_HREF_PATTERN = '/nr/mcp/agent/chat';

/** Well above the 2px a collapsed card keeps, well below the card's real height. */
const MIN_CARD_HEIGHT = 100;

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

/**
 * The seeded conversation's uid, after checking that the server answers it
 * with a decidable card. A broken seed fails here, not as a collapsed card.
 */
async function seededConversation(page: Page): Promise<number> {
    const state = await page.evaluate(async (title) => {
        const urls = (window as any).TYPO3.settings.ajaxUrls;
        const list = await (await fetch(urls.ai_chat_conversations, { credentials: 'same-origin' })).json();
        const conversation = list.conversations.find((c: { title: string }) => c.title === title);
        if (!conversation) {
            return { uid: 0, status: '', calls: 0 };
        }
        const base = urls.ai_chat_conversation_messages;
        const url = base + (base.includes('?') ? '&' : '?') + new URLSearchParams({ conversationUid: String(conversation.uid), after: '0' });
        const data = await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
        return {
            uid: conversation.uid as number,
            status: data.status as string,
            calls: (data.pendingApproval?.calls ?? []).length as number,
        };
    }, CONVERSATION_TITLE);

    expect(state.uid, `no conversation titled "${CONVERSATION_TITLE}"; is approval-card-seed.sql loaded?`).toBeGreaterThan(0);
    expect(state.status).toBe('awaiting_approval');
    expect(state.calls, 'the server sent no pending call for the card').toBeGreaterThan(0);

    return state.uid;
}

/** scrollHeight and clientHeight of the message column inside a component's shadow root. */
async function columnHeights(host: Locator, selector: string): Promise<{ scroll: number; client: number }> {
    return host.evaluate((el, selector) => {
        const column = el.shadowRoot!.querySelector(selector) as HTMLElement;
        return { scroll: column.scrollHeight, client: column.clientHeight };
    }, selector);
}

async function expectCardKeepsItsHeight(card: Locator): Promise<void> {
    await expect
        .poll(async () => Math.round((await card.boundingBox())?.height ?? 0), {
            message: 'the approval card collapsed: .approval-frame needs flex-shrink: 0 in the scrolling message column',
            timeout: 5000,
        })
        .toBeGreaterThan(MIN_CARD_HEIGHT);

    await expect(card.locator('.approval-actions .btn-primary')).toBeVisible();
    await expect(card.getByRole('button', { name: 'Cancel' })).toBeVisible();
}

test.describe('Approval card in an overflowing transcript', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('keeps its height in the AI Chat module', async ({ page }) => {
        await seededConversation(page);

        await page.locator(`a[href*="${MODULE_HREF_PATTERN}"]`).click();
        const iframe: FrameLocator = page.frameLocator('iframe[name="list_frame"]');
        const chatApp = iframe.locator('nr-chat-app');
        await expect(chatApp).toBeVisible({ timeout: 15000 });

        await chatApp.getByRole('option', { name: new RegExp(CONVERSATION_TITLE) }).click();
        const card = chatApp.locator('.approval-frame');
        await expect(card).toHaveCount(1, { timeout: 10000 });

        const column = await columnHeights(chatApp, '.messages');
        expect(column.scroll, 'the transcript does not overflow, so the test cannot see a collapse').toBeGreaterThan(column.client);

        await expectCardKeepsItsHeight(card);
    });

    test('keeps its height in the toolbar panel', async ({ page }) => {
        const uid = await seededConversation(page);

        await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });
        // Until init() has settled the panel renders a spinner instead of the chat.
        await page.waitForFunction(() => (document.querySelector('ai-chat-panel') as any).chat.loading === false, null, { timeout: 10000 });
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(page.locator('ai-chat-panel')).toHaveAttribute('state', 'expanded', { timeout: 3000 });

        // A fixed size, so whether the transcript overflows does not depend on
        // the panel's default size.
        await page.evaluate(async (uid) => {
            const panel = document.querySelector('ai-chat-panel') as any;
            panel._width = 480;
            panel._height = 600;
            await panel.chat.loadConversations();
            await panel.chat.selectConversation(uid);
            await panel.updateComplete;
        }, uid);

        const panel = page.locator('ai-chat-panel');
        const card = panel.locator('.approval-frame');
        await expect(card).toHaveCount(1, { timeout: 10000 });

        const column = await columnHeights(panel, '.panel-messages');
        expect(column.scroll, 'the transcript does not overflow, so the test cannot see a collapse').toBeGreaterThan(column.client);

        await expectCardKeepsItsHeight(card);
    });
});
