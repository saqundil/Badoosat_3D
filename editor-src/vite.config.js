import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('.', import.meta.url));

/**
 * Fixed names, straight into the site's public folder. PHP points at editor.js
 * and editor.css without reading a manifest, and the server never runs any of
 * this — it receives static files.
 *
 * editor.js pulls editor-scene.js after itself, by a relative import the browser
 * resolves on its own. Nothing in PHP names the second file, so the deployment
 * story is unchanged: copy the folder.
 */
export default defineConfig({
    root: ROOT,
    plugins: [react()],
    build: {
        outDir: fileURLToPath(new URL('../public/assets/editor', import.meta.url)),
        emptyOutDir: true,
        cssCodeSplit: false,
        target: 'es2020',
        sourcemap: false,
        reportCompressedSize: false,
        rollupOptions: {
            input: fileURLToPath(new URL('src/main.jsx', import.meta.url)),
            /*
             * Vite's app mode treats the entry as a script and drops its exports.
             * 'allow-extension' keeps mount() and unmount() on editor.js without
             * forcing Rollup to emit a re-export stub in front of it, which would
             * have cost a second round trip before a byte of the editor arrived.
             */
            preserveEntrySignatures: 'allow-extension',
            output: {
                format: 'es',
                /*
                 * The scene is deliberately a second chunk. It carries three.js,
                 * which is over half the island and is not needed to start
                 * designing; keeping it in the entry meant every customer waited
                 * for it before they could draw a line.
                 */
                entryFileNames: 'editor.js',
                chunkFileNames: 'editor-scene.js',
                manualChunks: undefined,
                assetFileNames: 'editor.[ext]',
            },
        },
    },
});
