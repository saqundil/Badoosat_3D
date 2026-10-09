/**
 * Prints the built island's transfer size, and where it goes. Run after
 * `npm run build`.
 *
 * This is a tracking number, not a gate: it never fails, and nothing in the
 * build or the acceptance suite reads it. What the editor is actually held to
 * is time — how long after the click on «صمّم بنفسك» the customer can start
 * drawing, measured on a throttled connection in tools/verify-editor.mjs.
 *
 * Bytes were the wrong proxy for that wait. This stack's floor is 361 kB before
 * a line of editor code is written, so a byte budget under it could only be met
 * by changing the stack, while the wait itself is answered by loading late,
 * caching, and saying plainly what is happening in the meantime.
 */
import { gzipSync } from 'node:zlib';
import { readFile } from 'node:fs/promises';

const files = [
    '../../public/assets/editor/editor.js',
    '../../public/assets/editor/editor.css',
    '../../public/assets/editor/editor-scene.js',
];

// Only the first two are on the path to being able to draw; the scene follows
// behind an already-usable editor, which is why the total below is not the
// number that matters.
const CRITICAL = 2;

let total = 0;
let critical = 0;
let n = 0;

for (const path of files) {
    const bytes = await readFile(new URL(path, import.meta.url));
    const gz = gzipSync(bytes, { level: 9 }).length;
    total += gz;

    if (n++ < CRITICAL) {
        critical += gz;
    }

    console.log(
        path.split('/').pop().padEnd(18) +
        (bytes.length / 1024).toFixed(1).padStart(9) + ' kB' +
        '   gzip ' + (gz / 1024).toFixed(1).padStart(7) + ' kB'
    );
}

console.log('-'.repeat(52));
console.log('before the first stroke'.padEnd(18) + (critical / 1024).toFixed(1).padStart(9) + ' kB gzip');
console.log('everything'.padEnd(18) + (total / 1024).toFixed(1).padStart(9) + ' kB gzip');
console.log('\nwhere it goes:');
console.log('  three              186 kB   in the scene chunk, fetched once the editor is open');
console.log('  fabric              89 kB');
console.log('  react-dom           74 kB');
console.log('  react-three-fiber   12 kB   with three, in the scene chunk');
console.log('\nnot a budget. the criterion is time: node tools/verify-editor.mjs');
