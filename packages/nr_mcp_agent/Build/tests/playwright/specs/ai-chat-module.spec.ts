import { test, expect, FrameLocator, Page } from '@playwright/test';

/**
 * E2E tests for the AI Chat backend module (Admin Tools > AI Chat).
 *
 * These tests run against a live TYPO3 v13 instance via DDEV.
 * The module content is rendered inside a TYPO3 backend iframe.
 * The <nr-chat-app> Lit 3 web component uses shadow DOM.
 *
 * Note: In a test environment without LLM configuration, the chat module
 * displays "AI Chat is not available" and disables the New button.
 * Tests are written to work with this unconfigured state.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

/** The module link href pattern in the TYPO3 module menu. */
const MODULE_HREF_PATTERN = '/nr/mcp/agent/chat';

test.describe('AI Chat Backend Module', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/typo3/');

        const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
        if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
            await page.getByLabel('Username').fill(TYPO3_USER);
            await page.getByLabel('Password').fill(TYPO3_PASSWORD);
            await page.getByRole('button', { name: /log.?in/i }).click();
            await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
        }
    });

    /**
     * Navigate to the AI Chat module via the sidebar link and return the
     * iframe FrameLocator that contains the module content.
     */
    async function navigateToModule(page: Page): Promise<FrameLocator> {
        const chatLink = page.locator(`a[href*="${MODULE_HREF_PATTERN}"]`);
        await chatLink.click();
        // Wait for the module iframe to appear and load
        const iframeEl = page.locator('iframe');
        await expect(iframeEl).toBeVisible({ timeout: 10000 });
        const iframe = page.frameLocator('iframe').first();
        // Wait for the Lit component inside the iframe
        await expect(iframe.locator('nr-chat-app')).toBeVisible({ timeout: 15000 });
        return iframe;
    }

    test('module is listed in the sidebar navigation', async ({ page }) => {
        const moduleMenu = page.locator('.scaffold-modulemenu');
        await expect(moduleMenu).toBeVisible({ timeout: 10000 });

        const chatLink = moduleMenu.locator(`a[href*="${MODULE_HREF_PATTERN}"]`);
        await expect(chatLink).toHaveCount(1);
    });

    test('AI Chat module loads and renders nr-chat-app', async ({ page }) => {
        const iframe = await navigateToModule(page);
        await expect(iframe.locator('nr-chat-app')).toBeVisible();
    });

    test('nr-chat-app has data-max-length attribute', async ({ page }) => {
        const iframe = await navigateToModule(page);
        const chatApp = iframe.locator('nr-chat-app');
        await expect(chatApp).toHaveAttribute('data-max-length', /\d+/);
    });

    test('chat app renders conversation sidebar', async ({ page }) => {
        const iframe = await navigateToModule(page);
        const chatApp = iframe.locator('nr-chat-app');

        // The sidebar shows "Conversations" heading and "No conversations yet"
        const conversationsHeading = chatApp.getByRole('heading', { name: 'Conversations' });
        await expect(conversationsHeading).toBeVisible({ timeout: 5000 });

        const emptyState = chatApp.getByText('No conversations yet');
        await expect(emptyState).toBeVisible({ timeout: 5000 });
    });

    test('chat app shows "+ New" button', async ({ page }) => {
        const iframe = await navigateToModule(page);
        const chatApp = iframe.locator('nr-chat-app');

        const newBtn = chatApp.getByRole('button', { name: /new/i });
        await expect(newBtn).toBeVisible({ timeout: 5000 });
    });

    test('shows unavailable message when LLM is not configured', async ({ page }) => {
        const iframe = await navigateToModule(page);
        const chatApp = iframe.locator('nr-chat-app');

        // Without LLM configuration, the module shows an unavailability notice
        const unavailableMsg = chatApp.getByText(/not available|check.*configuration/i);
        await expect(unavailableMsg).toBeVisible({ timeout: 5000 });
    });

    test('new button is disabled when chat is unavailable', async ({ page }) => {
        const iframe = await navigateToModule(page);
        const chatApp = iframe.locator('nr-chat-app');

        const newBtn = chatApp.getByRole('button', { name: /new/i });
        await expect(newBtn).toBeVisible({ timeout: 5000 });
        await expect(newBtn).toBeDisabled();
    });

    test('no critical JavaScript errors on module load', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (err) => errors.push(err.message));

        await navigateToModule(page);

        // Allow time for any deferred JS to execute
        await page.waitForTimeout(2000);

        // Filter for errors related to our component (ignore unrelated noise)
        const chatErrors = errors.filter(e =>
            e.toLowerCase().includes('chat') ||
            e.toLowerCase().includes('nr-') ||
            e.toLowerCase().includes('lit')
        );
        expect(chatErrors).toHaveLength(0);
    });
});
