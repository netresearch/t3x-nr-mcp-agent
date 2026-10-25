// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {createHash} from 'node:crypto';
import {checkAccessibility} from './check-accessibility.mjs';

// Unmodified symbol-only asset from Netresearch branding 3.1.0.
const SYMBOL_SHA256 = 'ca88779a6d008b4606bbe8d45f4640d6967f82b66b302d95b9153dcf2cfb9e29';
const FAMILY_DESTINATIONS = {
    en: ['https://netresearch.github.io/', 'https://netresearch.github.io/projects/', 'https://netresearch.github.io/modularer-ki-stack-page/en/', 'https://netresearch.github.io/claude-code-marketplace/en/', 'https://netresearch.github.io/maint/'],
    de: ['https://netresearch.github.io/de/', 'https://netresearch.github.io/de/projekte/', 'https://netresearch.github.io/modularer-ki-stack-page/', 'https://netresearch.github.io/claude-code-marketplace/de/', 'https://netresearch.github.io/maint/de/'],
};
function verifyFamilyNavigation(header, lang) {
    const errors = [];
    const expected = FAMILY_DESTINATIONS[lang];
    const menus = [...header.matchAll(/<nav\b[^>]*class="([^"]+)"[^>]*>([\s\S]*?)<\/nav>/g)];
    for (const className of ['nr-shell-desktop', 'nr-shell-menu-panel']) {
        const matching = menus.filter(menu => menu[1].split(/\s+/).includes(className));
        const links = [...(matching[0]?.[2] ?? '').matchAll(/<a\b[^>]*class="nr-shell-link"[^>]*href="([^"]+)"[^>]*>/g)].map(match => match[1]);
        if (!expected || matching.length !== 1 || links.length !== expected.length || links.some((href, index) => href !== expected[index])) {
            errors.push(`${className} must retain all five ${lang} family destinations`);
        }
    }
    return errors;
}
export function verifySiteShell(html, symbol, lang = 'en') {
    const errors = [];
    const headers = [...html.matchAll(/<header\b[^>]*data-nr-shell[^>]*>[\s\S]*?<\/header>/g)];
    const logos = [...html.matchAll(/<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>/g)];
    if (headers.length !== 1 || logos.length !== 1 || !headers[0]?.[0].includes(logos[0]?.[0])) {
        errors.push('exactly one canonical symbol must appear in the shared header');
    }
    if (!headers[0]?.[0].match(/<a\b[^>]*href="https:\/\/www\.netresearch\.de\/"[^>]*>\s*<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>\s*<\/a>/)) {
        errors.push('header symbol must link to Netresearch DTT GmbH');
    }
    if (!logos[0]?.[0].includes('width="44"') || !logos[0]?.[0].includes('height="44"') || !logos[0]?.[0].includes('alt="Netresearch DTT GmbH"')) {
        errors.push('header symbol must retain its dimensions and accessible name');
    }
    if (createHash('sha256').update(symbol).digest('hex') !== SYMBOL_SHA256) errors.push('header symbol differs from the authentic brand asset');
    return [...errors, ...verifyFamilyNavigation(headers[0]?.[0] ?? '', lang)];
}

export function verifyNotFoundPage(html, symbol, siteRoot) {
    const projectPath = new URL(siteRoot).pathname;
    const errors = [...verifySiteShell(html, symbol, 'en'), ...checkAccessibility(html)];
    if (!/<meta\b[^>]*name="robots"[^>]*content="noindex,follow"/.test(html)) errors.push('404 recovery must be noindex');
    if (!/<meta\b[^>]*name="viewport"/.test(html)) errors.push('404 recovery requires a responsive viewport');
    if (/<link\b[^>]*rel="(?:canonical|alternate)"/.test(html)) errors.push('404 recovery must not declare an indexable page counterpart');
    for (const [locale, path] of [['en', ''], ['de', 'de/']]) {
        if (!html.includes(`href="${siteRoot}${path}" lang="${locale}" hreflang="${locale}"`)) errors.push(`404 recovery needs its ${locale} project home`);
    }
    for (const [, asset] of html.matchAll(/<(?:script|link|img)\b[^>]+(?:src|href)="([^"]+)"/g)) {
        if (!asset.startsWith(siteRoot) && (!asset.startsWith(projectPath) || asset.startsWith('//'))) errors.push(`404 recovery asset must be absolute within the project: ${asset}`);
    }
    return errors;
}
