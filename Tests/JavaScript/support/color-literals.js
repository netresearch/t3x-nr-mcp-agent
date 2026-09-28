/**
 * Find colour literals that ignore the backend colour scheme.
 *
 * Parsed, not pattern-matched. The component sources are read with
 * @babel/parser, which separates code from comments and strings. Every piece of
 * CSS found in them is parsed with css-tree, and each declaration is matched
 * against its property's value grammar with css-tree's lexer: only the parts
 * the grammar types as `<color>` are looked at. So `grid-area: red`,
 * `animation-name: tomato` or `transition: background` are not colours, while
 * `border: 1px solid red` is — decided by the grammar, not by a keyword list.
 * A module that does not parse throws, and so does a CSS value that does not
 * parse or does not match its property's grammar: a guard that skipped them
 * would report them clean.
 *
 * What is allowed inside a `<color>`:
 *  - anything inside var() — a literal there is a fallback. For matching, each
 *    var() is stood in for by a placeholder that fits the grammar
 *    (`currentcolor`, `0`, `0px`, an identifier, …); the placeholder is never
 *    reported;
 *  - `transparent` and `currentColor`, which carry no colour of their own;
 *  - the current CSS system colours (Canvas, CanvasText, ButtonFace, …), which
 *    follow `color-scheme`. The deprecated ones (ThreeDFace, Window, …) and
 *    non-standard ones such as `-webkit-focus-ring-color` are reported;
 *  - the colours of a `box-shadow` layer whose blur radius is at least 1px:
 *    a soft shadow darkens whatever is below it in both schemes. The blur is
 *    read from the layer's `<shadow>` match, so a layer without blur, with a
 *    hairline blur or with a computed one (calc(), var()) is checked like any
 *    colour — it draws a line such as a focus ring.
 *
 * A custom property has no grammar; its value is scanned for anything that
 * matches the `<color>` type. So is a descriptor css-tree does not know as a
 * property (`initial-value` in `@property`).
 *
 * Where CSS is looked for:
 *  - templates tagged `css`, however it is imported (`css`, an alias of it,
 *    `lit.css`), `unsafeCSS('…')`, and one-argument `x.replaceSync('…')` /
 *    `x.replace('…')`;
 *  - inside `<…>` tags in any template or string: `<style>` elements and the
 *    `style`, `fill`, `stroke`, `stop-color`, `flood-color`, `lighting-color`
 *    and `color` attributes, quoted or not (`data-*` excluded). html``,
 *    svg``, untagged templates and string markup are all included;
 *  - element styles set from JavaScript, also through optional chaining:
 *    `x.style.prop = '…'`, `x.style.setProperty('prop', '…')`,
 *    `x.style.cssText = '…'`, `Object.assign(x.style, {prop: '…'})` and
 *    `x.setAttribute(name, '…')` for the attributes above.
 *
 * A finding names the source line of the literal, counted on the raw source
 * text, so escapes and line continuations do not shift it.
 *
 * Limits — what needs data flow or a model of an API, which a syntax walk
 * does not have:
 *  - a colour held in a constant and interpolated or passed along
 *    (`css`${unsafeCSS(RED)}``, `el.style.color = RED`);
 *  - string concatenation (`'#' + '555'`);
 *  - lit's `styleMap({...})` and any object not literally `x.style`;
 *  - `x.style` reached through a variable or a computed member (`el['style']`);
 *  - a property name that is itself interpolated;
 *  - SVG inside a `data:` URI in `url()`;
 *  - CSS assigned to a `<style>` element's `textContent`;
 *  - `sheet.insertRule('…')` and keyframes passed to `el.animate([...])`;
 *  - `css` bound by destructuring (`const {css: c} = lit`).
 */

import {parse} from '@babel/parser';
import * as csstree from 'css-tree';

/**
 * css-tree's `<color>` lacks the deprecated system colours and the relative
 * colour syntax. Both are added, so that such values match — and are reported —
 * instead of failing the grammar.
 */
