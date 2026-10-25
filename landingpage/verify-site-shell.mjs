// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {createHash} from 'node:crypto';
import {attrs, checkAccessibility} from './check-accessibility.mjs';

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

function isProjectAsset(asset, siteRoot) {
    if (asset.startsWith('//') || (!asset.startsWith('/') && !/^https?:\/\//i.test(asset))) return false;
    try {
        const root = new URL(siteRoot);
        const target = new URL(asset, root);
        return target.origin === root.origin && target.pathname.startsWith(root.pathname);
    } catch {
        return false;
    }
}

function inertSpans(html) {
    return [...html.matchAll(/<!--[\s\S]*?-->|<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi)]
        .map(match => ({start: match.index, end: match.index + match[0].length}));
}

function verifyRecoveryAssets(html, siteRoot) {
    const errors = [];
    const inert = inertSpans(html);
    for (const tag of html.matchAll(/<(script|link|img)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi)) {
        if (inert.some(span => tag.index > span.start && tag.index < span.end)) continue;
        const attribute = attrs(tag[0])[tag[1].toLowerCase() === 'link' ? 'href' : 'src'];
        if (attribute === undefined) continue;
        const asset = decodeMarkupAttribute(attribute);
        if (!isProjectAsset(asset, siteRoot)) errors.push(`404 recovery asset must be absolute within the project: ${asset}`);
    }
    return errors;
}

export function verifyNotFoundPage(html, symbol, siteRoot) {
    const errors = [...verifySiteShell(html, symbol, 'en'), ...checkAccessibility(html), ...verifyBusinessContacts(html, 'en', ['footer'])];
    if (!/<meta\b[^>]*name="robots"[^>]*content="noindex,follow"/.test(html)) errors.push('404 recovery must be noindex');
    if (!/<meta\b[^>]*name="viewport"/.test(html)) errors.push('404 recovery requires a responsive viewport');
    if (/<link\b[^>]*rel="(?:canonical|alternate)"/.test(html)) errors.push('404 recovery must not declare an indexable page counterpart');
    for (const [locale, path] of [['en', ''], ['de', 'de/']]) {
        if (!html.includes(`href="${siteRoot}${path}" lang="${locale}" hreflang="${locale}"`)) errors.push(`404 recovery needs its ${locale} project home`);
    }
    return [...errors, ...verifyRecoveryAssets(html, siteRoot)];
}


function decodeMarkupAttribute(value) {
    const named = {amp: '&', quot: '"', apos: "'", lt: '<', gt: '>'};
    return value.replace(/&(#(?:[xX][0-9a-fA-F]+|\d+)|amp|AMP|quot|QUOT|apos|lt|LT|gt|GT);/g, (_, entity) => {
        if (!entity.startsWith('#')) return named[entity.toLowerCase()];
        const code = entity.toLowerCase().startsWith('#x')
            ? Number.parseInt(entity.slice(2), 16) : Number(entity.slice(1));
        return Number.isSafeInteger(code) && code >= 0 && code <= 0x10ffff ? String.fromCodePoint(code) : '\ufffd';
    });
}

function contactIssues(link, lang) {
    const errors = [];
    let target;
    try {
        target = new URL(link.href ?? '');
    } catch {
        return ['business contact link has a missing or invalid href'];
    }
    const expectedPath = lang === 'de' ? '/kontakt/' : '/en/contact/';
    if (target.origin !== 'https://www.netresearch.de' || target.pathname !== expectedPath) {
        errors.push(`business contact link has the wrong destination or language: ${link.href}`);
    }
    for (const param of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']) {
        const values = target.searchParams.getAll(param);
        if (values.length !== 1 || !values[0].trim()) errors.push(`business contact link needs one nonempty ${param}`);
    }
    return errors;
}

export function verifyBusinessContacts(html, lang, requiredPositions = []) {
    const inert = inertSpans(html);
    const links = [...html.matchAll(/<a\b(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi)]
        .filter(match => !inert.some(span => match.index >= span.start && match.index < span.end))
        .map(match => Object.fromEntries(Object.entries(attrs(match[0])).map(([name, value]) => [name, decodeMarkupAttribute(value)])));
    const isContact = link => ['nr-shell-contact', 'nr-shell-menu-contact'].some(name => (link.class ?? '').split(/\s+/).includes(name));
    const selected = links.filter(link => link['data-cta'] === 'business' || isContact(link));
    const errors = [];
    for (const name of ['nr-shell-contact', 'nr-shell-menu-contact']) {
        if (links.filter(link => (link.class ?? '').split(/\s+/).includes(name)).length !== 1) errors.push(`expected one ${name} business contact link`);
    }
    if (!links.some(link => link['data-cta'] === 'business')) errors.push('no marked business CTA remains');
    for (const position of requiredPositions) {
        if (links.filter(link => link['data-cta'] === 'business' && link['data-cta-position'] === position).length !== 1) errors.push(`expected one marked business contact link at ${position}`);
    }
    return [...errors, ...selected.flatMap(link => contactIssues(link, lang))];
}
