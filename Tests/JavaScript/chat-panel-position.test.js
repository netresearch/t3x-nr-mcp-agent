/**
 * Positioning rules for the floating chat panel.
 *
 * The panel was clamped fully inside the viewport, so it could never be pushed
 * aside — whatever the user was reading, the panel sat on top of part of it and
 * the only escape was collapsing or closing it. These tests pin the rule that
 * replaces that: the panel may hang off an edge, as long as enough of it stays
 * reachable to drag it back.
 *
 * That "as long as" is the whole point, and it is why this is tested rather
 * than eyeballed. A clamp that is merely loosened lets the panel be lost off
 * screen with no way to recover it, which is a worse bug than the one being
 * fixed.
 */

import {describe, test, expect, beforeAll} from '@jest/globals';

const VIEWPORT = {width: 1440, height: 900};

let panel;

beforeAll(async () => {
    await import('../../Resources/Public/JavaScript/ai-chat-panel.js');

    Object.defineProperty(window, 'innerWidth', {value: VIEWPORT.width, configurable: true});
    Object.defineProperty(window, 'innerHeight', {value: VIEWPORT.height, configurable: true});

    panel = document.createElement('ai-chat-panel');
    document.body.append(panel);
    await panel.updateComplete;
});

/** The margin that must stay on screen, mirrored from the component. */
const MIN_VISIBLE = 64;

describe('panel positioning', () => {
    test('can be pushed off the right edge, keeping a grab margin', () => {
        const {x} = panel._constrainPosition(VIEWPORT.width, 100);

        // The old rule capped x at viewport width minus the full panel width,
        // which is what kept it permanently in the way.
        expect(x).toBeGreaterThan(VIEWPORT.width - panel._width);
        expect(x).toBe(VIEWPORT.width - MIN_VISIBLE);
    });

    test('can be pushed off the left edge, keeping a grab margin', () => {
        const {x} = panel._constrainPosition(-5000, 100);

        expect(x).toBeLessThan(0);
        expect(x).toBe(MIN_VISIBLE - panel._width);
    });

    test('can be pushed off the bottom, keeping a grab margin', () => {
        const {y} = panel._constrainPosition(100, 99999);

        expect(y).toBe(VIEWPORT.height - MIN_VISIBLE);
    });

    test('never goes above the top edge', () => {
        const {y} = panel._constrainPosition(100, -300);

        // Dragging happens by the header. Let the header leave the top and the
        // panel becomes unreachable — the one direction that must stay closed.
        expect(y).toBe(0);
    });

    test('leaves a position inside the viewport untouched', () => {
        const {x, y} = panel._constrainPosition(300, 200);

        expect({x, y}).toEqual({x: 300, y: 200});
    });

    test('always leaves something grabbable, whatever is thrown at it', () => {
        const extremes = [-99999, -1, 0, 700, VIEWPORT.width, 99999];

        for (const x of extremes) {
            for (const y of extremes) {
                const pos = panel._constrainPosition(x, y);
                const visibleWidth = Math.min(pos.x + panel._width, VIEWPORT.width) - Math.max(pos.x, 0);
                const visibleHeight = Math.min(pos.y + panel._height, VIEWPORT.height) - Math.max(pos.y, 0);

                expect(visibleWidth).toBeGreaterThanOrEqual(MIN_VISIBLE);
                expect(visibleHeight).toBeGreaterThanOrEqual(MIN_VISIBLE);
            }
        }
    });
});
