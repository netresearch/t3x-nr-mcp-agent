// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { Locator, Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * One editor's way through the chat column, clicked as an editor clicks it,
 * with the error guard of ../fixtures/backend.ts watching every step: no
 * console error, no uncaught exception, no failed request of this extension.
 * Each step asserts what the editor sees.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): pages-seed.sql fills the
 * page tree; journey-seed.sql holds two conversations waiting for an
 * approval of a change to page 2, one to approve and one to cancel. The
 * "keep" and "another variant" answers belong to proposals of a guided
 * process run, which these fixtures do not seed.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const OPEN_AREA = '[data-nr-chat-column="open"]';
const LIST_MODULE = '[data-modulemenu-identifier="web_list"], [data-modulemenu-identifier="records"]';

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

async function storePreferences(page: Page, layout: string, width: string): Promise<void> {
    await page.evaluate(async ({ layout, width }) => {
        const { default: Persistent } = await import('@typo3/backend/storage/persistent.js' as string);
        await Persistent.set('nrMcpAgent.chatPanel.layout', layout);
        await Persistent.set('nrMcpAgent.chatPanel.columnWidth', width);
    }, { layout, width });
}

async function openModule(page: Page, selector: string, identifier: RegExp): Promise<void> {
    await page.locator(selector).first().click();
    await expect.poll(() => page.evaluate(() => (document.querySelector('typo3-backend-module-router') as any)?.module ?? ''))
        .toMatch(identifier);
}

/** The column is open in the content area, before the module. */
async function expectColumn(page: Page, panel: Locator): Promise<void> {
    await expect(panel).toHaveAttribute('layout', 'column');
    await expect(panel).not.toHaveAttribute('narrow', '');
    await expect(page.locator(OPEN_AREA)).toHaveCount(1);
    await expect.poll(() => panel.evaluate((el) => el.nextElementSibling?.localName ?? '')).toBe('typo3-backend-module-router');
    await expect(panel.locator('[role="separator"]')).toBeVisible();
}

/** Open a conversation by its title, from the tab row or the "More" list. */
async function openConversation(page: Page, panel: Locator, title: string): Promise<void> {
    const tab = panel.getByRole('tab', { name: new RegExp(title) });
    if (await tab.count() > 0) {
        await tab.click();
    } else {
        await panel.locator('.conv-tab-more').click();
        await panel.getByRole('combobox', { name: /search/i }).fill(title);
        await page.keyboard.press('Enter');
    }
    await expect(panel.getByRole('tab', { name: new RegExp(title), selected: true })).toBeVisible();
}

