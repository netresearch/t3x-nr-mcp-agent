// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

// This prefix identifies the callback emitted by the pinned render-guides
// image. Refuse an unknown shape rather than editing unrelated vendor code.
const INITIALIZER = /document\.addEventListener\("DOMContentLoaded",\(\)=>\{const [A-Za-z_$][\w$]*=document\.getElementById\("languageSelect"\),(?<selector>[A-Za-z_$][\w$]*)=document\.getElementById\("versionSelect"\);/g;

/** Skip only the version-switcher callback when its optional control is absent. */
export function guardVersionSelector(script) {
  const matches = [...script.matchAll(INITIALIZER)];
  if (matches.length !== 1) {
    throw new Error('render-guides theme: expected exactly one version-selector initializer; review the pinned renderer before adapting it');
  }
  const match = matches[0];
  const end = match.index + match[0].length;
  const guard = `if(!${match.groups.selector})return;`;
  if (script.startsWith(guard, end)) return script;
  return script.slice(0, end) + guard + script.slice(end);
}
