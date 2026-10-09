import { Canvas, useFrame, useLoader, useThree } from '@react-three/fiber';
import { Suspense, useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import {
    AmbientLight,
    Box3,
    CanvasTexture,
    DoubleSide,
    MathUtils,
    PMREMGenerator,
    SRGBColorSpace,
    Vector3,
} from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';

import { MODEL_SCENE } from '../../public/assets/js/model-scene-config.js';
import { useEditor } from './store.js';

const MODEL_HEIGHT = MODEL_SCENE.modelHeight;
const TILT_LIMIT = 0.55;

/** The room probe is what makes cotton read as cotton rather than plastic. */
function Environment() {
    const { gl, scene } = useThree();

    useEffect(() => {
        const pmrem = new PMREMGenerator(gl);
        const EnvironmentClass = { RoomEnvironment }[MODEL_SCENE.environment.type];
        const room = new EnvironmentClass();
        const target = pmrem.fromScene(room, MODEL_SCENE.environment.sigma);
        room.dispose();
        scene.environment = target.texture;

        return () => {
            target.texture.dispose();
            pmrem.dispose();
            scene.environment = null;
        };
    }, [gl, scene]);

    return null;
}

function Shirt({ surface, url, color, facing, viewRevision }) {
    const gltf = useLoader(GLTFLoader, url);
    const group = useRef();
    const drag = useRef({ active: false, x: 0, y: 0, yaw: 0, pitch: 0 });
    const turned = useRef(false);

    // One model, one material, for the life of the editor: changing colour or
    // side must never reload anything.
    const model = useMemo(() => gltf.scene.clone(true), [gltf]);

    const material = useMemo(() => {
        let found = null;
        model.traverse((node) => {
            if (node.isMesh) {
                node.material = node.material.clone();
                node.material.side = DoubleSide;
                found = node.material;
            }
        });

        return found;
    }, [model]);

    const texture = useMemo(() => {
        const map = new CanvasTexture(surface.atlas);
        map.colorSpace = SRGBColorSpace;
        map.anisotropy = 8;

        return map;
    }, [surface]);

    useEffect(() => {
        if (!material) {
            return undefined;
        }

        material.map = texture;
        material.needsUpdate = true;

        // The canvas tells us when it has repainted; nothing polls.
        surface.onAtlasChange = () => {
            texture.needsUpdate = true;
        };

        return () => {
            surface.onAtlasChange = null;
            texture.dispose();
        };
    }, [material, texture, surface]);

    useEffect(() => {
        if (material && color) {
            material.color.set(color);
        }
    }, [material, color]);

    useEffect(() => {
        turned.current = false;
    }, [facing, viewRevision]);

    useEffect(() => {
        model.updateWorldMatrix(true, true);
        const bounds = new Box3().setFromObject(model);
        model.scale.setScalar(MODEL_HEIGHT / bounds.getSize(new Vector3()).y);
        model.updateWorldMatrix(true, true);
        const scaled = new Box3().setFromObject(model);
        model.position.sub(scaled.getCenter(new Vector3()));
    }, [model]);

    useEffect(() => {
        const el = document.querySelector('.ed-stage canvas');

        if (!el) {
            return undefined;
        }

        const down = (e) => {
            drag.current = {
                active: true,
                x: e.clientX,
                y: e.clientY,
                yaw: group.current?.rotation.y ?? 0,
                pitch: group.current?.rotation.x ?? 0,
            };
            el.setPointerCapture?.(e.pointerId);
        };

        const move = (e) => {
            if (!drag.current.active || !group.current) {
                return;
            }

            group.current.rotation.y = drag.current.yaw + (e.clientX - drag.current.x) * 0.008;
            group.current.rotation.x = MathUtils.clamp(
                drag.current.pitch + (e.clientY - drag.current.y) * 0.005,
                -TILT_LIMIT,
                TILT_LIMIT
            );
        };

        const up = () => {
            drag.current.active = false;
        };

        el.addEventListener('pointerdown', down);
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);

        return () => {
            el.removeEventListener('pointerdown', down);
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        };
    }, []);

    // No idle spin. An editor has to hold still on the side being worked on;
    // rotation is the customer's to ask for, by dragging.
    useFrame(() => {
        if (group.current && facing !== null && !drag.current.active && !turned.current) {
            group.current.rotation.y = facing;
            turned.current = true;
        }
    });

    return (
        <group ref={group}>
            <primitive object={model} />
        </group>
    );
}

export default function Preview() {
    const surface = useEditor((s) => s.surface);
    const config = useEditor((s) => s.config);
    const color = useEditor((s) => s.colorHex());
    const side = useEditor((s) => s.side);
    const viewRevision = useEditor((s) => s.viewRevision);
    const [visible, setVisible] = useState(!document.hidden);

    // A hidden tab renders nothing at all.
    useEffect(() => {
        const onVisibility = () => setVisible(!document.hidden);
        document.addEventListener('visibilitychange', onVisibility);

        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    if (!surface || !config) {
        return null;
    }

    return (
        <div className="ed-stage">
            <Canvas
                dpr={[1, 2]}
                frameloop={visible ? 'always' : 'never'}
                camera={{
                    fov: MODEL_SCENE.camera.fov,
                    near: MODEL_SCENE.camera.near,
                    far: MODEL_SCENE.camera.far,
                    position: MODEL_SCENE.camera.position,
                }}
                // The confirmation card reads this canvas back, and without a
                // preserved buffer the read comes out blank between frames.
                gl={{
                    antialias: true,
                    alpha: true,
                    preserveDrawingBuffer: true,
                    toneMapping: THREE[MODEL_SCENE.renderer.toneMapping],
                    toneMappingExposure: MODEL_SCENE.renderer.toneMappingExposure,
                    outputColorSpace: THREE[MODEL_SCENE.renderer.outputColorSpace],
                }}
            >
                <Environment />
                {MODEL_SCENE.lights.map((light) => light.type === 'DirectionalLight' ? (
                    <directionalLight
                        key={`${light.type}-${light.position.join(',')}`}
                        color={light.color}
                        intensity={light.intensity}
                        position={light.position}
                    />
                ) : (
                    <ambientLight
                        key={light.type}
                        color={light.color}
                        intensity={light.intensity}
                    />
                ))}
                <Suspense fallback={null}>
                    <Shirt
                        surface={surface}
                        url={config.modelUrl}
                        color={color}
                        facing={side === 'back' ? Math.PI : 0}
                        viewRevision={viewRevision}
                    />
                </Suspense>
            </Canvas>
        </div>
    );
}
