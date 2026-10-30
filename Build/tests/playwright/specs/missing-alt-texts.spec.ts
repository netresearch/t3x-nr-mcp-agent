// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { FrameLocator, Locator, Page } from '@playwright/test';
import { test, expect, waitForBackend, dashboardWidgetsLoaded } from '../fixtures/backend';

/**
 * The list "Fehlende Alt-Texte" (ADR-026) and its two dashboard entries,
 * driven as an editor drives them: by mouse and by keyboard.
 *
 * Fixtures (seed-and-test.sh, `configured` variant): alt-texts-seed.sql adds
 * three images without an alt text on the page "E2E Alt texts", two in
 * content elements (one of them hidden) and one in a file collection, and an
 * administrator `e2e-alttexts` whose only dashboard holds "Website prüfen" and
 * "Für deine Website empfohlen"; the admin's dashboard, which the other
 * dashboard specs drive, is not touched. No model is called.
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
    // The startup module is the dashboard: let its widgets load before leaving.
    await dashboardWidgetsLoaded(page);
}

async function routerModule(page: Page): Promise<string> {
    return page.evaluate(() => (document.querySelector('typo3-backend-module-router') as any)?.module ?? '');
}

/** The dashboard, with both entries rendered. */
async function openDashboard(page: Page): Promise<FrameLocator> {
    await page.locator('[data-modulemenu-identifier="dashboard"]').first().click();
    await expect.poll(() => routerModule(page)).toMatch(/^dashboard$/);
    const frame = page.frameLocator('iframe[name="list_frame"]');
    await expect(frame.locator('.nr-mcp-agent-website-check')).toBeVisible({ timeout: 15000 });
    await expect(frame.locator('.nr-mcp-agent-tile-altTexts')).toBeVisible({ timeout: 15000 });
    await dashboardWidgetsLoaded(page);

    return frame;
}

/**
 * In 14.3 the core's tab module moves every `.nav-tabs` into its scroller
 * element once the page is ready, and moving an element takes its focus. A
 * key pressed before that lands on nothing; 13.4 has no such element.
 */
async function tabsSettled(page: Page, frame: FrameLocator): Promise<void> {
    if (await page.locator('.scaffold-sidebar').count() > 0) {
        await expect(frame.locator('typo3-backend-tab-scroller [role="tablist"]')).toBeAttached({ timeout: 15000 });
    }
}

/**
 * Click a dashboard link to the list until the list shows. The dashboard can
 * still re-render a widget after it reported its content, and a click on the
 * element it replaces is lost (seen once in five local runs on 14.3).
 */
async function follow(frame: FrameLocator, link: Locator): Promise<void> {
    await expect(async () => {
        await link.click({ timeout: 5000 });
        await expect(frame.getByRole('heading', { level: 1 })).toHaveText('3 missing alt texts', { timeout: 5000 });
    }).toPass({ timeout: 30000 });
}

/** The list, reached from "Website prüfen". */
async function openList(page: Page): Promise<FrameLocator> {
    const frame = await openDashboard(page);
    await follow(frame, frame.locator('.nr-mcp-agent-website-check').getByRole('link', { name: 'Missing alt texts' }));

    return frame;
}

test.describe('The list of missing alt texts', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('"Website prüfen" always lists the check and leads to the list', async ({ page }) => {
        const frame = await openList(page);

        await expect(frame.getByRole('navigation', { name: 'Breadcrumb' }).getByRole('listitem'))
            .toHaveText(['AI Assistant', 'Check website', 'Missing alt texts']);
        await expect(frame.getByText('Images that are currently used without a suitable alternative text.')).toBeVisible();
        await expect(frame.getByRole('note')).toContainText('An alt text has to fit the context the image is used in.');
    });

    test('the tile in "Recommended for your website" counts the images and leads to the list', async ({ page }) => {
        const frame = await openDashboard(page);
        const tile = frame.locator('.nr-mcp-agent-tile-altTexts');

        await expect(tile).toContainText('3');
        await expect(tile).toContainText('Images without alt text');
        await expect(tile).toContainText('Found on 1 page and in 1 record');

        // The name starts with the visible "View" and says the number and what it counts.
        await follow(frame, tile.getByRole('link', { name: 'View: 3 Images without alt text' }));
    });

    test('"Pages" comes first and open, each record type follows as a tab', async ({ page }) => {
        const frame = await openList(page);
        const tabs = frame.getByRole('tablist', { name: 'Hits by origin' }).getByRole('tab');

        await expect(tabs).toHaveCount(2);
        await expect(tabs.nth(0)).toHaveAccessibleName(/^Pages\s*2 open$/);
        await expect(tabs.nth(1)).toHaveAccessibleName(/^File collections?\s*1 open$/i);
        await expect(tabs.nth(0)).toHaveAttribute('aria-selected', 'true');

        const pages = frame.getByRole('tabpanel', { name: /^Pages/ });
        await expect(pages.getByRole('columnheader')).toHaveText(['Image', 'File', 'Content element', 'Language', 'Actions']);
        // Each page heads its group of rows.
        await expect(pages.getByRole('rowheader')).toHaveText(['E2E Alt texts']);
        await expect(pages.getByRole('cell', { name: 'e2e-team.jpg', exact: true })).toBeVisible();
        // A hidden content element stays in the list, marked with its reason in words.
        await expect(pages.getByText('Currently not visible · Element hidden')).toBeVisible();
    });

    test('the tabs work by keyboard: arrows, Home and End', async ({ page }) => {
        const frame = await openList(page);
        const tabs = frame.getByRole('tablist', { name: 'Hits by origin' }).getByRole('tab');
        await tabsSettled(page, frame);

        await tabs.nth(0).focus();
        await page.keyboard.press('ArrowRight');
        await expect(tabs.nth(1)).toBeFocused();
        await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
        await expect(frame.getByRole('tabpanel', { name: /File collection/i })).toBeVisible();
        await expect(frame.getByRole('tabpanel', { name: /^Pages/ })).toBeHidden();
        await expect(frame.getByRole('tabpanel', { name: /File collection/i }).getByRole('columnheader', { name: 'Field' })).toBeVisible();

        await page.keyboard.press('Home');
        await expect(tabs.nth(0)).toBeFocused();
        await expect(frame.getByRole('tabpanel', { name: /^Pages/ })).toBeVisible();

        await page.keyboard.press('End');
        await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');

        // Tab leaves the tab list for the open panel.
        await page.keyboard.press('Tab');
        await expect(frame.getByRole('tabpanel', { name: /File collection/i })).toBeFocused();
    });
});
