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

// Configure marked: GFM tables + line breaks, no async
marked.setOptions({
    gfm: true,
    breaks: false,
    async: false,
});

/**
 * Parse a markdown string and return sanitized HTML.
 *
 * Safe to use with LLM output — HTML is sanitized by DOMPurify before
 * returning. Unknown constructs (e.g. ::: directives) pass through as
 * plain text without throwing.
 *
 * @param {string} text - Raw markdown string from the LLM
 * @returns {string} Sanitized HTML string, or '' for empty input
 */
export function renderMarkdown(text) {
    if (!text) return '';
    const raw = /** @type {string} */ (marked.parse(text));
    return DOMPurify.sanitize(raw, {
        USE_PROFILES: {html: true},
        FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed'],
        FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover'],
    });
}
