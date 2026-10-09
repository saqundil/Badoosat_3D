import { createRoot } from 'react-dom/client';

import App from './App.jsx';
import { readConfig } from './config.js';
import { DesignSurface } from './design.js';
import { useEditor } from './store.js';
import './styles.css';

/**
 * The island's whole surface area.
 *
 * Importing this file mounts the editor, which is why nothing on the site links
 * to it directly: a small launcher on the product page calls import() when the
 * customer asks for it, and until then not one byte of React is fetched.
 */

let root = null;
let container = null;

export function mount(target) {
    container = target ?? document.getElementById('tshirt-editor');

    if (!container) {
        throw new Error('#tshirt-editor is not on this page');
    }

    if (root) {
        return { unmount };
    }

    const config = readConfig();
    const surface = new DesignSurface(config);

    useEditor.getState().init(config, surface);

    container.classList.add('ed-open');
    document.documentElement.classList.add('ed-locked');

    root = createRoot(container);
    root.render(<App onClose={unmount} />);

    // Focus lands inside the editor, so keyboard users are not left behind on
    // the page underneath.
    requestAnimationFrame(() => {
        container.querySelector('button, [href], input')?.focus();
    });

    return { unmount };
}

/**
 * The mounted editor's internals.
 *
 * The launcher never needs this; the acceptance suite drives the editor through
 * it rather than through synthetic clicks, which would test the test harness as
 * much as the editor.
 */
export function getEditor() {
    const state = useEditor.getState();

    return { store: useEditor, surface: state.surface, config: state.config };
}

export function unmount() {
    if (!root) {
        return;
    }

    useEditor.getState().surface?.dispose();
    root.unmount();
    root = null;

    container?.classList.remove('ed-open');
    document.documentElement.classList.remove('ed-locked');
    document.querySelector('[data-editor-launch]')?.focus();
}

mount();

export default { mount, unmount };
