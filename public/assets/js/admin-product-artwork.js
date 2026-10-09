import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import {
    cameraPreset,
    createScene,
    dispose,
    loadModel,
    resize,
    setArtwork,
    setColor,
} from './model-core.js';
import { drawDesignLayers } from './hero3d.js';

/*
 * The product editor's live preview.
 *
 * One garment type, one piece of artwork. It replaces the layer-stack editor
 * that came before: a product's print is a single file on the product row now,
 * so there is no stack to order, no active-layer pointer, and no hidden JSON
 * field carrying a list.
 *
 * drawDesignLayers() still does the drawing. It takes a list, and a single
 * artwork is a list of one -- reusing it keeps the admin preview and the hero
 * painting pixels the same way, which is the whole reason the artwork lands
 * where the press will put it.
 */

const form = document.getElementById('product-form');
const configElement = document.getElementById('product-editor-config');
const stage = document.querySelector('[data-model-stage]');

if (form && configElement && stage) {
    const config = JSON.parse(configElement.textContent);
    const typeSelect = form.querySelector('[data-garment-select]');
    const artworkInput = form.querySelector('[data-artwork-input]');
    const artworkRemove = form.querySelector('[data-artwork-remove]');
    const sideSelect = form.querySelector('[data-artwork-side]');
    const colorInput = document.querySelector('[data-model-color]');
    const status = stage.querySelector('[data-model-status]');
    const sharedNote = document.querySelector('[data-model-shared]');
    const customization = form.querySelector('[data-customization]');
    const colorGrid = form.querySelector('[data-variant-colors]');
    const sizeLabel = form.querySelector('[data-type-sizes]');
    const variantCount = form.querySelector('[data-variant-count]');
    const placementInputs = Object.fromEntries(
        [...form.querySelectorAll('[data-placement]')].map((input) => [input.dataset.placement, input]),
    );

    let session = null;
    let revision = 0;
    let artworkRevision = 0;

    /* The artwork as it stands: the stored file, or one just chosen. */
    let artwork = config.artwork?.path
        ? { url: config.artwork.path, objectUrl: null }
        : null;

    function currentType() {
        return config.types[typeSelect.value] ?? null;
    }

    function currentGeometry() {
        const type = currentType();

        return type?.geometry ? config.geometry[type.geometry] ?? null : null;
    }

    /* ------------------------------------------------------------- the scene */

    async function start() {
        stop();

        const type = currentType();
        const geometry = currentGeometry();
        const modelPath = type?.model;

        if (sharedNote) sharedNote.hidden = !type?.shared_model;
        if (customization) customization.disabled = !geometry?.customizable;

        if (typeof modelPath !== 'string' || modelPath === '') {
            setStatus('لا يوجد مجسم مسجَّل لهذا النوع. راجع شاشة الأنواع.');
            return;
        }

        const ticket = ++revision;
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', 'معاينة المنتج ثلاثية الأبعاد');
        stage.replaceChildren(canvas, status);
        setStatus('جارٍ تحميل الموديل...');

        try {
            const state = createScene(canvas);
            const controls = new OrbitControls(state.camera, canvas);
            controls.enableDamping = true;
            controls.dampingFactor = 0.08;
            controls.minDistance = 2;
            controls.maxDistance = 5;
            controls.minPolarAngle = Math.PI * 0.2;
            controls.maxPolarAngle = Math.PI * 0.8;
            controls.target.set(0, 0, 0);

            const observer = new ResizeObserver(() => resize());
            observer.observe(stage);
            resize();
            session = { state, controls, observer, frame: 0, ready: false };

            await loadModel(modelPath);
            if (ticket !== revision || !session) return;

            setColor(colorInput.value);
            session.ready = true;
            await syncArtwork();
            if (ticket !== revision || !session) return;

            setStatus('');
            render();
        } catch (error) {
            if (error?.name === 'AbortError' || ticket !== revision) return;
            console.error('Product editor model failed to load.', error);
            stop();
            setStatus('تعذّر تحميل الموديل. تحقق من مساره ثم أعد المحاولة.');
        }
    }

    function stop() {
        revision++;
        artworkRevision++;

        if (session) {
            cancelAnimationFrame(session.frame);
            session.observer.disconnect();
            session.controls.dispose();
            session = null;
        }

        dispose();
        stage.querySelector('canvas')?.remove();
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

        /* Ticking "remove" clears the preview too, so what is on screen is what
           saving would produce -- not what is still in the database. */
        if (!session?.ready || artwork === null || artworkRemove?.checked) {
            if (session?.ready) setArtwork(null);
            return;
        }

        if (!geometry?.print_area?.front) {
            setArtwork(null);
            return;
        }

        try {
            const image = await loadImage(artwork.url);
            if (!session?.ready || ticket !== artworkRevision) return;

            setArtwork(drawDesignLayers([{
                image,
                side: sideSelect?.value === 'back' ? 'back' : 'front',
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
            setArtwork(null);
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
        status.textContent = message;
        status.hidden = message === '';
    }

    /* --------------------------------------------- colours, sizes and counts */

    /*
     * Repainting the colour list when the type changes.
     *
     * A colour ticked under the old type that the new one is not cut in is
     * dropped rather than carried across -- the server refuses it anyway, and
     * a box that stays ticked while the save rejects it is a worse lie than a
     * box that disappears.
     */
    function syncTypeOptions() {
        const type = currentType();
        if (!type || !colorGrid) return;

        const ticked = new Set(
            [...colorGrid.querySelectorAll('input[name="colors[]"]:checked')].map((box) => box.value),
        );

        colorGrid.replaceChildren(...type.colors.map((color) => {
            const label = document.createElement('label');
            label.className = 'field field--check';

            const box = document.createElement('input');
            box.type = 'checkbox';
            box.name = 'colors[]';
            box.value = String(color.id);
            box.dataset.variantOption = '';
            box.checked = ticked.has(String(color.id));
            box.addEventListener('change', syncVariantCount);

            const swatch = document.createElement('span');
            swatch.className = 'slide__swatch';
            swatch.style.background = color.hex;

            const name = document.createElement('span');
            name.textContent = color.name;

            label.append(box, swatch, name);
            return label;
        }));

        if (sizeLabel) {
            sizeLabel.textContent = type.sizes.length > 0 ? type.sizes.join('، ') : 'لا مقاسات';
        }

        syncVariantCount();
    }

    function syncVariantCount() {
        if (!variantCount) return;

        const type = currentType();
        const colors = colorGrid
            ? colorGrid.querySelectorAll('input[name="colors[]"]:checked').length
            : 0;
        const sizes = type?.sizes.length ?? 0;
        const total = colors * sizes;

        variantCount.textContent = total === 0
            ? 'لم يُختر لون، أو لا مقاسات للنوع: المنتج لن يكون قابلًا للبيع.'
            : colors + ' لون × ' + sizes + ' مقاس = ' + total + ' متغيّر.';
    }

    /* ------------------------------------------------------------- listeners */

    typeSelect?.addEventListener('change', () => {
        syncTypeOptions();
        start();
    });

    colorInput?.addEventListener('input', () => {
        if (session?.ready) setColor(colorInput.value);
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
    sideSelect?.addEventListener('change', syncArtwork);

    for (const [name, input] of Object.entries(placementInputs)) {
        input.addEventListener('input', () => {
            const output = form.querySelector(`[data-placement-output="${name}"]`);
            if (output) output.value = input.value + (name === 'scale' ? '%' : '');
            syncArtwork();
        });
    }

    form.querySelector('[data-placement-reset]')?.addEventListener('click', () => {
        for (const [name, input] of Object.entries(placementInputs)) {
            input.value = String(name === 'scale' ? 100 : 50);
            input.dispatchEvent(new Event('input'));
        }
    });

    for (const button of document.querySelectorAll('[data-camera-preset]')) {
        button.addEventListener('click', () => {
            if (session?.ready) cameraPreset(button.dataset.cameraPreset);
        });
    }

    /* Turning the shirt round when the side changes: asking someone to place a
       back print while looking at the front is asking them to guess. */
    sideSelect?.addEventListener('change', () => {
        if (session?.ready) cameraPreset(sideSelect.value === 'back' ? 'back' : 'front');
    });

    window.addEventListener('beforeunload', () => {
        if (artwork?.objectUrl) URL.revokeObjectURL(artwork.objectUrl);
        stop();
    });

    syncTypeOptions();
    start();
}
