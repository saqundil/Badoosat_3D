/**
 * Slide editor, editor side.
 *
 * The preview is a frame, not a panel: the hero's shape is decided by viewport
 * media queries, and only a frame has a viewport of its own to answer them.
 * See public/admin/hero-preview.php for why that matters and what it buys.
 *
 * So this file no longer touches the card. It builds a draft out of the form,
 * posts it into the frame, takes the nudges back when something is dragged,
 * and drives the two switches above the frame: which device width the frame IS
 * (a real width, not a zoom) and which language it renders in.
 */

const form = document.getElementById('slide-form');
const frame = document.getElementById('hero-preview');
const stage = document.getElementById('preview-stage');
const layoutField = document.getElementById('layout-field');
const fileInput = document.getElementById('upload-input');
const token = form.dataset.token;

/*
 * Colour, design and placement resolve here rather than on the server, so the
 * shirt in the preview turns over as the select is dragged.
 *
 * Every rule in this file's resolution comes out of the island: which file a
 * model key names, which hex a colour id is, which layers a design carries and
 * -- placementSides -- which sides a placement prints on. None of it is
 * restated, so what the preview draws is what HeroSlideService would have
 * built for the same draft.
 */
const lookups = JSON.parse(document.getElementById('hero-editor-lookups')?.textContent ?? '{}');
const modelFields = document.querySelector('[data-model-fields]');
const placementField = document.querySelector('[data-placement-field]');
const hasModelBox = document.getElementById('has-model');
const designSelect = document.getElementById('design-id');

// Guard against [] arriving where {} was meant: assigning named keys to an
// array works, but JSON.stringify then throws them away.
let layout = toObject(JSON.parse(layoutField.value || '{}'));
let ready = false;

function toObject(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

/* ------------------------------------------------------------------ preview */

function draftSlide() {
    const data = new FormData(form);
    const stats = [];

    for (let i = 0; i < 2; i++) {
        stats.push({
            value: data.get('stats[' + i + '][value]') ?? '',
            label: data.get('stats[' + i + '][label]') ?? '',
        });
    }

    const hasModel = data.get('has_model') === '1';
    const modelKey = String(data.get('model_key') ?? '');
    const colorId = String(data.get('color_id') ?? '');
    const designImage = data.get('design_image_path') || null;

    return {
        has_model: hasModel,
        show_badges: data.get('show_badges') === '1',
        parallax_enabled: data.get('parallax_enabled') === '1',
        model_key: modelKey,
        model_file: hasModel ? (lookups.models?.[modelKey] ?? null) : null,
        model_path: lookups.models?.[modelKey] ?? '',
        garment_color: lookups.colors?.[colorId] ?? '#E7E4DC',
        design_layers: hasModel
            ? layersFor(String(data.get('design_id') ?? ''), String(data.get('placement') ?? 'front'), designImage)
            : [],
        poster_path: data.get('poster_path'),
        design_image_path: designImage,
        background_value: data.get('background_value'),
        tab_word: data.get('tab_word'),
        wordmark: data.get('wordmark'),
        tagline: data.get('tagline'),
        stats,
        layout,
        cta_label: data.get('cta_label'),
        cta_url: data.get('cta_url'),
    };
}

/**
 * The design's layers, once per side the placement prints on.
 *
 * Mirrors HeroSlideService::designLayers(): a catalogue design contributes its
 * own placed layers, and a slide with no design but an old uploaded image
 * contributes that image, centred. Which sides -- the only judgement here --
 * is read from placementSides, not decided.
 */
function layersFor(designId, placement, fallbackImage) {
    const sides = lookups.placementSides?.[placement] ?? [];
    const base = designId
        ? (lookups.designLayers?.[designId] ?? [])
        : (fallbackImage ? [{ path: fallbackImage, x: 50, y: 50, scale: 100 }] : []);

    return sides.flatMap((side) => base.map((layer) => ({ ...layer, side })));
}

/** The model fields describe a model; with the switch off there is none. */
function syncModelFields() {
    const hasModel = hasModelBox?.checked !== false;

    if (modelFields) {
        modelFields.hidden = !hasModel;
    }

    if (placementField) {
        placementField.hidden = !hasModel || !designSelect?.value;
    }
}

function syncPreview() {
    layoutField.value = JSON.stringify(layout);
    syncModelFields();

    if (!ready) {
        return;
    }

    const draft = draftSlide();

    frame.contentWindow.postMessage(
        { type: 'slide', slide: draft, parallax: draft.parallax_enabled },
        window.location.origin
    );
}

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.source !== frame.contentWindow) {
        return;
    }

    const message = event.data;

    if (message === null || typeof message !== 'object') {
        return;
    }

    /* The frame has let go; the new document can have the context. */
    if (message.type === 'released') {
        swapFrame();

        return;
    }

    if (message.type === 'ready') {
        ready = true;
        syncPreview();
        fitFrame();
    }

    /* The frame clamped it against the real card; this only records it. */
    if (message.type === 'layout') {
        layout = toObject(message.layout);
        layoutField.value = JSON.stringify(layout);
    }
});

