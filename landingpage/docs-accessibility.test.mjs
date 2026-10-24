// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {prepareDocsHtml} from './docs-accessibility.mjs';

const navigation = '<div class="main_menu" role="navigation"><p>Contents</p><ul class="menu-level-1" role="menubar"><li class="active" role="menuitem"><a href="Chapter.html">Chapter</a><div class="wrapper"><ul class="menu-level-2"><li role="menuitem"><a href="Child.html">Child</a></li></ul></div></li></ul></div>';
const unrelated = '<div class="application-menu"><ul role="menubar"><li role="menuitem">Real application menu</li></ul></div>';

test('the theme table of contents becomes native nested lists with links and classes intact', () => {
  const result = prepareDocsHtml(`<head></head>${navigation}${unrelated}`, '_resources/css/docs-accessibility.css');
  const own = result.slice(result.indexOf('<div class="main_menu"'), result.indexOf(unrelated));
  assert.doesNotMatch(own, /role="(?:menubar|menuitem)"/);
  assert.match(own, /class="active"/);
  assert.match(own, /role="navigation"/);
  assert.match(own, /href="Chapter.html"/);
  assert.match(own, /href="Child.html"/);
  assert.ok(result.includes(unrelated));
});

test('nested manual pages receive one relative supplemental stylesheet and remain repeatable', () => {
  const href = '../../_resources/css/docs-accessibility.css';
  const once = prepareDocsHtml(`<html><head><title>Manual</title></head><body>${navigation}</body></html>`, href);
  assert.ok(once.includes(`href="${href}"`));
  assert.equal(prepareDocsHtml(once, href), once);
  assert.equal(once.match(/data-nr-docs-accessibility/g)?.length, 1);
});

test('scrollable code is a named keyboard-focusable region', () => {
  const result = prepareDocsHtml('<head></head><pre class="literal"><code>long command</code></pre>', 'docs.css');
  assert.match(result, /<pre[^>]*tabindex="0"/);
  assert.match(result, /<pre[^>]*role="region"/);
  assert.match(result, /<pre[^>]*aria-label="Code example"/);
  assert.match(result, /<code>long command<\/code>/);
});

test('an explicitly named code region keeps its existing accessibility attributes', () => {
  const code = '<pre tabindex="0" role="region" aria-labelledby="command-title">command</pre>';
  const result = prepareDocsHtml(`<head></head>${code}`, 'docs.css');
  assert.ok(result.includes(code));
  assert.equal(prepareDocsHtml(result, 'docs.css'), result);
});

test('existing responsive table wrappers become named keyboard regions without changing the table', () => {
  const table = '<table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Data</td></tr></tbody></table>';
  const result = prepareDocsHtml(`<head></head><div class="table-responsive">${table}</div><div class="other">Other</div>`, 'docs.css');
  assert.match(result, /<div class="table-responsive" tabindex="0" role="region" aria-label="Scrollable table">/);
  assert.ok(result.includes(table));
  assert.ok(result.includes('<div class="other">Other</div>'));
});

test('the owned underline has a correct singlepage path and an actually empty alternative text', () => {
  const markup = '<head></head><img src="Images/netresearch-underline.svg" width="200px" alt="1" class="no-border"/>';
  const result = prepareDocsHtml(markup, '../_resources/css/docs-accessibility.css', '../Images/netresearch-underline.svg');
  assert.match(result, /src="\.\.\/Images\/netresearch-underline\.svg"/);
  assert.match(result, /alt=""/);
  assert.match(result, /width="200px"/);
  assert.equal(prepareDocsHtml(result, '../_resources/css/docs-accessibility.css', '../Images/netresearch-underline.svg'), result);
});

test('other image alternatives and paths remain unchanged', () => {
  const image = '<img src="Images/ChatModule.png" alt="AI Chat module">';
  assert.ok(prepareDocsHtml(`<head></head>${image}`, 'docs.css').includes(image));
});
