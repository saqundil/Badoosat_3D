import { Canvas, FabricImage, Rect, Textbox } from 'fabric';

import { dpiVerdict, effectiveDpi } from './config.js';

const HISTORY_LIMIT = 30;

/**
 * Fabric drops properties it does not know about. These two have to survive
 * serialisation: they are how the server re-derives the print resolution
 * instead of believing whatever the browser said.
 */
const KEEP = ['naturalWidth', 'naturalHeight'];

/**
 * Owns the two-dimensional half of the editor.
 *
 * The customer draws on a working canvas the size of the print area. That canvas
 * is blitted into an atlas the size of the model's texture, at the rectangle the
 * garment configuration gives for the current side, and the atlas is what
 * three.js samples. Nothing is ever painted onto the model directly, and front
 * and back share one atlas because the model has one UV layout.
 *
 * Anything outside the working canvas simply is not drawn, which is the clipping
 * requirement satisfied by construction rather than by a rule that could be
 * forgotten.
 */
export class DesignSurface {
    constructor(config) {
        this.config = config;
        this.side = config.sides.includes('front') ? 'front' : config.sides[0];
        this.canvas = null;
        this.listeners = new Set();

        this.atlas = document.createElement('canvas');
        this.atlas.width = this.atlas.height = config.atlas;
        this.atlasCtx = this.atlas.getContext('2d', { willReadFrequently: false });
        this.clearAtlas();

        // One saved state per side; the atlas keeps whichever was blitted last.
        this.saved = {};
        this.history = { past: [], future: [], muted: false };
    }

    clearAtlas() {
        this.atlasCtx.fillStyle = '#ffffff';
        this.atlasCtx.fillRect(0, 0, this.atlas.width, this.atlas.height);
    }

    attach(element) {
        const { work } = this.config;

        this.canvas = new Canvas(element, {
            width: work.w,
            height: work.h,
            backgroundColor: 'rgba(0,0,0,0)',
            preserveObjectStacking: true,
            selection: true,
            controlsAboveOverlay: true,
        });

        // Belt and braces: the canvas already clips, but a stroke or shadow can
        // bleed a pixel past the edge without it.
        this.canvas.clipPath = new Rect({
            left: 0,
            top: 0,
            width: work.w,
            height: work.h,
            absolutePositioned: true,
        });

        this.canvas.on('after:render', () => this.blit());
        this.canvas.on('object:modified', () => this.commit());
        this.canvas.on('object:added', () => this.commit());
        this.canvas.on('object:removed', () => this.commit());
        this.canvas.on('selection:created', () => this.emit());
        this.canvas.on('selection:updated', () => this.emit());
        this.canvas.on('selection:cleared', () => this.emit());

        this.snapshot();
        this.blit();

        return this.canvas;
    }

    dispose() {
        this.canvas?.dispose();
        this.canvas = null;
        this.listeners.clear();
    }

    /* ------------------------------------------------------------- events */

    subscribe(fn) {
        this.listeners.add(fn);

        return () => this.listeners.delete(fn);
    }

    emit() {
        for (const fn of this.listeners) {
            fn(this);
        }
    }

    /* -------------------------------------------------------------- atlas */

    /** Copies the working canvas into its rectangle on the atlas. */
    blit() {
        if (!this.canvas) {
            return;
        }

        const rect = this.config.rect[this.side];
        const ctx = this.atlasCtx;

        ctx.save();
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(rect.x, rect.y, rect.w, rect.h);

        if (this.side === 'back') {
            ctx.translate(rect.x + rect.w, rect.y);
            ctx.scale(-1, 1);
            ctx.drawImage(this.canvas.lowerCanvasEl, 0, 0, rect.w, rect.h);
        } else {
            ctx.drawImage(this.canvas.lowerCanvasEl, rect.x, rect.y, rect.w, rect.h);
        }

        ctx.restore();

        this.onAtlasChange?.();
    }

    /* --------------------------------------------------------------- side */

    async setSide(side) {
        if (side === this.side || !this.config.rect[side]) {
            return;
        }

        this.saved[this.side] = this.canvas.toObject(KEEP);
        this.side = side;

        this.history.muted = true;
        await this.load(this.saved[side] ?? { objects: [] });
        this.history.muted = false;

        this.history.past = [];
        this.history.future = [];
        this.snapshot();
        this.emit();
    }

    /* ------------------------------------------------------------ objects */

    /** Places a bitmap covering the continuous body panel. */
    async addImage(source, naturalSize) {
        const image = await FabricImage.fromURL(source, { crossOrigin: 'anonymous' });
        const { work } = this.config;

        const fit = Math.max(work.w / image.width, work.h / image.height);
        image.set({
            left: work.w / 2,
            top: work.h / 2,
            originX: 'center',
            originY: 'center',
            scaleX: fit,
            scaleY: fit,
        });

        image.naturalWidth = naturalSize?.width ?? image.width;
        image.naturalHeight = naturalSize?.height ?? image.height;

        this.canvas.add(image);
        this.canvas.setActiveObject(image);
        this.canvas.requestRenderAll();

        return this.resolutionOf(image);
    }

    addText(value, options = {}) {
        const { work } = this.config;

        const text = new Textbox(value, {
            left: work.w / 2,
            top: work.h / 2,
            originX: 'center',
            originY: 'center',
            width: work.w * 0.7,
            fontSize: Math.round(work.w * 0.09),
            fontFamily: options.fontFamily ?? 'Cairo, system-ui, sans-serif',
            fill: options.fill ?? '#111111',
            textAlign: 'center',
            direction: options.direction ?? 'rtl',
        });

        this.canvas.add(text);
        this.canvas.setActiveObject(text);
        this.canvas.requestRenderAll();

        return text;
    }

