/**
 * Documentation screenshot generator for nr_mcp_agent.
 *
 * Produces:
 *   Documentation/Images/ChatModule.png
 *   Documentation/Images/MarkdownResponse.png
 *   Documentation/Images/FileAttachmentBadge.png
 *   Documentation/Images/ToolbarButton.png
 *   Documentation/Images/ChatPanel.png
 *
 * Usage:
 *   TYPO3_BASE_URL=https://v14.nr-mcp-agent.ddev.site \
 *     npx ts-node --esm Build/scripts/take-screenshots.ts
 *
 *   Or with tsx (recommended):
 *   TYPO3_BASE_URL=https://v14.nr-mcp-agent.ddev.site \
 *     npx tsx Build/scripts/take-screenshots.ts
 *
 * Credentials are read from env vars (defaults match DDEV dev setup):
 *   TYPO3_ADMIN_USER     (default: admin)
 *   TYPO3_ADMIN_PASSWORD (default: Joh316!!)
 */

import { chromium, Page, FrameLocator } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';

const BASE_URL   = process.env.TYPO3_BASE_URL     || 'https://v14.nr-mcp-agent.ddev.site:33001';
const USER       = process.env.TYPO3_ADMIN_USER    || 'admin';
const PASSWORD   = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const __dirname  = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR    = path.resolve(__dirname, '../../Documentation/Images');

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
        // v14 uses typo3-backend-module-router; wait for any backend URL
        await page.waitForURL('**/typo3/module/**', { timeout: 15000 });
        await page.waitForTimeout(1500);
    }
    console.log('  ✓ logged in');
}

async function navigateToChatModule(page: Page): Promise<FrameLocator> {
    // Navigate directly to the module URL (works in both v13 and v14)
    await page.goto(`${BASE_URL}/typo3/module/nr/mcp/agent/chat`);
    await page.waitForTimeout(1500);
    // Module content is rendered in a typo3-iframe-module or iframe
    const iframe = page.frameLocator('typo3-iframe-module iframe, iframe').first();
    await iframe.locator('nr-chat-app').waitFor({ timeout: 15000 });
    return iframe;
}

/**
 * Apply cosmetic fixes to the TYPO3 backend toolbar before screenshotting:
 * - Replace the workspace name with a clean demo name
 * - Make the chat badge visible with a sensible count
 * - Hide unrelated notification badges
 */
async function mockBackend(page: Page): Promise<void> {
    await page.evaluate(`(function() {
        // Replace workspace name
        document.querySelectorAll(
            '#typo3-cms-workspaces-backend-toolbaritems-workspaceselectortoolbaritem .toolbar-item-name'
        ).forEach(function(el) { el.textContent = 'My TYPO3 Site'; });

        // Show chat badge with a sensible count
        var chatBadge = document.querySelector('.ai-chat-badge');
        if (chatBadge) {
            chatBadge.textContent = '2';
            chatBadge.style.removeProperty('display');
        }

        // Hide unrelated notification badges (system messages etc.)
        document.querySelectorAll('.toolbar-item-badge.badge-danger, .toolbar-item-badge.badge-warning:not(.ai-chat-badge)')
            .forEach(function(el) { el.style.display = 'none'; });
    })()`);
}

/** Replace German conversation titles in the sidebar with English demo titles. */
async function mockConversationTitles(page: Page): Promise<void> {
    const titles = JSON.stringify([
        'Create Getting Started page with intro text',
        'List all pages in the site tree',
        'Optimize SEO for product pages',
        'Add news content element to page 15',
        'How can I improve my homepage content?',
    ]);
    await page.evaluate(`(function(titles) {
        var iframes = document.querySelectorAll('typo3-iframe-module iframe, iframe');
        for (var i = 0; i < iframes.length; i++) {
            var doc = iframes[i].contentDocument;
            if (!doc) continue;
            var app = doc.querySelector('nr-chat-app');
            if (!app || !app.shadowRoot) continue;
            var items = app.shadowRoot.querySelectorAll('.conversation-item .title');
            items.forEach(function(el, idx) {
                if (idx < titles.length) el.textContent = titles[idx];
            });
            return;
        }
    })(${titles})`);
}

