/*
 * The dashboard's form system: what plain HTML forms cannot do on their own.
 * Every form posts and validates without this file; it only adds feedback.
 *
 *   form[data-watch]            tracks unsaved changes: shows its
 *                               [data-save-bar="<form id>"], warns before leaving,
 *                               and [data-discard] puts every control back.
 *   [data-count-for]            live character counter for a field with maxlength.
 *   [data-error-summary]        takes focus on load; its links focus the control.
 *   [data-uncheck-confirm]      asks in a <dialog> before a box is unticked.
 *   [data-dialog-open="<id>"]   opens <dialog id> instead of submitting; the
 *                               dialog's own button submits (see form/dialog.php).
 *   [data-toasts]               toast region; window.adminToast(text, kind) adds one.
 *   [data-dropzone]             preview, drag-and-drop, paste, size warnings.
 *   [data-stock-matrix]         keeps row, column and grand totals current.
 *   [data-settings-index]       marks the section in view in the index.
 *
 * No user-facing string lives here: every sentence arrives in a data-* attribute
 * written by PHP from the lang files.
 */

const lang = document.documentElement.lang || 'ar';
const numbers = new Intl.NumberFormat(lang + '-u-nu-latn');

/* ------------------------------------------------------------ unsaved changes */

const snapshot = (form) => {
    const parts = [];

    for (const el of form.elements) {
        if (!el.name || el.disabled || el.type === 'submit' || el.type === 'button') {
            continue;
        }
        if (el.type === 'checkbox' || el.type === 'radio') {
            parts.push(el.name + '=' + (el.checked ? el.value : ''));
        } else if (el.type === 'file') {
            parts.push(el.name + '=' + [...el.files].map((f) => f.name + f.size).join('|'));
        } else {
            parts.push(el.name + '=' + el.value);
        }
    }

    return parts.join('&');
};

const watched = new Map();

const refresh = (form) => {
    const dirty = snapshot(form) !== watched.get(form);
    // getAttribute, not form.id: a form with a field named "id" (every edit
    // form posts one) answers form.id with that field.
    const bar = document.querySelector('[data-save-bar="' + form.getAttribute('id') + '"]');

    form.toggleAttribute('data-dirty', dirty);
    if (bar) {
        bar.hidden = !dirty;
    }
};

for (const form of document.querySelectorAll('form[data-watch]')) {
    watched.set(form, snapshot(form));
    form.addEventListener('input', () => refresh(form));
    form.addEventListener('change', () => refresh(form));
    form.addEventListener('submit', () => {
        // A form on its way to the server has nothing left to lose.
        watched.set(form, snapshot(form));
        form.removeAttribute('data-dirty');
    });
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-discard]') : null;
    const form = button && document.getElementById(button.dataset.discard);

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    form.reset();
    // reset() leaves a hidden field's value alone; the ones script changed
    // remember what they started as.
    for (const el of form.querySelectorAll('input[type="hidden"][data-initial]')) {
        el.value = el.dataset.initial;
    }
    // reset() fires no input events; the counters, previews and totals listen for them.
    for (const el of form.elements) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }
    for (const zone of form.querySelectorAll('[data-dropzone]')) {
        resetDropzone(zone);
    }
    refresh(form);
});

window.addEventListener('beforeunload', (event) => {
    for (const form of watched.keys()) {
        if (form.hasAttribute('data-dirty')) {
            event.preventDefault();
            return;
        }
    }
});

/* ---------------------------------------------------------- busy on submit */

// The button that sent the form shows it is working, and a second press of
// any submit button of that form is ignored until the page changes.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
        return;
    }
    if (form.dataset.busy) {
        event.preventDefault();
        return;
    }
    form.dataset.busy = '1';
    const button = event.submitter;
    if (button instanceof HTMLElement) {
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
    }
});

// A page restored from the back/forward cache is not still sending.
window.addEventListener('pageshow', () => {
    for (const form of document.querySelectorAll('form[data-busy]')) {
        delete form.dataset.busy;
    }
    for (const button of document.querySelectorAll('.is-busy')) {
        button.classList.remove('is-busy');
        button.removeAttribute('aria-busy');
    }
});

/* ------------------------------------------------------------------ counters */

const counters = document.querySelectorAll('[data-count-for]');

for (const counter of counters) {
    const field = document.getElementById(counter.dataset.countFor);
    const out = counter.querySelector('[data-count]');

    if (field && out) {
        const update = () => {
            out.textContent = numbers.format(field.value.length);
        };
        field.addEventListener('input', update);
        update();
    }
}

/* ------------------------------------------------------------- error summary */

