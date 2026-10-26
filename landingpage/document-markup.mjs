// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {parse} from 'parse5';

/** Real, explicit elements in document order, without executing or loading HTML.
 * Template contents are separate fragments, not document child nodes. Source
 * locations exclude implied elements and retain original opening-tag offsets.
 */
export function documentElements(html) {
    const pending = [parse(html, {sourceCodeLocationInfo: true})];
    const elements = [];
    while (pending.length) {
        const node = pending.pop();
        const opening = node.sourceCodeLocation?.startTag;
        if (opening) elements.push({node, name: node.tagName, start: opening.startOffset,
            attributes: Object.fromEntries(node.attrs.map(({name, value}) => [name, value]))});
        pending.push(...(node.childNodes ?? []).toReversed());
    }
    return elements;
}

/** Head membership follows the parsed document, including an implied head. */
export function isInDocumentHead(element) {
    for (let parent = element?.node.parentNode; parent; parent = parent.parentNode) {
        if (parent.tagName === 'head' && parent.namespaceURI === 'http://www.w3.org/1999/xhtml') return true;
    }
    return false;
}
