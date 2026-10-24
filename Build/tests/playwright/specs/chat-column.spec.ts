// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { test, expect, Locator, Page } from '@playwright/test';

/**
 * The chat docked as a column beside the page tree (ADR-024).
 *
 * The column is a sibling before the module router in the backend's content
 * area: "page tree | chat | module" in modules with a tree, the leftmost
 * column in the Dashboard. Layout and width are stored in the backend user's
 * settings (uc), so they survive a reload.
 *
 * jsdom lays nothing out, so the geometry is only checked here. The specs read
 * the scaffold of both TYPO3 13.4 and 14.3; CI runs them against 13.4 only.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): the pages in
 * pages-seed.sql fill the page tree, the conversation in
 * approval-card-seed.sql waits for an approval.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

/** The title of the seeded conversation (approval-card-seed.sql). */
const CONVERSATION_TITLE = 'E2E approval card';
/** The root page of pages-seed.sql. */
const PAGE_TITLE = 'E2E Home';
const PAGE_UID = 1;

/** The column's limits and default, mirrored from chat-column.js. */
const MIN_WIDTH = 320;
const DEFAULT_WIDTH = 380;
const KEY_STEP = 16;

/** The page tree: the navigation slot (14.3) or the navigation container (13.4). */
const NAVIGATION = 'typo3-backend-content-navigation[identifier="backend"] > [slot="navigation"], .t3js-scaffold-content-navigation';
/** The content area holding the open column. */
const OPEN_AREA = '[data-nr-chat-column="open"]';
/** The content area, open column or not: 14.3's content slot, 13.4's module container. */
const AREA = 'typo3-backend-content-navigation[identifier="backend"] > [slot="content"], .t3js-scaffold-content-module';

async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await expect(page.locator('typo3-backend-module-router')).toBeAttached({ timeout: 15000 });
    }
    await page.waitForFunction(() => !!(window as any).TYPO3?.settings?.ajaxUrls?.ai_chat_conversations, null, { timeout: 10000 });
}

/** Write the panel's preferences into the user's settings, as the panel does. */
async function storePreferences(page: Page, layout: string, width: string): Promise<void> {
    await page.evaluate(async ({ layout, width }) => {
        const { default: Persistent } = await import('@typo3/backend/storage/persistent.js' as string);
        await Persistent.set('nrMcpAgent.chatPanel.layout', layout);
        await Persistent.set('nrMcpAgent.chatPanel.columnWidth', width);
    }, { layout, width });
}

async function openPanel(page: Page): Promise<Locator> {
    await page.waitForFunction(() => (document.querySelector('ai-chat-panel') as any)?.chat?.loading === false, null, { timeout: 10000 });
    await page.locator('.ai-chat-toolbar-btn').click();
    const panel = page.locator('ai-chat-panel');
    await expect(panel).toHaveAttribute('state', 'expanded', { timeout: 3000 });
    return panel;
}

async function openColumn(page: Page): Promise<Locator> {
    const panel = await openPanel(page);
    await panel.locator('[data-action="layout"]').click();
    await expect(panel).toHaveAttribute('layout', 'column');
    await expect(page.locator(OPEN_AREA)).toHaveCount(1);
    return panel;
}

async function openModule(page: Page, identifier: string): Promise<void> {
    await page.locator(`[data-modulemenu-identifier="${identifier}"]`).first().click();
    await page.waitForFunction((id) => (document.querySelector('typo3-backend-module-router') as any)?.module === id, identifier, { timeout: 10000 });
}

/** Left and right edges of the page tree, the panel, the router and the content area. */
async function edges(page: Page) {
    return page.evaluate(({ navigation, areaSelector }) => {
        const box = (el: Element | null) => {
            const r = el?.getBoundingClientRect();
            return r && r.width > 0 ? { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) } : null;
        };
        const panel = document.querySelector('ai-chat-panel')!;
        return {
            navigation: box(document.querySelector(navigation)),
            panel: box(panel),
            router: box(document.querySelector('typo3-backend-module-router')),
            area: box(document.querySelector(areaSelector)),
            next: panel.nextElementSibling?.localName ?? '',
            inBody: panel.parentElement === document.body,
        };
    }, { navigation: NAVIGATION, areaSelector: AREA });
}

/** The seeded conversation's uid; a broken seed fails here. */
async function seededConversation(page: Page): Promise<number> {
    const uid = await page.evaluate(async (title) => {
        const urls = (window as any).TYPO3.settings.ajaxUrls;
        const list = await (await fetch(urls.ai_chat_conversations, { credentials: 'same-origin' })).json();
        return list.conversations.find((c: { title: string }) => c.title === title)?.uid ?? 0;
    }, CONVERSATION_TITLE);
    expect(uid, `no conversation titled "${CONVERSATION_TITLE}"; is approval-card-seed.sql loaded?`).toBeGreaterThan(0);
    return uid;
}

async function selectConversation(page: Page, uid: number): Promise<void> {
    await page.evaluate(async (uid) => {
        const panel = document.querySelector('ai-chat-panel') as any;
        await panel.chat.loadConversations();
        await panel.chat.selectConversation(uid);
        await panel.updateComplete;
    }, uid);
}

