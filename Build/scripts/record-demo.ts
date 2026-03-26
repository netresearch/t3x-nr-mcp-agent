/**
 * Demo GIF recorder for nr_mcp_agent.
 *
 * Records a real MCP agent session:
 *   1. Create "Getting Started" page under Homepage (id=10)
 *   2. Add intro text about AI-powered content automation
 *   3. Optimize SEO fields for the page
 *   4. Evaluate & rate the page out of 5 stars
 *   5. Navigate to the page tree to show the result
 *
 * Produces: Documentation/Images/AgentDemo.gif
 *
 * Usage:
 *   TYPO3_BASE_URL=https://v14.nr-mcp-agent.ddev.site:33001 \
 *     npx tsx Build/scripts/record-demo.ts
 *
 * Requires ffmpeg in PATH for WebM → GIF conversion.
 */

import { chromium, Page, FrameLocator } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import fs from 'fs';

const BASE_URL  = process.env.TYPO3_BASE_URL      || 'https://v14.nr-mcp-agent.ddev.site:33001';
const USER      = process.env.TYPO3_ADMIN_USER     || 'admin';
const PASSWORD  = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR   = path.resolve(__dirname, '../../Documentation/Images');
const TMP_DIR   = '/tmp/nr-mcp-demo';

// ---------------------------------------------------------------------------
// Demo messages — sent in sequence, each waits for a full AI response
// ---------------------------------------------------------------------------
const MESSAGES = [
    'Create a new page called "Getting Started" as a subpage of the Homepage (page id 10). The page should be visible and published.',
    'Add a text content element to the "Getting Started" page with an engaging introduction about how AI-powered tools can help automate content creation and SEO optimization in TYPO3.',
    'Optimize the SEO fields for the "Getting Started" page: set a compelling meta title and meta description that would help with search engine rankings.',
    'Please evaluate the "Getting Started" page we just created. Rate it out of 5 stars for content quality and SEO readiness, and give a brief summary of what was accomplished.',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function login(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    const loginForm = page.locator('form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.locator('input[name="username"]').fill(USER);
        await page.locator('input[type="password"]').fill(PASSWORD);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL('**/typo3/module/**', { timeout: 15000 });
        await page.waitForTimeout(1500);
    }
    console.log('  ✓ logged in');
}

async function navigateToChatModule(page: Page): Promise<FrameLocator> {
    await page.goto(`${BASE_URL}/typo3/module/nr/mcp/agent/chat`);
    await page.waitForTimeout(1500);
    const iframe = page.frameLocator('typo3-iframe-module iframe, iframe').first();
    await iframe.locator('nr-chat-app').waitFor({ timeout: 15000 });
    return iframe;
}

/** Start a fresh conversation in the chat module. */
async function startNewConversation(iframe: FrameLocator): Promise<void> {
    // Click the "New conversation" button if present
    const newBtn = iframe.locator('button[title*="New"], button[aria-label*="new" i], .btn-new-conversation, [data-action="new"]').first();
    if (await newBtn.count() > 0) {
        await newBtn.click();
        await newBtn.page().waitForTimeout(800);
    }
}

/**
 * Type a message into the chat input and press Enter.
 * Works inside the nr-chat-app shadow DOM.
 */
async function sendMessage(iframe: FrameLocator, message: string): Promise<void> {
    // Playwright auto-pierces shadow DOM for most locators
    const textarea = iframe.locator('nr-chat-app').locator('textarea');
    await textarea.waitFor({ timeout: 10000 });
    await textarea.click();
    // Type slowly for visual effect in the recording
    await textarea.pressSequentially(message, { delay: 18 });
    await textarea.page().waitForTimeout(300);
    await textarea.press('Enter');
    console.log(`  → sent: "${message.substring(0, 60)}..."`);
}

/**
 * Wait until the AI has finished responding.
 * Polls for the .spinner to disappear and the last message to be from assistant.
 */
async function waitForResponse(iframe: FrameLocator, page: Page, maxMs = 180_000): Promise<void> {
    const start = Date.now();
    console.log('  ⏳ waiting for AI response…');

    // First: wait a moment for processing to start
    await page.waitForTimeout(4000);

    while (Date.now() - start < maxMs) {
        const spinnerCount = await iframe.locator('nr-chat-app').locator('.spinner').count();
        if (spinnerCount > 0) {
            await page.waitForTimeout(2000);
            continue;
        }

        // Check last message is assistant (via evaluate to pierce shadow DOM)
        const lastIsAssistant = await page.evaluate(`(function() {
            var iframes = document.querySelectorAll('typo3-iframe-module iframe, iframe');
            for (var i = 0; i < iframes.length; i++) {
                var doc = iframes[i].contentDocument;
                if (!doc) continue;
                var app = doc.querySelector('nr-chat-app');
                if (!app || !app.shadowRoot) continue;
                var rows = app.shadowRoot.querySelectorAll('.message-row');
                if (rows.length === 0) continue;
                var last = rows[rows.length - 1];
                return last.classList.contains('assistant');
            }
            return false;
        })()`);

        if (lastIsAssistant) {
            await page.waitForTimeout(2500); // let user "read" for a moment
            console.log('  ✓ response received');
            return;
        }

        await page.waitForTimeout(2000);
    }
    throw new Error('Timeout waiting for AI response (3 min exceeded)');
}

/** Navigate to the page tree to show the created page. */
async function showPageTree(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/module/web/list`);
    await page.waitForTimeout(3000);

    // Find and click the "Getting Started" page in the tree (use force to bypass overlay)
    const pageTreeItem = page.locator('.node-contentlabel, .node-name').filter({ hasText: 'Getting Started' }).first();
    if (await pageTreeItem.count() > 0) {
        await pageTreeItem.scrollIntoViewIfNeeded();
        await pageTreeItem.click({ force: true });
        await page.waitForTimeout(3000);
    }

    // Let the view settle for the recording
    await page.waitForTimeout(3000);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
    fs.mkdirSync(TMP_DIR, { recursive: true });
    fs.mkdirSync(OUT_DIR, { recursive: true });

    console.log('\nRecording AI agent demo…');
    console.log(`  Target: ${BASE_URL}`);
    console.log(`  Output: ${OUT_DIR}/AgentDemo.gif\n`);

    const videoPath = path.join(TMP_DIR, 'demo.webm');

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        ignoreHTTPSErrors: true,
        recordVideo: {
            dir: TMP_DIR,
            size: { width: 1440, height: 900 },
        },
    });
    const page = await context.newPage();

    try {
        await login(page);

        // Navigate to chat module and start a fresh conversation
        const iframe = await navigateToChatModule(page);
        await startNewConversation(iframe);
        await page.waitForTimeout(1000);

        // Send all messages in sequence, waiting for each response
        for (let i = 0; i < MESSAGES.length; i++) {
            console.log(`\n[${i + 1}/${MESSAGES.length}] ${MESSAGES[i].substring(0, 70)}…`);
            await sendMessage(iframe, MESSAGES[i]);
            await waitForResponse(iframe, page);
        }

        // Navigate to page tree to reveal the created page
        console.log('\n→ navigating to page tree…');
        await showPageTree(page);

        console.log('\n  ✓ recording complete');
    } finally {
        await page.close();
        await context.close();
        await browser.close();
    }

    // Rename the recorded video (Playwright generates a hash name)
    const files = fs.readdirSync(TMP_DIR).filter(f => f.endsWith('.webm'));
    if (files.length === 0) throw new Error('No WebM recording found in ' + TMP_DIR);
    const recordedFile = path.join(TMP_DIR, files[0]);
    fs.renameSync(recordedFile, videoPath);
    console.log(`  ✓ video saved: ${videoPath}`);

    // Convert WebM → GIF using ffmpeg
    // - 6× speed-up so a ~5 min recording becomes ~50 sec GIF
    // - 12 fps, 1200px wide, optimised palette
    const gifPath = path.join(OUT_DIR, 'AgentDemo.gif');
    console.log('\n→ converting to GIF (this may take a minute)…');

    execSync(
        `ffmpeg -y -i "${videoPath}" ` +
        `-vf "setpts=0.167*PTS,fps=12,scale=1200:-1:flags=lanczos,` +
        `split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3" ` +
        `-loop 0 "${gifPath}"`,
        { stdio: 'inherit' }
    );

    const sizeMb = (fs.statSync(gifPath).size / 1024 / 1024).toFixed(1);
    console.log(`\n✓ AgentDemo.gif saved (${sizeMb} MB) → ${gifPath}\n`);
}

main().catch((err) => {
    console.error('\n✗ Demo recording failed:', err);
    process.exit(1);
});
