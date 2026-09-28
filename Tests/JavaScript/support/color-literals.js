/**
 * Find colour literals that ignore the backend colour scheme.
 *
 * Parsed, not pattern-matched: the component sources are read with
 * @babel/parser, which separates code from comments and strings, and every
 * piece of CSS in them is tokenized and parsed with the @csstools CSS parser.
 * Whether a value is a colour is decided by @csstools/css-color-parser, so hex,
 * every colour function (rgb(), hsl(), hwb(), lab(), lch(), oklab(), oklch(),
 * color(), relative colours, color-mix() of literals) and all named colours are
 * covered by the same rule. A module that does not parse throws: a guard that
 * skipped it would report it clean.
 *
 * What is allowed:
 *  - anything inside var() — a literal there is a fallback;
 *  - `transparent` and `currentColor`, which carry no colour of their own;
 *  - the current CSS system colours (Canvas, CanvasText, ButtonFace, …), which
 *    follow `color-scheme`. They pass because the colour parser does not
 *    resolve them — it has no scheme to resolve them against — not because they
 *    are listed. The deprecated ones (ThreeDFace, Window, …), fixed colours in
 *    practice, are listed below and reported;
 *  - a `box-shadow` layer with a non-zero blur radius: a soft shadow darkens
 *    whatever is below it in both schemes. A layer without blur is a drawn
 *    line — a focus ring or a border — and is checked like any colour.
 *
 * Where CSS is looked for:
 *  - templates tagged `css`, however it is imported (`css`, an alias of it,
 *    `lit.css`), `unsafeCSS('…')`, and `x.replaceSync('…')` / `x.replace('…')`;
 *  - `<style>` elements and the `style`, `fill`, `stroke`, `stop-color`,
 *    `flood-color`, `lighting-color` and `color` attributes (quoted or not) in
 *    any template or string, so html`` templates, untagged templates, string
 *    markup and inline SVG icons are included;
 *  - element styles set from JavaScript: `x.style.prop = '…'`,
 *    `x.style.setProperty('prop', '…')`, `x.style.cssText = '…'`,
 *    `Object.assign(x.style, {prop: '…'})` and `x.setAttribute(name, '…')`
 *    for the attributes above.
 *
 * Limits — what needs data flow, which a syntax walk does not follow:
 *  - a colour held in a constant and interpolated or passed along
 *    (`css`${unsafeCSS(RED)}``, `el.style.color = RED`);
 *  - string concatenation (`'#' + '555'`);
 *  - lit's `styleMap({...})` and any object not literally `x.style`;
 *  - `x.style` reached through a variable or a computed member (`el['style']`);
 *  - a property name that is itself interpolated;
 *  - SVG inside a `data:` URI in `url()`;
 *  - CSS assigned to a `<style>` element's `textContent`: the target is only
 *    known to be a style element through data flow.
 */

import {parse} from '@babel/parser';
import {tokenize} from '@csstools/css-tokenizer';
import {
    isFunctionNode,
    isSimpleBlockNode,
    isTokenNode,
    isWhiteSpaceOrCommentNode,
    parseListOfComponentValues,
    sourceIndices,
} from '@csstools/css-parser-algorithms';
import {color} from '@csstools/css-color-parser';

/** Keywords the colour parser accepts that carry no colour of their own. */
const COLOURLESS = new Set(['transparent', 'currentcolor']);

/** CSS Color 4 §6.3: deprecated system colours, fixed values in practice. */
const DEPRECATED_SYSTEM_COLOURS = new Set([
    'activeborder', 'activecaption', 'appworkspace', 'background', 'buttonhighlight', 'buttonshadow',
    'captiontext', 'inactiveborder', 'inactivecaption', 'inactivecaptiontext', 'infobackground',
    'infotext', 'menu', 'menutext', 'scrollbar', 'threeddarkshadow', 'threedface', 'threedhighlight',
    'threedlightshadow', 'threedshadow', 'window', 'windowframe', 'windowtext',
]);

/** Properties whose value is a single colour when written as an SVG/HTML attribute. */
const COLOUR_ATTRIBUTES = ['fill', 'stroke', 'stop-color', 'flood-color', 'lighting-color', 'color'];

