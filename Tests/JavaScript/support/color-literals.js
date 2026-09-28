/**
 * Find colour literals that ignore the backend colour scheme.
 *
 * Parsed, not pattern-matched: the component sources are read with
 * @babel/parser, which separates code from comments and strings, and every
 * piece of CSS in them is tokenized and parsed with the @csstools CSS parser.
 * Whether a value is a colour is decided by @csstools/css-color-parser, so hex,
 * every colour function (rgb(), hsl(), hwb(), lab(), lch(), oklab(), oklch(),
 * color(), color-mix() of literals) and all named colours are covered by the
 * same rule.
 *
 * What is allowed:
 *  - anything inside var() — a literal there is a fallback;
 *  - `transparent` and `currentColor`, which carry no colour of their own;
 *  - system colours (Canvas, CanvasText, …), which follow `color-scheme`;
 *  - the `box-shadow` declaration: a shadow darkens whatever is below it in
 *    both schemes, it is not a colour a reader has to tell apart.
 *
 * Where CSS is looked for:
 *  - css`` tagged templates (Lit styles);
 *  - `style="…"`, `fill="…"` and `stroke="…"` attributes in any template or
 *    string, so html`` templates and inline SVG icons are included;
 *  - element styles set from JavaScript: `x.style.prop = '…'`,
 *    `x.style.setProperty('prop', '…')`, `x.style.cssText = '…'` and
 *    `Object.assign(x.style, {prop: '…'})`.
 */

import {parse} from '@babel/parser';
import {tokenize} from '@csstools/css-tokenizer';
import {
    isFunctionNode,
    isSimpleBlockNode,
    isTokenNode,
    isWhiteSpaceOrCommentNode,
    parseListOfComponentValues,
} from '@csstools/css-parser-algorithms';
import {color} from '@csstools/css-color-parser';

const EXEMPT_PROPERTIES = new Set(['box-shadow']);

/** Keywords the colour parser accepts that carry no colour of their own. */
const COLOURLESS = new Set(['transparent', 'currentcolor']);

const isLiteralColour = (node) => Boolean(color(node))
    && !(isTokenNode(node) && COLOURLESS.has(node.toString().toLowerCase()));

/** Literal colours in a list of component values, var() fallbacks excluded. */
function literalsIn(nodes, found) {
    for (const node of nodes) {
        if (isFunctionNode(node)) {
            const name = node.getName().toLowerCase();
            if (name === 'var' || name === 'env') {
                continue;
            }
            if (isLiteralColour(node)) {
                found.push(node.toString());
                continue;
            }
            literalsIn(node.value, found);
        } else if (isSimpleBlockNode(node)) {
            literalsIn(node.value, found);
        } else if (isTokenNode(node)) {
            if (isLiteralColour(node)) {
                found.push(node.toString());
            }
        }
    }

    return found;
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
        const head = meaningful[hack];
        if (colon !== hack + 1 || !isTokenNode(head) || head.value[0] !== 'ident-token') {
            return;
        }
        yield {property: head.value[4].value.toLowerCase(), value: meaningful.slice(colon + 1)};
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

/** Literal colours in a stylesheet or a declaration list (a style attribute). */
export function colourLiteralsInCss(text) {
    const found = [];
    for (const {property, value} of declarations(parseCss(text))) {
        if (EXEMPT_PROPERTIES.has(property)) {
            continue;
        }
        for (const literal of literalsIn(value, [])) {
            found.push(`${property}: ${literal}`);
        }
    }

    return found;
}

/** Literal colours in one CSS value, e.g. an SVG `fill` attribute. */
export function colourLiteralsInValue(property, value) {
    return colourLiteralsInCss(`${property}: ${value}`);
}

const ATTRIBUTE = /\b(style|fill|stroke)\s*=\s*(["'])(.*?)\2/gis;

/** Literal colours in the style-bearing attributes of a piece of markup. */
export function colourLiteralsInMarkup(markup) {
    const found = [];
    for (const [, attribute, , value] of markup.matchAll(ATTRIBUTE)) {
        const name = attribute.toLowerCase();
        found.push(...(name === 'style' ? colourLiteralsInCss(value) : colourLiteralsInValue(name, value)));
    }

    return found;
}

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
        if (!expression) {
            return;
        }
        const options = stringOptions(expression) ?? ['var(--interpolated)'];
        variants = variants.flatMap((v) => options.map((o) => v + o));
    });

    return variants;
}

