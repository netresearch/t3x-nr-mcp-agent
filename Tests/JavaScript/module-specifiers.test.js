// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Every module under Resources/Public/JavaScript imports its siblings through
 * the importmap prefix `@netresearch/nr-mcp-agent/`, never through a relative
 * path.
 *
 * TYPO3 serves `_assets` with `cache-control: immutable` and a directory name
 * that does not change between extension versions. Only the importmap entries
 * carry the `?bust=` query. A relative import such as `./chat-core.js` resolves
 * against the busted URL of the importing module and loses the query, so a
 * browser keeps the copy of chat-core.js from an earlier version while
 * chat-app.js is new. The bare specifier resolves through the importmap and
 * gets the busted URL.
 *
 * Specifiers are read with @babel/parser, so a JSDoc `import('lit')` type in
 * a comment is not mistaken for code.
 */

import {describe, test, expect} from '@jest/globals';
import {existsSync, readFileSync, readdirSync} from 'node:fs';
import {dirname, join, relative} from 'node:path';
import {fileURLToPath} from 'node:url';
import {parse} from '@babel/parser';

const PREFIX = '@netresearch/nr-mcp-agent/';
const jsDir = join(dirname(fileURLToPath(import.meta.url)), '../../Resources/Public/JavaScript');

/** Every .js file below `dir`, as a path relative to jsDir. */
function moduleFiles(dir = jsDir) {
    return readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return moduleFiles(path);
        }

        return entry.name.endsWith('.js') ? [relative(jsDir, path)] : [];
    });
}

function* walk(node) {
    if (!node || typeof node.type !== 'string') {
        return;
    }
    yield node;
    for (const [key, value] of Object.entries(node)) {
        if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments' || key === 'innerComments') {
            continue;
        }
        for (const child of Array.isArray(value) ? value : [value]) {
            if (child && typeof child === 'object') {
                yield* walk(child);
            }
        }
    }
}

/** The string specifiers of every static import, re-export and dynamic import. */
function specifiers(source) {
    const ast = parse(source, {sourceType: 'module', errorRecovery: false});
    const found = [];
    for (const node of walk(ast.program)) {
        let target = null;
        if (['ImportDeclaration', 'ExportNamedDeclaration', 'ExportAllDeclaration'].includes(node.type)) {
            target = node.source;
        } else if (node.type === 'ImportExpression') {
            target = node.source;
        } else if (node.type === 'CallExpression' && node.callee?.type === 'Import') {
            target = node.arguments[0];
        }
        if (target?.type === 'StringLiteral') {
            found.push({specifier: target.value, line: target.loc.start.line});
        }
    }

    return found;
}

const own = moduleFiles().filter((file) => !file.startsWith('Vendor/'));
const imports = own.flatMap((file) => specifiers(readFileSync(join(jsDir, file), 'utf8')).map((hit) => ({file, ...hit})));

describe('module specifiers under Resources/Public/JavaScript', () => {
    test('the scan sees the entry modules, including the toolbar subdirectory', () => {
        expect(own).toEqual(expect.arrayContaining(['chat-app.js', 'ai-chat-panel.js', 'chat-core.js', join('toolbar', 'chat-panel.js')]));
    });

    test('no module imports a sibling through a relative path', () => {
        const relativeImports = imports
            .filter(({specifier}) => specifier.startsWith('./') || specifier.startsWith('../'))
            .map(({file, line, specifier}) => `${file}:${line} -> ${specifier}`);

        expect(relativeImports).toEqual([]);
    });

    test('the modules load their siblings through the importmap prefix', () => {
        const prefixed = imports.filter(({specifier}) => specifier.startsWith(PREFIX));

        expect(prefixed.length).toBeGreaterThanOrEqual(16);
    });

    test('every importmap-prefixed specifier names an existing file', () => {
        const missing = imports
            .filter(({specifier}) => specifier.startsWith(PREFIX))
            .filter(({specifier}) => !existsSync(join(jsDir, specifier.slice(PREFIX.length))))
            .map(({file, line, specifier}) => `${file}:${line} -> ${specifier}`);

        expect(missing).toEqual([]);
    });

    test('a dynamic import and a re-export are read, a JSDoc import() type is not', () => {
        const source = [
            "/** @type {import('./ignored.js').X} */",
            "export {a} from './reexport.js';",
            "const m = import('./dynamic.js');",
        ].join('\n');

        expect(specifiers(source).map(({specifier}) => specifier)).toEqual(['./reexport.js', './dynamic.js']);
    });
});
