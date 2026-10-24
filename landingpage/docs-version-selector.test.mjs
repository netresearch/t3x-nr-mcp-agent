// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {runInNewContext} from 'node:vm';
import {guardVersionSelector} from './docs-version-selector.mjs';

// The renderer's initializer prefix, followed by observable selector behavior.
const initializer = 'document.addEventListener("DOMContentLoaded",()=>{const n=document.getElementById("languageSelect"),a=document.getElementById("versionSelect");let r=document.URL;window.version=a.getAttribute("data-current-version");a.addEventListener("change",()=>{});window.initialized=true;});';

function initialize(script, selector) {
  let ready;
  const window = {};
  runInNewContext(script, {
    window,
    document: {
      URL: 'https://example.test/docs/',
      getElementById: (id) => id === 'versionSelect' ? selector : null,
      addEventListener: (event, callback) => { if (event === 'DOMContentLoaded') ready = callback; },
    },
  });
  ready();
  return window;
}

test('documentation without its removed version selector initializes without a page error', () => {
  const result = initialize(guardVersionSelector(initializer), null);
  assert.equal(result.initialized, undefined);
});

test('a present version selector retains its original initialization and change listener', () => {
  const events = [];
  const result = initialize(guardVersionSelector(initializer), {
    getAttribute: (name) => name === 'data-current-version' ? '0.18' : null,
    addEventListener: (event) => events.push(event),
  });
  assert.equal(result.version, '0.18');
  assert.equal(result.initialized, true);
  assert.deepEqual(events, ['change']);
});

test('the generated-theme adaptation is idempotent', () => {
  const once = guardVersionSelector(initializer);
  assert.equal(guardVersionSelector(once), once);
});

test('an unknown or duplicated renderer initializer fails rather than patching arbitrary JavaScript', () => {
  assert.throws(() => guardVersionSelector('window.otherTheme = true;'), /exactly one version-selector initializer/);
  assert.throws(() => guardVersionSelector(initializer + initializer), /exactly one version-selector initializer/);
});