const identOf = (node) => (isTokenNode(node) && node.value[0] === 'ident-token' ? node.value[4].value.toLowerCase() : null);

function isLiteralColour(node) {
    const ident = identOf(node);
    if (ident && COLOURLESS.has(ident)) {
        return false;
    }
    if (ident && DEPRECATED_SYSTEM_COLOURS.has(ident)) {
        return true;
    }

    return Boolean(color(node));
}

const offsetOf = (node) => sourceIndices(node)[0];

/**
 * Properties whose values name other properties: `transition: background 0.15s`
 * holds the property `background`, not the deprecated system colour of that
 * name, so bare keywords are not read as colours there.
 */
const PROPERTY_LISTS = new Set(['transition', 'transition-property', 'will-change']);

/** Literal colours in a list of component values, var() fallbacks excluded. */
function literalsIn(nodes, found = [], keywords = true) {
    for (const node of nodes) {
        if (isFunctionNode(node)) {
            if (node.getName().toLowerCase() === 'var') {
                continue;
            }
            if (isLiteralColour(node)) {
                found.push({literal: node.toString(), offset: offsetOf(node)});
                continue;
            }
            literalsIn(node.value, found, keywords);
        } else if (isSimpleBlockNode(node)) {
            literalsIn(node.value, found, keywords);
        } else if (isTokenNode(node) && (keywords || !identOf(node)) && isLiteralColour(node)) {
            found.push({literal: node.toString(), offset: offsetOf(node)});
        }
    }

    return found;
}

/**
 * A box-shadow layer with a blur radius above zero: `<x> <y> <blur> …`. The
 * third length decides; `inset` and the colour may stand anywhere.
 */
function isSoftShadowLayer(nodes) {
    const lengths = nodes.filter((n) => isTokenNode(n) && /^(dimension|number)-token$/.test(n.value[0]));

    return lengths.length >= 3 && lengths[2].value[4].value > 0;
}

/** Literals in a declaration value, soft box-shadow layers left out. */
function literalsInDeclaration(property, value) {
    if (PROPERTY_LISTS.has(property)) {
        return literalsIn(value, [], false);
    }
    if (property !== 'box-shadow' && property !== '-webkit-box-shadow') {
        return literalsIn(value);
    }
    const layers = [[]];
    for (const node of value) {
        if (isTokenNode(node) && node.value[0] === 'comma-token') {
            layers.push([]);
        } else {
            layers.at(-1).push(node);
        }
    }

    return layers.filter((layer) => !isSoftShadowLayer(layer)).flatMap((layer) => literalsIn(layer));
}

const parseCss = (text) => parseListOfComponentValues(tokenize({css: text}), {onParseError: () => {}});

/**
 * Split component values into declarations, descending into `{}` blocks.
 * Selectors and at-rule preludes before a block are dropped with it.
 */
function* declarations(nodes) {
    let current = [];
    const flush = function* () {
        const meaningful = current.filter((n) => !isWhiteSpaceOrCommentNode(n));
        current = [];
        const colon = meaningful.findIndex((n) => isTokenNode(n) && n.value[0] === 'colon-token');
        // A leading `*` or `_` is the old IE property hack; the property still applies elsewhere.
        const hack = isTokenNode(meaningful[0]) && meaningful[0].value[0] === 'delim-token' ? 1 : 0;
        const property = identOf(meaningful[hack]);
        if (colon !== hack + 1 || property === null) {
            return;
        }
        yield {property, value: meaningful.slice(colon + 1)};
    };
    for (const node of nodes) {
        if (isSimpleBlockNode(node) && node.startToken[0] === '{-token') {
            current = [];
            yield* declarations(node.value);
            continue;
        }
        if (isTokenNode(node) && node.value[0] === 'semicolon-token') {
            yield* flush();
            continue;
        }
        current.push(node);
    }
    yield* flush();
}

/** Findings in CSS text: `{property, literal, offset}`, offset into `text`. */
function findInCss(text) {
    const found = [];
    for (const {property, value} of declarations(parseCss(text))) {
        for (const hit of literalsInDeclaration(property, value)) {
            found.push({property, ...hit});
        }
    }

    return found;
}

