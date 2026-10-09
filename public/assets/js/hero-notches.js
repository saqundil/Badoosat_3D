/*
 * The hero's three notches.
 *
 * There is no separate navigation bar above the hero any more. The dark card
 * runs to the top of the page and the chrome floats in pieces cut out of it:
 *
 *   top inline-start   the logo and the section links
 *   top inline-end     the cart button and the account icon
 *   bottom inline-start  the "design your shirt" call to action
 *
 * This is the same drawing as the notch under a product card's price, at a
 * very different size, so it reads the same five proportions out of
 * catalog-cards.js rather than keeping a second copy of them. What it does NOT
 * share is mediaPath(): that function cuts one notch out of one corner and is
 * relied on by the cards and the product page, so it is left exactly as it is
 * and heroPath() below is written separately.
 *
 * One deliberate difference from the cards. A price badge is a fixed box that
 * the price is fitted into, so the card measures nothing off its own content.
 * Here each notch is measured from the element that floats in it, through a
 * ResizeObserver, because the links are words: "منتجات مطبوعة" and "Printed
 * products" are not the same width, and a notch sized for one would crop or
 * strand the other.
 */

import { NOTCH, isRtl, SUPPORTS_PATH } from './catalog-cards.js';

export { SUPPORTS_PATH };

/** The three notches, named by the corner each one is cut into. */
export const CORNERS = ['start', 'end', 'bottom'];

/**
 * A notch's curves come from the notch, not from the hero.
 *
 * The product card can derive every radius from one width because the notch
 * there is a fixed fraction of the tile. Here the card is five times wider than
 * a tile and the things sitting in the notches are navigation links, whose
 * height does not grow with the page: 0.075 of a 1400px hero is a 105px fillet
 * cut into a 98px-deep notch, which is not a corner but a collapse. So each
 * notch rounds by a fraction of its own shorter side, held between a floor that
 * still reads as a curve and a ceiling that keeps it a corner.
 */
const CURVE = { ratio: 0.45, min: 24, max: 52 };

/**
 * The hero's own outer corner still follows the page width, but only so far.
 * Past this it stops being a rounded corner and starts being a circle.
 */
const R_MAX = 64;

/**
 * The breathing room between a floating group and the edge of the notch cut
 * for it, on the notch's two inner sides.
 *
 * From the group's own height, not from the page width. A fraction of the hero
 * is the right measure for the hero's corners and the wrong one for this: at
 * 1440 it came to 31px of clearance around a 38px-tall row of links, so the
 * notch ran a full link's height below the links and left a band of empty
 * card under them. What the gap has to clear is the content, so that is what
 * it is measured from.
 */
const GAP = { ratio: 0.12, min: 6, max: 14 };

/**
 * How the top edge steps down into a notch.
 *
 * Not a fillet, an inner corner and a second fillet with straight runs between
 * them -- one S, two arcs of the same radius meeting tangentially, so there is
 * no inner corner anywhere on the top edge and no flat between the curves. The
 * first leaves the notch's floor horizontal, the second arrives at the card's
 * top horizontal, and the steepest point is where they touch.
 *
 * Each arc turns through the same angle:
 *
 *      cos θ = 1 - nh / (2 · sweepR)
 *      run   = 2 · sweepR · sin θ
 *
 * sweepR is the one dial. Larger draws the step out longer and shallower;
 * sweepR = nh / 2 is the hard floor, where θ reaches 90° and the step is as
 * abrupt as two tangent arcs can make it. The default below is sweepR = nh,
 * which gives θ = 60° and run = √3 · nh.
 *
 * The midpoint falls out exactly: sweepR·(1 - cos θ) is nh/2 by definition, so
 * the arcs always meet at half the depth and half the run, whatever sweepR is.
 */
const SWEEP_RATIO = 1;

const curveFor = (nw, nh) =>
    Math.min(CURVE.max, Math.max(CURVE.min, Math.round(Math.min(nh, nw) * CURVE.ratio)));

const gapFor = (contentHeight) =>
    Math.min(GAP.max, Math.max(GAP.min, Math.round(contentHeight * GAP.ratio)));