/** Inject a mock Markdown assistant message into the nr-chat-app shadow DOM (inside iframe). */
async function injectMarkdownMessage(page: Page): Promise<void> {
    await page.evaluate(`(function() {
        var iframes = document.querySelectorAll('typo3-iframe-module iframe, iframe');
        var app = null;
        for (var i = 0; i < iframes.length; i++) {
            var doc = iframes[i].contentDocument;
            if (!doc) continue;
            app = doc.querySelector('nr-chat-app');
            if (app) break;
        }
        if (!app || !app.shadowRoot) return;

        var msgHtml =
            '<div class="message-row assistant mock-screenshot-msg">' +
            '<div class="avatar avatar-assistant" style="width:28px;height:28px;border-radius:50%;background:#0078d4;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;flex-shrink:0">AI</div>' +
            '<div class="message-bubble"><div class="message assistant" style="background:#f0f4ff;border-radius:8px;padding:12px 16px;max-width:600px">' +
            '<h3 style="font-size:1em;margin:0 0 8px;color:#1a1a2e">Tips for Writing Good Page Titles</h3>' +
            '<ul style="padding-left:1.3em;margin:0 0 8px">' +
            '<li>Keep titles <strong>concise</strong> — aim for under 60 characters</li>' +
            '<li>Include the <strong>primary keyword</strong> near the beginning</li>' +
            '<li>Avoid duplicate titles across the site tree</li>' +
            '</ul>' +
            '<p style="margin:0 0 8px">Check for duplicates with this SQL query:</p>' +
            '<pre style="background:#1e1e2e;color:#cdd6f4;border-radius:4px;padding:8px 12px;font-size:.82em;overflow:auto;margin:0">SELECT title, COUNT(*) c FROM pages\nGROUP BY title HAVING c &gt; 1;</pre>' +
            '</div></div>' +
            '</div>';

        var existingRow = app.shadowRoot.querySelector('.message-row');
        if (existingRow && existingRow.parentElement) {
            existingRow.parentElement.insertAdjacentHTML('beforeend', msgHtml);
        } else {
            var root = app.shadowRoot.querySelector('.messages, .scroll-area, div');
            if (root) root.insertAdjacentHTML('beforeend', msgHtml);
        }
    })()`);
}

/** Select a conversation in the floating panel using the SELECT dropdown. */
async function selectPanelConversation(page: Page): Promise<void> {
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;
        var sel = panel.shadowRoot.querySelector('select');
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value && sel.options[i].value !== '') {
                sel.value = sel.options[i].value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
        }
    })()`);
    await page.waitForTimeout(800);
}

/** Inject a mock file attachment badge into the ai-chat-panel shadow DOM. */
async function injectFileBadge(page: Page): Promise<void> {
    await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return;

        var badgeHtml = '<div class="file-badge mock-screenshot-badge" style="display:inline-flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;font-size:.8rem;margin:0 0 6px 0;max-width:280px;">' +
            '<span>📄</span>' +
            '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">annual-report-2025.pdf — 1.4 MB</span>' +
            '<span style="cursor:pointer;opacity:.6;font-size:16px;line-height:1">×</span>' +
            '</div>';

        // Insert before .input-wrap (inside .panel-input, above the textarea row)
        var inputWrap = panel.shadowRoot.querySelector('.input-wrap');
        if (inputWrap) {
            inputWrap.insertAdjacentHTML('beforebegin', badgeHtml);
            return;
        }
        // Fallback: before the textarea
        var textarea = panel.shadowRoot.querySelector('textarea');
        if (textarea && textarea.parentElement) {
            textarea.parentElement.insertAdjacentHTML('beforebegin', badgeHtml);
        }
    })()`);
}

// ---------------------------------------------------------------------------
// Screenshot tasks
// ---------------------------------------------------------------------------

async function screenshotToolbarButton(page: Page): Promise<void> {
    console.log('\n→ ToolbarButton.png');

    const btn = page.locator('.ai-chat-toolbar-btn');
    await btn.waitFor({ timeout: 10000 });
    const btnBox = await btn.boundingBox();
    if (!btnBox) throw new Error('Chat toolbar button not found');

    // Apply cosmetic mocks (workspace name, badge)
    await mockBackend(page);

    // Crop: wide enough to include the chat icon and all toolbar icons to the right.
    // Use the chat button's own x-position as the left boundary (with 40px padding).
    const viewportSize = page.viewportSize()!;
    const toolbarHeight = Math.round(btnBox.y + btnBox.height + 8);
    const clipX = Math.max(0, Math.round(btnBox.x) - 40);
    const clip = {
        x: clipX,
        y: 0,
        width: viewportSize.width - clipX,
        height: toolbarHeight,
    };

    await page.screenshot({
        path: path.join(OUT_DIR, 'ToolbarButton.png'),
        clip,
    });
    console.log('  ✓ saved ToolbarButton.png');
}

async function screenshotChatModule(page: Page, iframe: FrameLocator): Promise<void> {
    console.log('\n→ ChatModule.png');
    // Click the first conversation to open it
    const firstConv = iframe.locator('.conversation-item').first();
    if (await firstConv.count() > 0) {
        await firstConv.click();
        await page.waitForTimeout(1000);
    }
    // Replace German conversation titles with English demo titles
    await mockConversationTitles(page);
    const iframeEl = page.locator('iframe').first();
    await iframeEl.screenshot({ path: path.join(OUT_DIR, 'ChatModule.png') });
    console.log('  ✓ saved ChatModule.png');
}