/** Findings in markup: `<style>` elements and colour-bearing attributes. */
function findInMarkup(markup) {
    const found = [];
    for (const match of markup.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) {
        const base = match.index + match[0].indexOf('>') + 1;
        found.push(...findInCss(match[1]).map((hit) => ({...hit, offset: base + hit.offset})));
    }
    const names = ['style', ...COLOUR_ATTRIBUTES].join('|');
    const attribute = new RegExp(`(?<![\\w-])(${names})\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s"'=<>\`]+))`, 'gi');
    for (const match of markup.matchAll(attribute)) {
        const name = match[1].toLowerCase();
        const value = match[2] ?? match[3] ?? match[4];
        const base = match.index + match[0].lastIndexOf(value);
        const css = name === 'style' ? value : `${name}: ${value}`;
        const shift = name === 'style' ? 0 : -(name.length + 2);
        found.push(...findInCss(css).map((hit) => ({...hit, offset: base + shift + hit.offset})));
    }

    return found;
}

const render = (hits) => hits.map(({property, literal}) => `${property}: ${literal}`);

/** Literal colours in a stylesheet or a declaration list (a style attribute). */
export const colourLiteralsInCss = (text) => render(findInCss(text));

/** Literal colours in the style-bearing parts of a piece of markup. */
export const colourLiteralsInMarkup = (markup) => render(findInMarkup(markup));

const kebab = (name) => name.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);

/**
 * The text a template produces, once per possible value of each interpolation
 * that is a string or a choice between strings; any other interpolation becomes
 * a var() reference, which the checks treat as scheme-aware.
 */
function templateVariants(quasis, expressions) {
    let variants = [''];
    quasis.forEach((quasi, i) => {
        variants = variants.map((v) => v + (quasi.value.cooked ?? quasi.value.raw));
        const expression = expressions[i];
        if (expression) {
            const options = stringOptions(expression) ?? ['var(--interpolated)'];
            variants = variants.flatMap((v) => options.map((o) => v + o));
        }
    });

    return variants;
}

function stringOptions(node) {
    switch (node?.type) {
        case 'StringLiteral':
            return [node.value];
        case 'TemplateLiteral':
            return templateVariants(node.quasis, node.expressions);
        case 'ConditionalExpression': {
            const a = stringOptions(node.consequent);
            const b = stringOptions(node.alternate);
            return a && b ? [...a, ...b] : null;
        }
        default:
            return null;
    }
}

const isStyleObject = (node) => node?.type === 'MemberExpression' && !node.computed && node.property.name === 'style';
const calleeName = (node) => (node.callee.type === 'MemberExpression' && !node.callee.computed ? node.callee.property.name : node.callee.name);

/** The `line`-based position of `offset` characters into a node's text. */
const lineAt = (node, text, offset) => node.loc.start.line + (text.slice(0, offset).match(/\n/g)?.length ?? 0);

/** CSS text in each option of a string-valued node. */
const cssOptions = (node, wrap = (v) => v) => (stringOptions(node) ?? []).flatMap((v) => findInCss(wrap(v)));

/** One handler per called function, by name. Each returns findings; a finding may carry its own line. */
const CALL_HANDLERS = {
    setProperty(node) {
        if (!isStyleObject(node.callee.object)) {
            return [];
        }
        const [name, value] = node.arguments;
        const property = stringOptions(name)?.[0] ?? 'color';
        return cssOptions(value, (v) => `${property}: ${v}`);
    },

    setAttribute(node) {
        const [name, value] = node.arguments;
        const attribute = stringOptions(name)?.[0]?.toLowerCase();
        if (attribute === 'style') {
            return cssOptions(value);
        }
        return COLOUR_ATTRIBUTES.includes(attribute) ? cssOptions(value, (v) => `${attribute}: ${v}`) : [];
    },

    unsafeCSS: (node) => cssOptions(node.arguments[0]),

    replaceSync: (node) => cssOptions(node.arguments[0]),

    replace: (node) => (node.callee.type === 'MemberExpression' ? cssOptions(node.arguments[0]) : []),

    assign(node) {
        const [target, ...sources] = node.arguments;
        if (node.callee.object?.name !== 'Object' || !isStyleObject(target)) {
            return [];
        }
        return sources.flatMap((object) => (object.properties ?? []).flatMap((property) => {
            const key = kebab(String(property.key?.name ?? property.key?.value));
            return cssOptions(property.value, (v) => `${key}: ${v}`).map((hit) => ({...hit, line: property.loc.start.line}));
        }));
    },
};