const RELATIVE = ['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch']
    .map((name) => `${name}( from <color> <declaration-value> )`).join(' | ');
const {lexer} = csstree.fork({
    types: {
        color: `${csstree.definitionSyntax.generate(csstree.lexer.types.color.syntax)} | <deprecated-system-color> | ${RELATIVE}`,
    },
});

/** Keywords of `<color>` that carry no colour of their own. */
const COLOURLESS = new Set(['transparent', 'currentcolor']);

/** CSS Color 4 §6.2: system colours that follow the used colour scheme. */
const SYSTEM_COLOURS = new Set([
    'accentcolor', 'accentcolortext', 'activetext', 'buttonborder', 'buttonface', 'buttontext',
    'canvas', 'canvastext', 'field', 'fieldtext', 'graytext', 'highlight', 'highlighttext',
    'linktext', 'mark', 'marktext', 'selecteditem', 'selecteditemtext', 'visitedtext',
]);

/** Properties whose value is a single colour when written as an SVG/HTML attribute. */
const COLOUR_ATTRIBUTES = new Set(['fill', 'stroke', 'stop-color', 'flood-color', 'lighting-color', 'color']);

/**
 * Stand-ins for var() and env(), tried until the value matches its grammar.
 * Each is a list of nodes: `0 0` stands in for a whole shadow.
 */
const PLACEHOLDERS = [
    [{type: 'Identifier', name: 'currentcolor'}],
    [{type: 'Number', value: '0'}],
    [{type: 'Dimension', value: '0', unit: 'px'}],
    [{type: 'Identifier', name: 'placeholder'}],
    [{type: 'Identifier', name: 'none'}],
    [{type: 'Dimension', value: '0', unit: 's'}],
    [{type: 'Number', value: '1'}],
    [{type: 'Identifier', name: 'auto'}],
    [{type: 'Percentage', value: '0'}],
    [{type: 'Number', value: '0'}, {type: 'Number', value: '0'}],
];

const PLACEHOLDER_MARK = Symbol('placeholder');

const SUBSTITUTED = new Set(['var', 'env']);

const isSubstituted = (node) => node.type === 'Function' && SUBSTITUTED.has(node.name.toLowerCase());

/** The outermost var() and env() calls of a value, in order. */
function substitutions(value) {
    const found = [];
    csstree.walk(value, {
        visit: 'Function',
        enter(node) {
            if (isSubstituted(node)) {
                found.push(node);
                return this.skip;
            }
            return undefined;
        },
    });

    return found;
}

/** A copy of a value AST with the n-th var()/env() replaced by `choice[n]`. */
function withPlaceholders(value, choice) {
    const copy = csstree.clone(value);
    let index = 0;
    csstree.walk(copy, {
        visit: 'Function',
        enter(node, item, list) {
            if (!list || !isSubstituted(node)) {
                return undefined;
            }
            for (const placeholder of choice[index++]) {
                list.insertData({...placeholder, loc: node.loc, [PLACEHOLDER_MARK]: true}, item);
            }
            list.remove(item);
            return this.skip;
        },
    });

    return copy;
}

/**
 * Placeholder combinations: first the same stand-in everywhere, then, for up
 * to three substitutions, every mix of them.
 */
function* placeholderChoices(count) {
    for (const placeholder of PLACEHOLDERS) {
        yield Array(count).fill(placeholder);
    }
    if (count < 2 || count > 3) {
        return;
    }
    const mixes = (n) => (n === 0 ? [[]] : mixes(n - 1).flatMap((rest) => PLACEHOLDERS.map((p) => [p, ...rest])));
    yield* mixes(count);
}

/** Literal colours written as the fallback of an env(). */
function envFallbackLiterals(value) {
    return substitutions(value)
        .filter((node) => node.name.toLowerCase() === 'env')
        .flatMap((node) => {
            const children = node.children.toArray();
            const comma = children.findIndex((c) => c.type === 'Operator' && c.value === ',');
            if (comma < 0) {
                return [];
            }
            return literalsInFreeValue({type: 'Value', children: new csstree.List().fromArray(children.slice(comma + 1))});
        });
}

