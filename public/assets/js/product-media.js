/*
 * The product page's photo tile.
 *
 * It is the catalogue card's tile -- the same rounded rectangle with the same
 * piece cut out of its bottom inline-end corner, and the same price pill sat
 * flush into the cut. Not a second drawing of it: every length comes out of
 * geometry() in catalog-cards.js, and the outline out of mediaPath(), so the
 * two cannot drift apart. Change a factor there and both change together.
 *
 * S is this page's tile width rather than a card's, which is the whole of the
 * difference: a 560px column and a two-up phone grid are the same drawing at
 * two sizes, because nothing in it is an absolute number.
 *
 * Importing the module runs its top-level code, which looks for catalogue
 * cards and finds none on this page. That is the point -- one copy of the
 * geometry, wherever it is used.
 */

import { geometry, mediaPath, plainOutline, fitPrice, isRtl, SUPPORTS_PATH } from './catalog-cards.js';

const wrap = document.querySelector('[data-pdp-media]');
const shape = wrap === null ? null : wrap.querySelector('.pdp__media');
const badge = wrap === null ? null : wrap.querySelector('.pdp__price');

if (shape !== null && badge === null) {
    /*
     * A product whose price has not been entered renders no pill, so the tile
     * is an ordinary rounded rectangle: the notch is given no width and no
     * height, which leaves R where the cut would have been. The same move the
     * catalogue card makes, for the same reason -- a notch with nothing in it
     * is a bite out of the photograph for no one.
     */
    const plain = () => {
        const w = shape.clientWidth;
        const h = shape.clientHeight;

        if (w === 0 || h === 0) {
            return;
        }

        const { R } = geometry(w, h);

        shape.style.setProperty('--R', `${R}px`);

        if (SUPPORTS_PATH) {
            const d = plainOutline(w, h, R, isRtl());

            shape.style.webkitClipPath = `path("${d}")`;
            shape.style.clipPath = `path("${d}")`;
        }
    };

    if (window.ResizeObserver) {
        new ResizeObserver(plain).observe(shape);
    } else {
        window.addEventListener('resize', plain);
    }

    plain();
}

if (shape !== null && badge !== null) {
    const value = badge.querySelector('.pdp__price-value');

    /*
     * Kept so the price can be re-fitted on its own. product.js rewrites it
     * with the live total every time a colour, a size or the quantity
     * changes, and "12.00 د.أ" is not the width of "12 د.أ".
     */
    let lastBadgeW = 0;
    let lastFontPx = 0;

    function layout() {
        const w = shape.clientWidth;
        const h = shape.clientHeight;

        if (w === 0 || h === 0) {
            return;
        }

        const { R, badgeW, badgeH, badgeR, fontPx } = geometry(w, h);

        /*
         * Handed back to the stylesheet. The pill is a box of exactly this
         * size whatever it contains, and a browser without clip-path: path()
         * rounds the tile by R. All of it follows the measured width, so none
         * of it can be written down in CSS.
         */
        badge.style.setProperty('--badge-w', `${badgeW}px`);
        badge.style.setProperty('--badge-h', `${badgeH}px`);
        badge.style.setProperty('--badge-r', `${badgeR}px`);
        shape.style.setProperty('--R', `${R}px`);

        lastBadgeW = badgeW;
        lastFontPx = fontPx;

        if (value !== null) {
            fitPrice(value, badgeW, fontPx);
        }

        if (!SUPPORTS_PATH) {
            return;                       // .pdp__media keeps its border-radius
        }

        const d = mediaPath(w, h, isRtl());

        shape.style.webkitClipPath = `path("${d}")`;
        shape.style.clipPath = `path("${d}")`;
    }

    if (window.ResizeObserver) {
        new ResizeObserver(layout).observe(shape);
    } else {
        window.addEventListener('resize', layout);
    }

    layout();

    /* Cairo arrives after first paint and the price re-measures when it does. */
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layout);
    }

    /*
     * A new price landing in the pill is a text change, not a size change, so
     * the ResizeObserver above never hears about it. Only childList and
     * characterData are watched: fitPrice writes a style attribute, and
     * observing attributes too would feed this its own output.
     */
    if (value !== null && window.MutationObserver) {
        new MutationObserver(() => {
            if (lastBadgeW > 0) {
                fitPrice(value, lastBadgeW, lastFontPx);
            }
        }).observe(value, { childList: true, characterData: true, subtree: true });
    }
}
