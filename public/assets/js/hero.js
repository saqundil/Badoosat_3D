/**
 * Hero controller. Holds no three.js: the WebGL half lives in hero3d.js and is
 * pulled in with a dynamic import, and only on pointers that can hover.
 *
 * Layers move as whole layers, never per element, so a slide change is a text
 * swap and nothing re-lays-out.
 */

const LERP = 0.075;
const AUTOPLAY_MS = 7000;
const TILT_DEG = 6;

/** Travel across the full width of the card, as a percentage of its box. */
const SHIFT = { bg: 2, back: 5, front: 8 };

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function prefersReducedMotion() {
    return reducedMotion.matches;
}

/** Coarse pointers and narrow screens keep the poster and skip WebGL entirely. */
export function supports3d() {
    return !window.matchMedia('(pointer: coarse)').matches && window.innerWidth >= 768;
}

/** Breathing room between a nudged element and the edge it is held off. */
const EDGE_GAP = { ratio: 0.008, min: 6, max: 16 };

/**
 * Where an element sits before any nudge, in the card's own coordinates.
 *
 * offsetLeft/offsetTop, not getBoundingClientRect: the layers carry the
 * parallax transform and the element carries the nudge itself, and a measured
 * rect has both baked in. Offsets are the layout position, which is the thing
 * a nudge is a difference FROM.
 */
function basePosition(card, el) {
    let x = el.offsetLeft;
    let y = el.offsetTop;

    for (let parent = el.offsetParent; parent && parent !== card; parent = parent.offsetParent) {
        x += parent.offsetLeft;
        y += parent.offsetTop;
    }

    return { x, y, w: el.offsetWidth, h: el.offsetHeight };
}

/**
 * A nudge that cannot put its element outside the card.
 *
 * A nudge is a percentage of the card and a percentage knows nothing about the
 * size of the thing it is moving, so -24% of the card was free to carry a stat
 * card clean off the left edge on a phone and down into the notch the call to
 * action sits in on a desktop. Both happened; both are what this prevents.
 *
 * Two regions are out of bounds: everything outside the dark surface, and the
 * bottom notch -- a piece cut out of the card, so anything drawn over it is
 * drawn over a hole and across the button in it. The notch is only avoided on
 * the axis that matters: an element clear of it horizontally may still hang as
 * low as the card's own edge.
 *
 * An element with less room than it needs on an axis is left alone on that
 * axis. The wordmark is deliberately wider than the card -- it runs out from
 * behind the garment -- and clamping it to fit would be a different design,
 * not a safer one.
 */