/** Every match-tree node, depth first. */
function* matchNodes(node) {
    if (!node) {
        return;
    }
    yield node;
    for (const child of node.match ?? []) {
        yield* matchNodes(child);
    }
}

const isType = (match, name) => match.syntax?.type === 'Type' && match.syntax.name === name;

/** The AST nodes a match covers, in order. */
const astNodesOf = (match) => [...matchNodes(match)].map((m) => m.node).filter(Boolean);

/** A colour is literal unless it is colourless, a current system colour or a stand-in. */
function isLiteral(node) {
    if (node[PLACEHOLDER_MARK]) {
        return false;
    }
    if (node.type === 'Identifier') {
        const name = node.name.toLowerCase();
        return !COLOURLESS.has(name) && !SYSTEM_COLOURS.has(name);
    }

    return true;
}

/** `<color>` matches that contain no other `<color>`: the colours actually written. */
function leafColours(match) {
    const found = [];
    const visit = (node) => {
        if (isType(node, 'color') && ![...matchNodes(node)].slice(1).some((m) => isType(m, 'color'))) {
            found.push(node);
            return;
        }
        (node.match ?? []).forEach(visit);
    };
    visit(match);

    return found;
}

/** Shadow layers whose blur is a plain length of at least 1. */
function softShadows(match) {
    return [...matchNodes(match)].filter((m) => isType(m, 'shadow')).filter((shadow) => {
        const lengths = [];
        const collect = (node) => {
            if (isType(node, 'color')) {
                return;
            }
            if (isType(node, 'length') && node !== shadow) {
                lengths.push(node);
                return;
            }
            (node.match ?? []).forEach(collect);
        };
        (shadow.match ?? []).forEach(collect);
        const blur = lengths[2] ? astNodesOf(lengths[2]) : [];

        return blur.length === 1 && blur[0].type === 'Dimension' && !blur[0][PLACEHOLDER_MARK] && Number(blur[0].value) >= 1;
    });
}

/** Literal colours in a value matched against a known property's grammar. */
function literalsInPropertyValue(property, value) {
    const count = substitutions(value).length;
    const fallbacks = envFallbackLiterals(value);
    let lastError = null;
    for (const choice of count > 0 ? placeholderChoices(count) : [[]]) {
        const candidate = count > 0 ? withPlaceholders(value, choice) : value;
        const result = lexer.matchProperty(property, candidate);
        if (result.matched) {
            const soft = /(^|-)box-shadow$/.test(property) ? softShadows(result.matched) : [];
            const exempt = new Set(soft.flatMap((shadow) => astNodesOf(shadow)));
            return [...fallbacks, ...leafColours(result.matched)
                .map((colour) => astNodesOf(colour))
                .filter((nodes) => nodes.length > 0 && !nodes.some((n) => exempt.has(n)) && nodes.some(isLiteral))
                .map((nodes) => nodes[0])];
        }
        lastError = result.error;
    }
    throw new Error(`colour guard: "${property}: ${csstree.generate(value)}" does not match the property's grammar (${lastError?.message?.split('\n')[0]})`);
}

/** Literal colours in a value without a grammar: anything that is a `<color>`. */
function literalsInFreeValue(value) {
    const found = [];
    csstree.walk(value, {
        enter(node) {
            if (node.type === 'Function' && node.name.toLowerCase() === 'var') {
                return this.skip;
            }
            if (!['Identifier', 'Hash', 'Function'].includes(node.type)) {
                return undefined;
            }
            const result = lexer.matchType('color', node);
            if (!result.matched) {
                return undefined;
            }
            found.push(...leafColours(result.matched).map((c) => astNodesOf(c)).filter((n) => n.some(isLiteral)).map((n) => n[0]));
            return this.skip;
        },
    });

    return found;
}

const parseErrors = (errors, text) => {
    if (errors.length > 0) {
        throw new Error(`colour guard: CSS does not parse (${errors[0].message}) in: ${text.slice(0, 120)}`);
    }
};

