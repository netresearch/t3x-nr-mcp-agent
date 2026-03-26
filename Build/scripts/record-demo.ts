/**
 * Demo GIF recorder for nr_mcp_agent.
 *
 * Records a real MCP agent session using the floating chat panel:
 *   1. Navigate to the TYPO3 page list (Homepage, id=10)
 *   2. Open & maximize the floating panel
 *   3. Create "Getting Started" page under Homepage
 *   4. Minimize panel → navigate to page list → new page appears in tree
 *   5. Re-expand panel → add intro content
 *   6. Rate the page out of 5 stars
 *
 * Produces: Documentation/Images/AgentDemo.gif
 *
 * Usage:
 *   TYPO3_BASE_URL=https://v14.nr-mcp-agent.ddev.site:33001 \
 *     npx tsx Build/scripts/record-demo.ts
 */

import { chromium, Page } from 'playwright';
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
// Helpers
// ---------------------------------------------------------------------------

async function login(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    if (await page.locator('form[name="loginform"]').isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.locator('input[name="username"]').fill(USER);
        await page.locator('input[type="password"]').fill(PASSWORD);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL('**/typo3/module/**', { timeout: 15000 });
        await page.waitForTimeout(1500);
    }
    console.log('  ✓ logged in');
}

/** Open the floating panel and start a new conversation. */
async function openPanel(page: Page): Promise<void> {
    await page.locator('.ai-chat-toolbar-btn').click();
    const panel = page.locator('ai-chat-panel');
    await panel.waitFor({ state: 'visible', timeout: 5000 });
    await page.waitForTimeout(600);

    // Click "New conversation" button (btn-icon next to the select dropdown)
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var btn = panel.shadowRoot.querySelector('.compact-switcher .btn-icon');
        if (btn) btn.click();
    })()`);
    await page.waitForTimeout(800);
    console.log('  ✓ panel open, new conversation started');
}

/** Click a button by title inside the ai-chat-panel shadow DOM. */
async function clickPanelButton(page: Page, title: string): Promise<void> {
    await page.evaluate(`(function(t) {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var btns = panel.shadowRoot.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].title === t || btns[i].getAttribute('aria-label') === t) {
                btns[i].click(); return;
            }
        }
    })("${title}")`);
    await page.waitForTimeout(600);
}

/** Maximize the floating panel for a better view. */
async function maximizePanel(page: Page): Promise<void> {
    await clickPanelButton(page, 'Maximize');
    await page.waitForTimeout(400);
    console.log('  ✓ panel maximized');
}

/** Minimize the floating panel (collapse to bottom bar). */
async function minimizePanel(page: Page): Promise<void> {
    await clickPanelButton(page, 'Minimize');
    await page.waitForTimeout(600);
    console.log('  ✓ panel minimized');
}

/** Re-open panel after navigation (panel resets to hidden on page change). */
async function reopenPanel(page: Page): Promise<void> {
    await page.locator('.ai-chat-toolbar-btn').click();
    const panel = page.locator('ai-chat-panel');
    await panel.waitFor({ state: 'visible', timeout: 5000 });
    await page.waitForTimeout(600);

    // Re-select the most recent conversation (first in dropdown)
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var sel = panel.shadowRoot.querySelector('select');
        if (!sel || sel.options.length < 2) return;
        // Select first option with a value
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value) {
                sel.value = sel.options[i].value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
        }
    })()`);
    await page.waitForTimeout(1000);
    console.log('  ✓ panel reopened with conversation');
}

/** Type a message into the floating panel textarea and submit it. */
async function sendMessage(page: Page, message: string): Promise<void> {
    // Focus the textarea via JS
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var ta = panel.shadowRoot.querySelector('textarea');
        if (ta) ta.focus();
    })()`);
    await page.waitForTimeout(200);

    // Type the message using real keyboard events (Lit picks these up)
    await page.keyboard.type(message, { delay: 20 });
    await page.waitForTimeout(400);

    // Click the send button via JS
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var btn = panel.shadowRoot.querySelector('.btn-send');
        if (btn) btn.click();
    })()`);
    console.log(`  → sent: "${message.substring(0, 70)}…"`);
}

