// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import { Page } from '@playwright/test';
import { test, expect, waitForBackend } from '../fixtures/backend';

/**
 * The status badge of the shared conversation row, readable in the selected
 * row and in the others, in light and dark (ADR-027).
 *
 * In TYPO3 14.3 --typo3-text-color-variant is a mix of currentColor. The
 * neutral badge took it, so its text followed the row around it: white on its
 * light pill in the selected row (1.04:1), and the variant of the already
 * muted status line in the other rows (2.66:1). jsdom computes no colours, so
 * this is measured here, from getComputedStyle(), composited over the row.
 */

const TYPO3_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const TYPO3_PASSWORD = process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!';

const TONES = { neutral: 'idle', info: 'processing', warning: 'awaiting_approval', danger: 'failed' } as const;

async function login(page: Page): Promise<void> {
    await page.goto('/typo3/');
    const loginForm = page.locator('#t3-login-form, form[name="loginform"]');
    if (await loginForm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await page.getByLabel('Username').fill(TYPO3_USER);
        await page.getByLabel('Password').fill(TYPO3_PASSWORD);
        await page.getByRole('button', { name: /log.?in/i }).click();
        await waitForBackend(page);
    }
    await page.waitForFunction(() => !!document.querySelector('ai-chat-panel'), null, { timeout: 10000 });
    await page.waitForFunction(() => (document.querySelector('ai-chat-panel') as any).chat.loading === false, null, { timeout: 10000 });
}

type Measured = { color: string; text: number; edge: number };

/**
 * The maximized panel's sidebar with one conversation per tone, the one of
 * `tone` selected or not; the badge of that row, measured.
 */
async function measure(page: Page, scheme: 'light' | 'dark', tone: string, selected: boolean): Promise<Measured> {
    return page.evaluate(async ({ scheme, tones, tone, selected }) => {
        document.documentElement.dataset.colorScheme = scheme;
        const panel = document.querySelector('ai-chat-panel') as any;
        const conversations = Object.entries(tones).map(([t, status], i) => ({
            uid: 9000 + i,
            title: `Row ${t}`,
            displayTitle: `Row ${t}`,
            fullTitle: `Row ${t}`,
            contextLabel: 'Guided Tour',
            status,
            messageCount: 1,
            pinned: false,
            lastActivityAt: new Date().toISOString(),
        }));
        const target = conversations.find((c) => c.title === `Row ${tone}`)!;
        panel.chat.conversations = conversations;
        panel.chat.activeUid = selected ? target.uid : conversations.find((c) => c !== target)!.uid;
        panel.state = 'maximized';
        panel.requestUpdate();
        await panel.updateComplete;
        // The row's background is a transition; it starts with the next frame.
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        await Promise.all(panel.shadowRoot.getAnimations().map((a: Animation) => a.finished.catch(() => null)));

        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
        const rgba = (css: string): number[] => {
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = css;
            ctx.fillRect(0, 0, 1, 1);
            const d = ctx.getImageData(0, 0, 1, 1).data;
            return [d[0], d[1], d[2], d[3] / 255];
        };
        const over = (fg: number[], bg: number[]) => fg.slice(0, 3).map((v, i) => v * fg[3] + bg[i] * (1 - fg[3])).concat(1);
        const lum = (c: number[]) => {
            const f = (v: number) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        const ratio = (a: number[], b: number[]) => {
            const [x, y] = [lum(a), lum(b)];
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        };
        const parent = (n: Element): Element | null => n.parentElement ?? ((n.getRootNode() as ShadowRoot).host ?? null);
        const backdrop = (el: Element) => {
            const chain: number[][] = [];
            for (let n: Element | null = el; n; n = parent(n)) {
                chain.push(rgba(getComputedStyle(n).backgroundColor));
            }
            return chain.reverse().reduce((acc, layer) => over(layer, acc), scheme === 'dark' ? [0, 0, 0, 1] : [255, 255, 255, 1]);
        };

        const row = [...panel.shadowRoot.querySelectorAll('.sidebar-item')].find((r: Element) => r.textContent!.includes(`Row ${tone}`));
        const badge = row.querySelector('.conv-status');
        const style = getComputedStyle(badge);
        const rowBg = backdrop(row);
        const badgeBg = over(rgba(style.backgroundColor), rowBg);
        const text = over(rgba(style.color), badgeBg);
        const shadow = style.boxShadow === 'none' ? null : style.boxShadow.replace(/\s+inset\s*$/, '').replace(/(\s+-?[\d.]+px){2,4}\s*$/, '');
        // The badge's boundary: its edge, or the pill itself where that stands out more.
        const edge = Math.max(ratio(badgeBg, rowBg), shadow ? ratio(over(rgba(shadow), rowBg), rowBg) : 0);
        return { color: style.color, text: ratio(text, badgeBg), edge };
    }, { scheme, tones: TONES, tone, selected });
}

test.describe('Status badge in the conversation row', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    for (const scheme of ['light', 'dark'] as const) {
        for (const tone of Object.keys(TONES)) {
            test(`the ${tone} badge is readable in the selected and the other rows (${scheme})`, async ({ page }) => {
                const selected = await measure(page, scheme, tone, true);
                const other = await measure(page, scheme, tone, false);

                expect(selected.text, 'text on the badge, selected row').toBeGreaterThanOrEqual(4.5);
                expect(other.text, 'text on the badge, other row').toBeGreaterThanOrEqual(4.5);
                // The badge does not take the colour of the row it sits in.
                expect(selected.color).toBe(other.color);
                if (tone === 'warning') {
                    // A waiting conversation carries an edge (ADR-027).
                    expect(selected.edge, 'edge against the selected row').toBeGreaterThanOrEqual(3);
                    expect(other.edge, 'edge against the other rows').toBeGreaterThanOrEqual(3);
                }
            });
        }
    }
});