test.describe('Chat as a column beside the page tree', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
        await storePreferences(page, 'floating', '');
        // The panel read the old preferences when the backend loaded.
        await page.reload();
        await login(page);
    });

    test.afterEach(async ({ page }) => {
        // The other specs expect the floating panel.
        await storePreferences(page, 'floating', '');
    });

    test('opens between the page tree and the module', async ({ page }) => {
        await openModule(page, 'web_layout');
        await openColumn(page);
        await page.getByRole('treeitem', { name: PAGE_TITLE }).click();

        await expect.poll(async () => (await edges(page)).next).toBe('typo3-backend-module-router');
        const e = await edges(page);
        expect(e.navigation, 'the page module shows no page tree').not.toBeNull();
        expect(Math.abs(e.panel!.left - e.navigation!.right), 'the column does not start at the page tree').toBeLessThanOrEqual(2);
        expect(Math.abs(e.router!.left - e.panel!.right), 'the module does not start at the column').toBeLessThanOrEqual(2);
        expect(e.router!.right, 'the module overflows the content area').toBeLessThanOrEqual(e.area!.right + 1);
        expect(e.router!.width, 'the module is narrower than the column').toBeGreaterThanOrEqual(e.panel!.width - 1);
        // At most half the content area: beside the tree in a 1280px window
        // that is less than the default.
        const expected = Math.min(DEFAULT_WIDTH, Math.max(MIN_WIDTH, Math.floor(e.area!.width / 2)));
        expect(Math.abs(e.panel!.width - expected)).toBeLessThanOrEqual(1);
    });

    // Proves the panel listens: no guided process runs, so the chat's poll is
    // off and only the page-tree events can update the page. Whether the
    // tree's selection wins over the module URL is pinned in
    // chat-page-context.test.js; this browser run cannot tell the two apart.
    test('refreshes the chat\'s page when a page is selected in the tree', async ({ page }) => {
        await openModule(page, 'web_layout');
        const panel = await openColumn(page);

        await page.getByRole('treeitem', { name: PAGE_TITLE }).click();

        await expect.poll(() => panel.evaluate((el: any) => el.chat.backendPage.pageId), { timeout: 5000 }).toBe(PAGE_UID);
    });

    test('stays the leftmost column of the content in the Dashboard', async ({ page }) => {
        await openModule(page, 'web_layout');
        const panel = await openColumn(page);

        await openModule(page, 'dashboard');

        await expect(panel).toBeVisible();
        await expect(page.locator(OPEN_AREA)).toHaveCount(1);
        const e = await edges(page);
        expect(e.panel!.left).toBe(e.area!.left);
        expect(Math.abs(e.router!.left - e.panel!.right)).toBeLessThanOrEqual(2);
        expect(e.router!.right).toBeLessThanOrEqual(e.area!.right + 1);
    });

    test('resizes with the keyboard', async ({ page }) => {
        const panel = await openColumn(page);
        const splitter = panel.locator('[role="separator"][aria-orientation="vertical"]');
        await splitter.focus();

        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await expect(splitter).toHaveAttribute('aria-valuenow', String(DEFAULT_WIDTH + 2 * KEY_STEP));
        await expect.poll(async () => (await edges(page)).panel!.width).toBe(DEFAULT_WIDTH + 2 * KEY_STEP);

        await page.keyboard.press('ArrowLeft');
        await expect(splitter).toHaveAttribute('aria-valuenow', String(DEFAULT_WIDTH + KEY_STEP));

        await page.keyboard.press('Home');
        await expect(splitter).toHaveAttribute('aria-valuenow', String(MIN_WIDTH));
        await expect.poll(async () => (await edges(page)).panel!.width).toBe(MIN_WIDTH);

        await page.keyboard.press('End');
        const area = (await edges(page)).area!.width;
        await expect(splitter).toHaveAttribute('aria-valuenow', String(Math.max(MIN_WIDTH, Math.floor(area / 2))));
    });

    test('keeps layout and width after a reload', async ({ page }) => {
        const panel = await openColumn(page);
        const splitter = panel.locator('[role="separator"]');
        await splitter.focus();

        const stored = page.waitForResponse((r) => r.request().method() === 'POST'
            && (r.request().postData() ?? '').includes('columnWidth'));
        for (let i = 0; i < 4; i++) {
            await page.keyboard.press('ArrowRight');
        }
        await stored;
        const width = DEFAULT_WIDTH + 4 * KEY_STEP;

        await page.reload();
        await login(page);
        const reopened = await openPanel(page);

        await expect(reopened).toHaveAttribute('layout', 'column');
        await expect(reopened.locator('[role="separator"]')).toHaveAttribute('aria-valuenow', String(width));
        await expect.poll(async () => (await edges(page)).panel!.width).toBe(width);
    });

    test('minimizes to a bar in the corner and opens again', async ({ page }) => {
        const uid = await seededConversation(page);
        const panel = await openColumn(page);
        await selectConversation(page, uid);

        await panel.locator('[data-action="minimize"]').focus();
        await page.keyboard.press('Enter');

        await expect(panel).toHaveAttribute('state', 'collapsed');
        // Focus stays in the chat, on the way back, not on the body.
        await expect(panel.locator('[data-action="reopen"]')).toBeFocused();
        const bar = panel.locator('.minimized-bar');
        await expect(bar).toBeVisible();
        await expect(bar.locator('.minimized-title')).toHaveText(CONVERSATION_TITLE);
        await expect(bar.locator('.minimized-status')).toHaveText('Waiting for your approval');
        await expect(page.locator(OPEN_AREA)).toHaveCount(0);

        const viewport = page.viewportSize()!;
        const box = (await panel.boundingBox())!;
        expect(Math.round(viewport.width - (box.x + box.width)), 'the bar is not in the right corner').toBe(24);
        expect(Math.round(viewport.height - (box.y + box.height)), 'the bar is not at the bottom').toBe(24);
        const e = await edges(page);
        expect(e.router!.width, 'the module did not get its width back').toBe(e.area!.width);
        for (const button of await bar.getByRole('button').all()) {
            const target = (await button.boundingBox())!;
            expect(Math.min(target.width, target.height), 'a button of the bar is smaller than 44px').toBeGreaterThanOrEqual(44);
        }

        await page.keyboard.press('Enter');
        await expect(panel).toHaveAttribute('state', 'expanded');
        await expect(page.locator(OPEN_AREA)).toHaveCount(1);
        // A conversation waiting for an approval has its input enabled.
        await expect(panel.locator('.panel-input textarea')).toBeFocused();
    });

    test('floats in a content area too narrow for it and docks again when it is wide enough', async ({ page }) => {
        await openModule(page, 'web_layout');
        const panel = await openColumn(page);
        await panel.locator('[data-action="minimize"]').focus();
        const focusInside = () => panel.evaluate((el) => el.shadowRoot!.activeElement !== null);

        // A window narrower than twice the column's minimum: the content area
        // is too (at 760px 13.4 collapses its module menu and the area stays
        // wide enough).
        await page.setViewportSize({ width: 600, height: 720 });
        await expect(panel).toHaveAttribute('narrow', '');
        await expect(page.locator(OPEN_AREA)).toHaveCount(0);
        const narrow = await edges(page);
        expect(narrow.inBody, 'the floating panel stayed inside the content area').toBe(true);
        expect(narrow.router!.width, 'the module did not get the whole content area').toBe(narrow.area!.width);
        await expect.poll(focusInside, { message: 'focus fell out of the panel' }).toBe(true);
        const stored = await page.evaluate(async () => {
            const { default: Persistent } = await import('@typo3/backend/storage/persistent.js' as string);
            return Persistent.get('nrMcpAgent.chatPanel.layout');
        });
        expect(stored, 'the narrow area rewrote the stored choice').toBe('column');

        // Real clicks: Playwright refuses when another element would take
        // them. In 14.3 the narrow content area is a layer under the backend
        // header, so a panel left inside it had its maximized header covered.
        await panel.getByRole('button', { name: 'Maximize' }).click();
        await expect(panel).toHaveAttribute('state', 'maximized');
        await panel.getByRole('button', { name: 'Restore' }).click();
        await expect(panel).toHaveAttribute('state', 'expanded');
        await panel.getByRole('button', { name: 'Maximize' }).click();
        await expect(panel).toHaveAttribute('state', 'maximized');

        await page.setViewportSize({ width: 1280, height: 720 });
        await expect(panel).not.toHaveAttribute('narrow', '');
        await expect(page.locator(OPEN_AREA)).toHaveCount(1);
        await expect(panel.locator('[role="separator"]')).toBeVisible();
        // No maximized state in the column.
        await expect(panel).toHaveAttribute('state', 'expanded');
        await expect.poll(async () => (await edges(page)).next).toBe('typo3-backend-module-router');
        await expect.poll(focusInside, { message: 'focus fell out of the panel' }).toBe(true);
    });

    test('keeps the approval card\'s height in an overflowing transcript', async ({ page }) => {
        const uid = await seededConversation(page);
        const panel = await openColumn(page);
        await selectConversation(page, uid);

        const card = panel.locator('.approval-frame');
        await expect(card).toHaveCount(1, { timeout: 10000 });
        const column = await panel.evaluate((el) => {
            const messages = el.shadowRoot!.querySelector('.panel-messages') as HTMLElement;
            return { scroll: messages.scrollHeight, client: messages.clientHeight };
        });
        expect(column.scroll, 'the transcript does not overflow, so the test cannot see a collapse').toBeGreaterThan(column.client);

        await expect
            .poll(async () => Math.round((await card.boundingBox())?.height ?? 0), {
                message: 'the approval card collapsed in the column',
                timeout: 5000,
            })
            .toBeGreaterThan(100);
        await expect(card.locator('.approval-actions .btn-primary')).toBeVisible();
        await expect(card.getByRole('button', { name: 'Cancel' })).toBeVisible();
    });
});
