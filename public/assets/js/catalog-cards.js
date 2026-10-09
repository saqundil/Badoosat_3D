/*
 * The notch under a product card's price.
 *
 * The photo is a rounded rectangle with a piece cut out of its bottom
 * inline-end corner, and the price pill sits flush into that cut, touching
 * both outer edges of the tile. The cut cannot be done with border-radius.
 *
 * The curves are not all one radius: the outer corners and the fillet at the
 * tile's side share R, while the fillet at the bottom edge and the notch's
 * own inner corner are their own size.
 *
 * Every length -- the pill included -- is a fraction of the tile's own
 * measured width, so a card in a four-column desktop grid and the same card
 * two-up on a phone are the same drawing at two sizes. Nothing is measured
 * off the rendered price: the pill is a fixed box that the price is fitted
 * into, not a box that the price inflates. That is what makes two cards side
 * by side carry identical badges and identical notches however far apart
 * "19 د.أ" and "12,345 د.أ" are in length.
 *
 * None of these numbers live in the stylesheet. CSS reads them back out of
 * the element; it does not set them.
 *
 * The pill is NOT inside the clipped element -- it lives in the region the
 * clip removes, so clipping its own container would erase it. catalog.php
 * keeps it a sibling of .product-card__media for that reason.
 */

/* path() takes absolute pixels, so there is no CSS-only version of this. */
export const SUPPORTS_PATH = !!(window.CSS && CSS.supports &&
    (CSS.supports('clip-path', 'path("M 0 0 Z")') ||
     CSS.supports('-webkit-clip-path', 'path("M 0 0 Z")')));

/**
 * Never shrink the price past this, however long it is.
 *
 * A price is the one thing on a tile that has to be readable at a glance, and
 * 8px was not: on a narrow two-up phone grid the badge was legible as a shape
 * and illegible as a number. The pill keeps its fixed size -- it is still
 * 0.32 x 0.155 of the tile and still never widens for its contents -- so what
 * gives instead is the band of empty space inside it.
 */
const MIN_FONT_PX = 12;

/**
 * The notch's proportions, as fractions of the shape's own width.
 *
 * These are the drawing, not a card setting: anything that cuts this notch --
 * the product tile here, the hero in hero-notches.js -- reads the same five
 * numbers, so the two shapes stay the same shape at two very different sizes.
 * Lifted out of geometry() so there is one copy of them rather than two.
 */
export const NOTCH = {
    R: 0.115,           // outer corners, and the fillet at the shape's side
    fillet: 0.075,      // fillet at the top/bottom edge, and the inner corner
    gap: 0.022,         // breathing room on the notch's two inner sides
    gapMin: 6,          // ...which never falls below this many pixels
};

/**
 * Every length the shape needs, derived from the tile's measured width.
 *
 * The clamps are not defensive padding. Without them a narrow column drives
 * the notch wider than the side it is cut into, the arcs begin overlapping
 * one another, and the outline folds inside out instead of degrading.
 */
export function geometry(w, h) {
    const S = w;

    /* The pill: a fixed box, sized from the tile and nothing else. */
    const badgeW = Math.round(S * 0.32);
    const badgeH = Math.round(S * 0.155);
    const badgeR = Math.round(badgeH / 2);          // a full capsule
    /* The only length here with a floor: below it the number stops being one. */
    const fontPx = Math.max(MIN_FONT_PX, Math.round(S * 0.055));

    const gap = Math.max(NOTCH.gapMin, Math.round(S * NOTCH.gap));

    /*
     * Four corners, three sizes. The outer corners and the fillet where the
     * notch runs back out to the tile's side share one radius; the fillet at
     * the bottom edge and the notch's own inner corner share the other.
     */
    let R = Math.round(S * NOTCH.R);                // outer corners
    let nfSide = R;                                 // fillet at the side edge
    let nfBottom = Math.round(S * NOTCH.fillet);    // fillet at the bottom edge
    let nr = nfBottom;                              // the notch's inner corner

    /*
     * The gap is added on the two inner sides and nowhere else. The pill is
     * flush into the corner -- its bottom edge on the tile's bottom edge, its
     * side on the tile's side -- so a band below it or outside it would be a
     * band running off the tile.
     */
    let nw = badgeW + gap;      // the inner side edge
    let nh = badgeH + gap;      // the inner top edge

    R = Math.max(0, Math.min(R, w / 2, h / 2));

    /* The notch has to fit the tile before anything is rounded off it. */
    nw = Math.max(0, Math.min(nw, w - R));
    nh = Math.max(0, Math.min(nh, h - R));

    /*
     * Each fillet is bounded by the tile edge it sits on, independently --
     * they are two different radii now and no longer move together.
     */
    nfSide = Math.max(0, Math.min(nfSide, h - nh - R));
    nfBottom = Math.max(0, Math.min(nfBottom, w - R - nw));

    /*
     * The inner corner shares the notch's two edges with the fillets: the
     * side edge carries nr and nfBottom between them, the top edge carries
     * nr and nfSide. Both have to fit or the outline folds through itself.
     *
     * Neither binds at the current factors -- the side edge carries
     * 0.15*S against an nh of 0.177*S -- so this is a safety net rather
     * than something the shape runs into. It is what stops a future change
     * to any of these numbers from folding the outline instead of showing
     * up as a corner that quietly went flat.
     */
    nr = Math.max(0, Math.min(nr, nh - nfBottom, nw - nfSide));

    return { R, gap, nfBottom, nfSide, nw, nh, nr, badgeW, badgeH, badgeR, fontPx };
}