/**
 * One handler per node type. Each returns findings as `{line, hit}`; the walk
 * collects them. `ctx.cssTags` holds the local names bound to lit's `css`.
 */
const HANDLERS = {
    ImportDeclaration(node, ctx) {
        for (const specifier of node.specifiers) {
            if (specifier.type === 'ImportSpecifier' && specifier.imported.name === 'css') {
                ctx.cssTags.add(specifier.local.name);
            }
        }
        return [];
    },

    TaggedTemplateExpression(node, ctx) {
        const {tag, quasi} = node;
        const isCss = (tag.type === 'Identifier' && ctx.cssTags.has(tag.name))
            || (tag.type === 'MemberExpression' && !tag.computed && tag.property.name === 'css');
        const find = isCss ? findInCss : findInMarkup;
        return templateVariants(quasi.quasis, quasi.expressions)
            .flatMap((text) => find(text).map((hit) => ({line: lineAt(quasi, text, hit.offset), hit})));
    },

    TemplateLiteral(node, ctx, parent) {
        if (parent?.type === 'TaggedTemplateExpression') {
            return [];
        }
        return templateVariants(node.quasis, node.expressions)
            .flatMap((text) => findInMarkup(text).map((hit) => ({line: lineAt(node, text, hit.offset), hit})));
    },

    StringLiteral(node) {
        return findInMarkup(node.value).map((hit) => ({line: lineAt(node, node.value, hit.offset), hit}));
    },

    AssignmentExpression(node) {
        const {left, right} = node;
        if (left.type !== 'MemberExpression' || !isStyleObject(left.object)) {
            return [];
        }
        const property = kebab(left.property.name ?? left.property.value ?? '');
        return (stringOptions(right) ?? []).flatMap((value) => (property === 'css-text'
            ? findInCss(value)
            : findInCss(`${property}: ${value}`)).map((hit) => ({line: node.loc.start.line, hit})));
    },

    CallExpression(node) {
        const name = calleeName(node);
        const handler = Object.hasOwn(CALL_HANDLERS, name) ? CALL_HANDLERS[name] : null;
        const hits = handler ? handler(node) : [];
        return hits.map((hit) => ({line: hit.line ?? node.loc.start.line, hit}));
    },
};

function* walk(node, parent) {
    if (!node || typeof node.type !== 'string') {
        return;
    }
    yield [node, parent];
    for (const [key, child] of Object.entries(node)) {
        if (key === 'loc' || key.endsWith('Comments')) {
            continue;
        }
        for (const item of Array.isArray(child) ? child : [child]) {
            yield* walk(item, node);
        }
    }
}

/**
 * Literal colours in a JavaScript module's styles and markup, each as
 * `<line>: <property>: <literal>`, where the line is the one the literal is on.
 * Comments are not code and are never read. Throws when the module does not
 * parse.
 */
export function colourLiteralsInModule(source) {
    const ast = parse(source, {sourceType: 'module', errorRecovery: false});
    const ctx = {cssTags: new Set(['css'])};
    // Imports first, so an aliased `css` is known wherever it is used.
    for (const node of ast.program.body) {
        if (node.type === 'ImportDeclaration') {
            HANDLERS.ImportDeclaration(node, ctx);
        }
    }
    const found = [];
    for (const [node, parent] of walk(ast.program)) {
        const handler = Object.hasOwn(HANDLERS, node.type) ? HANDLERS[node.type] : null;
        if (handler && node.type !== 'ImportDeclaration') {
            for (const {line, hit} of handler(node, ctx, parent)) {
                found.push(`${line}: ${hit.property}: ${hit.literal}`);
            }
        }
    }

    return [...new Set(found)];
}
