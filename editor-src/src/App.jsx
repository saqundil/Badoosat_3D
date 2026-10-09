import { Suspense, lazy, useCallback, useEffect, useRef, useState } from 'react';

import Confirm from './Confirm.jsx';
import Toolbar from './Toolbar.jsx';
import { ColourRow, QuantityRow, SizeRow } from './Panels.jsx';
import { downscale, inspectImage } from './image.js';
import { snapshotScene } from './snapshot.js';
import { useEditor } from './store.js';

/*
 * three.js is 186 kB gzipped of the island's 362, and none of it is needed to
 * put a design on the shirt — only to show the shirt. Splitting it off the
 * critical path is what brings the wait between the click and the first stroke
 * from 2.8 s down under one: the design canvas opens on the bytes that have
 * arrived, and the scene fills in behind it a moment later.
 */
const Preview = lazy(() => import('./Preview.jsx'));

const TABS = [
    ['color', 'اللون'],
    ['design', 'التصميم'],
    ['size', 'المقاس'],
];

const PATTERN_PATHS = {
    front: 'M4.04 143.00 L27.44 140.83 L33.45 140.87 L39.88 140.28 L46.55 140.58 L53.17 140.15 L85.38 141.72 L91.53 142.22 L97.45 143.00 L98.36 129.67 L98.61 113.31 L96.34 76.76 L96.30 70.51 L97.01 64.85 L100.00 55.67 L97.97 55.15 L95.94 54.16 L92.29 51.36 L90.80 49.59 L89.61 47.61 L88.72 45.45 L88.11 43.13 L87.81 40.71 L88.03 35.90 L89.26 31.12 L90.71 27.84 L92.04 25.57 L97.77 17.78 L88.94 10.46 L81.93 3.25 L79.72 1.47 L77.21 0.00 L72.06 9.29 L68.27 14.84 L66.34 17.06 L64.38 18.93 L60.57 21.56 L56.77 23.20 L54.80 23.76 L50.80 24.30 L46.67 23.95 L42.52 22.82 L38.32 20.75 L34.30 17.55 L32.33 15.48 L28.35 10.24 L22.39 1.09 L19.79 3.16 L17.72 5.27 L10.98 13.19 L3.67 19.73 L7.36 24.98 L10.27 30.09 L12.07 34.90 L12.54 37.22 L12.70 39.83 L12.55 42.45 L11.99 45.19 L11.05 47.86 L9.69 50.22 L8.04 52.33 L5.82 54.11 L3.05 55.58 L0.00 56.44 L0.08 59.69 L0.43 62.01 L3.34 73.69 L3.83 76.91 L4.37 83.39 L4.37 95.49 L3.36 110.75 L3.01 123.46 L3.23 133.11 L4.04 143.00 Z',
    back: 'M1.62 143.00 L0.28 100.38 L0.24 74.99 L2.46 59.21 L2.10 53.51 L3.99 52.73 L6.71 50.57 L8.06 48.12 L8.83 41.55 L8.82 34.29 L7.90 24.12 L4.19 7.68 L11.07 5.61 L25.02 3.14 L28.16 1.95 L31.16 0.00 L33.76 2.25 L36.93 3.89 L42.82 5.43 L48.07 5.82 L58.59 4.89 L63.76 2.70 L66.33 0.75 L71.72 4.00 L85.26 7.01 L93.88 9.96 L90.62 26.93 L89.65 41.34 L90.11 44.62 L92.24 49.97 L95.53 53.08 L97.65 53.59 L97.72 57.06 L99.69 66.25 L100.01 72.45 L98.71 100.45 L98.61 132.92 L98.12 143.00 L59.90 141.97 L1.62 143.00 Z',
};