export function clampNudge(card, surface, el, nudge) {
    const W = card.clientWidth;
    const H = card.clientHeight;

    if (W === 0 || H === 0 || el.offsetWidth === 0) {
        return nudge;
    }

    const base = basePosition(card, el);
    const area = surface === null ? { x: 0, y: 0, w: W, h: H } : basePosition(card, surface);
    const gap = Math.max(EDGE_GAP.min, Math.min(EDGE_GAP.max, Math.round(W * EDGE_GAP.ratio)));

    const read = (name) => parseFloat(card.style.getPropertyValue(name)) || 0;
    const rtl = getComputedStyle(card).direction === 'rtl';

    /* One axis at a time, x first: whether the notch is in the way at all
       depends on where the element ends up horizontally. */
    const limit = (from, size, low, high) => {
        const room = high - low - size;

        return room < 0 ? null : [low - from, high - size - from];
    };

    const apply = (value, span, total) => {
        if (span === null) {
            return value;
        }

        return Math.max(span[0] / total * 100, Math.min(span[1] / total * 100, value));
    };

    const dx = apply(nudge.dx, limit(base.x, base.w, area.x + gap, area.x + area.w - gap), W);

    /*
     * What the element has to stay above, once it is known where it sits.
     *
     * Two rectangles, and they are not the same rectangle: the notch is the
     * piece cut out of the card, and the call to action is the button floating
     * in it -- which the stylesheet centres on a narrow card instead of leaving
     * in the corner. Clearing the cut is not clearing the button there, so the
     * button is measured rather than assumed to be inside the notch.
     */
    const notchW = read('--notch-bottom-w');
    const notchH = read('--notch-bottom-h');
    const blockers = [];

    if (notchW > 0 && notchH > 0) {
        const x = rtl ? area.x + area.w - notchW : area.x;

        blockers.push({ x0: x, x1: x + notchW, top: area.y + area.h - notchH });
    }

    const button = card.querySelector('[data-hero-notch="bottom"]');

    if (button !== null && button.offsetWidth > 0) {
        const box = basePosition(card, button);

        blockers.push({ x0: box.x, x1: box.x + box.w, top: box.y });
    }

    /*
     * The tagline is the third thing the badges must not land on. It is only a
     * blocker for them: the tagline itself is free to sit where it likes, or
     * the two would each be waiting for the other to move.
     *
     * It bites only when the badges have actually been dragged across it --
     * left where the stylesheet puts them, the two never share a column.
     */
    if (el.matches('[data-hero-stats]')) {
        const line = card.querySelector('[data-hero-tagline]');

        if (line !== null && !line.hidden && line.offsetWidth > 0) {
            const box = basePosition(card, line);

            /*
             * Where the tagline actually IS, not where it would sit unmoved:
             * it carries a nudge of its own, and a blocker placed at its layout
             * position would be guarding empty card while the text itself sat
             * somewhere else. applyLayout settles the tagline before the badges
             * -- the order it walks the elements in -- so the value read here
             * is the clamped one that will be painted.
             */
            const ox = (parseFloat(line.style.getPropertyValue('--dx')) || 0) / 100 * W;
            const oy = (parseFloat(line.style.getPropertyValue('--dy')) || 0) / 100 * H;

            blockers.push({ x0: box.x + ox, x1: box.x + box.w + ox, top: box.y + oy });
        }
    }

    const left = base.x + (dx / 100) * W;
    const right = left + base.w;
    let floor = area.y + area.h - gap;

    for (const blocker of blockers) {
        if (left < blocker.x1 && right > blocker.x0) {
            floor = Math.min(floor, blocker.top - gap);
        }
    }

    /*
     * ...and the card is not a rectangle.
     *
     * The bottom corner away from the notch is a quarter of a circle of radius
     * R, and the front layer is NOT clipped by the path -- only the surface and
     * the stage are -- so an element parked in that corner is drawn on the page
     * beside the card rather than on the card. The arc is where it really ends:
     * at the element's outer edge, how far down the circle still reaches.
     */
    const R = read('--R');
    const outer = rtl ? left - area.x : area.x + area.w - right;

    if (R > 0 && outer < R) {
        const reach = Math.sqrt(Math.max(0, R * R - (R - outer) * (R - outer)));

        floor = Math.min(floor, area.y + area.h - R + reach - gap);
    }

    const dy = apply(nudge.dy, limit(base.y, base.h, area.y + gap, floor), H);

    return { dx: Math.round(dx * 100) / 100, dy: Math.round(dy * 100) / 100 };
}

