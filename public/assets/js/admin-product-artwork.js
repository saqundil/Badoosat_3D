/*
 * The product editor's live preview, and the parts of the page that react to
 * it (public/admin/product-edit.php).
 *
 * One garment type, one piece of artwork. drawDesignLayers() still does the
 * drawing: it takes a list, and a single artwork is a list of one -- reusing it
 * keeps the admin preview and the hero painting pixels the same way, which is
 * the whole reason the artwork lands where the press will put it.
 *
 * three.js is fetched only where it may be: never under 768px or on a touch
 * screen. There the stage keeps its still picture, and everything else on the
 * page -- the colours, the counts, the photos, the sections -- works the same.
 *
 * No sentence lives here: every message arrives in a data-* attribute.
 */

const form = document.getElementById('product-form');
const configElement = document.getElementById('product-editor-config');
const stage = document.querySelector('[data-model-stage]');
const studio = document.getElementById('product-studio');

const lang = document.documentElement.lang || 'ar';
const numbers = new Intl.NumberFormat(lang + '-u-nu-latn');
const fill = (template, values) => Object.entries(values)
    .reduce((text, [key, value]) => text.replaceAll(':' + key, value), template ?? '');

/* The project rule: no three.js on phones or touch screens. */
const noWebGL = window.matchMedia('(max-width: 767px), (pointer: coarse)');

let three = null;
const loadThree = () => {
    three ??= Promise.all([
        import('three/addons/controls/OrbitControls.js'),
        import('./model-core.js'),
        import('./hero3d.js'),
    ]).then(([controls, core, hero]) => ({ OrbitControls: controls.OrbitControls, ...core, drawDesignLayers: hero.drawDesignLayers }));

    return three;
};

