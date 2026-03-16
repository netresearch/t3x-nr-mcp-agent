import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr_mcp_agent E2E tests.
 *
 * Expects a running TYPO3 v13 instance (e.g. via DDEV).
 * Set TYPO3_BASE_URL environment variable to override the default URL.
 */
export default defineConfig({
    testDir: './specs',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: 'html',
    use: {
        baseURL: process.env.TYPO3_BASE_URL || 'https://nr-mcp-agent.ddev.site',
        trace: 'on-first-retry',
        ignoreHTTPSErrors: true,
    },
    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'Build/tests/playwright/.auth/admin.json',
            },
            dependencies: ['setup'],
        },
    ],
});
