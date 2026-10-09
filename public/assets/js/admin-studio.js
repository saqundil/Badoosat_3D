/*
 * The studio shell's behaviour (src/View/admin/studio.php).
 *
 *   [data-studio-toggle]   folds the layers or the properties pane away, giving
 *                          the preview the room; remembered per page for the visit.
 *   [data-studio-device]   sets the preview frame's width (--frame-w).
 *   [data-studio-lang]     tells the page which language to preview in:
 *                          "studio:lang" on the studio, and to an iframe preview
 *                          a postMessage({studio: 'lang', lang}).
 *   [data-studio-zoom]     scales the frame; "fit" shrinks it to the stage.
 *   [data-studio-tab]      the properties tabs, with arrow keys.
 *   [data-studio-item]     selection kept in step between the layers list and
 *                          the canvas; fires "studio:select" with the key.
 *   [data-studio-undo/redo] fire "studio:undo" / "studio:redo"; the page enables
 *                          them by calling studio.setHistory(canUndo, canRedo).
 */

for (const studio of document.querySelectorAll('[data-studio]')) {
    const frame = studio.querySelector('[data-studio-frame]');
    const stage = studio.querySelector('[data-studio-stage]');
    const key = 'studio:' + location.pathname + ':';
    const emit = (name, detail) => studio.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    const iframe = () => frame?.querySelector('iframe');

    /* -------------------------------------------------------------- panes */

    const setPane = (pane, open) => {
        const button = studio.querySelector('[data-studio-toggle="' + pane + '"]');
        studio.classList.toggle('studio--no-' + pane, !open);
        button?.setAttribute('aria-expanded', String(open));
        try {
            sessionStorage.setItem(key + pane, open ? '1' : '0');
        } catch {
            // Private windows may refuse storage; the pane still folds.
        }
    };

    for (const pane of ['layers', 'panel']) {
        let stored = null;
        try {
            stored = sessionStorage.getItem(key + pane);
        } catch {
            stored = null;
        }
        if (stored === '0') {
            setPane(pane, false);
        }
    }

    /* ------------------------------------------------- device, zoom, lang */

    let width = Number(frame?.style.getPropertyValue('--frame-w').replace('px', '')) || 0;
    let zoom = 'fit';

    const fit = () => {
        if (!frame || !stage || !width) {
            return;
        }
        const room = stage.clientWidth - 32;
        const scale = zoom === 'fit' ? Math.min(1, room / width) : Number(zoom);
        frame.style.setProperty('--frame-scale', String(scale));
    };

    if (stage && 'ResizeObserver' in window) {
        new ResizeObserver(fit).observe(stage);
    }

    studio.addEventListener('click', (event) => {
        const el = event.target instanceof Element ? event.target : null;
        const toggle = el?.closest('[data-studio-toggle]');
        const device = el?.closest('[data-studio-device]');
        const language = el?.closest('[data-studio-lang]');
        const item = el?.closest('[data-studio-item]');

        if (toggle) {
            const pane = toggle.dataset.studioToggle;
            setPane(pane, toggle.getAttribute('aria-expanded') !== 'true');
            fit();
        } else if (device) {
            width = Number(device.dataset.studioDevice);
            frame?.style.setProperty('--frame-w', width + 'px');
            press(device, '[data-studio-device]');
            fit();
        } else if (language) {
            press(language, '[data-studio-lang]');
            emit('studio:lang', { lang: language.dataset.studioLang });
            iframe()?.contentWindow?.postMessage({ studio: 'lang', lang: language.dataset.studioLang }, location.origin);
        } else if (el?.closest('[data-studio-undo]')) {
            emit('studio:undo');
        } else if (el?.closest('[data-studio-redo]')) {
            emit('studio:redo');
        } else if (item) {
            select(item.dataset.studioItem);
        }
    });

    studio.querySelector('[data-studio-zoom]')?.addEventListener('change', (event) => {
        zoom = event.target.value;
        fit();
    });

    function press(button, selector) {
        for (const other of studio.querySelectorAll(selector)) {
            other.setAttribute('aria-pressed', String(other === button));
        }
    }

    /* ---------------------------------------------------------- selection */

    function select(itemKey) {
        for (const el of studio.querySelectorAll('[data-studio-item]')) {
            const on = el.dataset.studioItem === itemKey;
            el.classList.toggle('is-selected', on);
            if (el.matches('[role="option"]')) {
                el.setAttribute('aria-selected', String(on));
            }
        }
        emit('studio:select', { key: itemKey });
        iframe()?.contentWindow?.postMessage({ studio: 'select', key: itemKey }, location.origin);
    }

    window.addEventListener('message', (event) => {
        if (event.origin === location.origin && event.source === iframe()?.contentWindow && event.data?.studio === 'select') {
            select(String(event.data.key));
        }
    });

    // The layers list is a listbox: arrows move through it, Enter/Space selects.
    studio.querySelector('[data-studio-layers]')?.addEventListener('keydown', (event) => {
        const options = [...studio.querySelectorAll('[data-studio-layers] [data-studio-item]')];
        const at = options.indexOf(document.activeElement);
        if (at < 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            options[(at + (event.key === 'ArrowDown' ? 1 : options.length - 1)) % options.length].focus();
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            select(options[at].dataset.studioItem);
        }
    });

    /* --------------------------------------------------------------- tabs */

    const tabs = [...studio.querySelectorAll('[data-studio-tab]')];

    const activate = (tab) => {
        for (const other of tabs) {
            const on = other === tab;
            other.setAttribute('aria-selected', String(on));
            other.tabIndex = on ? 0 : -1;
            document.getElementById(other.getAttribute('aria-controls')).hidden = !on;
        }
    };

    for (const tab of tabs) {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', (event) => {
            const at = tabs.indexOf(tab);
            // Arrow keys follow the reading direction.
            const rtl = getComputedStyle(studio).direction === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
            if (step) {
                event.preventDefault();
                const next = tabs[(at + step + tabs.length) % tabs.length];
                activate(next);
                next.focus();
            }
        });
    }

    /* ------------------------------------------------------------ history */

    const undo = studio.querySelector('[data-studio-undo]');
    const redo = studio.querySelector('[data-studio-redo]');

    studio.setHistory = (canUndo, canRedo) => {
        if (undo) {
            undo.disabled = !canUndo;
        }
        if (redo) {
            redo.disabled = !canRedo;
        }
    };

    if (undo || redo) {
        document.addEventListener('keydown', (event) => {
            const typing = event.target instanceof Element && event.target.matches('input, textarea, select, [contenteditable]');
            if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z' || typing) {
                return;
            }
            event.preventDefault();
            const button = event.shiftKey ? redo : undo;
            if (button && !button.disabled) {
                emit(event.shiftKey ? 'studio:redo' : 'studio:undo');
            }
        });
    }

    fit();
}
