/**
 * The editor's preview, from inside the frame.
 *
 * The frame owns the hero: it builds the component, applies whatever draft the
 * editor sends it, and handles the dragging -- because the elements being
 * dragged are in this document and a pointer event does not cross a frame
 * boundary.
 *
 * The editor owns the form. The two talk over postMessage:
 *
 *   in    { type: 'slide',  slide, parallax }   render this draft
 *   in    { type: 'layout', layout }            replace the nudges (reset)
 *   out   { type: 'ready' }                     the component is up
 *   out   { type: 'layout', layout }            a drag finished, here is the
 *                                               clamped result to store
 *
 * Nothing is trusted from the message beyond its shape: both ends are the same
 * origin and the same application, and the draft is the administrator's own
 * form, which the server validates again on save.
 */

import { clampNudge, initHero } from './hero.js';

const root = document.querySelector('[data-hero]');
const card = document.querySelector('[data-hero-card]');
const surface = document.querySelector('.hero__surface');

const DRAGGABLE = {
    tab: '[data-hero-tab]',
    wordmark: '[data-hero-wordmark]',
    tagline: '[data-hero-tagline]',
    stats: '[data-hero-stats]',
};

const MAX_NUDGE = 45;

const hero = initHero(root, { autoplay: false, parallax: false, enable3d: true });

let layout = {};

function markHandles() {
    for (const [name, selector] of Object.entries(DRAGGABLE)) {
        const el = card.querySelector(selector);

        if (el) {
            el.classList.add('is-handle');
            el.dataset.element = name;
        }
    }
}

function post(message) {
    parent.postMessage(message, window.location.origin);
}

/* ------------------------------------------------------------- the editor */

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.source !== parent) {
        return;
    }

    const message = event.data;

    if (message === null || typeof message !== 'object') {
        return;
    }

    /*
     * The editor is about to replace this document. Give the WebGL context
     * back now, while this document is still running, rather than leaving it
     * to pagehide -- which fires late enough that the incoming document can be
     * asking for a context while this one still holds it, and a browser hands
     * out only so many.
     */
    if (message.type === 'release') {
        hero.destroy();
        post({ type: 'released' });

        return;
    }

    if (message.type === 'slide') {
        layout = message.slide?.layout ?? {};
        hero.setParallax(Boolean(message.parallax));
        hero.setSlides([message.slide]);
        markHandles();
    }
});

/* ---------------------------------------------------------------- dragging */

let drag = null;

card.addEventListener('pointerdown', (event) => {
    const el = event.target.closest('.is-handle');

    if (!el) {
        return;
    }

    event.preventDefault();
    el.setPointerCapture(event.pointerId);

    const name = el.dataset.element;

    drag = {
        el,
        name,
        box: card.getBoundingClientRect(),
        startX: event.clientX,
        startY: event.clientY,
        fromX: layout[name]?.dx ?? 0,
        fromY: layout[name]?.dy ?? 0,
    };

    el.classList.add('is-dragging');
    /* Nothing should move under the element being placed. */
    hero.setParallax(false);
});

card.addEventListener('pointermove', (event) => {
    if (!drag) {
        return;
    }

    /*
     * Percent of the card, from screen pixels over the card's screen box. The
     * frame is drawn scaled down, so both numbers are scaled by the same
     * factor and the percentage comes out right without knowing the scale.
     */
    const dx = clamp(drag.fromX + ((event.clientX - drag.startX) / drag.box.width) * 100);
    const dy = clamp(drag.fromY + ((event.clientY - drag.startY) / drag.box.height) * 100);

    /* The same clamp the site applies when it reads the value back, so the
       element stops at the edge under the pointer rather than following it out
       and snapping back on the next render. What is stored is what was shown. */
    const safe = clampNudge(card, surface, drag.el, { dx: round(dx), dy: round(dy) });

    layout[drag.name] = safe;
    drag.el.style.setProperty('--dx', String(safe.dx));
    drag.el.style.setProperty('--dy', String(safe.dy));
});

function endDrag() {
    if (!drag) {
        return;
    }

    drag.el.classList.remove('is-dragging');

    if (layout[drag.name].dx === 0 && layout[drag.name].dy === 0) {
        delete layout[drag.name];
    }

    drag = null;
    post({ type: 'layout', layout });
}

card.addEventListener('pointerup', endDrag);
card.addEventListener('pointercancel', endDrag);

function clamp(value) {
    return Math.min(MAX_NUDGE, Math.max(-MAX_NUDGE, value));
}

function round(value) {
    return Math.round(value * 100) / 100;
}

markHandles();
post({ type: 'ready' });
