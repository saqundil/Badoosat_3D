/*
 * The homepage screen (public/admin/home.php).
 *
 *   [data-promo]      a banner field; what it says appears in the card beside it
 *   [data-promo-out]  where that text is shown, falling back to the placeholder
 *                     exactly as the live page falls back to its default
 *   [data-trending]   the picker: counts the ticks and says when eight is reached
 *
 * No sentence lives here: the one message comes from a data-* attribute.
 */

/* ------------------------------------------------------- the banner preview */

const card = document.querySelector('[data-promo-card]');

if (card) {
    for (const field of document.querySelectorAll('[data-promo]')) {
        const out = card.querySelector('[data-promo-out="' + field.dataset.promo + '"] bdi');

        if (!out) {
            continue;
        }

        const show = () => {
            // Empty means the default, which is what the field's placeholder
            // already says and what the live page will print.
            out.textContent = field.value.trim() === '' ? field.placeholder : field.value;
        };

        field.addEventListener('input', show);
        show();
    }
}

/* ------------------------------------------------------- the trending list */

const list = document.querySelector('[data-trending]');

if (list) {
    const LIMIT = 8;
    const boxes = [...list.querySelectorAll('input[type="checkbox"]')];
    let warned = false;

    const sync = () => {
        const chosen = boxes.filter((box) => box.checked);

        for (const box of boxes) {
            box.closest('.trending-row').classList.toggle('is-on', box.checked);
            // Over the limit the save is refused, so the ticks that would take
            // it there are closed off rather than offered and then rejected.
            box.disabled = !box.checked && chosen.length >= LIMIT;
        }

        if (chosen.length >= LIMIT && !warned) {
            warned = true;
            window.adminToast?.(list.dataset.msgLimit);
        }
        if (chosen.length < LIMIT) {
            warned = false;
        }
    };

    list.addEventListener('change', sync);
    sync();
}
