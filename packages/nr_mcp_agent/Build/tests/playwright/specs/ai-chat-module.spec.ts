import { test, expect } from '@playwright/test';

/**
 * Basic smoke test for the AI Chat backend module.
 *
 * Skip if no TYPO3 instance is available (CI without DDEV).
 */
const isTypo3Available = !!process.env.TYPO3_BASE_URL;

test.describe('AI Chat Backend Module', () => {
    test.skip(!isTypo3Available, 'Skipped: no TYPO3 instance available (set TYPO3_BASE_URL)');

    test('module loads and displays chat interface', async ({ page }) => {
        // Navigate to AI Chat module via TYPO3 backend module URL
        await page.goto('/typo3/module/web/nr-mcp-agent/chat');

        // Verify the module frame/content loaded
        await expect(page.locator('[data-module-name="web_NrMcpAgentChat"]').or(page.locator('.ai-chat'))).toBeVisible({
            timeout: 10000,
        });
    });

    test('new conversation can be started', async ({ page }) => {
        await page.goto('/typo3/module/web/nr-mcp-agent/chat');

        // Look for a "new conversation" button or equivalent
        const newButton = page.getByRole('button', { name: /new/i });
        if (await newButton.isVisible()) {
            await newButton.click();
            // Verify chat input area appears
            await expect(page.locator('textarea, [contenteditable]').first()).toBeVisible({ timeout: 5000 });
        }
    });
});
