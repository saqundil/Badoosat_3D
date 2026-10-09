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

const form = document.getElementById('product-form');
const configElement = document.getElementById('product-editor-config');
const stage = document.querySelector('[data-model-stage]');

if (form && configElement && stage) {
    const config = JSON.parse(configElement.textContent);
    const garmentSelect = form.querySelector('[data-garment-select]');
    const displayMode = form.querySelector('[data-display-mode]');
    const artworkInput = form.querySelector('[data-design-artwork]');
    const layersField = form.querySelector('[data-design-layers]');
    const layerList = form.querySelector('[data-layer-list]');
    const layerSide = form.querySelector('[data-layer-side]');
    const layerNumber = form.querySelector('[data-layer-number]');
    const placementInputs = Object.fromEntries(
        [...form.querySelectorAll('[data-placement]')].map((input) => [input.dataset.placement, input])
    );
    const colorInput = document.querySelector('[data-model-color]');
    const status = stage.querySelector('[data-model-status]');
    const customization = form.querySelector('[data-customization]');

    let session = null;
    let revision = 0;
    let artworkRevision = 0;
    let activeLayer = config.layers.length > 0 ? 0 : -1;
    const layers = config.layers.map((layer) => ({ ...layer, url: layer.path, file: null }));

    async function start() {
        stop();

        const garment = config.garments[garmentSelect.value];
        const hasModel = typeof garment?.model === 'string' && garment.model !== '';
        customization.disabled = !garment?.customizable;

        for (const option of displayMode.options) {
            option.disabled = !hasModel && option.value !== 'photos';
        }

        if (!hasModel) {
            displayMode.value = 'photos';
            setStatus('لا يوجد موديل ثلاثي الأبعاد معرّف لهذه القطعة.');
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

            await loadModel(garment.model);
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

    async function syncArtwork() {
        const ticket = ++artworkRevision;

        if (!session?.ready || displayMode.value !== 'model_design' || layers.length === 0) {
            if (session?.ready) setArtwork(null);
            return;
        }

        const garment = config.garments[garmentSelect.value];
        if (!garment?.print_area?.front) {
            setArtwork(null);
            return;
        }

        try {
            const renderedLayers = await Promise.all(layers.map(async (layer) => ({
                ...layer,
                image: await loadImage(layer.url),
            })));
            if (!session?.ready || ticket !== artworkRevision) return;

            setArtwork(drawDesignLayers(renderedLayers, {
                printArea: garment.print_area,
                uvScaleCm: garment.uv_scale_cm,
                canvasSize: garment.canvas_px,
            }));
        } catch (error) {
            if (!session?.ready || ticket !== artworkRevision) return;
            setArtwork(null);
            console.warn('Product editor artwork could not be applied.', error);
        }
    }

    function setStatus(message) {
        status.textContent = message;
        status.hidden = message === '';
    }

    function selectedLayer() {
        return activeLayer >= 0 ? layers[activeLayer] : null;
    }

    function syncLayerControls() {
        const layer = selectedLayer();

        layerSide.disabled = !layer;
        layerNumber.value = layer ? `${activeLayer + 1}/${layers.length}` : 'لا توجد طبقات';

        for (const [name, input] of Object.entries(placementInputs)) {
            const output = form.querySelector(`[data-placement-output="${name}"]`);
            input.disabled = !layer;
            input.value = String(layer?.[name] ?? (name === 'scale' ? 100 : 50));
            output.value = input.value + (name === 'scale' ? '%' : '');
        }

        if (layer) layerSide.value = layer.side;
        form.querySelector('[data-layer-remove]').disabled = !layer;
        form.querySelector('[data-layer-move="up"]').disabled = !layer || activeLayer === layers.length - 1;
        form.querySelector('[data-layer-move="down"]').disabled = !layer || activeLayer === 0;
    }

    function syncLayersField() {
        const transfer = new DataTransfer();
        let upload = 0;
        const payload = layers.map((layer) => {
            const source = layer.file ? { upload: upload++ } : { path: layer.path };

            if (layer.file) transfer.items.add(layer.file);

            return { ...source, side: layer.side, x: layer.x, y: layer.y, scale: layer.scale };
        });

        artworkInput.files = transfer.files;
        layersField.value = JSON.stringify(payload);
    }

    function renderLayerList() {
        layerList.replaceChildren();

        layers.forEach((layer, index) => {
            const button = document.createElement('button');
            const image = new Image();
            const label = document.createElement('span');
            button.type = 'button';
            button.className = 'product-layer' + (index === activeLayer ? ' is-active' : '');
            button.dataset.layerIndex = String(index);
            image.src = layer.url;
            image.alt = '';
            label.textContent = `${index + 1}. ${layer.side === 'back' ? 'الخلف' : 'الأمام'}`;
            button.append(image, label);
            layerList.append(button);
        });
    }

    function syncLayers() {
        syncLayersField();
        renderLayerList();
        syncLayerControls();

        syncArtwork();
    }

    garmentSelect.addEventListener('change', start);
    displayMode.addEventListener('change', syncArtwork);
    colorInput.addEventListener('input', () => {
        if (session?.ready) setColor(colorInput.value);
    });

    artworkInput.addEventListener('change', () => {
        const files = [...(artworkInput.files ?? [])];
        if (files.length === 0) return;

        for (const file of files.slice(0, Math.max(0, 20 - layers.length))) {
            layers.push({
                file,
                url: URL.createObjectURL(file),
                side: selectedLayer()?.side ?? 'front',
                x: 50,
                y: 50,
                scale: 100,
            });
        }

        activeLayer = layers.length - 1;
        displayMode.value = 'model_design';
        syncLayers();
    });

    layerList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-layer-index]');
        if (!button) return;

        activeLayer = Number(button.dataset.layerIndex);
        renderLayerList();
        syncLayerControls();
        if (session?.ready) cameraPreset(selectedLayer().side);
    });

    Object.entries(placementInputs).forEach(([name, input]) => input.addEventListener('input', () => {
        const layer = selectedLayer();
        if (!layer) return;

        layer[name] = Number(input.value);
        form.querySelector(`[data-placement-output="${name}"]`).value =
            input.value + (name === 'scale' ? '%' : '');
        syncLayersField();
        syncArtwork();
    }));

    layerSide.addEventListener('change', () => {
        const layer = selectedLayer();
        if (!layer) return;

        layer.side = layerSide.value;
        syncLayersField();
        renderLayerList();
        if (session?.ready) cameraPreset(layer.side);
        syncArtwork();
    });

    form.querySelector('[data-placement-reset]').addEventListener('click', () => {
        const layer = selectedLayer();
        if (!layer) return;

        Object.assign(layer, { x: 50, y: 50, scale: 100 });
        syncLayers();
    });

    form.querySelector('[data-layer-remove]').addEventListener('click', () => {
        const layer = selectedLayer();
        if (!layer) return;

        if (layer.file) URL.revokeObjectURL(layer.url);
        layers.splice(activeLayer, 1);
        activeLayer = Math.min(activeLayer, layers.length - 1);
        syncLayers();
    });

    form.querySelectorAll('[data-layer-move]').forEach((button) => button.addEventListener('click', () => {
        const offset = button.dataset.layerMove === 'up' ? 1 : -1;
        const target = activeLayer + offset;

        if (activeLayer < 0 || target < 0 || target >= layers.length) return;

        [layers[activeLayer], layers[target]] = [layers[target], layers[activeLayer]];
        activeLayer = target;
        syncLayers();
    }));

    document.querySelector('.product-model-editor__views').addEventListener('click', (event) => {
        const preset = event.target.closest('[data-camera-preset]')?.dataset.cameraPreset;
        if (!preset || !session?.ready) return;

        cameraPreset(preset);
        session.controls.target.set(0, 0, 0);
        session.controls.update();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else start();
    });

    window.addEventListener('pagehide', () => {
        layers.filter((layer) => layer.file).forEach((layer) => URL.revokeObjectURL(layer.url));
    });

    syncLayers();
    start();
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