/**
 * Findings in CSS text, `{property, literal, offset}` with the offset into
 * `text`. `context` is 'stylesheet' or 'declarationList' (a style attribute).
 */
function findInCss(text, context = 'stylesheet') {
    const errors = [];
    const ast = csstree.parse(text, {context, positions: true, onParseError: (e) => errors.push(e)});
    parseErrors(errors, text);
    const found = [];
    csstree.walk(ast, {
        visit: 'Declaration',
        enter(declaration) {
            // `*color` is the old IE property hack; the property still applies elsewhere.
            const property = declaration.property.replace(/^[*_]/, '').toLowerCase();
            let {value} = declaration;
            if (value.type === 'Raw') {
                const valueErrors = [];
                const offset = value.loc?.start.offset ?? 0;
                value = csstree.parse(value.value, {context: 'value', positions: true, offset, onParseError: (e) => valueErrors.push(e)});
                parseErrors(valueErrors, declaration.value.value);
            }
            // An empty value (`el.style.right = ''`) removes the property; there is nothing to match.
            if (value.children?.isEmpty) {
                return;
            }
            const known = !property.startsWith('--') && lexer.getProperty(property) !== null;
            const nodes = known ? literalsInPropertyValue(property, value) : literalsInFreeValue(value);
            for (const node of nodes) {
                found.push({property, literal: csstree.generate(node), offset: node.loc?.start.offset ?? 0});
            }
        },
    });

    return found;
}

/** Findings in markup: `<style>` elements, and colour-bearing attributes inside `<…>` tags. */
function findInMarkup(markup) {
    const found = [];
    for (const match of markup.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) {
        const base = match.index + match[0].indexOf('>') + 1;
        found.push(...findInCss(match[1]).map((hit) => ({...hit, offset: base + hit.offset})));
    }
    const names = ['style', ...COLOUR_ATTRIBUTES].join('|');
    const attribute = new RegExp(`(?<![\\w-])(${names})\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s"'=<>\`]+))`, 'gi');
    for (const tag of markup.matchAll(/<[a-zA-Z][\w:-]*\b(?:"[^"]*"|'[^']*'|[^'">])*>/g)) {
        for (const match of tag[0].matchAll(attribute)) {
            const name = match[1].toLowerCase();
            const value = match[2] ?? match[3] ?? match[4];
            const base = tag.index + match.index + match[0].lastIndexOf(value);
            const hits = name === 'style'
                ? findInCss(value, 'declarationList')
                : findInCss(`${name}: ${value}`, 'declarationList').map((hit) => ({...hit, offset: hit.offset - (name.length + 2)}));
            found.push(...hits.map((hit) => ({...hit, offset: base + hit.offset})));
        }
    }

    return found;
}

const render = (hits) => hits.map(({property, literal}) => `${property}: ${literal}`);

/** Literal colours in a stylesheet. */
export const colourLiteralsInCss = (text) => render(findInCss(text));

/** Literal colours in the style-bearing parts of a piece of markup. */
export const colourLiteralsInMarkup = (markup) => render(findInMarkup(markup));

const kebab = (name) => name.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);

/**
 * How many raw source characters produce `cooked` characters of a template or
 * string: escapes are longer in the source, a line continuation produces nothing.
 */
function rawLength(raw, cooked) {
    let r = 0;
    let c = 0;
    while (r < raw.length && c < cooked) {
        if (raw[r] !== '\\') {
            r++;
            c++;
            continue;
        }
        const next = raw[r + 1];
        if (next === '\r' && raw[r + 2] === '\n') {
            r += 3;
        } else if (next === '\n' || next === '\r' || next === '\u2028' || next === '\u2029') {
            r += 2;
        } else if (next === 'x') {
            r += 4;
            c++;
        } else if (next === 'u') {
            r += raw[r + 2] === '{' ? raw.indexOf('}', r) + 1 - r : 6;
            c++;
        } else {
            r += 2;
            c++;
        }
    }

    return r;
}