if (form && configElement && stage) {
    const config = JSON.parse(configElement.textContent);
    const typeSelect = form.querySelector('[data-garment-select]');
    const artworkInput = form.querySelector('[data-artwork-input]');
    const artworkRemove = form.querySelector('[data-artwork-remove]');
    const status = stage.querySelector('[data-model-status]');
    const poster = stage.querySelector('[data-model-poster]');
    const colourSwatches = document.querySelector('[data-model-colors]');
    const customization = form.querySelector('[data-customization]');
    const colorGrid = form.querySelector('[data-variant-colors]');
    const sizeLabel = form.querySelector('[data-type-sizes]');
    const variantCount = form.querySelector('[data-variant-count]');
    const placementInputs = Object.fromEntries(
        [...form.querySelectorAll('[data-placement]')].map((input) => [input.dataset.placement, input]),
    );
    const side = () => form.querySelector('[name="artwork_side"]:checked')?.value === 'back' ? 'back' : 'front';
    const previewHex = () => colourSwatches?.querySelector('input:checked')?.value ?? '#F4F0E8';

    let session = null;
    let revision = 0;
    let artworkRevision = 0;

    /* The artwork as it stands: the stored file, or one just chosen. */
    let artwork = config.artwork?.path ? { url: config.artwork.path, objectUrl: null } : null;

    const currentType = () => config.types[typeSelect.value] ?? null;
    const currentGeometry = () => {
        const type = currentType();

        return type?.geometry ? config.geometry[type.geometry] ?? null : null;
    };

    /* ------------------------------------------------------------- the scene */

    async function start() {
        stop();

        const type = currentType();
        const geometry = currentGeometry();

        if (customization) customization.disabled = !geometry?.customizable;

        // Phones keep the still picture; nothing below is fetched.
        if (noWebGL.matches) {
            poster.hidden = false;
            return;
        }

        if (typeof type?.model !== 'string' || type.model === '') {
            setStatus(stage.dataset.msgNoModel);
            return;
        }

        const ticket = ++revision;
        setStatus(stage.dataset.msgLoading);

        try {
            const lib = await loadThree();
            if (ticket !== revision) return;

            const canvas = document.createElement('canvas');
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', stage.dataset.msgCanvas);
            stage.prepend(canvas);

            const state = lib.createScene(canvas);
            const controls = new lib.OrbitControls(state.camera, canvas);
            controls.enableDamping = true;
            controls.dampingFactor = 0.08;
            controls.minDistance = 2;
            controls.maxDistance = 5;
            controls.minPolarAngle = Math.PI * 0.2;
            controls.maxPolarAngle = Math.PI * 0.8;
            controls.target.set(0, 0, 0);

            const observer = new ResizeObserver(() => lib.resize());
            observer.observe(stage);
            lib.resize();
            session = { lib, state, controls, observer, frame: 0, ready: false };

            await lib.loadModel(type.model);
            if (ticket !== revision || !session) return;

            lib.setColor(previewHex());
            session.ready = true;
            await syncArtwork();
            if (ticket !== revision || !session) return;

            poster.hidden = true;
            setStatus('');
            render();
        } catch (error) {
            if (error?.name === 'AbortError' || ticket !== revision) return;
            console.error('Product editor model failed to load.', error);
            stop();
            setStatus(stage.dataset.msgFailed);
        }
    }

    function stop() {
        revision++;
        artworkRevision++;

        if (session) {
            cancelAnimationFrame(session.frame);
            session.observer.disconnect();
            session.controls.dispose();
            session.lib.dispose();
            session = null;
        }

        stage.querySelector('canvas')?.remove();
        poster.hidden = false;
    }

    function render() {
        if (!session?.ready) return;
        session.controls.update();
        session.state.renderer.render(session.state.scene, session.state.camera);
        session.frame = requestAnimationFrame(render);
    }

    /* ---------------------------------------------------------- the artwork */

    async function syncArtwork() {
        const ticket = ++artworkRevision;
        const geometry = currentGeometry();
        const lib = session?.lib;

        /* Ticking "remove" clears the preview too, so what is on screen is what
           saving would produce -- not what is still in the database. */
        if (!session?.ready || artwork === null || artworkRemove?.checked) {
            if (session?.ready) lib.setArtwork(null);
            return;
        }

        if (!geometry?.print_area?.front) {
            lib.setArtwork(null);
            return;
        }

        try {
            const image = await loadImage(artwork.url);
            if (!session?.ready || ticket !== artworkRevision) return;

            lib.setArtwork(lib.drawDesignLayers([{
                image,
                side: side(),
                x: Number(placementInputs.x?.value ?? 50),
                y: Number(placementInputs.y?.value ?? 50),
                scale: Number(placementInputs.scale?.value ?? 100),
            }], {
                printArea: geometry.print_area,
                uvScaleCm: geometry.uv_scale_cm,
                canvasSize: geometry.canvas_px,
            }));
        } catch (error) {
            if (!session?.ready || ticket !== artworkRevision) return;
            lib.setArtwork(null);
            console.warn('Product editor artwork could not be applied.', error);
        }
    }

    function loadImage(url) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('image failed: ' + url));
            image.src = url;
        });
    }

    function setStatus(message) {
        status.textContent = message ?? '';
        status.hidden = !message;
    }

    function preset(name) {
        if (session?.ready) session.lib.cameraPreset(name);
        for (const button of document.querySelectorAll('.segmented [data-camera-preset]')) {
            button.setAttribute('aria-pressed', String(button.dataset.cameraPreset === name));
        }
    }

    /* --------------------------------------------- colours, sizes and counts */

    /*
     * Repainting the colour list when the type changes.
     *
     * A colour ticked under the old type that the new one is not cut in is
     * dropped rather than carried across -- the server refuses it anyway, and
     * a box that stays ticked while the save rejects it is a worse lie than a
     * box that disappears. The markup is the checklist's (form/field.php).
     */
    function syncTypeOptions(rebuild) {
        const type = currentType();
        if (!type || !colorGrid) return;

        if (rebuild) {
            const ticked = new Set([...colorGrid.querySelectorAll('input:checked')].map((box) => box.value));

            colorGrid.replaceChildren(...type.colors.map((color) => {
                const label = document.createElement('label');
                label.className = 'check';
                label.innerHTML = '<input type="checkbox" name="colors[]" data-variant-option>'
                    + '<span class="check__box" aria-hidden="true"></span>'
                    + '<span class="swatch swatch--on-light" aria-hidden="true"></span>'
                    + '<span class="check__text"><bdi></bdi></span>';
                const box = label.querySelector('input');
                box.value = String(color.id);
                box.checked = ticked.has(String(color.id));
                label.querySelector('.swatch').style.setProperty('--swatch', color.hex);
                label.querySelector('bdi').textContent = color.name;

                return label;
            }));

            // The preview swatches follow the type's colours too.
            if (colourSwatches) {
                const template = colourSwatches.querySelector('label');
                colourSwatches.replaceChildren(...type.colors.map((color, index) => {
                    const item = template.cloneNode(true);
                    const radio = item.querySelector('input');
                    radio.value = color.hex;
                    radio.checked = index === 0;
                    item.title = color.name;
                    item.querySelector('.swatch').style.setProperty('--swatch', color.hex);
                    item.querySelector('.visually-hidden').textContent = color.name;

                    return item;
                }));
            }

            if (sizeLabel) {
                sizeLabel.textContent = type.sizes.length > 0 ? type.sizes.join(' · ') : colorGrid.dataset.labelSizesNone;
            }
        }

        syncVariantCount();
    }

    function syncVariantCount() {
        if (!variantCount) return;

        const colors = colorGrid ? colorGrid.querySelectorAll('input:checked').length : 0;
        const sizes = currentType()?.sizes.length ?? 0;
        const total = colors * sizes;

        variantCount.textContent = total === 0
            ? variantCount.dataset.msgNone
            : fill(variantCount.dataset.msgCount, {
                colours: numbers.format(colors), sizes: numbers.format(sizes), total: numbers.format(total),
            });
        variantCount.classList.toggle('variant-count--empty', total === 0);
    }

    /* ------------------------------------------------------------- listeners */

    typeSelect?.addEventListener('change', () => {
        syncTypeOptions(true);
        start();
    });

    colorGrid?.addEventListener('change', syncVariantCount);

    colourSwatches?.addEventListener('change', () => {
        if (session?.ready) session.lib.setColor(previewHex());
    });

    artworkInput?.addEventListener('change', () => {
        const file = artworkInput.files?.[0] ?? null;

        if (artwork?.objectUrl) URL.revokeObjectURL(artwork.objectUrl);

        if (file) {
            const objectUrl = URL.createObjectURL(file);
            artwork = { url: objectUrl, objectUrl };
            /* A file chosen now overrides a removal ticked a moment ago. */
            if (artworkRemove) artworkRemove.checked = false;
        } else {
            artwork = config.artwork?.path ? { url: config.artwork.path, objectUrl: null } : null;
        }

        syncArtwork();
    });

    artworkRemove?.addEventListener('change', syncArtwork);

    /* Turning the shirt round when the side changes: asking someone to place a
       back print while looking at the front is asking them to guess. */
    for (const radio of form.querySelectorAll('[name="artwork_side"]')) {
        radio.addEventListener('change', () => {
            syncArtwork();
            preset(side());
        });
    }

    for (const [name, input] of Object.entries(placementInputs)) {
        input.addEventListener('input', () => {
            const output = form.querySelector(`[data-placement-output="${name}"]`);
            if (output) output.value = numbers.format(Number(input.value)) + '%';
            syncArtwork();
        });
    }

    form.querySelector('[data-placement-reset]')?.addEventListener('click', () => {
        for (const [name, input] of Object.entries(placementInputs)) {
            input.value = String(name === 'scale' ? 100 : 50);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    for (const button of document.querySelectorAll('[data-camera-preset]')) {
        button.addEventListener('click', () => preset(button.dataset.cameraPreset));
    }

    // Crossing 768px, or plugging in a mouse, starts or stops the scene.
    noWebGL.addEventListener('change', start);

    window.addEventListener('pagehide', () => {
        if (artwork?.objectUrl) URL.revokeObjectURL(artwork.objectUrl);
        stop();
    });

    /*
     * Discard puts the controls back, but not what script built from them: the
     * colour list of another type, the photo order. The form's reset event is
     * the cue; once the values are back, rebuild from them and say so, so the
     * save bar reads the restored form.
     */
    const initialType = typeSelect?.value;
    const initialTicks = new Set([...(colorGrid?.querySelectorAll('input:checked') ?? [])].map((box) => box.value));

    form.addEventListener('reset', () => setTimeout(() => {
        if (colorGrid && typeSelect?.value === initialType) {
            syncTypeOptions(true);
            for (const box of colorGrid.querySelectorAll('input')) {
                box.checked = initialTicks.has(box.value);
            }
            syncVariantCount();
            start();
        }
        const cards = [...document.querySelectorAll('[data-product-image]')];
        cards.sort((a, b) => Number(a.querySelector('[data-image-order]').dataset.initial) - Number(b.querySelector('[data-image-order]').dataset.initial));
        document.querySelector('[data-product-images]')?.append(...cards);
        form.dispatchEvent(new Event('change', { bubbles: true }));
    }));

    syncTypeOptions(false);
    start();
}

/* ------------------------------------------------------- photo order */

// Earlier / later swap a photo with its neighbour and renumber every card, so
// the order saved is the order on screen. "Earlier" points back along the line.
const photos = document.querySelector('[data-product-images]');

photos?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-image-move]') : null;
    if (!button) return;

    const card = button.closest('[data-product-image]');
    const sibling = button.dataset.imageMove === 'up' ? card.previousElementSibling : card.nextElementSibling;
    if (!sibling) return;

    if (button.dataset.imageMove === 'up') {
        photos.insertBefore(card, sibling);
    } else {
        photos.insertBefore(sibling, card);
    }

    photos.querySelectorAll('[data-image-order]').forEach((input, index) => {
        input.value = String(index);
    });
    button.focus();
    form?.dispatchEvent(new Event('change', { bubbles: true }));
});

