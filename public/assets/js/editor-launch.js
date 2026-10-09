/**
 * The only editor code the product page ships: about a kilobyte, no framework.
 *
 * The island is a separate bundle with React, three.js and Fabric in it. Naming
 * it in a <script src> would download all of that for every visitor who only
 * wanted to look at a t-shirt, so it is fetched by import() on the first click
 * and never before.
 */

const button = document.querySelector('[data-editor-launch]');

if (button) {
    let loading = false;
    let editor = null;

    const label = button.textContent;
    const overlay = document.getElementById('editor-loading');

    /*
     * The bundle landing is not the same event as the editor being usable: React
     * still has to render, three.js to build a scene and Fabric to take the
     * canvas. The island says when it is ready to be drawn on, and the overlay
     * stays up until it does — otherwise the customer gets a blank screen for
     * exactly the stretch the loading state was meant to cover.
     */
    const done = () => {
        loading = false;
        button.disabled = false;
        button.textContent = label;

        if (overlay) {
            overlay.hidden = true;
        }
    };

    document.addEventListener('tshirt-editor:ready', done);

    button.addEventListener('click', async () => {
        if (loading) {
            return;
        }

        loading = true;
        button.disabled = true;
        button.textContent = 'جارٍ فتح المحرّر…';

        if (overlay) {
            overlay.hidden = false;
        }

        if (editor) {
            editor.mount();

            return;                     // the ready event takes it from here
        }

        try {
            // The stylesheet belongs to the bundle, so it arrives with it rather
            // than sitting in the page's <head> for every visitor.
            if (button.dataset.editorStyles && !document.getElementById('editor-styles')) {
                const link = document.createElement('link');
                link.id = 'editor-styles';
                link.rel = 'stylesheet';
                link.href = button.dataset.editorStyles;
                document.head.append(link);
            }

            editor = await import(button.dataset.editorLaunch);
        } catch (error) {
            console.error('Editor bundle failed to load.', error);
            const notice = document.getElementById('editor-error');

            if (notice) {
                notice.hidden = false;
            }

            done();                     // no island, so no ready event coming
        }
    });
}
