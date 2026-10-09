import * as THREE from 'three';
import {
    REF_ASPECT,
    createScene as createModelScene,
    dispose as disposeModelScene,
    fovForAspect,
    loadModel,
    resize as resizeModelScene,
    setArtwork,
    setColor as setModelColor,
} from './model-core.js';

export { REF_ASPECT, fovForAspect };

/**
 * Tracks what `object-fit: contain` does to the poster, so the garment is the
 * same size in the still and in the scene and the cross-fade does not move.
 *
 * Wider than the poster, contain is driven by height and a fixed vertical field
 * of view already matches. Taller than the poster, contain shrinks the still by
 * aspect/REF, so the camera pulls back by the same factor.
 */
/**
 * Draws artwork into the garment's print rectangle on a square canvas.
 *
 * The artwork is fitted in centimetres and then converted to pixels, so the
 * rectangle absorbs the difference between the u and v scales and a square
 * design prints square. v runs up the garment, hence the (1 - v1) origin.
 */
export function drawDesign(
    image,
    { printArea, uvScaleCm, canvasSize },
    side = 'front',
    placement = { x: 50, y: 50, scale: 100 }
) {
    return drawDesignLayers([{ image, side, ...placement }], { printArea, uvScaleCm, canvasSize });
}

export function drawDesignLayers(layers, { printArea, uvScaleCm, canvasSize }) {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = canvasSize;
    const context = canvas.getContext('2d');

    /*
     * Left transparent, deliberately.
     *
     * This canvas used to be filled white first, because it became the garment
     * material's own map and white was the value that multiplied the shirt's
     * colour through unchanged. It is the print surface's map now -- a separate
     * mesh laid over the garment, see setArtwork() in model-core.js -- so
     * everywhere there is no ink there must be nothing at all, or the print
     * surface would cover the shirt in a white sheet with a design on it.
     */

    for (const layer of layers) {
        const area = printArea[layer.side];

        if (!area) continue;

        const rect = {
            x: area.u0 * canvasSize,
            y: (1 - area.v1) * canvasSize,
            w: (area.u1 - area.u0) * canvasSize,
            h: (area.v1 - area.v0) * canvasSize,
        };

        const areaCm = {
            w: (area.u1 - area.u0) * uvScaleCm.u,
            h: (area.v1 - area.v0) * uvScaleCm.v,
        };

        const fit = Math.min(areaCm.w / layer.image.naturalWidth, areaCm.h / layer.image.naturalHeight);
        const scale = Math.max(0.1, Math.min(1.5, layer.scale / 100));
        const draw = {
            w: (layer.image.naturalWidth * fit * scale / areaCm.w) * rect.w,
            h: (layer.image.naturalHeight * fit * scale / areaCm.h) * rect.h,
        };
        const sideCanvas = document.createElement('canvas');
        sideCanvas.width = Math.ceil(rect.w);
        sideCanvas.height = Math.ceil(rect.h);
        const sideContext = sideCanvas.getContext('2d');
        const centerX = sideCanvas.width * Math.max(0, Math.min(1, layer.x / 100));
        const centerY = sideCanvas.height * Math.max(0, Math.min(1, layer.y / 100));
        sideContext.drawImage(
            layer.image,
            centerX - draw.w / 2,
            centerY - draw.h / 2,
            draw.w,
            draw.h
        );

        context.save();

        if (layer.side === 'back') {
            context.translate(rect.x + rect.w, rect.y);
            context.scale(-1, 1);
            context.drawImage(sideCanvas, 0, 0, rect.w, rect.h);
        } else {
            context.drawImage(sideCanvas, rect.x, rect.y, rect.w, rect.h);
        }

        context.restore();
    }

    return canvas;
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

/**
 * One WebGL context for the whole hero. Slides swap colour and artwork on the
 * material that is already on the GPU; nothing is rebuilt between them, and two
 * simultaneous contexts would risk context loss on a phone.
 */
export async function createStage(canvas, options) {
    const core = createModelScene(canvas);
    const { renderer, scene, camera, pivot } = core;
    await loadModel(options.modelPath);
    const material = core.material;

    const designCache = new Map();
    let colorTween = null;
    let disposed = false;

    return {
        renderer,
        scene,
        camera,
        material,

        resize(width, height) {
            resizeModelScene(width, height);
        },

        setTilt(x, y) {
            pivot.rotation.y = x;
            pivot.rotation.x = y;
        },

        setColor(hex, animate = true) {
            if (!material) {
                return;
            }

            const to = new THREE.Color(hex);

            if (!animate) {
                colorTween = null;
                setModelColor(hex);

                return;
            }

            colorTween = { from: material.color.clone(), to, start: performance.now(), ms: 450 };
        },

        /** Advances the colour fade. Called once per rendered frame. */
        step() {
            if (!colorTween) {
                return;
            }

            const k = Math.min((performance.now() - colorTween.start) / colorTween.ms, 1);
            material.color.copy(colorTween.from).lerp(colorTween.to, k);

            if (k === 1) {
                colorTween = null;
            }
        },

        /**
         * Artwork as a list of placed layers -- a path, a side, a position and
         * a scale each -- which is the shape the product page already hands
         * drawDesignLayers(). The hero used to know only "one image, centred on
         * the chest"; a slide can now say front, back or both, and a catalogue
         * design can carry several layers, so it speaks the same language.
         *
         * @param {Array<{path:string,side:string,x:number,y:number,scale:number}>} layers
         */
        async setDesignLayers(layers) {
            if (!material) {
                return;
            }

            const list = Array.isArray(layers) ? layers.filter((layer) => layer && layer.path) : [];

            if (list.length === 0) {
                setArtwork(null);

                return;
            }

            /* The whole list is one texture, so the list is the cache key. */
            const key = JSON.stringify(list);
            let texture = designCache.get(key);

            if (!texture) {
                const drawn = await Promise.all(list.map(async (layer) => ({
                    ...layer,
                    image: await loadImage(layer.path),
                })));

                if (disposed) {
                    return;
                }

                texture = new THREE.CanvasTexture(drawDesignLayers(drawn, options));
                // flipY stays at the CanvasTexture default: this model's v runs
                // up the garment, so v-up has to map to canvas-up for artwork to
                // print the right way round.
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.anisotropy = renderer.capabilities.getMaxAnisotropy();
                designCache.set(key, texture);
            }

            setArtwork(texture);
        },

        /** One centred image on the chest -- setDesignLayers with one layer. */
        async setDesign(path) {
            return this.setDesignLayers(
                path ? [{ path, side: 'front', x: 50, y: 50, scale: 100 }] : []
            );
        },

        render() {
            renderer.render(scene, camera);
        },

        dispose() {
            if (disposed) return;
            disposed = true;

            designCache.forEach((texture) => texture.dispose());
            designCache.clear();
            disposeModelScene();
        },
    };
}
