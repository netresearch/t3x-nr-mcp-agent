// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {cp, mkdir, mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';

const here = dirname(fileURLToPath(import.meta.url));
for (const [base, expected] of [
  ['/nested/project', '/nested/project/docs/Configuration/Index.html'],
  ['/nested/project/', '/nested/project/docs/Configuration/Index.html'],
  ['', '/docs/Configuration/Index.html'],
]) {
  test(`old documentation URL redirects and canonical agree for base ${JSON.stringify(base)}`, async () => {
    const fixture = await mkdtemp(resolve(tmpdir(), 'nr-docs-redirect-'));
    try {
      await mkdir(resolve(fixture, 'landingpage'));
      await cp(resolve(here, 'redirect-stubs.mjs'), resolve(fixture, 'landingpage/redirect-stubs.mjs'));
      await mkdir(resolve(fixture, '.Build/site/docs/Configuration'), {recursive: true});
      await writeFile(resolve(fixture, '.Build/site/docs/Configuration/Index.html'), '<h1>Configuration</h1>');
      execFileSync(process.execPath, [resolve(fixture, 'landingpage/redirect-stubs.mjs')], {
        env: {...process.env, PAGES_BASE_PATH: base}, stdio: 'pipe',
      });
      const html = await readFile(resolve(fixture, '.Build/site/Configuration/Index.html'), 'utf8');
      assert.ok(html.includes(`content="0; url=${expected}"`));
      assert.ok(html.includes(`<link rel="canonical" href="${expected}">`));
      assert.ok(html.includes(`<a href="${expected}">`));
    } finally {
      await rm(fixture, {recursive: true, force: true});
    }
  });
}
