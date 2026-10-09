/*
 * The dashboard's small behaviours. Every page works without this file; it
 * only adds what plain HTML cannot do.
 *
 *   [data-notch]           measures its tabs into --notch-w, so the hole in the
 *                          dark panel is as wide as the words in it -- Arabic and
 *                          English labels are not the same width, and the counts
 *                          change. The CSS default fits the longest label already.
 *   form[data-confirm]     asks before an irreversible status change.
 *   a[data-loads="x"]      marks [data-loading-target="x"] busy while the next
 *                          page loads, so the card shows its skeleton instead of
 *                          a stale order.
 *   [data-admin-bar]       on a phone the section groups fold into a menu button;
 *                          the fold only happens once this file has run, so
 *                          without it the groups stay a scrolling row.
 *
 * Strings come from the page (data-confirm), never from here.
 */

const bar = document.querySelector('[data-admin-bar]');
const menuButton = bar?.querySelector('[data-nav-toggle]');

if (bar && menuButton) {
    bar.dataset.menu = 'closed';

    const setMenu = (open) => {
        bar.dataset.menu = open ? 'open' : 'closed';
        menuButton.setAttribute('aria-expanded', String(open));
    };

    menuButton.addEventListener('click', () => setMenu(bar.dataset.menu !== 'open'));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && bar.dataset.menu === 'open') {
            setMenu(false);
            menuButton.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (bar.dataset.menu === 'open' && event.target instanceof Node && !bar.contains(event.target)) {
            setMenu(false);
        }
    });
}

for (const panel of document.querySelectorAll('[data-notch]')) {
    const tabs = panel.querySelector('[data-notch-tabs] .tabs');

    if (!tabs || !('ResizeObserver' in window)) {
        continue;
    }

    // The tabs' own width plus the breathing room either side of the pill.
    const fit = () => {
        const gap = parseFloat(getComputedStyle(panel).getPropertyValue('--r-concave')) || 0;
        panel.style.setProperty('--notch-w', Math.ceil(tabs.scrollWidth + gap) + 'px');
    };

    new ResizeObserver(fit).observe(tabs);
    fit();
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[data-loads]') : null;

    // A new tab or window loads nothing here.
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const target = document.querySelector('[data-loading-target="' + link.dataset.loads + '"]');

    target?.setAttribute('aria-busy', 'true');
});

// Back/forward restores the page from cache with the busy flag still on.
window.addEventListener('pageshow', () => {
    for (const el of document.querySelectorAll('[data-loading-target][aria-busy]')) {
        el.removeAttribute('aria-busy');
    }
});
