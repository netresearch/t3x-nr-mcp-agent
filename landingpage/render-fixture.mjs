// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
/** Render an isolated test checkout without contacting the releases API. */
import {pathToFileURL} from 'node:url';

globalThis.fetch = async () => ({ok: false});
const renderer = await import(pathToFileURL(process.argv[2]).href);
if (typeof renderer.render === 'function') await renderer.render();
