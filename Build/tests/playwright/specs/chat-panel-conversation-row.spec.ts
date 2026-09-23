import { test, expect, Page } from '@playwright/test';

/**
 * The panel's conversation row with many conversations (NEXT-172).
 *
 * The row used to wrap one tab per conversation; with thirty conversations it
 * took most of the panel and the chat area kept a fraction of its height.
 * Measured here with getBoundingClientRect() in a real browser, because jsdom
 * does no layout: the chat area must have the same height with thirty
 * conversations as with three.
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
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
    }
    await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });
    // Until init() has settled the panel renders a spinner instead of the chat.
    await page.waitForFunction(() => (document.querySelector('ai-chat-panel') as any).chat.loading === false, null, { timeout: 10000 });
}

/** Create conversations through the chat's own endpoint until there are at least n. */
async function ensureConversations(page: Page, n: number): Promise<void> {
    await page.evaluate(async (n) => {
        const urls = (window as any).TYPO3.settings.ajaxUrls;
        const list = await (await fetch(urls.ai_chat_conversations, { credentials: 'same-origin' })).json();
        for (let i = list.conversations.length; i < n; i++) {
            await fetch(urls.ai_chat_conversation_create, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: '{}',
            });
        }
    }, n);
}

/** Open the panel at a fixed size, show the first `count` conversations, and measure. */
async function measure(page: Page, count: number) {
    return page.evaluate(async (count) => {
        const panel = document.querySelector('ai-chat-panel') as any;
        await panel.chat.loadConversations();
        panel.chat.conversations = panel.chat.conversations.slice(0, count);
        panel.chat.activeUid = panel.chat.conversations[0].uid;
        panel._width = 480;
        panel._height = 500;
        panel.state = 'expanded';
        panel.requestUpdate();
        await panel.updateComplete;
        const root = panel.shadowRoot;
        const height = (selector: string) => Math.round(root.querySelector(selector).getBoundingClientRect().height);
        return {
            row: height('.conv-tabs'),
            chat: height('.panel-messages'),
            tabs: root.querySelectorAll('.conv-tablist [role="tab"]').length,
        };
    }, count);
}

test.describe('AI Chat Panel conversation row', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
        await ensureConversations(page, 30);
    });

    test('the chat area keeps its height with thirty conversations', async ({ page }) => {
        const few = await measure(page, 3);
        const many = await measure(page, 30);

        expect(many.tabs).toBeLessThanOrEqual(4);
        expect(many.row).toBe(few.row);
        expect(many.chat).toBe(few.chat);
    });

    test('the rest of the conversations are reachable from the keyboard', async ({ page }) => {
        await measure(page, 30);
        await page.locator('ai-chat-panel .conv-tab-more').focus();
        await page.keyboard.press('Enter');

        const search = page.locator('ai-chat-panel .conv-more-search');
        await expect(search).toBeFocused();
        await expect(page.locator('ai-chat-panel #conv-more-list [role="option"]')).toHaveCount(26);

        await page.keyboard.press('ArrowDown');
        const picked = await search.getAttribute('aria-activedescendant');
        await page.keyboard.press('Enter');

        await expect(page.locator('ai-chat-panel .conv-more-popover')).toHaveCount(0);
        const active = await page.evaluate(() => (document.querySelector('ai-chat-panel') as any).chat.activeUid);
        expect(picked).toBe(`conv-more-option-${active}`);
    });
});