function stringOptions(node) {
    if (node.type === 'StringLiteral') {
        return [node.value];
    }
    if (node.type === 'ConditionalExpression') {
        const a = stringOptions(node.consequent);
        const b = stringOptions(node.alternate);
        return a && b ? [...a, ...b] : null;
    }
    if (node.type === 'TemplateLiteral') {
        return templateVariants(node.quasis, node.expressions);
    }

    return null;
}

const isStyleObject = (node) => node?.type === 'MemberExpression' && !node.computed && node.property.name === 'style';

function* walk(node, parent) {
    if (!node || typeof node.type !== 'string') {
        return;
    }
    yield [node, parent];
    for (const [key, child] of Object.entries(node)) {
        if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments' || key === 'innerComments') {
            continue;
        }
        if (Array.isArray(child)) {
            for (const item of child) {
                yield* walk(item, node);
            }
        } else if (child && typeof child.type === 'string') {
            yield* walk(child, node);
        }
    }
}

/**
 * Literal colours in a JavaScript module's styles and markup, each with the
 * line it starts on. Comments are not code and are never read.
 */
export function colourLiteralsInModule(source) {
    const ast = parse(source, {sourceType: 'module', errorRecovery: false});
    const found = [];
    const report = (line, literals) => literals.forEach((literal) => found.push(`${line}: ${literal}`));

    for (const [node, parent] of walk(ast.program)) {
        const line = node.loc?.start.line;

        if (node.type === 'TaggedTemplateExpression' && node.tag.type === 'Identifier' && node.tag.name === 'css') {
            for (const text of templateVariants(node.quasi.quasis, node.quasi.expressions)) {
                report(line, colourLiteralsInCss(text));
            }
            continue;
        }

        // Markup: every template literal and string, html`` included.
        if (node.type === 'TemplateLiteral' && parent?.type !== 'TaggedTemplateExpression') {
            for (const text of templateVariants(node.quasis, node.expressions)) {
                report(line, colourLiteralsInMarkup(text));
            }
        }
        if (node.type === 'TaggedTemplateExpression' && !(node.tag.type === 'Identifier' && node.tag.name === 'css')) {
            for (const text of templateVariants(node.quasi.quasis, node.quasi.expressions)) {
                report(line, colourLiteralsInMarkup(text));
            }
        }
        if (node.type === 'StringLiteral') {
            report(line, colourLiteralsInMarkup(node.value));
        }

        // x.style.prop = '…' / x.style.cssText = '…'
        if (node.type === 'AssignmentExpression' && node.left.type === 'MemberExpression' && isStyleObject(node.left.object)) {
            const property = kebab(node.left.property.name ?? node.left.property.value ?? '');
            for (const value of stringOptions(node.right) ?? []) {
                report(line, property === 'css-text' ? colourLiteralsInCss(value) : colourLiteralsInValue(property, value));
            }
        }

        // x.style.setProperty('prop', '…')
        if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression'
            && isStyleObject(node.callee.object) && node.callee.property.name === 'setProperty') {
            const [name, value] = node.arguments;
            const names = name ? stringOptions(name) : null;
            for (const v of (value && stringOptions(value)) ?? []) {
                report(line, colourLiteralsInValue(names?.[0] ?? 'color', v));
            }
        }

        // Object.assign(x.style, {prop: '…'})
        if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression'
            && node.callee.object.name === 'Object' && node.callee.property.name === 'assign'
            && isStyleObject(node.arguments[0])) {
            for (const object of node.arguments.slice(1)) {
                for (const property of object.properties ?? []) {
                    const key = property.key?.name ?? property.key?.value;
                    for (const value of (property.value && stringOptions(property.value)) ?? []) {
                        report(property.loc?.start.line, colourLiteralsInValue(kebab(String(key)), value));
                    }
                }
            }
        }
    }

    return found;
}