/* ------------------------------------------------- device width and scaling */

/*
 * The frame is the width it says it is. It is only DRAWN smaller.
 *
 * Everything inside resolves against the frame's own viewport -- the media
 * queries, the container queries, the notch geometry -- so 390 here is the
 * 390 a phone has, down to the single spanning notch that appears under 600.
 * The scale is a transform, which paints without touching layout, so nothing
 * inside can tell the difference.
 */
const WIDTHS = [1440, 768, 390];

let deviceWidth = WIDTHS[0];

function fitFrame() {
    const available = stage.clientWidth;

    if (available === 0) {
        return;
    }

    const scale = Math.min(1, available / deviceWidth);
    const painted = deviceWidth * scale;

    frame.style.width = deviceWidth + 'px';
    /* Centred when it does not fill the column -- a phone-width frame pinned to
       one edge reads as a broken layout rather than a narrow device. */
    frame.style.transform =
        'translateX(' + (document.dir === 'rtl' ? '-' : '') + Math.round((available - painted) / 2)
        + 'px) scale(' + scale + ')';

    /* The wrapper keeps the painted height, not the laid-out one, or the form
       beside it would be pushed down by space the frame is not using. */
    stage.style.height = Math.round(frame.offsetHeight * scale) + 'px';
}

function setDeviceWidth(width) {
    deviceWidth = width;

    for (const button of document.querySelectorAll('[data-preview-width]')) {
        button.setAttribute('aria-pressed',
            String(Number(button.dataset.previewWidth) === width));
    }

    fitFrame();
}

for (const button of document.querySelectorAll('[data-preview-width]')) {
    button.addEventListener('click', () => setDeviceWidth(Number(button.dataset.previewWidth)));
}

window.addEventListener('resize', fitFrame);

/* ---------------------------------------------------- the preview's language */

/*
 * Reloads the frame rather than swapping strings in it. The wording is the
 * server's -- Lang::get(), the same call the site makes -- and the direction
 * belongs on the document element, so the honest way to see the other language
 * is to ask for the document in it.
 */
function setPreviewLang(lang) {
    ready = false;

    for (const button of document.querySelectorAll('[data-preview-lang]')) {
        button.setAttribute('aria-pressed', String(button.dataset.previewLang === lang));
    }

    const url = new URL(frame.src, window.location.origin);
    url.searchParams.set('lang', lang);

    /* Ask the frame to let go of its WebGL context before handing it a new
       document to load, so the two are never holding one at the same time. */
    frame.contentWindow.postMessage({ type: 'release' }, window.location.origin);
    pendingSrc = url.toString();
    window.setTimeout(swapFrame, 120);
}

let pendingSrc = null;

function swapFrame() {
    if (pendingSrc !== null) {
        frame.src = pendingSrc;
        pendingSrc = null;
    }
}

for (const button of document.querySelectorAll('[data-preview-lang]')) {
    button.addEventListener('click', () => setPreviewLang(button.dataset.previewLang));
}

/* --------------------------------------------------------------- uploads */

let uploadTarget = null;

document.querySelectorAll('[data-upload]').forEach((button) => {
    button.addEventListener('click', () => {
        uploadTarget = button.dataset.upload;
        fileInput.value = '';
        fileInput.click();
    });
});

fileInput.addEventListener('change', async () => {
    const file = fileInput.files?.[0];

    if (!file || !uploadTarget) {
        return;
    }

    const body = new FormData();
    body.append('image', file);
    body.append('_token', token);

    try {
        const response = await fetch('/admin/upload.php', {
            method: 'POST',
            body,
            headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error ?? 'تعذّر الرفع.');
        }

        form.elements[uploadTarget].value = result.path;
        syncPreview();
    } catch (error) {
        alert(error.message);
    }
});

/* ------------------------------------------------------------ form wiring */

const bgValue = document.getElementById('background-value');
const bgColor = document.getElementById('background-color');

bgColor.addEventListener('input', () => {
    bgValue.value = bgColor.value.toUpperCase();
    syncPreview();
});

bgValue.addEventListener('input', () => {
    if (/^#[0-9a-fA-F]{6}$/.test(bgValue.value)) {
        bgColor.value = bgValue.value;
    }
});

document.getElementById('reset-layout').addEventListener('click', () => {
    layout = {};
    syncPreview();
});

form.addEventListener('input', (event) => {
    if (event.target.matches('[data-live]')) {
        syncPreview();
    }
});

form.addEventListener('submit', () => {
    layoutField.value = JSON.stringify(layout);
});

setDeviceWidth(WIDTHS[0]);
syncModelFields();