/** Wait until the floating panel AI response is complete. */
async function waitForPanelResponse(page: Page, maxMs = 180_000): Promise<void> {
    const start = Date.now();
    console.log('  ⏳ waiting for AI response…');
    await page.waitForTimeout(4000);

    while (Date.now() - start < maxMs) {
        const lastIsAssistant = await page.evaluate(`(function() {
            var panel = document.querySelector('ai-chat-panel');
            if (!panel || !panel.shadowRoot) return false;
            var rows = panel.shadowRoot.querySelectorAll('.message-row');
            if (rows.length === 0) return false;
            var last = rows[rows.length - 1];
            return last.classList.contains('assistant');
        })()`);

        const hasSpinner = await page.evaluate(`(function() {
            var panel = document.querySelector('ai-chat-panel');
            if (!panel || !panel.shadowRoot) return false;
            return !!panel.shadowRoot.querySelector('.spinner');
        })()`);

        if (lastIsAssistant && !hasSpinner) {
            await page.waitForTimeout(3000); // pause so viewer can read
            console.log('  ✓ response received');
            return;
        }

        await page.waitForTimeout(2000);
    }
    throw new Error('Timeout waiting for AI response');
}

/**
 * Navigate to the page list for page 10 (Homepage).
 * This refreshes the tree and shows the newly created subpages.
 */
async function showPageList(page: Page): Promise<void> {
    console.log('  → navigating to page list to show new page in tree…');
    await page.goto(`${BASE_URL}/typo3/module/web/list?id=10`);
    await page.waitForTimeout(4000); // let tree + list load
    console.log('  ✓ page list visible');
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
    // Clean up old recordings
    fs.readdirSync(TMP_DIR).filter(f => f.endsWith('.webm')).forEach(f =>
        fs.unlinkSync(path.join(TMP_DIR, f))
    );

    console.log('\nRecording AI agent demo (floating panel)…');
    console.log(`  Target: ${BASE_URL}`);
    console.log(`  Output: ${OUT_DIR}/AgentDemo.gif\n`);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        ignoreHTTPSErrors: true,
        recordVideo: { dir: TMP_DIR, size: { width: 1440, height: 900 } },
    });
    const page = await context.newPage();

    try {
        await login(page);

        // Start on dashboard — clean starting point
        await page.goto(`${BASE_URL}/typo3/module/dashboard`);
        await page.waitForTimeout(2000);

        // Open floating panel and start new conversation
        await openPanel(page);
        await maximizePanel(page);
        await page.waitForTimeout(500);

        // --- Message 1: Create the page ---
        console.log('\n[1/3] Creating "Getting Started" page…');
        await sendMessage(page,
            'Create a new page called "Getting Started" as a subpage of the Homepage (page id 10). Make it visible and published.'
        );
        await waitForPanelResponse(page);

        // --- Message 2: Add content ---
        console.log('\n[2/3] Adding intro content…');
        await sendMessage(page,
            'Add a text content element to the "Getting Started" page with an introduction about how AI can automate content creation and SEO in TYPO3.'
        );
        await waitForPanelResponse(page);

        // --- Message 3: Rate the page ---
        console.log('\n[3/3] Rating the page…');
        await sendMessage(page,
            'Please rate the "Getting Started" page we just created out of 5 stars for content quality and SEO readiness.'
        );
        await waitForPanelResponse(page);

        // Minimize panel and show the new page in the page list
        await minimizePanel(page);
        await showPageList(page);
        await page.waitForTimeout(3000);

        console.log('\n  ✓ recording complete');
    } finally {
        await page.close();
        await context.close();
        await browser.close();
    }

    // Find and rename the recorded WebM
    const files = fs.readdirSync(TMP_DIR).filter(f => f.endsWith('.webm'));
    if (files.length === 0) throw new Error('No WebM recording found in ' + TMP_DIR);
    const videoPath = path.join(TMP_DIR, 'demo.webm');
    fs.renameSync(path.join(TMP_DIR, files[0]), videoPath);
    console.log(`  ✓ video saved: ${videoPath}`);

    // Convert WebM → GIF via Docker ffmpeg
    // 2.5× speed-up (setpts=0.4), 12 fps, 1200px wide, optimised palette
    const gifPath = path.join(OUT_DIR, 'AgentDemo.gif');
    console.log('\n→ converting to GIF via Docker…');
    execSync(
        `docker run --rm ` +
        `-v "${TMP_DIR}:/input" ` +
        `-v "${OUT_DIR}:/output" ` +
        `jrottenberg/ffmpeg:4.3-alpine ` +
        `-y -i /input/demo.webm ` +
        `-vf "setpts=0.4*PTS,fps=12,scale=1200:-1:flags=lanczos,` +
        `split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3" ` +
        `-loop 0 /output/AgentDemo.gif`,
        { stdio: 'inherit' }
    );

    const sizeMb = (fs.statSync(gifPath).size / 1024 / 1024).toFixed(1);
    console.log(`\n✓ AgentDemo.gif saved (${sizeMb} MB) → ${gifPath}\n`);
}

main().catch((err) => {
    console.error('\n✗ Demo recording failed:', err);
    process.exit(1);
});
