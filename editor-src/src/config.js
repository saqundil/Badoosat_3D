/**
 * Reads the block PHP printed into the page and derives every measurement the
 * editor needs from it.
 *
 * No number in this file is a constant from the garment. Re-measuring the model
 * is a change to config/garments.php and nothing else; if a value is missing
 * here the editor refuses to start rather than guess.
 */

function required(value, name) {
    if (value === undefined || value === null) {
        throw new Error('editor-config is missing ' + name);
    }

    return value;
}

export function readConfig(element) {
    const raw = element ?? document.getElementById('editor-config');

    if (!raw) {
        throw new Error('editor-config block not found');
    }

    const data = JSON.parse(raw.textContent);
    const garment = required(data.garment, 'garment');

    required(garment.design_rect_px, 'garment.design_rect_px');
    required(garment.canvas_px, 'garment.canvas_px');
    required(garment.print_size_cm, 'garment.print_size_cm');
    required(garment.export_px, 'garment.export_px');

    const work = required(garment.work_px, 'garment.work_px');
    const cm = garment.print_size_cm;

    return {
        productId: required(data.product_id, 'product_id'),
        variants: required(data.variants, 'variants'),

        /*
         * Colours and sizes are catalogue rows, carried so the island can show
         * a name and post an id. The ids are what the cart is written with:
         * a hex string in a cart line would break the moment the shop adjusted
         * a colour, and would say nothing about which variant was bought.
         */
        colors: required(data.colors, 'colors'),
        sizes: required(data.sizes, 'sizes'),
        presets: data.presets ?? [],
        modelUrl: required(data.model_url, 'model_url'),
        csrfToken: required(data.csrf_token, 'csrf_token'),
        maxUploadMb: Number(data.max_upload_mb ?? 1),
        endpoint: data.endpoint ?? '/api/design.php',
        returnsNotice: data.returns_notice ?? '',

        garment,
        sides: Object.keys(garment.design_rect_px),

        /** The atlas the model samples. */
        atlas: garment.canvas_px,

        /** Where each side's artwork lands on that atlas. */
        rect: garment.design_rect_px,

        /** The editing surface, shared by both sides. */
        work,

        /** Working pixels per centimetre of cloth. */
        pxPerCm: work.w / cm.w,

        printSizeCm: cm,

        /** Scale from working pixels to the 300 DPI export. */
        exportMultiplier: garment.export_px.w / work.w,
        exportPx: garment.export_px,

        dpi: garment.dpi ?? { target: 300, warn: 150, reject: 100 },
    };
}

/** Effective print resolution of a bitmap shown at a given working width. */
export function effectiveDpi(naturalPx, workingPx, config) {
    if (workingPx <= 0) {
        return 0;
    }

    const cm = workingPx / config.pxPerCm;

    return Math.floor(naturalPx / (cm / 2.54));
}

export function dpiVerdict(dpi, config) {
    if (dpi >= config.dpi.warn) {
        return { level: 'ok', dpi };
    }

    return { level: dpi >= config.dpi.reject ? 'warn' : 'reject', dpi };
}
