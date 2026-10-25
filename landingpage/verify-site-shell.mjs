// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
import {createHash} from 'node:crypto';

// Unmodified symbol-only asset from Netresearch branding 3.1.0.
const SYMBOL_SHA256 = 'ca88779a6d008b4606bbe8d45f4640d6967f82b66b302d95b9153dcf2cfb9e29';
export function verifySiteShell(html, symbol) {
    const errors = [];
    const headers = [...html.matchAll(/<header\b[^>]*data-nr-shell[^>]*>[\s\S]*?<\/header>/g)];
    const logos = [...html.matchAll(/<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>/g)];
    if (headers.length !== 1 || logos.length !== 1 || !headers[0]?.[0].includes(logos[0]?.[0])) {
        errors.push('exactly one canonical symbol must appear in the shared header');
    }
    if (!headers[0]?.[0].match(/<a\b[^>]*href="https:\/\/www\.netresearch\.de\/"[^>]*>\s*<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>\s*<\/a>/)) {
        errors.push('header symbol must link to Netresearch DTT GmbH');
    }
    if (!logos[0]?.[0].includes('width="44"') || !logos[0]?.[0].includes('height="44"') || !logos[0]?.[0].includes('alt="Netresearch DTT GmbH"')) {
        errors.push('header symbol must retain its dimensions and accessible name');
    }
    if (createHash('sha256').update(symbol).digest('hex') !== SYMBOL_SHA256) errors.push('header symbol differs from the authentic brand asset');
    return errors;
}
