/*
 * The product gallery: one scrolling track, one index.
 *
 * The track is a real horizontal scroller with scroll snapping, so the phone
 * needs no code at all to drag between slides -- the browser does it, with the
 * right rubber-banding and the right momentum, which is more than a pointer
 * handler would get right. Everything else here only ever moves the same
 * scroller: a dot, a thumbnail, an arrow and an arrow key all end in
 * scrollIntoView.
 *
 * Which slide is showing is never computed. scrollLeft is the wrong number to
 * ask for -- its sign and origin differ between engines in RTL, and it is a
 * position mid-gesture rather than a decision -- so an IntersectionObserver
 * against the track reports the slide that is actually on screen, and that is
 * the only thing that sets the index. The dots follow the scroller; the
 * scroller never follows the dots.
 */

const root = document.querySelector('[data-product-gallery]');
const track = root === null ? null : root.querySelector('[data-gallery-track]');

if (root !== null && track !== null) {
    const slides = [...track.querySelectorAll('.pdp__slide')];
    const marks = [...root.querySelectorAll('[data-goto]')];
    const dialog = root.querySelector('[data-gallery-dialog]');
    const full = root.querySelector('[data-gallery-full]');

    let index = 0;

    /*
     * Marks are matched by the slide number they carry, not by their position
     * in the list: the dots and the thumbnails are two lists over the same
     * indices, and the 3D slide has an entry in both.
     */
    function mark(active) {
        index = active;

        for (const button of marks) {
            const on = Number(button.dataset.goto) === active;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-current', on ? 'true' : 'false');
        }

        root.dispatchEvent(new CustomEvent('pdp-gallery:slide', { detail: { index: active } }));
    }

    function go(next) {
        const target = slides[Math.max(0, Math.min(slides.length - 1, next))];

        if (target) {
            /*
             * block:'nearest' matters: without it a slide whose top is above
             * the fold drags the whole page vertically on every arrow press.
             */
            target.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    /*
     * 0.6 rather than a hair over 0.5: mid-drag two slides are each about
     * half visible, and a lower threshold would fire twice per gesture and
     * flicker the dots. Above 0.6 only the slide being settled on qualifies.
     */
    if (window.IntersectionObserver) {
        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    mark(Number(entry.target.dataset.slide));
                }
            }
        }, { root: track, threshold: 0.6 });

        slides.forEach((slide) => observer.observe(slide));
    } else {
        mark(0);
    }

    root.addEventListener('click', (event) => {
        const goto = event.target.closest('[data-goto]');

        if (goto) {
            go(Number(goto.dataset.goto));

            return;
        }

        if (event.target.closest('[data-gallery-prev]')) {
            go(index - 1);
        } else if (event.target.closest('[data-gallery-next]')) {
            go(index + 1);
        } else if (event.target.closest('[data-gallery-open]')) {
            openFull();
        }
    });

    /*
     * Arrow keys move the track -- except inside the 3D slide, where they are
     * the model's own rotation keys and stopping there is the point.
     */
    root.addEventListener('keydown', (event) => {
        if (event.target.closest('[data-model-slide]')) {
            return;
        }

        if (event.key === 'ArrowRight') {
            go(index - 1);                 // RTL: the right-hand slide is the earlier one
        } else if (event.key === 'ArrowLeft') {
            go(index + 1);
        } else {
            return;
        }

        event.preventDefault();
    });

    /* ------------------------------------------------- the full-size view */

    function openFull() {
        const image = slides[index]?.querySelector('img');

        if (!dialog || !full || !image) {
            return;
        }

        full.src = image.currentSrc || image.src;
        full.srcset = image.srcset;
        full.sizes = '100vw';
        full.classList.remove('is-zoomed');
        dialog.showModal();
    }

    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        } else if (event.target.closest('[data-gallery-close]')) {
            dialog.close();
        } else if (event.target.closest('[data-gallery-zoom]')) {
            full.classList.toggle('is-zoomed');
        }
    });
}
