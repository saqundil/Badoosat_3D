import { useEditor } from './store.js';

/*
 * Colours and sizes are catalogue rows now, not the bare hex and letter the
 * variant list used to carry. The island reads their ids -- which is what the
 * cart and the order will be written with -- and shows their names, so a
 * customer picks "أزرق ليلي" rather than #3A5A78 and the shop can rename a
 * colour without breaking a saved design.
 */

/** Colours this product actually has a live variant in. */
function coloursOf(config) {
    const live = new Set(config.variants.filter((v) => v.stock > 0).map((v) => v.color_id));

    return config.colors.filter((c) => live.has(c.id));
}

/** Sizes available in the chosen colour, so a dead combination cannot be picked. */
function sizesOf(config, colorId) {
    const live = new Set(
        config.variants.filter((v) => v.color_id === colorId && v.stock > 0).map((v) => v.size_id)
    );

    return config.sizes.filter((s) => live.has(s.id));
}

export function ColourRow() {
    const config = useEditor((s) => s.config);
    const colour = useEditor((s) => s.color);
    const setColor = useEditor((s) => s.setColor);

    return (
        <fieldset className="ed-field">
            <legend>لون القميص</legend>
            <div className="ed-swatches">
                {coloursOf(config).map((item) => (
                    <label key={item.id} className="ed-swatch">
                        <input
                            type="radio"
                            name="ed-colour"
                            value={item.id}
                            checked={colour === item.id}
                            onChange={() => setColor(item.id)}
                        />
                        <span className="ed-swatch__dot" style={{ background: item.hex }} />
                        <span className="ed-sr">{item.name}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export function SizeRow() {
    const config = useEditor((s) => s.config);
    const colour = useEditor((s) => s.color);
    const size = useEditor((s) => s.size);
    const setSize = useEditor((s) => s.setSize);

    return (
        <fieldset className="ed-field">
            <legend>المقاس</legend>
            <div className="ed-chips">
                {sizesOf(config, colour).map((item) => (
                    <label key={item.id} className="ed-chip-label">
                        <input
                            type="radio"
                            name="ed-size"
                            value={item.id}
                            checked={size === item.id}
                            onChange={() => setSize(item.id)}
                        />
                        <span className="ed-chip" title={item.label}>{item.code}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export function QuantityRow() {
    const quantity = useEditor((s) => s.quantity);
    const setQuantity = useEditor((s) => s.setQuantity);

    return (
        <fieldset className="ed-field">
            <legend>الكمية</legend>
            <div className="ed-qty">
                <button type="button" className="ed-tool" aria-label="إنقاص الكمية"
                        onClick={() => setQuantity(quantity - 1)}>−</button>
                <input
                    type="number"
                    min="1"
                    max="200"
                    aria-label="الكمية"
                    value={quantity}
                    onChange={(event) => setQuantity(Number(event.target.value))}
                />
                <button type="button" className="ed-tool" aria-label="زيادة الكمية"
                        onClick={() => setQuantity(quantity + 1)}>+</button>
            </div>
        </fieldset>
    );
}
