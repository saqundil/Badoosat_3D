/*
 * The data table's behaviour (src/View/admin/data-table.php).
 *
 *   button[data-sort-by]   sorts the rows on the page by each cell's data-sort
 *                          value (or its text), and says so with aria-sort.
 *                          Server-sorted tables render links instead and this
 *                          file leaves them alone.
 *   [data-selectable]      row checkboxes, the select-all box (with its
 *                          "some" state) and the bulk bar's count.
 *   details.row-menu       closes when focus or a click goes elsewhere, and on Escape.
 */

const lang = document.documentElement.lang || 'ar';
const collator = new Intl.Collator(lang, { numeric: true, sensitivity: 'base' });
const numbers = new Intl.NumberFormat(lang + '-u-nu-latn');

for (const table of document.querySelectorAll('[data-table]')) {
    const tbody = table.querySelector('tbody');

    /* ---------------------------------------------------------------- sort */

    table.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('button[data-sort-by]') : null;

        if (!button || !tbody) {
            return;
        }

        const th = button.closest('th');
        const key = button.dataset.sortBy;
        const dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        const index = [...th.parentElement.children].indexOf(th);
        const value = (row) => {
            const cell = row.children[index];
            return cell.dataset.sort ?? cell.textContent.trim();
        };

        const rows = [...tbody.rows].sort((a, b) => {
            const x = value(a);
            const y = value(b);
            const both = x !== '' && y !== '' && !Number.isNaN(Number(x)) && !Number.isNaN(Number(y));
            const order = both ? Number(x) - Number(y) : collator.compare(x, y);
            return dir === 'ascending' ? order : -order;
        });

        for (const other of table.querySelectorAll('th[aria-sort]')) {
            other.removeAttribute('aria-sort');
        }
        th.setAttribute('aria-sort', dir);
        tbody.append(...rows);
        table.dataset.sortedBy = key;
    });

    /* ----------------------------------------------------------- selection */

    if (table.hasAttribute('data-selectable')) {
        const all = table.querySelector('[data-select-all]');
        const bar = table.querySelector('[data-bulk-bar]');
        const count = table.querySelector('[data-bulk-count]');
        const boxes = () => [...table.querySelectorAll('[data-select-row]')];

        const update = () => {
            const list = boxes();
            const chosen = list.filter((box) => box.checked).length;

            all.checked = chosen > 0 && chosen === list.length;
            all.indeterminate = chosen > 0 && chosen < list.length;
            bar.hidden = chosen === 0;
            count.textContent = numbers.format(chosen);
            for (const box of list) {
                box.closest('tr').classList.toggle('is-selected', box.checked);
            }
        };

        all?.addEventListener('change', () => {
            for (const box of boxes()) {
                box.checked = all.checked;
            }
            update();
        });
        table.addEventListener('change', (event) => {
            if (event.target instanceof Element && event.target.matches('[data-select-row]')) {
                update();
            }
        });
        table.querySelector('[data-bulk-clear]')?.addEventListener('click', () => {
            for (const box of boxes()) {
                box.checked = false;
            }
            update();
            all?.focus();
        });
        update();
    }
}

/* ---------------------------------------------------------------- row menus */

const closeMenus = (except) => {
    for (const menu of document.querySelectorAll('details.row-menu[open]')) {
        if (menu !== except) {
            menu.removeAttribute('open');
        }
    }
};

document.addEventListener('click', (event) => {
    const inside = event.target instanceof Element ? event.target.closest('details.row-menu') : null;
    closeMenus(inside);
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    const open = document.querySelector('details.row-menu[open]');
    if (open) {
        open.removeAttribute('open');
        open.querySelector('summary').focus();
    }
});

document.addEventListener('focusin', (event) => {
    const inside = event.target instanceof Element ? event.target.closest('details.row-menu') : null;
    closeMenus(inside);
});
