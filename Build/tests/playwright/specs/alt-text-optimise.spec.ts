// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Locator, Page } from '@playwright/test';
import { test, expect, waitForBackend, dashboardWidgetsLoaded } from '../fixtures/backend';

/**
 * "Optimieren" in the list of missing alt texts (ADR-026): the chat opens as
 * the column beside the list, on the row's image, and the two follow each
 * other; on a proposal the chat offers the specification's answers.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): alt-texts-seed.sql adds
 * three images without an alt text and the administrator `e2e-alttexts`;
 * alt-text-optimise-seed.sql enables the two tools of the flow and holds a
 * conversation of that user waiting on a proposed alt text for the image of
 * "E2E Hidden". Nothing here writes an image, so missing-alt-texts.spec.ts
 * still finds three.
 *
 * The E2E provider is never reachable (nr-llm-task-seed.sql): the run that
 * proposes the text fails within seconds, and the conversation waits for the
 * editor again.
 */

const TYPO3_USER = 'e2e-alttexts';
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
    await dashboardWidgetsLoaded(page);
}

/** The list, reached from "Website prüfen" on the dashboard. */
async function openList(page: Page): Promise<FrameLocator> {
    await page.locator('[data-modulemenu-identifier="dashboard"]').first().click();
    const frame = page.frameLocator('iframe[name="list_frame"]');
    const link = frame.locator('.nr-mcp-agent-website-check').getByRole('link', { name: 'Missing alt texts' });
    await expect(link).toBeVisible({ timeout: 15000 });
    await dashboardWidgetsLoaded(page);
    await expect(async () => {
        await link.click({ timeout: 5000 });
        await expect(frame.getByRole('heading', { level: 1 })).toHaveText('3 missing alt texts', { timeout: 5000 });
    }).toPass({ timeout: 30000 });

    return frame;
}

/** Whether the module menu is unfolded, in the shape of 14.3 or of 13.4. */
async function moduleMenuOpen(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const scaffold = document.querySelector('.t3js-scaffold');
        return Boolean(scaffold?.classList.contains('scaffold-sidebar-expanded') || scaffold?.classList.contains('scaffold-modulemenu-expanded'));
    });
}

/** The conversation's run has ended: the conversation waits for the editor. */
async function runEnded(panel: Locator): Promise<void> {
    await expect.poll(() => panel.evaluate((el: any) => el.chat.status), { timeout: 20000 }).not.toBe('processing');
}

test.describe('"Optimieren" in the list of missing alt texts', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('opens the chat beside the list on the image, moves on to the next hit with the list, and gives the focus back', async ({ page }) => {
        const frame = await openList(page);
        const panel = page.locator('ai-chat-panel');
        const menuWasOpen = await moduleMenuOpen(page);
        const row = frame.locator('#nr-alt-texts-row-9001');

        await frame.getByRole('button', { name: /^Optimise\s*: e2e-team\.jpg$/ }).click();

        // The column beside the list, the module menu folded, the row marked.
        await expect(panel).toHaveAttribute('layout', 'column');
        await expect.poll(() => moduleMenuOpen(page)).toBe(false);
        await expect(row).toHaveAttribute('aria-current', 'true');
        await expect(row.getByRole('button', { name: /^In the chat\s*: e2e-team\.jpg$/ })).toBeVisible();

        // The first message is the server's image card; the focus is on it.
        const heading = panel.getByRole('heading', { name: 'Add an alt text' });
        await expect(heading).toBeFocused();
        const card = panel.locator('.alt-text-card');
        await expect(card.locator('dd')).toContainText(['e2e-team.jpg', 'E2E Alt texts']);
        await expect(panel.locator('.alt-text-context .alt-text-chip').first()).toHaveText('E2E Alt texts');
        // The values are filled in, also where the label orders them by number.
        await expect(panel.locator('.alt-text-context-info')).toHaveText(/in the element Text & Images – “E2E Team” on the page E2E Alt texts\./);
        await runEnded(panel);

        // "Optimise the next hit": the divider naming the new image, its card,
        // the list follows.
        await panel.getByRole('button', { name: 'Optimise the next hit' }).click();
        await expect(panel.getByRole('separator', { name: 'Hit changed · e2e-pattern.jpg · E2E Alt texts' })).toBeVisible();
        await expect(panel.locator('.alt-text-card')).toHaveCount(2);
        await expect(frame.locator('#nr-alt-texts-row-9002')).toHaveAttribute('aria-current', 'true');
        await expect(row).not.toHaveAttribute('aria-current', 'true');
        await runEnded(panel);

        // Closing: the highlight goes, the menu unfolds again, the focus is
        // back on the row the chat was on.
        await panel.getByRole('button', { name: 'Close' }).first().click();
        await expect(frame.locator('[aria-current="true"][data-nr-alt-text-row]')).toHaveCount(0);
        await expect.poll(() => moduleMenuOpen(page)).toBe(menuWasOpen);
        await expect(frame.getByRole('button', { name: /^Optimise\s*: e2e-pattern\.jpg$/ })).toBeFocused();
    });

    test('a proposal offers the answers; "Mark as decorative" warns first, and "Skip" saves nothing', async ({ page }) => {
        const panel = page.locator('ai-chat-panel');
        await page.locator('.ai-chat-toolbar-btn').click();
        await expect(panel).toHaveAttribute('state', 'expanded');
        const tab = panel.getByRole('tab', { name: 'E2E alt text proposal' });
        if (await tab.count() > 0) {
            await tab.click();
        } else {
            await panel.locator('.conv-tab-more').click();
            await panel.getByRole('combobox', { name: /search/i }).fill('E2E alt text proposal');
            await page.keyboard.press('Enter');
        }

        const proposal = panel.locator('.alt-text-proposal');
        await expect(proposal).toContainText('Two people at a desk');
        await expect(proposal.getByRole('group', { name: 'Answers' }).getByRole('button'))
            .toHaveText(['Apply', 'Another variant', 'Mark as decorative', 'Skip']);

        // The image belongs to the section "E2E Hidden": the warning comes first.
        await proposal.getByRole('button', { name: 'Mark as decorative' }).click();
        await expect(proposal.getByRole('alert')).toContainText('E2E Hidden');
        await expect(proposal.getByRole('button')).toHaveText(['Yes, mark as decorative', 'Use an alt text after all']);

        // "Use an alt text after all": the proposal is back, nothing was saved.
        await proposal.getByRole('button', { name: 'Use an alt text after all' }).click();
        await expect(proposal.getByRole('alert')).toHaveCount(0);
        await expect(proposal).toContainText('Two people at a desk');

        // "Skip": the chat's own answer, the proposal withdrawn.
        await proposal.getByRole('button', { name: 'Skip' }).click();
        await expect(panel.getByText('Skipped. The hit stays open in the list.')).toBeVisible();
        await expect(panel.locator('.alt-text-proposal')).toHaveCount(0);
        await expect.poll(() => panel.evaluate((el: any) => el.chat.status)).toBe('idle');

        await panel.getByRole('button', { name: 'Close' }).first().click();
    });
});
