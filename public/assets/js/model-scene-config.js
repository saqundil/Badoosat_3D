export const MODEL_SCENE = Object.freeze({
    renderer: Object.freeze({
        toneMapping: 'ACESFilmicToneMapping',
        toneMappingExposure: 1.05,
        outputColorSpace: 'SRGBColorSpace',
    }),
    camera: Object.freeze({
        fov: 32,
        near: 0.1,
        far: 100,
        position: Object.freeze([0, 0, 3.1]),
        referenceAspect: 1.6,
    }),
    modelHeight: 1.45,
    environment: Object.freeze({
        type: 'RoomEnvironment',
        sigma: 0.06,
    }),
    lights: Object.freeze([
        Object.freeze({
            type: 'DirectionalLight',
            color: 0xffffff,
            intensity: 2.1,
            position: Object.freeze([-2.2, 2.4, 3.2]),
        }),
        Object.freeze({
            type: 'DirectionalLight',
            color: 0xffffff,
            intensity: 1.4,
            position: Object.freeze([2.6, 0.6, -2.4]),
        }),
        Object.freeze({
            type: 'AmbientLight',
            color: 0xffffff,
            intensity: 0.35,
        }),
    ]),
});