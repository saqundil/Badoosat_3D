/*
 * Reordering the hero slides.
 *
 * Two ways in, one save. The move buttons work from the keyboard and on a
 * phone; dragging a row is the shortcut for a mouse. Either way the whole
 * order is posted (save.php, action "reorder") and a toast says what happened
 * -- the old list saved silently, which left no way to tell a saved order from
 * a lost one.
 *
 * No sentence lives here: every message comes from a data-* attribute.
 */

const list = document.querySelector('[data-reorder]');
const body = list?.querySelector('tbody');

if (list && body) {
    const endpoint = list.dataset.endpoint;
    const token = list.dataset.token;
    const numbers = new Intl.NumberFormat((document.documentElement.lang || 'ar') + '-u-nu-latn');
    let dragged = null;

    const rows = () => [...body.querySelectorAll('[data-row]')];

    /* The visible position, and which buttons have somewhere to go. */
    const renumber = () => {
        const all = rows();

        all.forEach((row, index) => {
            const n = row.querySelector('.reorder__n');
            if (n) {
                n.textContent = numbers.format(index + 1);
            }
            row.querySelector('[data-move="up"]')?.toggleAttribute('disabled', index === 0);
            row.querySelector('[data-move="down"]')?.toggleAttribute('disabled', index === all.length - 1);
        });
    };

    const save = async () => {
        const ids = rows().map((row) => Number(row.dataset.row));

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, Accept: 'application/json' },
                body: JSON.stringify({ action: 'reorder', ids }),
            });

            if (!response.ok) {
                throw new Error(list.dataset.msgFailed);
            }

            window.adminToast?.(list.dataset.msgSaved);
        } catch (error) {
            window.adminToast?.(error.message || list.dataset.msgFailed, 'error');
            // The order on screen is no longer the order that is stored.
            setTimeout(() => location.reload(), 1500);
        }
    };

    /* ----------------------------------------------------- the move buttons */

    list.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-move]') : null;

        if (!button || button.disabled) {
            return;
        }

        const row = button.closest('[data-row]');
        const up = button.dataset.move === 'up';
        const sibling = up ? row.previousElementSibling : row.nextElementSibling;

        if (!sibling) {
            return;
        }

        if (up) {
            body.insertBefore(row, sibling);
        } else {
            body.insertBefore(sibling, row);
        }

        renumber();
        // The row moved under the pointer; keep the key that moved it focused.
        row.querySelector('[data-move="' + button.dataset.move + '"]')?.focus();
        announce(row);
        save();
    });

    /* A live region would be one more thing on the page; the toast is already
       polite, and it is where the save's own answer appears. */
    const announce = (row) => {
        const at = rows().indexOf(row) + 1;
        const name = row.querySelector('.cell-media__title')?.textContent.trim() ?? '';
        window.adminToast?.(list.dataset.msgMoved
            .replace(':name', name)
            .replace(':n', numbers.format(at))
            .replace(':total', numbers.format(rows().length)));
    };

    /* ---------------------------------------------------------- dragging */

    for (const row of rows()) {
        row.draggable = true;
    }

    list.addEventListener('dragstart', (event) => {
        dragged = event.target.closest('[data-row]');

        if (!dragged) {
            return;
        }

        dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Firefox will not start a drag without payload.
        event.dataTransfer.setData('text/plain', dragged.dataset.row);
    });

    list.addEventListener('dragover', (event) => {
        if (!dragged) {
            return;
        }

        event.preventDefault();

        const over = event.target instanceof Element ? event.target.closest('[data-row]') : null;

        if (!over || over === dragged) {
            return;
        }

        const box = over.getBoundingClientRect();
        body.insertBefore(dragged, event.clientY > box.top + box.height / 2 ? over.nextSibling : over);
    });

    list.addEventListener('dragend', () => {
        if (!dragged) {
            return;
        }

        dragged.classList.remove('is-dragging');
        dragged = null;
        renumber();
        save();
    });
}
