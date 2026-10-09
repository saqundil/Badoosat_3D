import { create } from 'zustand';

/**
 * Editor state. Deliberately small: the Fabric canvas owns the artwork, and this
 * holds the choices around it.
 *
 * Nothing here is persisted to the browser. Reloading the page loses the draft,
 * which is the trade the brief asks for — the only durable copy is the one the
 * server has.
 */
export const useEditor = create((set, get) => ({
    config: null,
    surface: null,

    side: 'front',
    viewRevision: 0,
    color: null,
    size: null,
    quantity: 1,

    selection: null,
    canUndo: false,
    canRedo: false,

    /** { level: 'ok' | 'warn' | 'reject', dpi } for the worst bitmap placed. */
    resolution: null,
    opaqueUpload: false,

    status: 'idle',
    error: null,
    step: 'design',

    /** Data URL of the scene, captured before the preview is unmounted. */
    sceneShot: null,

    init(config, surface) {
        const first = config.variants[0] ?? {};

        set({
            config,
            surface,
            color: first.color_id ?? null,
            size: first.size_id ?? null,
            side: surface.side,
        });
    },

    /** The catalogue row for the current choices; the server prices it again. */
    variant() {
        const { config, color, size } = get();

        return config?.variants.find((v) => v.color_id === color && v.size_id === size) ?? null;
    },

    priceCents() {
        const variant = get().variant();

        return variant ? variant.price_cents * get().quantity : 0;
    },

    /** The hex the 3D shirt is tinted with, looked up from the chosen colour. */
    colorHex() {
        const { config, color } = get();

        return config?.colors.find((c) => c.id === color)?.hex ?? '#EFEDE7';
    },

    /*
     * Changing colour can strand the chosen size: not every colour is stocked
     * in every size. Falling back to the first size that colour does have
     * keeps the selection buyable instead of leaving a combination that the
     * cart will refuse on submit.
     */
    setColor(color) {
        const { config, size } = get();
        const sizes = config.variants
            .filter((v) => v.color_id === color && v.stock > 0)
            .map((v) => v.size_id);

        set({ color, size: sizes.includes(size) ? size : (sizes[0] ?? null) });
    },

    setSize: (size) => set({ size }),
    setQuantity: (quantity) => set({ quantity: Math.min(200, Math.max(1, quantity | 0)) }),
    setStep: (step) => set({ step }),
    setSceneShot: (sceneShot) => set({ sceneShot }),
    setError: (error) => set({ error }),
    setStatus: (status) => set({ status }),
    setOpaqueUpload: (opaqueUpload) => set({ opaqueUpload }),

    async setSide(side) {
        const { surface } = get();
        await surface.setSide(side);
        set((state) => ({ side, selection: null, viewRevision: state.viewRevision + 1 }));
        get().sync();
    },

    /** Pulls whatever the canvas knows back into the store. */
    sync() {
        const { surface } = get();

        if (!surface) {
            return;
        }

        set({
            selection: surface.active(),
            canUndo: surface.canUndo(),
            canRedo: surface.canRedo(),
            resolution: surface.worstResolution(),
        });
    },

    /** Nothing may be ordered while a placed bitmap is below the floor. */
    blocked() {
        const { resolution, surface } = get();

        if (surface?.isEmpty()) {
            return 'ضع تصميمًا أو نصًّا قبل المتابعة.';
        }

        if (resolution?.level === 'reject') {
            return 'دقّة إحدى الصور منخفضة جدًا للطباعة. استبدلها أو صغّر حجمها.';
        }

        if (!get().variant()) {
            return 'اختر اللون والمقاس.';
        }

        return null;
    },
}));