/**
 * Clockwise outline of the tile: a rounded rectangle with a notch carved out
 * of the bottom-right corner (bottom-left when `rtl`).
 *
 * The sweep-flag of each notch arc is fixed by where its centre has to sit
 * for the tangents to match the straight segments on either side:
 *
 *   fillet at the inline edge : centre (w-nf, h-nh-nf)    -> E to S -> 1
 *   inner corner of the notch : centre (w-nw+nr, h-nh+nr) -> N to W -> 0
 *   fillet at the bottom edge : centre (w-nw-nf, h-nf)    -> E to S -> 1
 *
 * Flip any of them and the arc snaps to the opposite centre, which breaks
 * both tangents and leaves a hard 90-degree step where a curve should be.
 *
 * Mirroring for RTL is x -> w - x. That reverses the direction the outline is
 * traversed in, so every sweep-flag has to be inverted with it or all eight
 * arcs bulge the wrong way.
 */
export function mediaPath(w, h, rtl = false) {
    const { R, nw, nh, nr, nfBottom, nfSide } = geometry(w, h);

    return notchOutline(w, h, R, nw, nh, nr, nfBottom, nfSide, rtl);
}

/**
 * The outline itself, taking each radius as its own argument.
 *
 * nfBottom and nfSide are separate parameters, not one `nf` used twice: the
 * two fillets are the same kind of curve on different edges, and they are no
 * longer the same size.
 */
export function notchOutline(w, h, R, nw, nh, nr, nfBottom, nfSide, rtl = false) {
    const n = (v) => Math.round(v * 100) / 100;
    const X = (x) => n(rtl ? w - x : x);      // mirror the x axis
    const S = (f) => (rtl ? 1 - f : f);       // ...and every sweep with it
    const Y = n;

    return [
        'M', X(R) + ',0',
        'H', X(w - R),
        'A', n(R) + ',' + n(R), '0 0 ' + S(1), X(w) + ',' + Y(R),
        'V', Y(h - nh - nfSide),
        'A', n(nfSide) + ',' + n(nfSide), '0 0 ' + S(1), X(w - nfSide) + ',' + Y(h - nh),
        'H', X(w - nw + nr),
        'A', n(nr) + ',' + n(nr), '0 0 ' + S(0), X(w - nw) + ',' + Y(h - nh + nr),
        'V', Y(h - nfBottom),
        'A', n(nfBottom) + ',' + n(nfBottom), '0 0 ' + S(1), X(w - nw - nfBottom) + ',' + Y(h),
        'H', X(R),
        'A', n(R) + ',' + n(R), '0 0 ' + S(1), X(0) + ',' + Y(h - R),
        'V', Y(R),
        'A', n(R) + ',' + n(R), '0 0 ' + S(1), X(R) + ',0',
        'Z',
    ].join(' ');
}

/**
 * The same tile with no notch in it: four plain corners, all of them R.
 *
 * This is what a notch of no width and no height means, and it is a function of
 * its own because notchOutline() cannot say it. Feeding that one zeroes leaves
 * the outline running into the corner and back out along the bottom edge with
 * nothing rounding it -- a square corner where the cut would have been, which
 * is the one corner that has to match the other three.
 */