export function initHero(root, options = {}) {
    const settings = {
        autoplay: true,
        enable3d: true,
        // The editor turns this off so nothing is offset while a value is being
        // dragged or typed.
        parallax: true,
        ...options,
    };

    const card = root.querySelector('[data-hero-card]') ?? root;
    const bg = root.querySelector('[data-hero-bg]');
    const tab = root.querySelector('[data-hero-tab]');
    const tabWord = root.querySelector('[data-hero-tab-word]');
    const wordmark = root.querySelector('[data-hero-wordmark]');
    const tagline = root.querySelector('[data-hero-tagline]');
    const statsBox = root.querySelector('[data-hero-stats]');
    const backLayer = root.querySelector('[data-hero-layer="back"]');
    const frontLayer = root.querySelector('[data-hero-layer="front"]');
    const stageEl = root.querySelector('[data-hero-stage]');
    const surface = root.querySelector('.hero__surface');
    const canvas = root.querySelector('[data-hero-canvas]');
    const dotsBox = root.querySelector('[data-hero-dots]');
    const cta = root.querySelector('[data-hero-cta]');
    const srText = root.querySelector('[data-hero-sr]');

    const dataEl = root.querySelector('[data-hero-data]');
    const config = dataEl ? JSON.parse(dataEl.textContent) : { slides: [], garment: null };

    let slides = config.slides ?? [];
    let index = 0;
    let stage = null;

    /*
     * The two gates the 3D half has always been behind, named so that a slide
     * change can respect them as well as the first paint does.
     *
     * allow3d is the device's answer, decided once: a coarse pointer or a
     * narrow window keeps the poster and never fetches the library. lazyReady
     * is the card's -- it turns true when the card first comes near the
     * viewport, so advancing to a slide with a model still cannot pull three.js
     * in before the hero has been scrolled to.
     */
    const allow3d = settings.enable3d && supports3d();
    let lazyReady = false;
    let unloading = false;

    /*
     * pagehide, not unload: it fires for a frame being replaced and it does not
     * cost the page its back/forward cache the way unload does.
     *
     * The context is given back here rather than left to the collector. A
     * browser allows only so many live WebGL contexts, and the editor's preview
     * reloads its frame every time the language is switched -- enough reloads
     * without a release and the next createScene() is handed no context at all,
     * which surfaces as a null capabilities object deep inside three.
     */
    window.addEventListener('pagehide', () => {
        unloading = true;
        stage?.dispose();
        stage = null;
    });
    let raf = 0;
    let timer = 0;
    let visible = false;

    const target = { x: 0, y: 0 };
    const current = { x: 0, y: 0 };
    let box = { width: 0, height: 0 };

    /* ---------------------------------------------------------------- render */

    function renderSlide(slide, animateColor) {
        if (!slide) {
            return;
        }

        /*
         * Both properties are cleared before either is set, whatever the new
         * slide turns out to be.
         *
         * Only one of them used to be written per slide -- a colour slide set
         * the colour, an image slide set the image -- so switching from a
         * colour to a picture left the colour underneath it, and any picture
         * with transparency in it, or any moment before it had loaded, showed
         * the previous slide's colour rather than the card's own.
         *
         * The dark ground lives on .hero__surface, so a transparent layer here
         * reveals the card, never the page.
         */
        bg.style.backgroundColor = 'transparent';
        bg.style.backgroundImage = 'none';

        if (typeof slide.background_value === 'string' && slide.background_value.startsWith('#')) {
            bg.style.backgroundColor = slide.background_value;
        } else {
            bg.style.backgroundImage = 'url(' + JSON.stringify(slide.background_value) + ')';
        }

        setText(wordmark, slide.wordmark);
        setText(tabWord, slide.tab_word);
        setText(tagline, slide.tagline);

        if (tab) {
            tab.hidden = !slide.tab_word;
        }

        renderStats(slide.stats ?? []);
        applyLayout(slide.layout ?? {});

        if (cta) {
            /*
             * In the card's bottom notch the button is the site's -- one label,
             * always there -- and only where it points is the slide's. The
             * editor's preview still renders the slide's own call to action,
             * label and all, which is what an administrator is editing.
             */
            if (cta.hasAttribute('data-hero-cta-fixed')) {
                cta.href = slide.cta_url || cta.href;
            } else {
                const label = (slide.cta_label ?? '').trim();
                cta.textContent = label;
                cta.href = slide.cta_url ?? '#';
                cta.hidden = label === '';
            }
        }

        if (srText) {
            srText.textContent = [
                slide.tab_word,
                slide.wordmark,
                slide.tagline,
                ...(slide.stats ?? []).map((s) => s.value + ' ' + s.label),
            ].filter(Boolean).join('. ');
        }

        showStage(slide);

        /* Arriving on a slide that does not drift returns the layers to centre
           even if the pointer never moves again. */
        if (!parallaxWanted(slide)) {
            target.x = 0;
            target.y = 0;
        }

        if (stage && slide.has_model !== false) {
            stage.setColor(slide.garment_color, animateColor && !prefersReducedMotion());
            stage.setDesignLayers(slide.design_layers ?? []);
        }
    }

    /*
     * A slide that has no model has no stage either.
     *
     * The card owns one canvas, shared by every slide, so this is a matter of
     * putting it away rather than tearing it down -- the WebGL context and the
     * model already on the GPU are wanted again the moment a slide that has one
     * comes round. What must not happen is the 3D half being ENTERED for a
     * slide with no model: load3d() is never called for one, so a deck whose
     * current slide shows no garment fetches no three.js at all.
     */
    function showStage(slide) {
        const wants = slide.has_model !== false;

        card.classList.toggle('hero__card--no-model', !wants);

        if (stageEl) {
            stageEl.hidden = !wants;
        }

        if (wants && !stage && allow3d && lazyReady) {
            load3d();
        }
    }

    /**
     * Nudges saved by the editor are a difference from the stylesheet, never an
     * absolute position, so a slide that was never dragged reads no properties
     * at all and the CSS stays in charge.
     */
    function applyLayout(layout) {
        const elements = { tab, wordmark, tagline, stats: statsBox };

        for (const [name, el] of Object.entries(elements)) {
            if (!el) {
                continue;
            }

            const nudge = layout[name];

            if (nudge) {
                /*
                 * Clamped here rather than only where it was dragged. A nudge
                 * is saved once and read at every width, so a position that
                 * was inside the card on the editor's panel can be outside it
                 * on a phone -- and every value stored before there was any
                 * clamp at all arrives here too.
                 */
                const safe = clampNudge(card, surface, el, nudge);

                el.style.setProperty('--dx', String(safe.dx));
                el.style.setProperty('--dy', String(safe.dy));
            } else {
                el.style.removeProperty('--dx');
                el.style.removeProperty('--dy');
            }
        }
    }

    function setText(el, value) {
        if (!el) {
            return;
        }

        // textContent, never innerHTML: whatever an administrator typed stays
        // text on the page.
        el.textContent = value ?? '';
        el.hidden = !value;
    }

    function renderStats(stats) {
        if (!statsBox) {
            return;
        }

        statsBox.replaceChildren(...stats.map((stat) => {
            const card = document.createElement('div');
            card.className = 'hero__stat';

            const value = document.createElement('span');
            value.className = 'hero__stat-value';
            value.textContent = stat.value ?? '';

            const label = document.createElement('span');
            label.className = 'hero__stat-label';
            label.textContent = stat.label ?? '';

            card.append(value, label);

            return card;
        }));

        statsBox.hidden = stats.length === 0;
    }

    function renderDots() {
        if (!dotsBox) {
            return;
        }

        dotsBox.replaceChildren(...slides.map((slide, i) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'hero__dot';
            dot.setAttribute('aria-label', 'Slide ' + (i + 1));
            dot.setAttribute('aria-current', String(i === index));
            dot.addEventListener('click', () => goTo(i));

            return dot;
        }));

        dotsBox.hidden = slides.length < 2;
    }

    function syncDots() {
        if (!dotsBox) {
            return;
        }

        [...dotsBox.children].forEach((dot, i) => dot.setAttribute('aria-current', String(i === index)));
    }

    function goTo(i, animateColor = true) {
        if (slides.length === 0) {
            return;
        }

        index = (i + slides.length) % slides.length;
        renderSlide(slides[index], animateColor);
        syncDots();
        restartAutoplay();
    }

    /* ---------------------------------------------------------------- motion */

    /*
     * Whether the slide on screen answers the pointer.
     *
     * Three things have to agree, and they are asked in order of authority: a
     * reduced-motion preference, which no column overrides; the caller's own
     * setting, which the editor suspends mid-drag; and the slide's own switch.
     */
    function parallaxWanted(slide = slides[index]) {
        return settings.parallax
            && !prefersReducedMotion()
            && slide?.parallax_enabled !== false;
    }

    /*
     * The listener stays attached whatever the answer is. Slides change under
     * it -- one may drift and the next may not -- and a listener removed for
     * one slide is a listener missing for the next.
     */
    function onPointerMove(event) {
        if (!parallaxWanted()) {
            /* Not a snap back to centre: the frame loop eases `current` toward
               this, so a slide with the motion off settles rather than jumps. */
            target.x = 0;
            target.y = 0;

            return;
        }

        target.x = event.clientX / window.innerWidth - 0.5;
        target.y = event.clientY / window.innerHeight - 0.5;
    }

    function frame() {
        raf = requestAnimationFrame(frame);

        const settled = Math.abs(target.x - current.x) < 0.0002
            && Math.abs(target.y - current.y) < 0.0002;

        if (settled && !stage) {
            return;
        }

        current.x += (target.x - current.x) * LERP;
        current.y += (target.y - current.y) * LERP;

        // box comes from the resize observer: measuring here would force a
        // layout on every frame and hold up the first paint.
        const shift = (amount, axis) =>
            (current[axis] * amount * (axis === 'x' ? box.width : box.height)) / 100;

        bg.style.transform = translate(-shift(SHIFT.bg, 'x'), -shift(SHIFT.bg, 'y'));
        backLayer.style.transform = translate(-shift(SHIFT.back, 'x'), -shift(SHIFT.back, 'y'));
        frontLayer.style.transform = translate(shift(SHIFT.front, 'x'), shift(SHIFT.front, 'y'));

        if (stage) {
            const tilt = (TILT_DEG * Math.PI) / 180;
            stage.setTilt(current.x * 2 * tilt, current.y * 2 * tilt * 0.55);
            stage.step();
            stage.render();
        }
    }

    function translate(x, y) {
        return 'translate3d(' + x.toFixed(2) + 'px, ' + y.toFixed(2) + 'px, 0)';
    }

    function start() {
        if (!raf) {
            raf = requestAnimationFrame(frame);
        }

        restartAutoplay();
    }

    function stop() {
        cancelAnimationFrame(raf);
        raf = 0;
        clearInterval(timer);
        timer = 0;
    }

    function restartAutoplay() {
        clearInterval(timer);
        timer = 0;

        if (settings.autoplay && visible && slides.length > 1 && !prefersReducedMotion()) {
            timer = setInterval(() => goTo(index + 1), AUTOPLAY_MS);
        }
    }

    function resize() {
        box = { width: card.clientWidth, height: card.clientHeight };

        if (stage && stageEl) {
            stage.resize(stageEl.clientWidth, stageEl.clientHeight);
        }

        /* The room an element has just changed, so what fits in it has too. */
        applyLayout(slides[index]?.layout ?? {});
    }

    /* ------------------------------------------------------------------- 3d */

    async function load3d() {
        const slide = slides[index];

        /*
         * The guard on has_model is the whole of the "no 3D request" promise:
         * import() lives past it, so a slide with no model never reaches the
         * library, never mind the .glb. model_file comes from the registry in
         * config/hero-models.php -- a key the administrator picked, resolved on
         * the server -- so there is no path here that a form could have typed.
         */
        if (!slide || !canvas || stage || slide.has_model === false) {
            return;
        }

        const modelPath = slide.model_file ?? slide.model_path;

        if (!modelPath) {
            return;
        }

        try {
            const { createStage } = await import('./hero3d.js');

            /* The slide may have moved on while the library was in flight. */
            if (slides[index]?.has_model === false) {
                return;
            }

            stage = await createStage(canvas, {
                modelPath,
                printArea: config.garment.print_area,
                uvScaleCm: config.garment.uv_scale_cm,
                canvasSize: config.garment.canvas_px,
            });

            resize();
            stage.setColor(slide.garment_color, false);
            await stage.setDesignLayers(slide.design_layers ?? []);
            stage.render();

            card.classList.add('is-live');
        } catch (error) {
            // The poster is already on screen and stays there.
            //
            // A document on its way out is not a failure. The editor's preview
            // reloads its frame to change language, and a model still in flight
            // when that happens throws either an AbortError -- model-core saying
            // it was disposed mid-load, which is it working correctly -- or a
            // WebGL error from a context that can no longer be created. Neither
            // is something anyone can act on, so neither is reported.
            /*
             * Retrying is not an option worth taking: the card has ONE canvas
             * element, and a canvas that has been refused a context is refused
             * it for good -- the retry would fail every time and say so twice.
             * The poster is already on screen and stays.
             */
            if (!unloading && error?.name !== 'AbortError') {
                console.error('Hero: 3D stage unavailable.', error);
            }
        }
    }

    /* ----------------------------------------------------------------- wire */

    const observer = new IntersectionObserver(
        ([entry]) => {
            visible = entry.isIntersecting;
            visible ? start() : stop();
        },
        { rootMargin: '200px' }
    );

    observer.observe(card);

    // The editor preview changes width without the window ever resizing.
    const sizeObserver = new ResizeObserver(resize);
    sizeObserver.observe(card);

    /* The notch depths the clamp reads are published by hero-notches.js, and
       not until after the first render. Re-clamp when they land. */
    card.addEventListener('hero:notches', () => applyLayout(slides[index]?.layout ?? {}));

    window.addEventListener('resize', resize);

    window.addEventListener('pointermove', onPointerMove, { passive: true });

    if (dotsBox) {
        dotsBox.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            const forward = getComputedStyle(root).direction === 'rtl'
                ? event.key === 'ArrowLeft'
                : event.key === 'ArrowRight';

            event.preventDefault();
            goTo(index + (forward ? 1 : -1));
            dotsBox.children[index]?.focus();
        });
    }

    renderDots();
    renderSlide(slides[index], false);
    resize();

    if (allow3d) {
        const begin = () => {
            const io = new IntersectionObserver(
                ([entry]) => {
                    if (entry.isIntersecting) {
                        io.disconnect();
                        /* From here a slide change may build the stage itself,
                           which is what a deck that opens on a slide with no
                           model needs in order to ever get one. */
                        lazyReady = true;
                        load3d();
                    }
                },
                { rootMargin: '300px' }
            );

            io.observe(card);
        };

        if (document.readyState === 'complete') {
            begin();
        } else {
            window.addEventListener('load', begin, { once: true });
        }
    }

    return {
        goTo,
        get index() {
            return index;
        },
        get stage() {
            return stage;
        },
        applyLayout,
        /*
         * For the editor: the preview honours the slide's own switch so an
         * administrator can see what the toggle does, and suspends the drift
         * while a handle is being dragged so the card is not moving under the
         * element being placed on it.
         */
        setParallax(on) {
            settings.parallax = Boolean(on);

            if (!settings.parallax) {
                target.x = 0;
                target.y = 0;
            }
        },
        setSlides(next, keepIndex = true) {
            slides = next;
            index = keepIndex ? Math.min(index, Math.max(slides.length - 1, 0)) : 0;
            renderDots();
            renderSlide(slides[index], false);
        },
        refresh(animateColor = false) {
            renderSlide(slides[index], animateColor);
        },
        destroy() {
            stop();
            observer.disconnect();
            sizeObserver.disconnect();
            window.removeEventListener('resize', resize);
            window.removeEventListener('pointermove', onPointerMove);
            stage?.dispose();
            stage = null;
        },
    };
}

const auto = document.querySelector('[data-hero][data-hero-auto]');

if (auto) {
    initHero(auto);
}
