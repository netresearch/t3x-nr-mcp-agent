// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { test as base, expect, Page } from '@playwright/test';
import { readdirSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * What every spec shares: an error guard and the backend shell of TYPO3 13.4
 * and 14.3.
 *
 * The guard collects, in the top frame and every iframe of the page (the
 * module frame, the FAL picker's), and fails the test after it ran:
 *  - console errors, and console warnings that come from this extension's
 *    own modules,
 *  - uncaught exceptions (`pageerror`),
 *  - failed responses of this extension: its AJAX routes (`/ajax/ai-chat/`)
 *    and its own assets, status 400 and above or a request that failed.
 *
 * A request aborted on purpose (`net::ERR_ABORTED`: a navigation leaving the
 * page, the module frame loading another module, the chat panel moving and
 * aborting its controller's requests by design) is not a failure.
 *
 * A spec that provokes an error on purpose declares it with
 * `expectedErrors.push(/pattern/)`; it is removed from the list instead of
 * failing the test, and the test fails if it did not occur.
 */

/** Messages that are not this extension's and are documented as harmless. */
const FOREIGN_NOISE: { pattern: RegExp; why: string }[] = [
    {
        pattern: /net::ERR_NETWORK_CHANGED/,
        why: 'Chromium aborts every load in flight when the host changes its network interfaces '
            + '(Docker or WSL adding one); seen once for core assets on a local run, not caused by the page',
    },
];

/** Every public file of this extension, as its path below `Resources/Public/`. */
const PUBLIC_DIR = fileURLToPath(new URL('../../../../Resources/Public', import.meta.url));
function publicFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        return entry.isDirectory() ? publicFiles(path) : [relative(PUBLIC_DIR, path).split(sep).join('/')];
    });
}
const OWN_ASSETS = new Set(publicFiles(PUBLIC_DIR));

/** Whether a URL is one of this extension's AJAX routes or assets. */
export function isOwnUrl(url: string): boolean {
    let path: string;
    try {
        path = new URL(url).pathname;
    } catch {
        return false;
    }
    if (path.includes('/ajax/ai-chat/')) {
        return true;
    }
    // TYPO3 publishes Resources/Public as /_assets/<hash>/.
    const asset = path.match(/\/_assets\/[0-9a-f]+\/(.+)$/);
    return asset !== null && OWN_ASSETS.has(decodeURIComponent(asset[1]));
}

/**
 * Attach the guard to a page. Returns how many of this extension's requests
 * are still in flight, so the check after the test can wait for their answer:
 * a test that ends right after a click would otherwise never see the error
 * that click causes.
 */
export function attachGuard(page: Page, problems: string[]): () => number {
    const pending = new Set<unknown>();
    page.on('request', (request) => {
        if (isOwnUrl(request.url())) {
            pending.add(request);
        }
    });
    page.on('requestfinished', (request) => pending.delete(request));
    page.on('console', (msg) => {
        const where = msg.location()?.url ?? '';
        if (msg.type() === 'error' || (msg.type() === 'warning' && isOwnUrl(where))) {
            problems.push(`console.${msg.type()}: ${msg.text()}${where ? ` (${where})` : ''}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`uncaught: ${error.message}`));
    page.on('response', (response) => {
        if (response.status() >= 400 && isOwnUrl(response.url())) {
            problems.push(`HTTP ${response.status()}: ${response.request().method()} ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        pending.delete(request);
        const failure = request.failure()?.errorText ?? '';
        if (isOwnUrl(request.url()) && !failure.includes('ERR_ABORTED')) {
            problems.push(`request failed (${failure}): ${request.method()} ${request.url()}`);
        }
    });

    return () => pending.size;
}

export const test = base.extend<{ expectedErrors: RegExp[]; errorGuard: void }>({
    expectedErrors: async ({}, use) => {
        await use([]);
    },
    errorGuard: [async ({ page, expectedErrors }, use, testInfo) => {
        const problems: string[] = [];
        const inFlight = attachGuard(page, problems);
        await use();
        // Up to 3 s for answers still on their way; a poll that keeps a
        // request open is not waited for longer than that.
        for (let waited = 0; inFlight() > 0 && waited < 3000 && !page.isClosed(); waited += 100) {
            await page.waitForTimeout(100);
        }
        if (testInfo.status !== testInfo.expectedStatus) {
            // The test failed already; list what the guard saw beside it.
            if (problems.length > 0) {
                testInfo.annotations.push({ type: 'error-guard', description: problems.join('\n') });
            }
            return;
        }
        const unexpected = problems.filter((problem) => !FOREIGN_NOISE.some(({ pattern }) => pattern.test(problem))
            && !expectedErrors.some((pattern) => pattern.test(problem)));
        const missing = expectedErrors.filter((pattern) => !problems.some((problem) => pattern.test(problem)));
        expect(unexpected, 'console errors, uncaught exceptions or failed requests of this extension').toEqual([]);
        expect(missing.map(String), 'errors the test provokes did not occur').toEqual([]);
    }, { auto: true }],
});

export { expect };

/** The module menu: `.scaffold-modulemenu` in 13.4, `.scaffold-sidebar` in 14.3. */
export const MODULE_MENU = '.scaffold-modulemenu, .scaffold-sidebar';

/** Wait until the backend shell stands, in 13.4 and 14.3. */
export async function waitForBackend(page: Page): Promise<void> {
    await expect(page.locator('typo3-backend-module-router')).toBeAttached({ timeout: 15000 });
    await expect(page.locator(MODULE_MENU).first()).toBeVisible({ timeout: 15000 });
}
