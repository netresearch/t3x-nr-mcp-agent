import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr_mcp_agent E2E tests.
 *
 * Run from the HOST against the DDEV TYPO3 v13 instance.
 * Override the base URL via TYPO3_BASE_URL env variable.
 *
 * Usage:
 *   npx playwright test --config=Build/tests/playwright/playwright.config.ts
 *   TYPO3_BASE_URL=https://my-host:1234 npx playwright test --config=...
 */
export default defineConfig({
    testDir: './specs',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: [['html', { open: 'never' }]],
    timeout: 30000,
    use: {
        baseURL: process.env.TYPO3_BASE_URL || 'https://v13.nr-mcp-agent.ddev.site:33001',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
