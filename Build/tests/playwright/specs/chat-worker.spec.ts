import { test, expect, Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';

/**
 * A sent message reaches the background worker (`typo3 ai-chat:process`).
 *
 * The exec processor starts the worker with the `typo3` binary from Composer's
 * bin-dir. This installation keeps it in `.Build/bin`; while the path was
 * hard-coded to `vendor/bin/typo3`, the worker never started and the log said
 * "Could not open input file".
 *
 * The seeded provider points at a closed local port, so a worker that runs
 * fails the turn within seconds. A worker that never starts leaves the
 * conversation in `processing`. The status therefore tells the two apart
 * without any model answering.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

// Written by ExecChatProcessor when it cannot find the binary; see
// ExecChatProcessor::WORKER_NOT_STARTED_MESSAGE.
const WORKER_NOT_STARTED = 'could not start its background process';

// In CI the specs run from the root of the installation the server serves.
const PROCESS_LOG = path.join(process.cwd(), 'var/log/ai-chat-process.log');

async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await expect(page.locator('.scaffold-modulemenu')).toBeVisible({ timeout: 15000 });
    }
    await page.waitForFunction(() => !!(window as any).TYPO3?.settings?.ajaxUrls?.ai_chat_conversation_send, null, { timeout: 10000 });
}

test.describe('AI Chat background worker', () => {
    test('a sent message is picked up by the worker', async ({ page }) => {
        test.setTimeout(60000);
        await login(page);

        const sent = await page.evaluate(async () => {
            const urls = (window as any).TYPO3.settings.ajaxUrls;
            const post = (url: string, body: unknown) => fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            const created = await (await post(urls.ai_chat_conversation_create, {})).json();
            const response = await post(urls.ai_chat_conversation_send, { conversationUid: created.uid, content: 'ping' });
            return { uid: created.uid as number, status: response.status, body: await response.json() };
        });

        expect(sent.status, JSON.stringify(sent.body)).toBe(202);

        const readState = () => page.evaluate(async (uid) => {
            const base = (window as any).TYPO3.settings.ajaxUrls.ai_chat_conversation_messages;
            const url = base + (base.includes('?') ? '&' : '?') + new URLSearchParams({ conversationUid: String(uid), after: '0' });
            const data = await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
            return { status: data.status as string, errorMessage: (data.errorMessage ?? '') as string };
        }, sent.uid);

        await expect
            .poll(async () => (await readState()).status, {
                message: 'the conversation never left "processing": the background worker did not run',
                timeout: 40000,
                intervals: [500, 1000, 2000],
            })
            .not.toBe('processing');

        const state = await readState();
        // The worker ran and failed on the unreachable provider.
        expect(state.status).toBe('failed');
        expect(state.errorMessage).not.toContain(WORKER_NOT_STARTED);

        if (process.env.CI) {
            const log = fs.readFileSync(PROCESS_LOG, 'utf8');
            expect(log).not.toContain('Could not open input file');
        }
    });
});