/** One field of a multipart body, as core's AjaxRequest posts the settings. */
function formField(body: string, name: string): string | null {
    const match = body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)\\r\\n`));
    return match ? match[1] : null;
}

/** The next column width written to the user's settings (optionally a given one). */
async function storedWidth(page: Page, expected?: number): Promise<number> {
    const response = await page.waitForResponse((r) => {
        const body = r.request().postData() ?? '';
        return r.request().method() === 'POST' && formField(body, 'key') === 'nrMcpAgent.chatPanel.columnWidth'
            && (expected === undefined || formField(body, 'value') === String(expected));
    });
    return Number(formField(response.request().postData() ?? '', 'value'));
}

async function splitterWidth(panel: Locator): Promise<number> {
    return Number(await panel.locator('[role="separator"]').getAttribute('aria-valuenow'));
}

test.describe('An editor working with the chat column', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
        await storePreferences(page, 'floating', '');
        await page.reload();
        await login(page);
    });

    test.afterEach(async ({ page }) => {
        await storePreferences(page, 'floating', '');
    });

    test('from the Dashboard through a page, two approvals, the modules and back', async ({ page }) => {
        const panel = page.locator('ai-chat-panel');

        // 1. Dashboard: open the chat, dock it as a column.
        await openModule(page, '[data-modulemenu-identifier="dashboard"]', /^dashboard$/);
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(panel).toHaveAttribute('state', 'expanded');
        await panel.getByRole('button', { name: 'Column mode' }).click();
        await expectColumn(page, panel);

        // 2. A new conversation: empty, with the input ready.
        await panel.getByRole('button', { name: 'New chat' }).first().click();
        await expect(panel.locator('.panel-input textarea')).toBeEnabled();
        await expect(panel.locator('.message-row')).toHaveCount(0);

        // 3. The page module, a page from the tree: the chat follows it, the
        //    module shows it.
        await openModule(page, '[data-modulemenu-identifier="web_layout"]', /^web_layout$/);
        await page.getByRole('treeitem', { name: 'E2E Home' }).click();
        await expect.poll(() => panel.evaluate((el: any) => el.chat.backendPage.pageId)).toBe(1);
        await expect(page.frameLocator('iframe[name="list_frame"]').getByRole('heading', { name: 'E2E Home' }).first()).toBeVisible();
        await expectColumn(page, panel);

        // 4. Approve a change.
        await openConversation(page, panel, 'E2E journey approve');
        const card = panel.locator('.approval-frame');
        await expect(card).toBeVisible();
        await expect(card).toContainText('E2E Subpage renamed');
        await card.locator('.approval-actions .btn-primary').click();
        await expect(panel.getByText('Approval granted — the assistant is carrying the step out.')).toBeVisible();
        // The worker answers within seconds: the change is applied, or the run
        // comes back with the reason in the card. In the E2E installation
        // nr-llm refuses the admin as approver of this write
        // (approverNotPermitted), and the chat must say so instead of
        // leaving the optimistic line standing.
        await expect(panel.getByText('Approval granted — the assistant is carrying the step out.')).toHaveCount(0, { timeout: 15000 });
        const outcome = await panel.evaluate((el: any) => ({ status: el.chat.status, error: el.chat.errorMessage }));
        if (outcome.status === 'awaiting_approval') {
            expect(outcome.error, 'the run came back without a reason').not.toBe('');
            await expect(card).toContainText(outcome.error);
            await expect(card.locator('.approval-actions')).toBeVisible();
        } else {
            await expect(card.locator('.approval-actions')).toHaveCount(0);
        }

        // 5. Cancel another one.
        await openConversation(page, panel, 'E2E journey cancel');
        const secondCard = panel.locator('.approval-frame');
        await expect(secondCard).toBeVisible();
        await secondCard.getByRole('button', { name: 'Cancel' }).click();
        await expect(panel.getByText('Cancelled — the step was not carried out.')).toBeVisible();
        await expect(panel.locator('.approval-actions')).toHaveCount(0);

        // 6. Minimize to the bar, open the column again from it.
        await panel.getByRole('button', { name: 'Minimize' }).click();
        await expect(panel).toHaveAttribute('state', 'collapsed');
        await expect(panel.locator('.minimized-bar')).toBeVisible();
        await expect(panel.locator('.minimized-title')).toHaveText('E2E journey cancel');
        await expect(page.locator(OPEN_AREA)).toHaveCount(0);
        await panel.getByRole('button', { name: 'Open the chat again' }).click();
        await expect(panel).toHaveAttribute('state', 'expanded');
        await expectColumn(page, panel);

        // 7. Other modules: the column stays.
        await openModule(page, LIST_MODULE, /^(web_list|records)$/);
        await expectColumn(page, panel);
        await openModule(page, '[data-modulemenu-identifier="dashboard"]', /^dashboard$/);
        await expectColumn(page, panel);
        await openModule(page, '[data-modulemenu-identifier="web_layout"]', /^web_layout$/);
        await expectColumn(page, panel);

        // 8. Resize: drag the splitter, then the keyboard.
        const before = await splitterWidth(panel);
        const handle = (await panel.locator('[role="separator"]').boundingBox())!;
        await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
        await page.mouse.down();
        await page.mouse.move(handle.x + handle.width / 2 - 40, handle.y + handle.height / 2, { steps: 4 });
        // The drag stores its width once, when it ends.
        const dragStored = storedWidth(page);
        await page.mouse.up();
        const dragged = await dragStored;
        expect(dragged).toBeLessThan(before);
        await expect.poll(() => splitterWidth(panel)).toBe(dragged);
        const width = Math.max(320, dragged - 16);
        await panel.locator('[role="separator"]').focus();
        const keyStored = storedWidth(page, width);
        await page.keyboard.press('ArrowLeft');
        await expect.poll(() => splitterWidth(panel)).toBe(width);
        await keyStored;
        await expect.poll(async () => Math.round((await panel.boundingBox())!.width)).toBe(width);

        // 9. A window too narrow for the column, and wide again.
        await page.setViewportSize({ width: 600, height: 720 });
        await expect(panel).toHaveAttribute('narrow', '');
        await expect(page.locator(OPEN_AREA)).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Close' })).toBeVisible();
        await page.setViewportSize({ width: 1280, height: 720 });
        await expectColumn(page, panel);

        // 10. Reload: the column and its width come back.
        await page.reload();
        await login(page);
        await page.locator('.ai-chat-toolbar-btn').click();
        await expectColumn(page, panel);
        await expect(panel.locator('[role="separator"]')).toHaveAttribute('aria-valuenow', String(width));

        // 11. Floating and back to the column.
        await panel.getByRole('button', { name: 'Column mode' }).click();
        await expect(panel).toHaveAttribute('layout', 'floating');
        await expect.poll(() => panel.evaluate((el) => el.parentElement === document.body)).toBe(true);
        await expect(page.locator(OPEN_AREA)).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Column mode' })).toHaveAttribute('aria-pressed', 'false');
        await panel.getByRole('button', { name: 'Column mode' }).click();
        await expectColumn(page, panel);
    });
});
