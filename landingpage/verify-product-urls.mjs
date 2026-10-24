// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {stat} from 'node:fs/promises';
import {resolve} from 'node:path';

/** Verify published URLs against the deployment origin and real build files. */
export async function verifyProductUrls(html, pageName, output, slug, deploymentOrigin) {
    const errors = [];
    const canonical = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
    if (!canonical) return errors; // The main gate reports missing metadata.
    if (!slug.startsWith('/') || slug.startsWith('//') || !slug.endsWith('/')) {
        errors.push('manifest slug must be an absolute project path ending with a slash');
    }
    const origin = new URL(deploymentOrigin).origin;
    const site = new URL(slug, origin);
    if (site.origin !== origin) errors.push('manifest slug must stay on the deployment origin');
    const path = pageName.replace(/index\.html$/, '').replaceAll('\\', '/');
    const expected = new URL(path, site).href;
    if (canonical !== expected) errors.push(`canonical ${canonical} does not identify ${expected}`);

    for (const [lang, target] of [['en', site.href], ['de', new URL('de/', site).href]]) {
        const href = html.match(new RegExp(`<link rel="alternate" hreflang="${lang}" href="([^"]+)"`))?.[1];
        if (href !== target) errors.push(`hreflang ${lang} must identify ${target}`);
    }
    for (const [, value] of html.matchAll(/<(?:link[^>]*rel="sitemap"[^>]*href|meta[^>]*(?:property="og:image"|name="twitter:image")[^>]*content)="([^"]+)"/g)) {
        // A relative URL on /de/ is relative to that page, not the site root.
        const url = new URL(value, canonical);
        if (url.origin !== origin || !url.pathname.startsWith(site.pathname)) {
            errors.push(`metadata resource leaves the project base path: ${value}`);
            continue;
        }
        try {
            const resource = await stat(resolve(output, url.pathname.slice(site.pathname.length)));
            if (!resource.isFile()) errors.push(`metadata resource is not a regular file: ${value}`);
        } catch {
            errors.push(`metadata resource does not exist in the build: ${value}`);
        }
    }
    return errors;
}
