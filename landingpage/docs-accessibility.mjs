// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/** Only the renderer's plain-link table of contents is a native navigation list. */
function navigationRanges(markup) {
  const ranges = [];
  let start = null;
  let depth = 0;
  for (const match of markup.matchAll(/<\/?div\b[^>]*>/gi)) {
    const closing = match[0].startsWith('</');
    if (start === null) {
      const classes = match[0].match(/\bclass=(["'])([^"']*)\1/);
      if (!closing && classes?.[2].split(/\s+/).includes('main_menu')) {
        start = match.index;
        depth = 1;
      }
      continue;
    }
    depth += closing ? -1 : 1;
    if (depth === 0) {
      ranges.push([start, match.index + match[0].length]);
      start = null;
    }
  }
  if (start !== null) throw new Error('render-guides: unclosed main_menu navigation');
  return ranges;
}

function focusableRegion(tag, label) {
  const attributes = [];
  if (!/\btabindex\s*=/i.test(tag)) attributes.push(' tabindex="0"');
  if (!/\brole\s*=/i.test(tag)) attributes.push(' role="region"');
  if (!/\baria-label(?:ledby)?\s*=/i.test(tag)) attributes.push(` aria-label="${label}"`);
  return tag.slice(0, -1) + attributes.join('') + '>';
}

function focusableScrollRegions(markup) {
  return markup.replace(/<(?:pre|div)\b[^>]*>/gi, (tag) => {
    if (/^<pre\b/i.test(tag)) return focusableRegion(tag, 'Code example');
    const classes = tag.match(/\bclass=(["'])([^"']*)\1/);
    if (classes?.[2].split(/\s+/).includes('table-responsive')) return focusableRegion(tag, 'Scrollable table');
    return tag;
  });
}

function decorativeUnderline(markup, href) {
  return markup.replace(/<img\b[^>]*>/gi, (tag) => {
    const source = tag.match(/\bsrc=(["'])([^"']*)\1/i);
    if (!source || !/^(?:\.\.\/)*Images\/netresearch-underline\.svg$/.test(source[2])) return tag;
    // The pinned renderer turns empty :alt: into "1", and its singlepage image
    // path omits the parent segment. Correct only this owned decorative asset.
    const image = tag.replace(source[0], `src="${href}"`)
      .replace(/\salt=(["'])[^"']*\1/gi, '');
    return image.replace(/\/?>$/, (ending) => ` alt=""${ending}`);
  });
}

export function prepareDocsHtml(markup, stylesheet, underline = 'Images/netresearch-underline.svg') {
  for (const [start, end] of navigationRanges(markup).reverse()) {
    const navigation = markup.slice(start, end).replace(/<(?:ul|li)\b[^>]*>/gi,
      (tag) => tag.replace(/\srole=(["'])(?:menubar|menuitem)\1/gi, ''));
    markup = markup.slice(0, start) + navigation + markup.slice(end);
  }
  markup = focusableScrollRegions(markup);
  markup = decorativeUnderline(markup, underline);
  if (/<link\b[^>]*\bdata-nr-docs-accessibility\b/i.test(markup)) return markup;
  if (!markup.includes('</head>')) throw new Error('render-guides: missing document head');
  return markup.replace('</head>', `<link rel="stylesheet" href="${stylesheet}" data-nr-docs-accessibility>\n</head>`);
}
