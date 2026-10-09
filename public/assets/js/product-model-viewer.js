/*
 * The 3D slide.
 *
 * It is a slide in the photo track, not a tab beside it, so it has to behave
 * like one: nothing is built until the slide is actually on screen, and the
 * draw loop stops the moment it leaves.
 *
 * "Nothing" includes three.js. The renderer, the loader and the model are all
 * behind an import() that the IntersectionObserver fires -- a static import
 * would pull a few hundred kilobytes of library into the page for every
 * visitor who only ever looked at the photos, which is most of them.
 *
 * There are no OrbitControls here on purpose. Orbiting hands the customer
 * zoom, tilt and a way to end up underneath the shirt looking at its inside;
 * what is wanted is the one motion a person makes with a garment on a hanger.
 * So the handle drives pivot.rotation.y and nothing else -- the camera never
 * moves, and there is no state to reset.
 */

const slide = document.querySelector('[data-model-slide]');
const configElement = document.getElementById('product-model-config');

if (slide && configElement) {
    const config = JSON.parse(configElement.textContent);
    const canvasHost = slide.querySelector('[data-model-canvas]');
    const status = slide.querySelector('[data-model-status]');
    const handle = slide.querySelector('[data-model-spin]');
    const form = document.getElementById('add-form');

    /** Radians of turn per pixel dragged. A slide's width is a bit over a turn. */
    const PER_PIXEL = 0.011;

    let lib = null;
    let session = null;
    let revision = 0;
    let designRevision = 0;
    /*
     * The product's own artwork, or nothing. There is no selection to track:
     * the picker that used to swap designs on the shirt is gone, so this is
     * read once and drawn once.
     */
    const artwork = config.artwork ?? null;
    let angle = 0;
    let visible = false;
    let touched = false;

    /** The library, fetched once, on the first visit to this slide. */
    async function load() {
        if (lib === null) {
            const [core, hero] = await Promise.all([
                import('./model-core.js'),
                import('./hero3d.js'),
            ]);

            lib = { ...core, drawDesignLayers: hero.drawDesignLayers };
        }

        return lib;
    }

    /* ------------------------------------------------- build and tear down */

    async function start() {
        if (session || document.hidden) return;

        const ticket = ++revision;
        setStatus('loading', 'جارٍ تحميل العرض ثلاثي الأبعاد...');

        let core;

        try {
            core = await load();
        } catch (error) {
            setStatus('error', 'تعذّر تحميل العرض ثلاثي الأبعاد.');

            return;
        }

        if (ticket !== revision) return;

        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', 'معاينة المنتج ثلاثية الأبعاد');
        canvasHost.replaceChildren(canvas);

        let state;

        try {
            state = core.createScene(canvas);
        } catch (error) {
            canvasHost.replaceChildren();
            setStatus('error', 'العرض ثلاثي الأبعاد غير متاح على هذا الجهاز.');

            return;
        }

        const observer = new ResizeObserver(() => {
            core.resize();
            render();
        });
        observer.observe(canvasHost);
        core.resize();

        session = { state, observer, frame: 0, ready: false };
        const started = performance.now();

        try {
            await core.loadModel(config.modelUrl);

            if (ticket !== revision || !session) return;

            core.setColor(currentColor());
            session.state.pivot.rotation.y = angle;

            session.ready = true;
            await applyDesign();
            if (ticket !== revision || !session) return;

            setStatus('ready', 'العرض ثلاثي الأبعاد جاهز.');
            render();
            hint();
            document.dispatchEvent(new CustomEvent('product-model:ready', {
                detail: { loadMs: performance.now() - started },
            }));
        } catch (error) {
            if (error?.name === 'AbortError' || ticket !== revision) return;

            stop(false);
            setStatus('error', 'تعذّر تحميل العرض ثلاثي الأبعاد.');
        }
    }

    function stop(clearStatus = true) {
        revision++;
        designRevision++;

        if (session) {
            cancelAnimationFrame(session.frame);
            session.observer.disconnect();
            session = null;
            lib?.dispose();
        }

        canvasHost.replaceChildren();

        if (clearStatus && status) status.hidden = true;
    }

    /*
     * On demand rather than every frame: nothing in this scene animates by
     * itself, so a continuous loop would burn a phone's battery redrawing an
     * identical picture sixty times a second. A frame is asked for when
     * something changes it -- a drag, a colour, a design, a resize.
     */
    function render() {
        if (!session?.ready) return;

        cancelAnimationFrame(session.frame);
        session.frame = requestAnimationFrame(() => {
            if (!session?.ready) return;

            session.state.renderer.render(session.state.scene, session.state.camera);
        });
    }

    /* ------------------------------------------------------ the lazy start */

    if (window.IntersectionObserver) {
        new IntersectionObserver((entries) => {
            for (const entry of entries) {
                visible = entry.isIntersecting;

                if (visible) {
                    start();
                } else {
                    stop();
                }
            }
        }, { threshold: 0.5 }).observe(slide);
    } else {
        visible = true;
        start();
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else if (visible) {
            start();
        }
    });

    /* ----------------------------------------------------------- the spin */

    function turn(radians) {
        angle += radians;

        if (session?.ready) {
            session.state.pivot.rotation.y = angle;
            render();
        }
    }

    function settle() {
        if (touched) return;

        touched = true;
        handle?.classList.remove('is-hinting');
    }

    /** One pulse, the first time the slide is ever reached, and never again. */
    function hint() {
        if (touched || !handle) return;

        handle.classList.add('is-hinting');
    }

    if (handle) {
        let dragging = null;

        handle.addEventListener('pointerdown', (event) => {
            dragging = { id: event.pointerId, x: event.clientX };
            handle.setPointerCapture(event.pointerId);
            settle();
            event.preventDefault();
        });

        handle.addEventListener('pointermove', (event) => {
            if (!dragging || dragging.id !== event.pointerId) return;

            const dx = event.clientX - dragging.x;
            dragging.x = event.clientX;
            turn(dx * PER_PIXEL);
        });

        for (const name of ['pointerup', 'pointercancel']) {
            handle.addEventListener(name, (event) => {
                if (dragging?.id === event.pointerId) {
                    handle.releasePointerCapture(event.pointerId);
                    dragging = null;
                }
            });
        }

        /* The track's own arrow keys stop at this slide so these can have them. */
        handle.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

            settle();
            turn(event.key === 'ArrowLeft' ? -0.35 : 0.35);
            event.preventDefault();
        });
    }

    /* ------------------------------------ what the form says it looks like */

    function currentColor() {
        const selected = form?.querySelector('input[name="color_id"]:checked');

        return config.colors[selected?.value] || '#ffffff';
    }

    function setStatus(state, message) {
        if (!status) return;

        status.dataset.state = state;
        status.textContent = message;
        status.hidden = state === 'ready';
    }

    form?.addEventListener('change', (event) => {
        if (event.target.name === 'color_id' && session?.ready) {
            lib.setColor(currentColor());
            render();
        }
    });

    /*
     * Draws the product's artwork onto the shirt, once, at the position the
     * administrator saved.
     *
     * drawDesignLayers() takes a list, and one artwork is a list of one -- the
     * same call the admin editor makes, which is what guarantees the customer
     * sees the placement the shop set rather than a second implementation of
     * it that drifts.
     */
    async function applyDesign() {
        const ticket = ++designRevision;

        if (!session?.ready || artwork === null) {
            if (session?.ready) {
                lib.setArtwork(null);
                render();
            }

            return;
        }

        try {
            const image = await loadImage(artwork.path);

            if (!session?.ready || ticket !== designRevision) return;

            lib.setArtwork(lib.drawDesignLayers([{
                image,
                side: artwork.side === 'back' ? 'back' : 'front',
                x: Number(artwork.x ?? 50),
                y: Number(artwork.y ?? 50),
                scale: Number(artwork.scale ?? 100),
            }], {
                printArea: config.garment.print_area,
                uvScaleCm: config.garment.uv_scale_cm,
                canvasSize: config.garment.canvas_px,
            }));
            render();
        } catch (error) {
            if (!session?.ready || ticket !== designRevision) return;

            lib.setArtwork(null);
            render();
            console.warn('Product model artwork was not applied.', error);
        }
    }
}

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Could not load ' + src));
        image.src = src;
    });
}