async function screenshotMarkdownResponse(page: Page, iframe: FrameLocator): Promise<void> {
    console.log('\n→ MarkdownResponse.png');

    // Try to find an existing assistant message (Lit uses .message.assistant)
    const existingMsg = iframe.locator('.message.assistant');
    const hasReal = await existingMsg.count() > 0;

    if (!hasReal) {
        console.log('  ℹ no real assistant messages found — injecting mock content');
        await injectMarkdownMessage(page);
        await page.waitForTimeout(300);
    }

    // Screenshot the last assistant message row (includes avatar + bubble)
    const msgEl = iframe.locator('.message-row.assistant').last();
    if (await msgEl.count() > 0) {
        await msgEl.screenshot({ path: path.join(OUT_DIR, 'MarkdownResponse.png') });
        console.log('  ✓ saved MarkdownResponse.png');
    } else {
        // Fallback: screenshot the whole iframe
        await page.locator('iframe').first().screenshot({ path: path.join(OUT_DIR, 'MarkdownResponse.png') });
        console.log('  ✓ saved MarkdownResponse.png (full module fallback)');
    }
}

async function screenshotChatPanel(page: Page): Promise<void> {
    console.log('\n→ ChatPanel.png');

    // Navigate to dashboard so page tree / backend is visible behind the panel
    await page.goto(`${BASE_URL}/typo3/module/dashboard`);
    await page.waitForTimeout(1500);

    // Open the panel
    await page.locator('.ai-chat-toolbar-btn').click();
    const panel = page.locator('ai-chat-panel');
    await panel.waitFor({ state: 'visible', timeout: 5000 });
    await page.waitForTimeout(500); // let animation finish

    // Select a conversation so the panel shows real content
    await selectPanelConversation(page);

    // Apply cosmetic mocks (workspace name, badge)
    await mockBackend(page);

    // Full-page screenshot showing panel overlaying the backend
    await page.screenshot({
        path: path.join(OUT_DIR, 'ChatPanel.png'),
        fullPage: false,
    });
    console.log('  ✓ saved ChatPanel.png');

    // Keep panel open for FileAttachmentBadge
}

async function screenshotFileAttachmentBadge(page: Page): Promise<void> {
    console.log('\n→ FileAttachmentBadge.png');

    const panel = page.locator('ai-chat-panel');
    if (!await panel.isVisible()) {
        await page.locator('.ai-chat-toolbar-btn').click();
        await panel.waitFor({ state: 'visible', timeout: 5000 });
        await page.waitForTimeout(400);
        // Ensure a conversation is selected so the input area is visible
        await selectPanelConversation(page);
    }

    // Always inject the mock badge (real uploads aren't possible in automation)
    console.log('  ℹ injecting mock file badge');
    await injectFileBadge(page);
    await page.waitForTimeout(300);

    // Crop to the .panel-input area (bottom section with + button, badge, textarea)
    // Get its bounding box via evaluate since it's inside shadow DOM
    const inputBox = await page.evaluate(`(function() {
        var panel = document.querySelector('ai-chat-panel');
        if (!panel || !panel.shadowRoot) return null;
        var el = panel.shadowRoot.querySelector('.panel-input');
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return { x: r.left, y: r.top, width: r.width, height: r.height };
    })()`);

    if (inputBox && (inputBox as any).width > 0) {
        const ib = inputBox as { x: number; y: number; width: number; height: number };
        // Include 40px above the input area to show the badge
        await page.screenshot({
            path: path.join(OUT_DIR, 'FileAttachmentBadge.png'),
            clip: {
                x: ib.x,
                y: Math.max(0, ib.y - 40),
                width: ib.width,
                height: ib.height + 40,
            },
        });
    } else {
        // Fallback: screenshot the panel element directly
        await panel.screenshot({ path: path.join(OUT_DIR, 'FileAttachmentBadge.png') });
    }
    console.log('  ✓ saved FileAttachmentBadge.png');
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
    console.log(`\nGenerating documentation screenshots`);
    console.log(`  Target:  ${BASE_URL}`);
    console.log(`  Output:  ${OUT_DIR}\n`);

    const browser = await chromium.launch({ headless: true });
    const page    = await browser.newPage({
        viewport: { width: 1440, height: 900 },
        ignoreHTTPSErrors: true,
    });

    try {
        await login(page);

        // ToolbarButton — just needs the backend to be loaded
        await screenshotToolbarButton(page);

        // ChatModule + MarkdownResponse — navigate to module
        const iframe = await navigateToChatModule(page);
        await screenshotChatModule(page, iframe);
        await screenshotMarkdownResponse(page, iframe);

        // ChatPanel — navigate away first so page tree is visible
        await screenshotChatPanel(page);

        // FileAttachmentBadge — panel is already open from previous step
        await screenshotFileAttachmentBadge(page);

        console.log('\n✓ All screenshots saved to Documentation/Images/\n');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error('\n✗ Screenshot generation failed:', err);
    process.exit(1);
});