function useIsNarrow() {
    const [narrow, setNarrow] = useState(() => window.matchMedia('(max-width: 900px)').matches);

    useEffect(() => {
        const query = window.matchMedia('(max-width: 900px)');
        const update = () => setNarrow(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return narrow;
}

function money(cents) {
    return (cents / 100).toFixed(2) + ' د.أ';
}

export default function App({ onClose }) {
    const config = useEditor((s) => s.config);
    const surface = useEditor((s) => s.surface);
    const side = useEditor((s) => s.side);
    const resolution = useEditor((s) => s.resolution);
    const opaqueUpload = useEditor((s) => s.opaqueUpload);
    const status = useEditor((s) => s.status);
    const error = useEditor((s) => s.error);
    const step = useEditor((s) => s.step);
    const sync = useEditor((s) => s.sync);

    const canvasRef = useRef(null);
    const fileRef = useRef(null);
    const narrow = useIsNarrow();
    const [tab, setTab] = useState('design');
    const [busy, setBusy] = useState(false);

    /* --------------------------------------------------- mount the canvas */

    useEffect(() => {
        if (!surface || !canvasRef.current) {
            return undefined;
        }

        surface.attach(canvasRef.current);
        const off = surface.subscribe(() => sync());
        sync();

        /*
         * The point the customer can actually start work: the chrome is painted
         * and the design canvas will take a drag. The launcher keeps its loading
         * state up until this fires, because the bundle finishing its download
         * is a good deal earlier than this and the gap between the two is
         * exactly where a blank screen would otherwise sit.
         */
        const painted = requestAnimationFrame(() => {
            document.dispatchEvent(new CustomEvent('tshirt-editor:ready'));
        });

        return () => {
            cancelAnimationFrame(painted);
            off();
            surface.dispose();
        };
    }, [surface, sync]);

    /* ------------------------------------------------------- the keyboard */

    useEffect(() => {
        const onKey = (event) => {
            const key = event.key.toLowerCase();

            if ((event.ctrlKey || event.metaKey) && key === 'z') {
                event.preventDefault();
                (event.shiftKey ? surface.redo() : surface.undo()).then(sync);
            }

            if (key === 'delete' || key === 'backspace') {
                const tag = document.activeElement?.tagName;

                if (tag !== 'INPUT' && tag !== 'TEXTAREA') {
                    event.preventDefault();
                    surface.remove();
                }
            }

            if (key === 'escape') {
                onClose?.();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [surface, sync, onClose]);

    /* ---------------------------------------------------------- uploading */

    const onFile = useCallback(async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        setBusy(true);
        useEditor.getState().setError(null);

        try {
            const info = await inspectImage(file, config.maxUploadMb * 1024 * 1024);

            // The preview needs no more pixels than the working canvas has; the
            // untouched file is what goes to the server for the press.
            const preview = await downscale(info.url, config.work.w * 2, config.work.h * 2);

            await surface.addImage(preview, { width: info.width, height: info.height });
            surface.lastUpload = { file, ...info };

            useEditor.getState().setOpaqueUpload(info.opaque);
            sync();
        } catch (e) {
            useEditor.getState().setError(e.message);
        } finally {
            setBusy(false);
        }
    }, [config, surface, sync]);

    if (!config) {
        return null;
    }

    const blocked = useEditor.getState().blocked();
    const price = useEditor.getState().priceCents();

    const designPanel = (
        <>
            <Toolbar
                onUpload={() => fileRef.current?.click()}
                busy={busy}
            />

            <div className="ed-canvas-wrap">
                <div
                    className="ed-canvas-frame"
                    style={{ aspectRatio: config.work.w + ' / ' + config.work.h }}
                >
                    <div className="ed-pattern-guide" aria-hidden="true">
                        <svg className="ed-pattern-guide__panel" viewBox="0 0 100 143"
                             preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="pattern-zones" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stopColor="#d95e64" stopOpacity="0.18" />
                                    <stop offset="0.24" stopColor="#d95e64" stopOpacity="0.18" />
                                    <stop offset="0.24" stopColor="#2aa89c" stopOpacity="0.16" />
                                    <stop offset="0.61" stopColor="#2aa89c" stopOpacity="0.16" />
                                    <stop offset="0.61" stopColor="#2874d8" stopOpacity="0.14" />
                                    <stop offset="1" stopColor="#2874d8" stopOpacity="0.14" />
                                </linearGradient>
                            </defs>
                            <path
                                d={PATTERN_PATHS[side]}
                                fill="url(#pattern-zones)"
                                stroke="rgba(91, 87, 80, 0.48)"
                                strokeWidth="0.8"
                                vectorEffect="non-scaling-stroke"
                            />
                        </svg>
                        <span className="ed-pattern-guide__label ed-pattern-guide__label--shoulders">
                            الكتفان
                        </span>
                        <span className="ed-pattern-guide__label ed-pattern-guide__label--chest">
                            الصدر
                        </span>
                        <span className="ed-pattern-guide__label ed-pattern-guide__label--torso">
                            الجذع
                        </span>
                        <span className="ed-pattern-guide__side">
                            {side === 'front' ? 'الواجهة الأمامية' : 'الواجهة الخلفية'}
                        </span>
                    </div>
                    <canvas ref={canvasRef} className="ed-canvas" />
                </div>
                <p className="ed-hint">
                    الإطار المتقطّع هو مساحة الطباعة: {config.printSizeCm.w}×{config.printSizeCm.h} سم.
                    ما يخرج عنه لا يُطبع.
                </p>
            </div>
        </>
    );

    return (
        <div className={'ed-root' + (narrow ? ' ed-root--narrow' : '')} dir="rtl">
            <header className="ed-bar">
                <div className="ed-bar__steps" role="progressbar" aria-valuemin={1} aria-valuemax={2}
                     aria-valuenow={step === 'design' ? 1 : 2} aria-label="تقدّم التصميم">
                    <span className={'ed-bar__step' + (step === 'design' ? ' is-on' : '')} />
                    <span className={'ed-bar__step' + (step === 'confirm' ? ' is-on' : '')} />
                </div>

                <div className="ed-bar__sides" role="group" aria-label="وجه القميص">
                    {config.sides.map((s) => (
                        <button
                            key={s}
                            type="button"
                            className={'ed-chip' + (side === s ? ' is-on' : '')}
                            aria-pressed={side === s}
                            onClick={() => useEditor.getState().setSide(s)}
                        >
                            {s === 'front' ? 'الأمام' : 'الخلف'}
                        </button>
                    ))}
                </div>

                <button type="button" className="ed-close" onClick={onClose} aria-label="إغلاق المحرّر">
                    ✕
                </button>
            </header>

            {step === 'confirm' ? (
                <Confirm onBack={() => useEditor.getState().setStep('design')} />
            ) : (
                <div className="ed-body">
                    <Suspense fallback={
                        <div className="ed-stage ed-stage--waiting">
                            <p>جارٍ تحميل المعاينة ثلاثية الأبعاد…</p>
                        </div>
                    }>
                        <Preview />
                    </Suspense>

                    {narrow ? (
                        <div className="ed-sheet">
                            <div className="ed-sheet__tabs" role="tablist">
                                {TABS.map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        role="tab"
                                        aria-selected={tab === key}
                                        className={'ed-chip' + (tab === key ? ' is-on' : '')}
                                        onClick={() => setTab(key)}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>

                            <div className="ed-sheet__body">
                                {tab === 'color' && <ColourRow />}
                                {tab === 'design' && designPanel}
                                {tab === 'size' && (
                                    <>
                                        <SizeRow />
                                        <QuantityRow />
                                    </>
                                )}
                            </div>
                        </div>
                    ) : (
                        <aside className="ed-panel">
                            {designPanel}
                            <ColourRow />
                            <SizeRow />
                            <QuantityRow />
                        </aside>
                    )}
                </div>
            )}

            <footer className="ed-foot">
                <div className="ed-foot__notes" role="status" aria-live="polite">
                    {resolution?.level === 'warn' && (
                        <p className="ed-note ed-note--warn">
                            الصورة {resolution.dpi} نقطة/بوصة عند {config.printSizeCm.w}×
                            {config.printSizeCm.h} سم — قد تظهر مشوّشة عند الطباعة.
                        </p>
                    )}
                    {resolution?.level === 'reject' && (
                        <p className="ed-note ed-note--stop">
                            الصورة {resolution.dpi} نقطة/بوصة — منخفضة جدًا للطباعة. استبدلها أو صغّر حجمها.
                        </p>
                    )}
                    {opaqueUpload && (
                        <p className="ed-note ed-note--warn">
                            الصورة بلا خلفية شفافة، وسيُطبع الأبيض أبيضَ حول التصميم.
                            استخدم PNG بخلفية شفافة لتفادي ذلك.
                        </p>
                    )}
                    {error && <p className="ed-note ed-note--stop">{error}</p>}
                    {config.returnsNotice && (
                        <p className="ed-note ed-note--plain">{config.returnsNotice}</p>
                    )}
                </div>

                {/* The confirmation card carries its own actions; two primary
                    buttons on screen at once is one too many. */}
                <div className="ed-foot__buy" hidden={step === 'confirm'}>
                    <strong className="ed-price">{money(price)}</strong>
                    <button
                        type="button"
                        className="ed-primary"
                        disabled={Boolean(blocked) || busy || status === 'saving'}
                        title={blocked ?? ''}
                        onClick={() => {
                            // The scene is about to be unmounted, so photograph
                            // it now rather than from the card that replaces it.
                            useEditor.getState().setSceneShot(snapshotScene());
                            useEditor.getState().setStep('confirm');
                        }}
                    >
                        {blocked ?? 'مراجعة التصميم'}
                    </button>
                </div>
            </footer>

            <input
                ref={fileRef}
                type="file"
                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                onChange={onFile}
                hidden
            />
        </div>
    );
}