const summary = document.querySelector('[data-error-summary]');

if (summary) {
    // After load and a frame: a #fragment in the URL is scrolled to after the
    // scripts run, and landing on it would otherwise take the focus away again.
    const take = () => requestAnimationFrame(() => {
        summary.scrollIntoView({ block: 'center' });
        summary.focus({ preventScroll: true });
    });
    if (document.readyState === 'complete') {
        take();
    } else {
        window.addEventListener('load', take, { once: true });
    }

    summary.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
        const target = link && document.getElementById(link.getAttribute('href').slice(1));

        if (target) {
            event.preventDefault();
            target.closest('details')?.setAttribute('open', '');
            target.scrollIntoView({ block: 'center' });
            target.focus({ preventScroll: true });
        }
    });
}

/* ------------------------------------------------------------------- dialogs */

document.addEventListener('click', (event) => {
    const el = event.target instanceof Element ? event.target : null;
    const opener = el?.closest('[data-dialog-open]');

    if (opener) {
        const dialog = document.getElementById(opener.dataset.dialogOpen);

        if (dialog instanceof HTMLDialogElement) {
            // The opener is a submit button so the action still works without
            // script; with script, the dialog stands in between.
            event.preventDefault();
            dialog.showModal();
        }
        return;
    }

    if (el?.closest('[data-dialog-close]')) {
        el.closest('dialog')?.close();
        return;
    }

    // A click on the backdrop lands on the <dialog> itself.
    if (el instanceof HTMLDialogElement && el.open) {
        const box = el.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
            el.close();
        }
    }
});

/* -------------------------------------------------- ask before unticking */

// Unticking a box with data-uncheck-confirm="<dialog id>" opens that dialog,
// filled from the box's data-uncheck-title, -text and -items (a JSON list of
// names). Keeping puts the tick back. Accepting leaves it off and sets the
// form's [data-uncheck-confirmed] field to 1, so the server knows the question
// was asked; without script the server asks instead.
document.addEventListener('change', (event) => {
    const box = event.target;

    if (!(box instanceof HTMLInputElement) || !box.dataset.uncheckConfirm || box.checked) {
        return;
    }

    const dialog = document.getElementById(box.dataset.uncheckConfirm);

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    const fill = (name) => dialog.querySelector('[data-fill="' + name + '"]');
    fill('title').textContent = box.dataset.uncheckTitle || '';
    fill('text').textContent = box.dataset.uncheckText || '';
    fill('items').replaceChildren(...JSON.parse(box.dataset.uncheckItems || '[]').map((text) => {
        const item = document.createElement('li');
        item.textContent = text;
        return item;
    }));

    const accept = dialog.querySelector('[data-uncheck-accept]');
    const keep = dialog.querySelector('[data-uncheck-keep]');
    let accepted = false;
    const onAccept = () => {
        accepted = true;
        dialog.close();
    };
    const onKeep = () => dialog.close();

    accept.addEventListener('click', onAccept);
    keep.addEventListener('click', onKeep);

    // Esc and a click on the backdrop close it too, and both mean "keep".
    dialog.addEventListener('close', () => {
        accept.removeEventListener('click', onAccept);
        keep.removeEventListener('click', onKeep);

        if (accepted) {
            const flag = box.form?.querySelector('[data-uncheck-confirmed]');
            if (flag) {
                flag.dataset.initial ??= flag.value;
                flag.value = '1';
            }
        } else {
            box.checked = true;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        }
        box.focus();
    }, { once: true });

    dialog.showModal();
});

/* -------------------------------------------------------------------- toasts */

const region = document.querySelector('[data-toasts]');
const MAX_TOASTS = 3;

const dismiss = (toast) => {
    toast.classList.add('toast--leaving');
    setTimeout(() => toast.remove(), 200);
};

const arm = (toast) => {
    toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismiss(toast));
    // Errors stay until they are closed: they are the ones that need reading.
    if (!toast.classList.contains('toast--error')) {
        setTimeout(() => dismiss(toast), 5000);
    }
};

if (region) {
    region.querySelectorAll('.toast').forEach(arm);

    window.adminToast = (text, kind = 'ok') => {
        const toast = document.createElement('div');
        const body = document.createElement('p');
        const close = document.createElement('button');

        toast.className = 'toast toast--' + kind;
        if (kind === 'error') {
            toast.setAttribute('role', 'alert');
        }
        body.className = 'toast__text';
        body.textContent = text;
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('data-toast-close', '');
        close.setAttribute('aria-label', region.dataset.closeLabel || '');
        close.innerHTML = region.dataset.closeIcon || '×';
        toast.append(body, close);
        region.append(toast);

        const all = region.querySelectorAll('.toast:not(.toast--leaving)');
        if (all.length > MAX_TOASTS) {
            dismiss(all[0]);
        }
        arm(toast);
    };
}