export function sweepFor(nh, ratio = SWEEP_RATIO) {
    const sweepR = Math.max(1, Math.round(nh * ratio));
    const cos = 1 - nh / (2 * sweepR);
    const theta = Math.acos(Math.max(-1, Math.min(1, cos)));

    return { sweepR, theta, run: Math.round(2 * sweepR * Math.sin(theta)) };
}

/**
 * Every length the outline needs, from the card's measured width and the
 * measured box of the element floating in each notch.
 *
 * `boxes` is { start: {w, h}, end: {w, h}, bottom: {w, h} } in CSS pixels --
 * the border boxes of the three floating groups. A missing or zero-sized entry
 * means that corner is not notched at all and keeps the outer radius R.
 *
 * Nothing here is clamped. check() is what decides whether the result can be
 * drawn, and it is called separately so a violation can be reported with the
 * numbers that caused it rather than silently absorbed.
 */
export function heroGeometry(W, boxes) {
    const S = W;                                     // the hero's own width

    const R = Math.min(Math.round(S * NOTCH.R), R_MAX);

    /*
     * The gap goes on the notch's two inner sides and nowhere else. Each group
     * is flush into its corner of the card -- a band on the outer sides would
     * be a band running off the card -- and each gets its own gap, from its own
     * content, so a short row is not cut as deep as a tall one.
     *
     * A box marked `full` spans the whole top edge rather than sitting in a
     * corner of it. There is then no inner corner and no fillet on that edge to
     * size: the card simply starts lower, with its outer radius at both top
     * corners, and every radius below is zero.
     */
    const notches = {};

    for (const corner of CORNERS) {
        const box = boxes[corner];

        if (!box || box.w <= 0 || box.h <= 0) {
            notches[corner] = null;
            continue;
        }

        const hContent = Math.round(box.h);
        const wContent = Math.round(box.w);
        const gap = gapFor(hContent);

        if (box.full) {
            notches[corner] = {
                nw: W, nh: hContent + gap, gap,
                full: true, top: true, run: 0, sweepR: 0, theta: 0,
            };
            continue;
        }

        const nw = wContent + gap;
        const nh = hContent + gap;

        /*
         * The two top notches step down on an S and have no inner corner, so nr
         * and the two fillets do not apply to them at all. The bottom notch is
         * still a corner cut with an inner corner, and keeps curve.
         *
         * Neither uses a radius of its own where it meets the card's outline:
         * that meeting point IS one of the card's four outer corners, and all
         * four are R.
         */
        notches[corner] = corner === 'bottom'
            ? { nw, nh, gap, full: false, top: false, nr: curveFor(nw, nh), nfEdge: curveFor(nw, nh) }
            : { nw, nh, gap, full: false, top: true, ...sweepFor(nh) };
    }

    return { S, R, notches };
}

/**
 * The constraints, checked one notch at a time and then across the top pair.
 *
 * Each returned entry is a failure, carrying the numbers, so the caller can
 * print something a human can act on instead of "the hero looks wrong".
 */