    /** @return {{dpi:number,level:string}|null} */
    resolutionOf(object) {
        if (!object || !object.naturalWidth) {
            return null;
        }

        const shownPx = object.width * object.scaleX;

        return dpiVerdict(effectiveDpi(object.naturalWidth, shownPx, this.config), this.config);
    }

    /** The worst resolution among the bitmaps currently placed, on any side. */
    worstResolution() {
        let worst = null;

        const consider = (objects) => {
            for (const object of objects) {
                const result = this.resolutionOf(object);

                if (result && (worst === null || result.dpi < worst.dpi)) {
                    worst = result;
                }
            }
        };

        consider(this.canvas ? this.canvas.getObjects() : []);

        return worst;
    }

    active() {
        return this.canvas?.getActiveObject() ?? null;
    }

    remove() {
        const object = this.active();

        if (object) {
            this.canvas.remove(object);
            this.canvas.discardActiveObject();
            this.canvas.requestRenderAll();
        }
    }

    order(direction) {
        const object = this.active();

        if (!object) {
            return;
        }

        if (direction === 'up') {
            this.canvas.bringObjectForward(object);
        } else {
            this.canvas.sendObjectBackwards(object);
        }

        this.canvas.requestRenderAll();
        this.commit();
    }

    rotate(degrees) {
        const object = this.active();

        if (!object) {
            return;
        }

        object.rotate((object.angle + degrees) % 360);
        this.canvas.requestRenderAll();
        this.commit();
    }

    scale(factor) {
        const object = this.active();

        if (!object) {
            return;
        }

        object.scale(Math.max(0.05, object.scaleX * factor));
        object.setCoords();
        this.canvas.requestRenderAll();
        this.commit();
    }

    /* ------------------------------------------------------------ history */

    snapshot() {
        if (!this.canvas) {
            return;
        }

        this.history.past.push(JSON.stringify(this.canvas.toObject(KEEP)));

        if (this.history.past.length > HISTORY_LIMIT + 1) {
            this.history.past.shift();
        }
    }

    commit() {
        if (this.history.muted) {
            return;
        }

        this.snapshot();
        this.history.future = [];
        this.emit();
    }

    canUndo() {
        return this.history.past.length > 1;
    }

    canRedo() {
        return this.history.future.length > 0;
    }

    async undo() {
        if (!this.canUndo()) {
            return;
        }

        this.history.future.push(this.history.past.pop());
        await this.replay(this.history.past[this.history.past.length - 1]);
    }

    async redo() {
        if (!this.canRedo()) {
            return;
        }

        const state = this.history.future.pop();
        this.history.past.push(state);
        await this.replay(state);
    }

    async replay(state) {
        this.history.muted = true;
        await this.load(JSON.parse(state));
        this.history.muted = false;
        this.emit();
    }

    async load(json) {
        await this.canvas.loadFromJSON(json);
        this.canvas.requestRenderAll();
        this.blit();
    }

    /* ------------------------------------------------------------- output */

    /** Every side's state, so a saved design reopens exactly as it was left. */
    toJSON() {
        const sides = { ...this.saved };

        if (this.canvas) {
            sides[this.side] = this.canvas.toObject(KEEP);
        }

        return { version: 1, active: this.side, sides };
    }

    async fromJSON(payload) {
        if (!payload || !payload.sides) {
            return;
        }

        this.history.muted = true;
        this.saved = { ...payload.sides };

        // Paint every side once so the atlas carries all of them.
        for (const side of this.config.sides) {
            if (!this.saved[side]) {
                continue;
            }

            this.side = side;
            await this.load(this.saved[side]);
        }

        this.side = payload.active && this.config.rect[payload.active] ? payload.active : this.side;
        await this.load(this.saved[this.side] ?? { objects: [] });

        this.history.muted = false;
        this.history.past = [];
        this.history.future = [];
        this.snapshot();
        this.emit();
    }

    /**
     * The press file: the print area alone, at the configured DPI.
     *
     * Never the 3D view. What the press receives is the flat artwork at its true
     * physical size; the scene is a preview and nothing else.
     */
    async printDataUrl() {
        const { exportPx } = this.config;

        const raw = this.canvas.toDataURL({
            format: 'png',
            multiplier: this.config.exportMultiplier,
            enableRetinaScaling: false,
        });

        // Fabric's multiplier cannot land on both target dimensions at once, so
        // the result is redrawn at exactly the size the press expects. "28 x 35 cm
        // at 300 DPI" has to be true to the pixel, not to within a rounding.
        const canvas = document.createElement('canvas');
        canvas.width = exportPx.w;
        canvas.height = exportPx.h;

        const image = new Image();
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('تعذّر تجهيز ملف الطباعة.'));
            image.src = raw;
        });

        canvas.getContext('2d').drawImage(image, 0, 0, exportPx.w, exportPx.h);

        return canvas.toDataURL('image/png');
    }

    /** True when nothing has been placed on any side. */
    isEmpty() {
        const active = this.canvas ? this.canvas.getObjects().length : 0;
        const others = Object.values(this.saved)
            .reduce((n, state) => n + (state?.objects?.length ?? 0), 0);

        return active + others === 0;
    }

    /** Whether any placed bitmap lacks transparency. */
    hasOpaqueBitmap() {
        return this.canvas
            ? this.canvas.getObjects().some((o) => o.isType('image') && o.opaqueSource === true)
            : false;
    }
}
