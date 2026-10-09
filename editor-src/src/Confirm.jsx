import { useEffect, useState } from 'react';

import { dataUrlToBlob } from './image.js';
import { useEditor } from './store.js';

/**
 * The agreement card.
 *
 * Two pictures, because they answer different questions: the scene shows how it
 * will look worn, and the flat file shows exactly what goes to the press. A
 * customer who has seen both cannot be surprised by the parcel.
 */
const SIDE_LABELS = { front: 'الأمام', back: 'الخلف' };

export default function Confirm({ onBack }) {
    const config = useEditor((s) => s.config);
    const surface = useEditor((s) => s.surface);
    const status = useEditor((s) => s.status);
    const error = useEditor((s) => s.error);
    const sceneShot = useEditor((s) => s.sceneShot);

    /*
     * One press file per designed side, front first. The placement is read from
     * the design, not from the side on screen: a customer who designed the front
     * and then the back gets both printed.
     */
    const [sides] = useState(() => surface.designedSides());
    const placement = sides.length === 2 ? 'both' : sides[0];

    const [shots, setShots] = useState({ scene: null, prints: null });

    useEffect(() => {
        let live = true;

        Promise.all(sides.map((side) => surface.printDataUrl(side).then((url) => ({ side, url }))))
            .then((prints) => {
                if (live) {
                    setShots({ scene: sceneShot, prints });
                }
            });

        return () => {
            live = false;
        };
    }, [surface, sceneShot, sides]);

    const submit = async () => {
        const state = useEditor.getState();
        state.setStatus('saving');
        state.setError(null);

        try {
            const body = new FormData();
            body.append('_token', config.csrfToken);
            body.append('action', 'save');
            body.append('product_id', String(config.productId));
            body.append('placement', placement);
            body.append('quantity', String(state.quantity));
            body.append('variant_id', state.variant()?.id ?? '');
            body.append('design_json', JSON.stringify(surface.toJSON()));
            // The front -- or the one side printed -- then the back when both are.
            body.append('print_png', dataUrlToBlob(shots.prints[0].url), 'print.png');

            if (shots.prints[1]) {
                body.append('print_png_back', dataUrlToBlob(shots.prints[1].url), 'print-back.png');
            }

            if (shots.scene) {
                body.append('preview_png', dataUrlToBlob(shots.scene), 'preview.png');
            }

            // The original upload, full resolution, is what the press file is
            // built from; the canvas copy was only ever for the screen.
            if (surface.lastUpload?.file) {
                body.append('source', surface.lastUpload.file, surface.lastUpload.file.name);
            }

            const response = await fetch(config.endpoint, {
                method: 'POST',
                body,
                headers: { 'X-CSRF-Token': config.csrfToken, Accept: 'application/json' },
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error ?? 'تعذّر حفظ التصميم.');
            }

            // Adding to the cart is an ordinary form post, and the server prices
            // it from the catalogue: nothing about money travels from here.
            const form = document.createElement('form');
            form.method = 'post';
            form.action = '/cart.php';
            form.hidden = true;

            const fields = {
                _token: config.csrfToken,
                action: 'add',
                item_type: 'custom',
                product_id: config.productId,
                color_id: state.color,
                size_id: state.size,
                placement,
                quantity: state.quantity,
                custom_token: result.design_token,
            };

            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = String(value);
                form.append(input);
            }

            document.body.append(form);
            form.submit();
        } catch (e) {
            state.setError(e.message);
            state.setStatus('idle');
        }
    };

    return (
        <div className="ed-confirm">
            <h2>راجع تصميمك قبل الإضافة</h2>

            <div className="ed-confirm__grid">
                <figure>
                    <div className="ed-confirm__shot">
                        {shots.scene && <img src={shots.scene} alt="معاينة القميص بالتصميم" />}
                    </div>
                    <figcaption>كما سيبدو على القميص</figcaption>
                </figure>

                {(shots.prints ?? sides.map((side) => ({ side, url: null }))).map(({ side, url }) => (
                    <figure key={side}>
                        <div className="ed-confirm__shot ed-confirm__shot--flat">
                            {url && <img src={url} alt={'ملف الطباعة المسطّح — ' + SIDE_LABELS[side]} />}
                        </div>
                        <figcaption>
                            ملف الطباعة ({SIDE_LABELS[side]}) — {config.printSizeCm.w}×{config.printSizeCm.h} سم عند{' '}
                            {config.dpi.target} نقطة/بوصة ({config.exportPx.w}×{config.exportPx.h} بكسل)
                        </figcaption>
                    </figure>
                ))}
            </div>

            {config.returnsNotice && <p className="ed-note ed-note--plain">{config.returnsNotice}</p>}
            {error && <p className="ed-note ed-note--stop">{error}</p>}

            <div className="ed-confirm__buttons">
                <button type="button" className="ed-tool" onClick={onBack}>
                    رجوع للتحرير
                </button>
                <button
                    type="button"
                    className="ed-primary"
                    onClick={submit}
                    disabled={status === 'saving' || !shots.prints}
                >
                    {status === 'saving' ? 'جارٍ الحفظ…' : 'أضف إلى السلة'}
                </button>
            </div>
        </div>
    );
}