export function check(W, H, g) {
    const bad = [];
    const { R, notches } = g;

    const fail = (where, rule, left, right, parts) =>
        bad.push({ where, rule, left: Math.round(left), right: Math.round(right), parts });

    for (const corner of CORNERS) {
        const n = notches[corner];

        /* A notch that spans its whole edge has no step to draw. */
        if (n === null || n.full) {
            continue;
        }

        if (n.top) {
            /* Below this the two arcs cannot meet tangentially at all: cos θ
               would have to exceed 0 and the S would need a straight run or a
               corner between its halves. */
            if (n.sweepR < n.nh / 2) {
                fail(corner, 'sweepR >= nh / 2', n.sweepR, n.nh / 2,
                    { sweepR: n.sweepR, nh: n.nh });
            }

            /* The notch and the step out of it have to fit the top edge, clear
               of the rounded corner at the far end. */
            if (n.nw + n.run > W - R) {
                fail(corner, 'nw + run <= W - R', n.nw + n.run, W - R,
                    { nw: n.nw, run: n.run, W, R });
            }

            continue;
        }

        /* The bottom notch is still a corner cut, and still has an inner
           corner between its two edges. */
        if (n.nr + n.nfEdge > n.nh) {
            fail(corner, 'nr + nfEdge <= nh', n.nr + n.nfEdge, n.nh,
                { nr: n.nr, nfEdge: n.nfEdge, nh: n.nh });
        }

        /* Its other edge carries the inner corner and the card's own corner. */
        if (n.nr + R > n.nw) {
            fail(corner, 'nr + R <= nw', n.nr + R, n.nw, { nr: n.nr, R, nw: n.nw });
        }

        if (n.nw + n.nfEdge > W - R) {
            fail(corner, 'nw + nfEdge <= W - R', n.nw + n.nfEdge, W - R,
                { nw: n.nw, nfEdge: n.nfEdge, W, R });
        }
    }

    /* The two top notches share the top edge and must not meet in the middle.
       Where one notch spans the edge there is no pair to check. */
    if (notches.start !== null && notches.end !== null
        && !notches.start.full && !notches.end.full) {
        const used = notches.start.nw + notches.start.run
            + notches.end.nw + notches.end.run;

        if (used > W - R * 2) {
            fail('top pair', 'nw_s + run_s + nw_e + run_e <= W - R*2', used, W - R * 2, {
                nwStart: notches.start.nw, runStart: notches.start.run,
                nwEnd: notches.end.nw, runEnd: notches.end.run, W, R,
            });
        }
    }

    /*
     * Not in the brief, but the two inline-start notches share the left edge
     * the same way the top pair share the top one, and the end notch shares the
     * right edge with the one rounded corner that is left. Left unchecked these
     * fold the outline exactly as the listed constraints do.
     *
     * A spanning top notch puts the card's own outer radius on both top corners
     * instead of a side fillet, so that is what the edges carry below it.
     */
    if (notches.start !== null && notches.bottom !== null) {
        const used = notches.start.nh + notches.bottom.nh + R * 2;

        if (used > H) {
            fail('start edge', 'nh_start + nh_bottom + R*2 <= H', used, H, {
                nhStart: notches.start.nh, nhBottom: notches.bottom.nh, R, H,
            });
        }
    }

    const endDepth = notches.end !== null && !notches.end.full
        ? notches.end.nh
        : (notches.start !== null && notches.start.full ? notches.start.nh : null);

    if (endDepth !== null && endDepth + R * 2 > H) {
        fail('end edge', 'nh_end + R*2 <= H', endDepth + R * 2, H, { nhEnd: endDepth, R, H });
    }

    return bad;
}

/**
 * Reduces radii until the outline can be drawn, and says what it reduced.
 *
 * This is a safety net, not a design decision. It is the same move geometry()
 * makes in catalog-cards.js -- there a narrow column would otherwise drive the
 * notch wider than the side it is cut into and fold the outline inside out --
 * and it only ever shrinks. When check() comes back empty it changes nothing.
 *
 * It runs per notch because nw and nh are per notch; R, the fillets and nr are
 * one shared set and stay shared wherever the constraints allow.
 */
