/*
 * The one animation the new sections get: they fade up as they arrive.
 *
 * WHY THE CSS DOES NOT DO THIS ALONE
 *
 * Because an element that starts at opacity: 0 in the stylesheet is invisible
 * to anyone whose JavaScript did not run, and "the page is blank" is a far worse
 * failure than "the page did not animate". So the hidden state is applied by
 * this file -- it adds data-revealed="false" before observing, and the CSS only
 * hides what is marked -- which means no script, no hiding, and the page reads
 * exactly as it would have.
 *
 * Progressive enhancement is not a slogan here: it is the reason the attribute
 * is written from the script instead of the template.
 *
 * ONE MOVE PER ELEMENT
 *
 * An element is unobserved the moment it has been revealed. It does not fade
 * back out when it leaves the viewport and it does not fade in a second time
 * when it returns, because a section that re-animates every time the reader
 * scrolls past it is the thing that makes people turn animation off.
 */

const REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');

const targets = Array.from(document.querySelectorAll('[data-reveal]'));

/*
 * Reduced motion, or a browser without IntersectionObserver.
 *
 * Both answers are the same answer: show everything, now, and never mark it
 * hidden in the first place. Note that this respects the setting rather than
 * shortening the animation -- the request is for no motion, and 1ms of motion is
 * still motion.
 */
if (targets.length > 0 && !(REDUCED && REDUCED.matches) && 'IntersectionObserver' in window) {
    targets.forEach((element) => {
        element.setAttribute('data-revealed', 'false');
    });

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            /*
             * The stagger is read off the element, not counted here: a card is
             * the third in its row because the template said so, and a counter
             * in this file would number them in document order across every
             * section on the page.
             */
            entry.target.setAttribute('data-revealed', 'true');
            observer.unobserve(entry.target);
        });
    }, {
        /*
         * A negative bottom margin, so a section is not "arrived" until it is
         * properly on screen; and 12% of it visible, so a tall section does not
         * wait for its own bottom edge. rootMargin's bottom is the only side
         * that matters -- everything here is revealed on the way down.
         */
        rootMargin: '0px 0px -10% 0px',
        threshold: 0.12,
    });

    targets.forEach((element) => observer.observe(element));
}