/* ------------------------------------------------------------------ dropzone */

function resetDropzone(zone) {
    const preview = zone.querySelector('[data-preview]');
    const empty = zone.querySelector('[data-empty]');
    const status = zone.querySelector('[data-status]');

    if (preview.dataset.original) {
        preview.src = preview.dataset.original;
        preview.hidden = false;
        empty.hidden = true;
    } else {
        preview.removeAttribute('src');
        preview.hidden = true;
        empty.hidden = false;
    }
    status.textContent = '';
    zone.classList.remove('dropzone--warn', 'dropzone--removed');
}

const show = (zone, file) => {
    const preview = zone.querySelector('[data-preview]');
    const empty = zone.querySelector('[data-empty]');
    const status = zone.querySelector('[data-status]');
    const input = zone.querySelector('input[type="file"]');
    const accept = input.accept.split(',').map((s) => s.trim());
    const msgs = zone.dataset;

    zone.classList.remove('dropzone--warn');

    if (accept.length && !accept.includes(file.type)) {
        status.textContent = msgs.msgType;
        zone.classList.add('dropzone--warn');
        return;
    }
    if (Number(msgs.maxBytes) && file.size > Number(msgs.maxBytes)) {
        status.textContent = msgs.msgBig;
        zone.classList.add('dropzone--warn');
        return;
    }

    const url = URL.createObjectURL(file);
    preview.onload = () => {
        const small = preview.naturalWidth < Number(msgs.minWidth) || preview.naturalHeight < Number(msgs.minHeight);
        status.textContent = small ? msgs.msgSmall : msgs.msgChosen.replace(':name', file.name);
        zone.classList.toggle('dropzone--warn', small);
    };
    preview.src = url;
    preview.hidden = false;
    empty.hidden = true;
};

for (const zone of document.querySelectorAll('[data-dropzone]')) {
    const input = zone.querySelector('input[type="file"]');
    const preview = zone.querySelector('[data-preview]');
    const remove = zone.querySelector('[data-drop-remove]');

    if (preview.getAttribute('src')) {
        preview.dataset.original = preview.getAttribute('src');
    }

    input.addEventListener('change', () => {
        if (input.files[0]) {
            show(zone, input.files[0]);
        }
    });

    // Put dropped or pasted files into the real input, so the form posts them.
    const take = (files) => {
        if (!files || !files.length) {
            return;
        }
        const list = new DataTransfer();
        list.items.add(files[0]);
        input.files = list.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    zone.addEventListener('dragover', (event) => {
        event.preventDefault();
        zone.classList.add('dropzone--over');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('dropzone--over'));
    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        zone.classList.remove('dropzone--over');
        take(event.dataTransfer.files);
    });
    zone.addEventListener('paste', (event) => take(event.clipboardData?.files));

    remove?.addEventListener('change', () => {
        zone.classList.toggle('dropzone--removed', remove.checked);
    });
}

/* -------------------------------------------------------------- stock matrix */

for (const matrix of document.querySelectorAll('[data-stock-matrix]')) {
    const sum = (selector) => [...matrix.querySelectorAll(selector)].reduce((n, el) => n + (Number(el.value) || 0), 0);

    const totals = () => {
        for (const cell of matrix.querySelectorAll('[data-row-total]')) {
            cell.textContent = numbers.format(sum('input[data-row="' + cell.dataset.rowTotal + '"]'));
        }
        for (const cell of matrix.querySelectorAll('[data-col-total]')) {
            cell.textContent = numbers.format(sum('input[data-col="' + cell.dataset.colTotal + '"]'));
        }
        matrix.querySelector('[data-grand-total]').textContent = numbers.format(sum('input[data-row]'));
    };

    matrix.addEventListener('input', totals);
}

/* ------------------------------------------------------------ settings index */

const index = document.querySelector('[data-settings-index]');

if (index && 'IntersectionObserver' in window) {
    const links = new Map([...index.querySelectorAll('a[href^="#"]')].map((a) => [a.getAttribute('href').slice(1), a]));
    const seen = new Map();

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            seen.set(entry.target.id, entry.isIntersecting);
        }
        const current = [...links.keys()].find((id) => seen.get(id));
        for (const [id, link] of links) {
            if (id === current) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        }
    }, { rootMargin: '-20% 0px -60% 0px' });

    for (const id of links.keys()) {
        const section = document.getElementById(id);
        if (section) {
            observer.observe(section);
        }
    }
}