export function clampToFit(W, H, g) {
    const notches = {};

    const R = Math.max(0, Math.min(g.R, W / 2, H / 2));

    for (const corner of CORNERS) {
        const n = g.notches[corner];

        if (n === null) {
            notches[corner] = null;
            continue;
        }

        if (n.full) {
            notches[corner] = {
                ...n, nw: W, nh: Math.max(0, Math.min(n.nh, H - R * 2)),
            };
            continue;
        }

        const nw = Math.max(0, Math.min(n.nw, W - R));
        const nh = Math.max(0, Math.min(n.nh, H - R));

        if (n.top) {
            /* The step is a function of the depth, so a clamped depth redraws
               it rather than being scaled after the fact. */
            notches[corner] = { ...n, nw, nh, ...sweepFor(nh) };
            continue;
        }

        /* The bottom notch's edge fillet is bounded by the notch edge it shares
           with the inner corner, and by the card edge it sits on. */
        const nfEdge = Math.max(0, Math.min(n.nfEdge, nh, W - R - nw));
        const nr = Math.max(0, Math.min(n.nr, nh - nfEdge, nw - R));

        notches[corner] = { ...n, nw, nh, nfEdge, nr };
    }

    /*
     * The top pair share the top edge: give each half of what is left.
     *
     * The limit is the brief's own -- W - R*2, not the bare edge. With the
     * curves cut from each notch rather than from the page width, and the links
     * folding away before the two groups can crowd each other, this no longer
     * fires; it stays as the floor it was meant to be rather than something the
     * shape leans on.
     */
    if (notches.start !== null && notches.end !== null
        && !notches.start.full && !notches.end.full) {
        const room = W - R * 2 - notches.start.run - notches.end.run;
        const want = notches.start.nw + notches.end.nw;

        if (want > room && want > 0) {
            const scale = Math.max(0, room / want);
            notches.start.nw = Math.floor(notches.start.nw * scale);
            notches.end.nw = Math.floor(notches.end.nw * scale);
        }
    }

    /* ...and the inline-start pair share the left edge the same way. */
    if (notches.start !== null && notches.bottom !== null) {
        const room = H - R * 2;
        const want = notches.start.nh + notches.bottom.nh;

        if (want > room && want > 0) {
            const scale = Math.max(0, room / want);
            notches.start.nh = Math.floor(notches.start.nh * scale);
            notches.bottom.nh = Math.floor(notches.bottom.nh * scale);
        }
    }

    /* Whatever the two passes above took off nw or nh, the curves follow. */
    for (const corner of CORNERS) {
        const n = notches[corner];

        if (n === null || n.full) {
            continue;
        }

        if (n.top) {
            Object.assign(n, sweepFor(n.nh));
            continue;
        }

        n.nfEdge = Math.max(0, Math.min(n.nfEdge, n.nh));
        n.nr = Math.max(0, Math.min(n.nr, n.nh - n.nfEdge, n.nw - R));
    }

    return { ...g, R, notches };
}

/**
 * Clockwise outline of the hero card: up to three corners carry a notch, the
 * remaining corners are rounded by R.
 *
 * Every arc is one of two kinds and the sweep-flag follows from which:
 *
 *   convex  -- an outer corner, or a fillet running back out to a card edge;
 *              its centre is inside the card             -> sweep 1
 *   concave -- a notch's own inner corner; its centre is
 *              inside the notch, in the part cut away    -> sweep 0
 *
 * Flip either and the arc snaps to the opposite centre, breaking both tangents
 * and leaving a hard step where a curve should be.
 *
 * Mirroring for a right-to-left document is x -> W - x. That reverses the
 * direction the outline is traversed in, so every sweep-flag inverts with it or
 * the arcs all bulge the wrong way -- the same rule notchOutline() follows.
 */
export function heroPath(W, H, g, rtl = false) {
    const n = (v) => Math.round(v * 100) / 100;
    const X = (x) => n(rtl ? W - x : x);
    const F = (f) => (rtl ? 1 - f : f);
    const Y = n;

    const { R, notches } = g;
    const d = [];

    const arc = (r, sweep, x, y) => d.push('A', n(r) + ',' + n(r), '0 0 ' + F(sweep), X(x) + ',' + Y(y));
    const line = (x, y) => d.push('L', X(x) + ',' + Y(y));

    const S = notches.start;
    const E = notches.end;
    const B = notches.bottom;

    /*
     * One notch spanning the top edge is not a corner cut at all: the card
     * simply begins at y = nh with its own radius on both top corners. There is
     * no end notch in that mode, and nothing to fillet back out to.
     */
    const spans = S !== null && S.full;
    const top = spans ? S.nh : 0;

    /* Where the top edge begins, just past the start notch's step. */
    const topFrom = spans ? R : (S ? S.nw + S.run : R);

    d.push('M', X(topFrom) + ',' + Y(top));

    /* ---- top edge, left to right, into the end notch or the end corner ----

       The step down is one S: two arcs of sweepR meeting tangentially at half
       the depth and half the run, with nothing between them. Descending to the
       right, the first arc turns one way and the second the other, so the flags
       are 1 then 0 -- the mirror of the start notch's 0 then 1. */
    if (E && !spans) {
        line(W - E.nw - E.run, 0);
        arc(E.sweepR, 1, W - E.nw - E.run / 2, E.nh / 2);
        arc(E.sweepR, 0, W - E.nw, E.nh);
        line(W - R, E.nh);                                // the notch's own floor
        arc(R, 1, W, E.nh + R);                           // corner 2 of the card
    } else {
        line(W - R, top);
        arc(R, 1, W, top + R);
    }

    /* ---- right edge down to the bottom-end corner: corner 4, the reference ---- */
    line(W, H - R);
    arc(R, 1, W - R, H);

    /* ---- bottom edge, right to left, into the bottom notch ---- */
    if (B) {
        line(B.nw + B.nfEdge, H);
        arc(B.nfEdge, 1, B.nw, H - B.nfEdge);             // into the notch
        line(B.nw, H - B.nh + B.nr);
        arc(B.nr, 0, B.nw - B.nr, H - B.nh);              // the notch's inner corner
        line(R, H - B.nh);
        arc(R, 1, 0, H - B.nh - R);                       // corner 3 of the card
    } else {
        line(R, H);
        arc(R, 1, 0, H - R);
    }

    /* ---- left edge up, into the start notch and back to where we began ----

       Rising to the right: the first arc leaves the notch's floor horizontal
       (flag 0), the second levels out onto the card's top (flag 1). */
    if (S && !spans) {
        line(0, S.nh + R);
        arc(R, 1, R, S.nh);                               // corner 1 of the card
        line(S.nw, S.nh);                                 // the notch's own floor
        arc(S.sweepR, 0, S.nw + S.run / 2, S.nh / 2);
        arc(S.sweepR, 1, S.nw + S.run, 0);
    } else {
        line(0, top + R);
        arc(R, 1, R, top);
    }

    d.push('Z');

    return d.join(' ');
}