/**
 * A text built from source pieces, each remembering where it starts in the
 * text and on which source line, so an offset maps back to a line.
 */
class SourceText {
    constructor(text = '', segments = []) {
        this.text = text;
        this.segments = segments;
    }

    append(piece) {
        const shifted = piece.segments.map((s) => ({...s, start: s.start + this.text.length}));
        return new SourceText(this.text + piece.text, [...this.segments, ...shifted]);
    }

    static fromRaw(cooked, raw, line) {
        return new SourceText(cooked, [{start: 0, length: cooked.length, raw, line}]);
    }

    lineAt(offset) {
        const segment = [...this.segments].reverse().find((s) => offset >= s.start) ?? this.segments[0];
        if (!segment) {
            return 0;
        }
        const rawPrefix = segment.raw.slice(0, rawLength(segment.raw, offset - segment.start));
        return segment.line + (rawPrefix.match(/\r\n|[\n\r\u2028\u2029]/g)?.length ?? 0);
    }
}

/**
 * The texts a template produces, once per possible value of each interpolation
 * that is a string or a choice between strings; any other interpolation becomes
 * a var() reference, which the checks treat as scheme-aware.
 */
function templateVariants(quasis, expressions, css = false) {
    let variants = [new SourceText()];
    quasis.forEach((quasi, i) => {
        const piece = SourceText.fromRaw(quasi.value.cooked ?? quasi.value.raw, quasi.value.raw, quasi.loc.start.line);
        variants = variants.map((v) => v.append(piece));
        const expression = expressions[i];
        if (expression) {
            variants = variants.flatMap((v) => (stringOptions(expression) ?? [interpolated(v.text, css, expression)])
                .map((o) => v.append(o)));
        }
    });

    return variants;
}

/**
 * What an interpolation that is not a string stands for. In markup and in a
 * CSS value it is a var() reference; between CSS rules or declarations (a
 * `${unsafeCSS(...)}` block) it is a comment, so the stylesheet still parses.
 */
function interpolated(textBefore, css, expression) {
    const inValue = !css || /:[^;{}]*$/.test(textBefore);
    const text = inValue ? 'var(--interpolated)' : '/* interpolated */';
    return SourceText.fromRaw(text, text, expression.loc.start.line);
}

