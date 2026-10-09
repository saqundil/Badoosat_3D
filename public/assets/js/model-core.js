import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { MODEL_SCENE } from './model-scene-config.js';

export const REF_ASPECT = MODEL_SCENE.camera.referenceAspect;

let active = null;

export function fovForAspect(aspect) {
    const scale = aspect >= REF_ASPECT ? 1 : REF_ASPECT / aspect;
    const halfBase = THREE.MathUtils.degToRad(MODEL_SCENE.camera.fov) / 2;

    return THREE.MathUtils.radToDeg(2 * Math.atan(Math.tan(halfBase) * scale));
}

export function createScene(canvas) {
    dispose();

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.toneMapping = THREE[MODEL_SCENE.renderer.toneMapping];
    renderer.toneMappingExposure = MODEL_SCENE.renderer.toneMappingExposure;
    renderer.outputColorSpace = THREE[MODEL_SCENE.renderer.outputColorSpace];

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(
        MODEL_SCENE.camera.fov,
        REF_ASPECT,
        MODEL_SCENE.camera.near,
        MODEL_SCENE.camera.far
    );
    camera.position.set(...MODEL_SCENE.camera.position);

    const pmrem = new THREE.PMREMGenerator(renderer);
    const Environment = { RoomEnvironment }[MODEL_SCENE.environment.type];
    const room = new Environment();
    const environment = pmrem.fromScene(room, MODEL_SCENE.environment.sigma);
    room.dispose();
    scene.environment = environment.texture;

    const lights = MODEL_SCENE.lights.map((light) => {
        const instance = new THREE[light.type](light.color, light.intensity);

        if (light.position) instance.position.set(...light.position);

        return instance;
    });
    scene.add(...lights);

    const pivot = new THREE.Group();
    scene.add(pivot);

    active = {
        canvas,
        renderer,
        scene,
        camera,
        pivot,
        pmrem,
        environment,
        model: null,
        material: null,
        printMesh: null,
        overlay: null,
        ownedArtwork: null,
        disposed: false,
    };

    return active;
}

export async function loadModel(url) {
    const target = requireActive();
    const gltf = await new GLTFLoader().loadAsync(url);

    if (active !== target || target.disposed) {
        disposeObject(gltf.scene);
        throw new DOMException('Model scene was disposed while loading.', 'AbortError');
    }

    const model = gltf.scene;
    let material = null;
    let printMesh = null;

    model.traverse((node) => {
        if (node.isMesh) {
            node.material.side = THREE.DoubleSide;
            material = node.material;
            printMesh = node;
        }
    });

    target.pivot.add(model);
    model.updateWorldMatrix(true, true);

    const bounds = new THREE.Box3().setFromObject(model);
    model.scale.setScalar(MODEL_SCENE.modelHeight / bounds.getSize(new THREE.Vector3()).y);
    model.updateWorldMatrix(true, true);

    const scaled = new THREE.Box3().setFromObject(model);
    model.position.sub(scaled.getCenter(new THREE.Vector3()));

    target.model = model;
    target.material = material;
    target.printMesh = printMesh;

    return model;
}

/**
 * The garment's own colour. Nothing else reads this and nothing else writes it.
 *
 * It stays on material.color -- not baked into a texture -- because that is
 * what lets the hero fade between two colours over 450ms by lerping a single
 * Color, and because the lighting model wants an albedo it can shade.
 */
export function setColor(hex) {
    const state = requireActive();

    if (state.material) state.material.color.set(hex);
}

/**
 * The printed artwork, on a surface of its own laid over the garment.
 *
 * It used to be the garment material's own `map`, and that is the bug this
 * shape exists to prevent: a standard material computes its albedo as
 * `color * map`, so every pixel of the design was multiplied by whatever
 * colour the shirt happened to be. A red print on a navy shirt came out
 * muddy, and on the near-black #1C1C1E the multiplication took the artwork to
 * nothing at all -- the design did not darken, it disappeared.
 *
 * The fix is separation rather than arithmetic. The design goes on a second
 * mesh sharing the garment's geometry and UVs, with its own material whose
 * colour is left white, so `color * map` is `1 * map` and the artwork keeps
 * the colours it was drawn in. The garment underneath keeps its own colour and
 * its own tween, untouched.
 *
 * A child of the print mesh, not a sibling placed by hand: the transform is
 * then inherited, so the two cannot drift apart however the model is scaled or
 * recentred after loading.
 *
 * The alternative -- painting the garment colour into the same canvas and
 * holding material.color at white -- is the simpler idea and the wrong one
 * here. The canvas is 2048 square; the hero animates the shirt's colour
 * between slides, and that would mean redrawing and re-uploading it every
 * frame of the fade.
 */