/* ------------------------------------------------------------------ wiring */

const card = document.querySelector('[data-hero-card]');

if (card !== null && card.querySelector('[data-hero-notch]') !== null) {
    const clipped = Array.from(card.querySelectorAll('[data-hero-clip]'));
    const chrome = card.querySelector('[data-hero-chrome]');
    const floats = {};

    for (const corner of CORNERS) {
        floats[corner] = card.querySelector('[data-hero-notch="' + corner + '"]');
    }

    let reported = '';

    const measure = (el) => {
        if (el === null || el.hidden || el.offsetParent === null) {
            return null;
        }

        const r = el.getBoundingClientRect();

        return r.width > 0 && r.height > 0 ? { w: r.width, h: r.height } : null;
    };

    /*
     * Which shape the top of the card takes is the stylesheet's call, since it
     * is the stylesheet that decides when the two groups stop fitting side by
     * side and become one row. It says so in a custom property rather than
     * having this file keep a second copy of the breakpoint.
     */
    const spansTopEdge = () =>
        getComputedStyle(card).getPropertyValue('--chrome-mode').trim() === 'single';

    function layout() {
        const W = card.clientWidth;
        const H = card.clientHeight;

        if (W === 0 || H === 0) {
            return;
        }

        const boxes = { bottom: measure(floats.bottom) };

        if (spansTopEdge() && chrome !== null) {
            /* One row across the whole edge: the wrapper is the notch, and
               there is no second group to cut a corner for. */
            const row = measure(chrome);

            boxes.start = row === null ? null : { ...row, full: true };
            boxes.end = null;
        } else {
            boxes.start = measure(floats.start);
            boxes.end = measure(floats.end);
        }

        const raw = heroGeometry(W, boxes);
        const bad = check(W, H, raw);
        const g = clampToFit(W, H, raw);

        /*
         * Handed back to the stylesheet: the floating groups are positioned by
         * CSS, but the tagline and the slide dots have to keep clear of the
         * notches, and only this knows how deep they are.
         */
        card.style.setProperty('--notch-start-h', (g.notches.start?.nh ?? 0) + 'px');
        card.style.setProperty('--notch-start-w', (g.notches.start?.nw ?? 0) + 'px');
        card.style.setProperty('--notch-end-h', (g.notches.end?.nh ?? 0) + 'px');
        card.style.setProperty('--notch-bottom-h', (g.notches.bottom?.nh ?? 0) + 'px');
        card.style.setProperty('--notch-bottom-w', (g.notches.bottom?.nw ?? 0) + 'px');
        card.style.setProperty('--notch-gap', (g.notches.start?.gap ?? 0) + 'px');
        card.style.setProperty('--R', g.R + 'px');

        /*
         * Said out loud, because these arrive after the controller has already
         * laid the slide out. hero.js holds the nudged elements off the notch,
         * off the button in it and inside the rounded corner -- all three of
         * which are the numbers just written above, so anything measured
         * before this fired was measured against a card with no notches.
         */
        card.dispatchEvent(new CustomEvent('hero:notches', { bubbles: false }));

        report(W, H, raw, bad);

        if (!SUPPORTS_PATH) {
            /* No path() here: the layers keep their border-radius and the
               floating groups keep the paper pill they render with. */
            return;
        }

        const d = heroPath(W, H, g, isRtl());

        for (const el of clipped) {
            el.style.webkitClipPath = 'path("' + d + '")';
            el.style.clipPath = 'path("' + d + '")';
        }

        /* There are real holes now, so the groups can drop their stand-in
           background and sit directly on the page behind the card. */
        card.classList.add('is-notched');
    }

    /*
     * A violation is a design question -- which proportion gives -- not
     * something to paper over, so it is printed once per distinct state with
     * the numbers, and left on the element for a test to read.
     */
    function report(W, H, raw, bad) {
        const key = bad.map((b) => b.where + ':' + b.rule + ':' + b.left + '/' + b.right).join('|');

        if (key === reported) {
            return;
        }

        reported = key;

        if (bad.length === 0) {
            card.removeAttribute('data-hero-geometry-violation');

            return;
        }

        card.setAttribute('data-hero-geometry-violation', String(bad.length));
        const curves = CORNERS
            .map((c) => (raw.notches[c] === null ? null : c + '=' + raw.notches[c].nr))
            .filter(Boolean).join(' ');

        console.warn(
            'Hero notches: ' + bad.length + ' constraint(s) cannot hold at ' +
            Math.round(W) + '×' + Math.round(H) + ' (S=' + raw.S + ', R=' + raw.R +
            ', curve ' + curves + '). Radii were reduced to keep ' +
            'the outline from folding; the proportions themselves have not been changed.',
            bad
        );
    }

    if (window.ResizeObserver) {
        /* The card and the three groups: the notches are sized from the groups,
           and the groups are laid out by CSS, so both ends have to be watched. */
        const observer = new ResizeObserver(() => layout());

        observer.observe(card);

        for (const corner of CORNERS) {
            if (floats[corner] !== null) {
                observer.observe(floats[corner]);
            }
        }
    } else {
        window.addEventListener('resize', layout);
    }

    layout();

    /* Cairo arrives after first paint and the links change width when it does. */
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layout);
    }

    /* ------------------------------------------------------- collapsed menu */

    /*
     * Below the width where both top notches fit side by side, the links fold
     * into a panel behind one button and the end notch keeps the cart and the
     * account. No link is dropped -- the button opens the same three anchors.
     *
     * The stylesheet decides when that happens; this only opens and closes it,
     * and reads back whether the button is on screen at all so a click cannot
     * leave aria-expanded stale on a wide window.
     */
    const menuButton = card.querySelector('[data-hero-menu-button]');
    const menu = card.querySelector('[data-hero-menu]');

    if (menuButton !== null && menu !== null) {
        const setOpen = (open) => {
            menuButton.setAttribute('aria-expanded', String(open));
            card.classList.toggle('is-menu-open', open);
        };

        menuButton.addEventListener('click', () => {
            setOpen(menuButton.getAttribute('aria-expanded') !== 'true');
        });

        menu.addEventListener('click', (event) => {
            if (event.target.closest('a') !== null) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
                setOpen(false);
                menuButton.focus();
            }
        });

        document.addEventListener('pointerdown', (event) => {
            if (menuButton.getAttribute('aria-expanded') === 'true'
                && event.target.closest('.hero__notch--start') === null) {
                setOpen(false);
            }
        });

        /* Widening the window past the fold-away point hides the button; the
           panel must not stay latched open behind it. */
        window.addEventListener('resize', () => {
            if (getComputedStyle(menuButton).display === 'none') {
                setOpen(false);
            }
        });
    }
}
