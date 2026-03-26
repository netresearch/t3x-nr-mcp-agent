import { test, expect, Page } from '@playwright/test';

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

/**
 * Login and navigate to the TYPO3 backend.
 * Returns true when the backend shell is ready.
 */
async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
    }
}

/**
 * Wait until ai-chat-panel is present and the chat controller is ready
 * (TYPO3 settings are populated, elementBrowserUrl is available).
 */
async function waitForChatPanel(page: Page): Promise<void> {
    await page.waitForFunction(
        () => !!document.querySelector('ai-chat-panel'),
        null,
        { timeout: 10000 },
    );
    // Ensure TYPO3 backend settings (including elementBrowserUrl) are injected
    await page.waitForFunction(
        () => !!(window as any).top?.TYPO3?.settings?.Wizards?.elementBrowserUrl,
        null,
        { timeout: 10000 },
    );
}

/**
 * Open the FAL picker overlay by dispatching the custom event directly on the
 * ai-chat-panel element (bypasses the UI button so tests don't depend on
 * visionSupported or an active conversation).
 *
 * Returns false if elementBrowserUrl is not set (TYPO3 settings not ready).
 */
async function openFalPicker(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const panel = document.querySelector('ai-chat-panel');
        if (!panel) return false;
        panel.dispatchEvent(
            new CustomEvent('nr-mcp-open-fal-picker', { bubbles: true, composed: true }),
        );
        return true;
    });
}

// ── helpers to query inside shadow DOM ────────────────────────────────────────

async function getOverlay(page: Page) {
    // The overlay is appended to document.body (light DOM), not shadow DOM
    return page.locator('body > [aria-modal="true"]');
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

test.describe('FAL picker overlay', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
        // Use the main backend page — ai-chat-panel is injected there
        await page.locator('.ai-chat-toolbar-btn').click();
        await waitForChatPanel(page);
    });

    // ── 1. Overlay lifecycle ───────────────────────────────────────────────

    test('opens overlay with t3js-modal-iframe when elementBrowserUrl is available', async ({ page }) => {
        const triggered = await openFalPicker(page);
        if (!triggered) test.skip();

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        // Must contain an <iframe class="t3js-modal-iframe"> (required by TYPO3 getParent())
        const iframe = overlay.locator('iframe.t3js-modal-iframe');
        await expect(iframe).toBeVisible();

        // iframe src must include mode=file and our bparams
        const src = await iframe.getAttribute('src') ?? '';
        expect(src).toContain('mode=file');
        expect(src).toContain('bparams=');
        expect(decodeURIComponent(src)).toContain('nr_mcp_agent_fal_picker');
    });

    test('overlay has correct ARIA attributes (role=dialog, aria-modal=true)', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });
        await expect(overlay).toHaveAttribute('role', 'dialog');
        await expect(overlay).toHaveAttribute('aria-modal', 'true');
    });

    test('clicking backdrop (outside the box) closes the overlay', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        // Click on the semi-transparent backdrop (top-left corner, outside the centred box)
        await overlay.click({ position: { x: 5, y: 5 }, force: true });

        await expect(overlay).not.toBeVisible({ timeout: 3000 });
    });

    test('only one overlay is created when picker is opened twice (guard)', async ({ page }) => {
        await openFalPicker(page);
        await openFalPicker(page); // second call must be a no-op

        const overlays = page.locator('body > [aria-modal="true"]');
        await expect(overlays).toHaveCount(1, { timeout: 3000 });
    });

    // ── 2. postMessage handling — the core of our fix ─────────────────────

    test('postMessage typo3:elementBrowser:elementAdded closes the overlay', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        // Simulate TYPO3 element browser posting the "file selected" message.
        // getParent() should resolve to window (or top), both of which we now listen on.
        await page.evaluate(() => {
            window.dispatchEvent(
                new MessageEvent('message', {
                    origin: window.location.origin,
                    data: {
                        actionName: 'typo3:elementBrowser:elementAdded',
                        fieldName: 'nr_mcp_agent_fal_picker',
                        value: '1',   // uid=1 — will be passed to getFileInfo
                        label: 'test.pdf',
                    },
                }),
            );
        });

        // Overlay must disappear — this is the bug we fixed: the message was delivered
        // but the overlay was not removed because the listener was on the wrong window.
        await expect(overlay).not.toBeVisible({ timeout: 3000 });
    });

    test('postMessage on top window also closes the overlay (multi-window listener fix)', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        // Simulate the message being delivered to top (the TYPO3 backend root),
        // which is one of the candidate windows TYPO3's getParent() may choose.
        await page.evaluate(() => {
            (top ?? window).dispatchEvent(
                new MessageEvent('message', {
                    origin: window.location.origin,
                    data: {
                        actionName: 'typo3:elementBrowser:elementAdded',
                        fieldName: 'nr_mcp_agent_fal_picker',
                        value: '1',
                        label: 'test.pdf',
                    },
                }),
            );
        });

        await expect(overlay).not.toBeVisible({ timeout: 3000 });
    });

    test('postMessage with wrong fieldName does NOT close the overlay', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        await page.evaluate(() => {
            window.dispatchEvent(
                new MessageEvent('message', {
                    origin: window.location.origin,
                    data: {
                        actionName: 'typo3:elementBrowser:elementAdded',
                        fieldName: 'some_other_field',
                        value: '1',
                    },
                }),
            );
        });

        // Overlay must stay open — unrelated fieldName must be ignored
        await expect(overlay).toBeVisible({ timeout: 1000 });

        // Cleanup
        await page.evaluate(() => {
            (document.querySelector('body > [aria-modal]') as HTMLElement | null)?.remove();
        });
    });

    test('postMessage received only once even when listener registered on multiple windows', async ({ page }) => {
        // Register error listener before any actions so it captures all errors
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        // Dispatch on both window and top simultaneously to verify the guard prevents double-handling
        await page.evaluate(() => {
            const data = {
                actionName: 'typo3:elementBrowser:elementAdded',
                fieldName: 'nr_mcp_agent_fal_picker',
                value: '1',
            };
            const opts = { origin: window.location.origin, data };
            window.dispatchEvent(new MessageEvent('message', opts));
            (top ?? window).dispatchEvent(new MessageEvent('message', opts));
        });

        // Overlay must close exactly once — no double-cleanup errors.
        // Waiting for this condition also gives the JS engine time to surface
        // any errors that would result from double-cleanup attempts.
        await expect(overlay).not.toBeVisible({ timeout: 3000 });

        expect(errors).toHaveLength(0);
    });

    // ── 3. Iframe content ─────────────────────────────────────────────────

    test('iframe loads TYPO3 element browser (HTTP 200, not error page)', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        const iframe = overlay.locator('iframe.t3js-modal-iframe');
        const src = await iframe.getAttribute('src') ?? '';
        expect(src).toBeTruthy();

        // Verify the URL responds successfully
        const response = await page.request.get(src, { ignoreHTTPSErrors: true });
        expect(response.status()).toBe(200);
    });

    test('iframe content loads and contains expected TYPO3 file browser elements', async ({ page }) => {
        await openFalPicker(page);

        const overlay = await getOverlay(page);
        await expect(overlay).toBeVisible({ timeout: 3000 });

        const iframe = overlay.frameLocator('iframe.t3js-modal-iframe');

        // TYPO3 element browser should load a recognisable structure
        await expect(
            iframe.locator('.element-browser-body, .element-browser-main-content, typo3-backend-component-filestorage-browser-tree, .browse-tree').first(),
        ).toBeVisible({ timeout: 15000 });
    });
});
