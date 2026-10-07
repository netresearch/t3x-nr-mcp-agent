// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Markdown rendering utility for AI chat messages.
 *
 * Uses marked (v15) for parsing and DOMPurify (v3) for sanitization.
 * Both are vendored in Resources/Public/JavaScript/Vendor/ and registered
 * in the TYPO3 importmap (Configuration/JavaScriptModules.php).
 *
 * @module @netresearch/nr-mcp-agent/markdown
 */

import {marked} from 'marked';
import DOMPurify from 'dompurify';

const MARKED_OPTIONS = {
    gfm: true,
    breaks: false,
    async: false,
};

const INLINE_IMAGE = /^data:image\/(png|jpeg|gif|webp)[;,]/i;

/**
 * Whether an image source may stay in a rendered answer: an inline image
 * (data:image/...) or an image of the backend's own origin. The browser
 * requests an image as soon as it is shown, without any action of the reader.
 *
 * @param {string} src
 * @returns {boolean}
 */
function isAllowedImageSource(src) {
    if (INLINE_IMAGE.test(src.trim())) {
        return true;
    }
    try {
        const url = new URL(src, document.baseURI);
        return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === window.location.origin;
    } catch {
        return false;
    }
}

/**
 * Whether a link leaves the backend's origin.
 *
 * @param {string} href
 * @returns {boolean}
 */
function isExternalLink(href) {
    try {
        return new URL(href, document.baseURI).origin !== window.location.origin;
    } catch {
        return true;
    }
}

// An own instance, so the hooks below do not change what other modules get
// from the shared 'dompurify' import.
const purifier = DOMPurify(window);

purifier.addHook('uponSanitizeElement', (node, data) => {
    if (data.tagName === 'img' && !isAllowedImageSource(node.getAttribute('src') || '')) {
        node.remove();
    }
});

purifier.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.hasAttribute('href') && isExternalLink(node.getAttribute('href') || '')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

/**
 * Parse a markdown string and return sanitized HTML.
 *
 * Safe to use with LLM output — HTML is sanitized by DOMPurify before
 * returning. Images are kept only when they are inline or come from the
 * backend's own origin; links to another origin open in a new tab without
 * a referrer. Unknown constructs (e.g. ::: directives) pass through as
 * plain text without throwing.
 *
 * @param {string} text - Raw markdown string from the LLM
 * @returns {string} Sanitized HTML string, or '' for empty input
 */
export function renderMarkdown(text) {
    if (!text) return '';
    const raw = /** @type {string} */ (marked.parse(text, MARKED_OPTIONS));
    return purifier.sanitize(raw, {
        USE_PROFILES: {html: true},
        // Elements and attributes that load a resource without the reader's
        // action, besides <img src> (see the hooks above), are not kept.
        FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed', 'picture', 'source', 'video', 'audio', 'track', 'input'],
        FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'srcset', 'poster', 'background', 'style'],
    });
}