export function setArtwork(canvasOrTexture) {
    const state = requireActive();

    if (!state.material || !state.printMesh) return null;

    if (state.ownedArtwork) {
        state.ownedArtwork.dispose();
        state.ownedArtwork = null;
    }

    let texture = canvasOrTexture;

    if (canvasOrTexture && !canvasOrTexture.isTexture) {
        texture = new THREE.CanvasTexture(canvasOrTexture);
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.anisotropy = state.renderer.capabilities.getMaxAnisotropy();
        state.ownedArtwork = texture;
    }

    const overlay = texture ? printSurface(state) : state.overlay;

    if (overlay) {
        overlay.material.map = texture || null;
        overlay.material.needsUpdate = true;
        overlay.visible = Boolean(texture);
    }

    return texture || null;
}

/**
 * The mesh the artwork is drawn on, built once and kept.
 *
 * Same geometry, same UVs, same material family as the garment, so the print
 * is lit by the same lights and the same environment and curves with the cloth
 * rather than sitting on it as a flat sticker. Only three things differ:
 *
 *   colour        left white, which is the whole point
 *   alphaTest     the canvas is transparent everywhere there is no ink, and
 *                 those fragments are discarded rather than blended, so the
 *                 surface writes depth only where the design actually is
 *   polygonOffset the two surfaces are in exactly the same place, and without
 *                 a nudge toward the camera they would z-fight
 */
function printSurface(state) {
    if (state.overlay) return state.overlay;

    const source = state.printMesh;
    const material = new THREE.MeshStandardMaterial({
        transparent: true,
        alphaTest: 0.01,
        side: source.material.side,
        roughness: source.material.roughness,
        metalness: source.material.metalness,
        polygonOffset: true,
        polygonOffsetFactor: -1,
        polygonOffsetUnits: -1,
    });

    state.overlay = new THREE.Mesh(source.geometry, material);
    state.overlay.visible = false;
    source.add(state.overlay);

    return state.overlay;
}

export function cameraPreset(name) {
    const state = requireActive();
    const distance = MODEL_SCENE.camera.position[2];
    const positions = {
        front: [0, 0, distance],
        back: [0, 0, -distance],
        side: [distance, 0, 0],
        reset: MODEL_SCENE.camera.position,
    };
    const position = positions[name] || positions.reset;

    state.camera.position.set(...position);
    state.camera.up.set(0, 1, 0);
    state.camera.lookAt(0, 0, 0);

    if (name === 'reset') state.pivot.rotation.set(0, 0, 0);
}

export function resize(width, height) {
    const state = requireActive();
    const nextWidth = width ?? state.canvas.parentElement?.clientWidth ?? state.canvas.clientWidth;
    const nextHeight = height ?? state.canvas.parentElement?.clientHeight ?? state.canvas.clientHeight;

    if (nextWidth < 1 || nextHeight < 1) return;

    state.renderer.setSize(nextWidth, nextHeight, false);
    state.camera.aspect = nextWidth / nextHeight;
    state.camera.fov = fovForAspect(state.camera.aspect);
    state.camera.updateProjectionMatrix();
}

export function dispose() {
    const state = active;

    if (!state || state.disposed) return;
    state.disposed = true;

    if (state.ownedArtwork) state.ownedArtwork.dispose();
    state.environment.texture.dispose();
    state.pmrem.dispose();
    disposeObject(state.scene);
    state.renderer.dispose();
    state.renderer.forceContextLoss();
    active = null;
}

function requireActive() {
    if (!active || active.disposed) throw new Error('Call createScene(canvas) first.');

    return active;
}

function disposeObject(root) {
    const geometries = new Set();
    const materials = new Set();
    const textures = new Set();

    root.traverse((node) => {
        if (node.geometry) geometries.add(node.geometry);

        const nodeMaterials = Array.isArray(node.material) ? node.material : [node.material];

        for (const material of nodeMaterials) {
            if (!material) continue;
            materials.add(material);

            for (const value of Object.values(material)) {
                if (value?.isTexture) textures.add(value);
            }
        }
    });

    textures.forEach((texture) => texture.dispose());
    materials.forEach((material) => material.dispose());
    geometries.forEach((geometry) => geometry.dispose());
}