/* --------------------------------------------- sections <-> tabs */

// The sections list and the properties tabs are one choice: picking a section
// shows its tab, picking a tab marks its section. A field named in an error is
// brought into view by opening its tab first.
if (studio) {
    const tabFor = (key) => document.getElementById('product-studio-tab-' + key);

    const mark = (key) => {
        for (const row of studio.querySelectorAll('.studio__layers [data-studio-item]')) {
            const on = row.dataset.studioItem === key;
            row.classList.toggle('is-selected', on);
            row.setAttribute('aria-selected', String(on));
        }
    };

    studio.addEventListener('studio:select', (event) => {
        const tab = tabFor(event.detail.key);
        if (tab && tab.getAttribute('aria-selected') !== 'true') tab.click();
        mark(event.detail.key);
    });

    for (const tab of studio.querySelectorAll('[data-studio-tab]')) {
        tab.addEventListener('click', () => mark(tab.id.replace('product-studio-tab-', '')));
    }

    const reveal = (el) => {
        const panel = el?.closest('.studio__tabpanel');
        if (panel?.hidden) document.querySelector(`[aria-controls="${panel.id}"]`)?.click();
    };

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('.error-summary a[href^="#"]') : null;
        if (link) reveal(document.getElementById(link.getAttribute('href').slice(1)));
    }, true);

    // After a refused save, open the tab of the first field in trouble.
    reveal(form?.querySelector('[aria-invalid="true"], .form-field--error'));
}