export function plainOutline(w, h, R, rtl = false) {
    const n = (v) => Math.round(v * 100) / 100;
    const X = (x) => n(rtl ? w - x : x);
    const S = (f) => (rtl ? 1 - f : f);
    const r = Math.max(0, Math.min(R, w / 2, h / 2));

    return [
        'M', X(r) + ',0',
        'H', X(w - r),
        'A', n(r) + ',' + n(r), '0 0 ' + S(1), X(w) + ',' + n(r),
        'V', n(h - r),
        'A', n(r) + ',' + n(r), '0 0 ' + S(1), X(w - r) + ',' + n(h),
        'H', X(r),
        'A', n(r) + ',' + n(r), '0 0 ' + S(1), X(0) + ',' + n(h - r),
        'V', n(r),
        'A', n(r) + ',' + n(r), '0 0 ' + S(1), X(r) + ',0',
        'Z',
    ].join(' ');
}

/**
 * Fits the price inside the fixed pill by type size alone.
 *
 * The size comes from the tile, not the pill, so the type is a constant on
 * every card the way the box is. This only steps in when a price is long
 * enough to overrun the box at that size: it is set smaller rather than the
 * box being widened or the text cut. Font metrics scale close enough to
 * linearly that measuring once and dividing lands within a pixel; the loop
 * after it is there for the rounding, and runs once or twice at most.
 */
export function fitPrice(value, badgeW, fontPx) {
    const room = badgeW - 2 * Math.max(4, Math.round(badgeW * 0.08));
    const base = Math.max(MIN_FONT_PX, fontPx);

    value.style.fontSize = `${base}px`;

    const natural = value.scrollWidth;

    if (natural <= room || natural === 0) {
        return;
    }

    let size = Math.max(MIN_FONT_PX, Math.floor(base * (room / natural) * 10) / 10);
    value.style.fontSize = `${size}px`;

    while (value.scrollWidth > room && size > MIN_FONT_PX) {
        size = Math.max(MIN_FONT_PX, size - 0.5);
        value.style.fontSize = `${size}px`;
    }
}

export const isRtl = () => (document.documentElement.dir || '').toLowerCase() === 'rtl';

function layout(wrap) {
    const shape = wrap.querySelector('.product-card__media');
    const badge = wrap.querySelector('.product-card__price');

    if (shape === null) {
        return;
    }

    const w = shape.clientWidth;
    const h = shape.clientHeight;

    if (w === 0 || h === 0) {
        return;
    }

    const { R, badgeW, badgeH, badgeR, fontPx } = geometry(w, h);

    /*
     * The design-it-yourself card, and any product whose price has not been
     * entered, have a tile and no pill. The corner is then an ordinary one:
     * a notch of no width and no height, which leaves R where the cut would
     * have been. An empty notch would be a hole bitten out of the tile for
     * something that is not there.
     */
    if (badge === null) {
        shape.style.setProperty('--R', `${R}px`);

        if (SUPPORTS_PATH) {
            const plain = plainOutline(w, h, R, isRtl());

            shape.style.webkitClipPath = `path("${plain}")`;
            shape.style.clipPath = `path("${plain}")`;
        }

        return;
    }

    /*
     * Handed back to the stylesheet. The pill is a box of exactly this size
     * whatever it contains, and a browser without clip-path: path() rounds
     * the tile by R. All of it follows the measured width, so none of it can
     * be written down in CSS.
     */
    badge.style.setProperty('--badge-w', `${badgeW}px`);
    badge.style.setProperty('--badge-h', `${badgeH}px`);
    badge.style.setProperty('--badge-r', `${badgeR}px`);
    shape.style.setProperty('--R', `${R}px`);

    const value = badge.querySelector('.product-card__price-value');

    if (value !== null) {
        fitPrice(value, badgeW, fontPx);
    }

    if (!SUPPORTS_PATH) {
        return;                       // .product-card__media keeps its border-radius
    }

    const d = mediaPath(w, h, isRtl());

    shape.style.webkitClipPath = `path("${d}")`;
    shape.style.clipPath = `path("${d}")`;
}

const wraps = Array.from(document.querySelectorAll('.product-card__media-wrap'));

if (wraps.length > 0) {
    const layoutAll = () => wraps.forEach(layout);

    /*
     * Only the tile is watched. The pill is an output of the tile's width
     * now, so observing it would just feed this function its own last result.
     */
    if (window.ResizeObserver) {
        const observer = new ResizeObserver((entries) => {
            entries.forEach((entry) => {
                const wrap = entry.target.closest('.product-card__media-wrap');

                if (wrap !== null) {
                    layout(wrap);
                }
            });
        });

        wraps.forEach((wrap) => observer.observe(wrap));
    } else {
        window.addEventListener('resize', layoutAll);
    }

    layoutAll();

    /* Cairo arrives after first paint and the price re-measures when it does. */
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layoutAll);
    }
}