function stringOptions(node) {
    switch (node?.type) {
        case 'StringLiteral':
            return [SourceText.fromRaw(node.value, node.extra?.raw?.slice(1, -1) ?? node.value, node.loc.start.line)];
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

/** The plain strings of a node's options, for names and values that are not searched for markup. */
const stringValues = (node) => (stringOptions(node) ?? []).map((o) => o.text);

const isMember = (node) => node?.type === 'MemberExpression' || node?.type === 'OptionalMemberExpression';
const isStyleObject = (node) => isMember(node) && !node.computed && node.property.name === 'style';
const calleeName = (node) => (isMember(node.callee) && !node.callee.computed ? node.callee.property.name : node.callee.name);

/** Findings in every variant of a text, each with its source line. */
const inVariants = (variants, find) => variants.flatMap((variant) => find(variant.text)
    .map((hit) => ({line: variant.lineAt(hit.offset), hit})));

/** CSS findings in each option of a string-valued node; `wrap` turns a value into a declaration. */
const cssOptions = (node, wrap = null) => (stringOptions(node) ?? []).flatMap((option) => {
    if (!wrap) {
        return inVariants([option], findInCss);
    }
    const prefix = wrap('');
    return findInCss(wrap(option.text), 'declarationList')
        .map((hit) => ({line: option.lineAt(Math.max(0, hit.offset - prefix.length)), hit}));
});

/** One handler per called function, by name. */
const CALL_HANDLERS = {
    setProperty(node) {
        if (!isStyleObject(node.callee.object)) {
            return [];
        }
        const [name, value] = node.arguments;
        const property = stringValues(name)[0] ?? 'color';
        return cssOptions(value, (v) => `${property}: ${v}`);
    },

    setAttribute(node) {
        const [name, value] = node.arguments;
        const attribute = stringValues(name)[0]?.toLowerCase();
        if (attribute === 'style') {
            return (stringOptions(value) ?? []).flatMap((o) => inVariants([o], (t) => findInCss(t, 'declarationList')));
        }
        return COLOUR_ATTRIBUTES.has(attribute) ? cssOptions(value, (v) => `${attribute}: ${v}`) : [];
    },

    unsafeCSS: (node) => cssOptions(node.arguments[0]),

    replaceSync: (node) => (node.arguments.length === 1 ? cssOptions(node.arguments[0]) : []),

    // CSSStyleSheet.replace() takes one argument; String.prototype.replace takes two.
    replace: (node) => (isMember(node.callee) && node.arguments.length === 1 ? cssOptions(node.arguments[0]) : []),

    assign(node) {
        const [target, ...sources] = node.arguments;
        if (node.callee.object?.name !== 'Object' || !isStyleObject(target)) {
            return [];
        }
        return sources.flatMap((object) => (object.properties ?? []).flatMap((property) => {
            const key = kebab(String(property.key?.name ?? property.key?.value));
            return cssOptions(property.value, (v) => `${key}: ${v}`);
        }));
    },
};

function callHandler(node) {
    const name = calleeName(node);
    return Object.hasOwn(CALL_HANDLERS, name) ? CALL_HANDLERS[name](node) : [];
}

/** One handler per node type. Each returns findings as `{line, hit}`. */
const HANDLERS = {
    TaggedTemplateExpression(node, ctx) {
        const {tag, quasi} = node;
        const isCss = (tag.type === 'Identifier' && ctx.cssTags.has(tag.name))
            || (isMember(tag) && !tag.computed && tag.property.name === 'css');
        return inVariants(templateVariants(quasi.quasis, quasi.expressions, isCss), isCss ? findInCss : findInMarkup);
    },

    TemplateLiteral(node, ctx, parent) {
        if (parent?.type === 'TaggedTemplateExpression') {
            return [];
        }
        return inVariants(templateVariants(node.quasis, node.expressions), findInMarkup);
    },

    StringLiteral(node) {
        return inVariants(stringOptions(node), findInMarkup);
    },

    AssignmentExpression(node) {
        const {left, right} = node;
        if (!isMember(left) || !isStyleObject(left.object)) {
            return [];
        }
        const property = kebab(left.property.name ?? left.property.value ?? '');
        return property === 'css-text'
            ? (stringOptions(right) ?? []).flatMap((o) => inVariants([o], (t) => findInCss(t, 'declarationList')))
            : cssOptions(right, (v) => `${property}: ${v}`);
    },

    CallExpression: callHandler,

    OptionalCallExpression: callHandler,
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

/** Local names bound to lit's `css` by an import. */
function cssTagNames(program) {
    const names = new Set(['css']);
    for (const node of program.body) {
        if (node.type !== 'ImportDeclaration') {
            continue;
        }
        for (const specifier of node.specifiers) {
            if (specifier.type === 'ImportSpecifier' && specifier.imported.name === 'css') {
                names.add(specifier.local.name);
            }
        }
    }

    return names;
}

/**
 * Literal colours in a JavaScript module's styles and markup, each as
 * `<line>: <property>: <literal>`, where the line is the one the literal is on.
 * Comments are not code and are never read. Throws when the module, or a CSS
 * value in it, does not parse.
 */
export function colourLiteralsInModule(source) {
    const ast = parse(source, {sourceType: 'module', errorRecovery: false});
    const ctx = {cssTags: cssTagNames(ast.program)};
    const found = [];
    for (const [node, parent] of walk(ast.program)) {
        const handler = Object.hasOwn(HANDLERS, node.type) ? HANDLERS[node.type] : null;
        if (handler) {
            for (const {line, hit} of handler(node, ctx, parent)) {
                found.push(`${line}: ${hit.property}: ${hit.literal}`);
            }
        }
    }

    return [...new Set(found)];
}
