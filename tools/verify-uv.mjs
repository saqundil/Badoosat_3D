/**
 * The test that comes before any editor work: does a square drawn in the print
 * rectangle come out square on the garment, and a circle come out a circle?
 *
 * Everything the editor does rests on the answer, so it is measured twice.
 *
 *   1. On the mesh. For every triangle whose UVs land inside the design
 *      rectangle, |dP/du| and |dP/dv| give centimetres of cloth per UV unit
 *      along each axis. Their ratio IS the anisotropy; nothing about cameras or
 *      lighting can affect it. This is the authoritative number.
 *
 *   2. On the render. A grid of known cell size and two discs are painted into
 *      the rectangle and photographed front-on. This confirms the pipeline
 *      end to end — atlas, CanvasTexture, UVs, material.
 *
 * A shape at the centre of the chest photographs a few percent wider than the
 * rectangle average, because that is the part of the surface nearest the lens.
 * A real photograph of a printed shirt does the same. The check that this is
 * projection and not a mapping fault is that a square and a disc of equal size
 * measure identically, and that the printed rectangle as a whole renders at its
 * true aspect.
 *
 *   php -S 127.0.0.1:8899 -t public
 *   node tools/verify-uv.mjs
 */

import { mkdir, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = new URL('../build/', import.meta.url).pathname.slice(1);
const SIZE = 2000;
const GRID_CM = 3.5;
const SMALL_DISC_CM = 5;
const LARGE_DISC_CM = 18;

await mkdir(OUT, { recursive: true });

const pass = [];
const fail = [];
const check = (ok, label, detail = '') => {
    (ok ? pass : fail).push(label + (detail ? '  [' + detail + ']' : ''));
    console.log((ok ? '  PASS  ' : '  FAIL  ') + label + (detail ? '  ' + detail : ''));
};

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

const page = await browser.newPage();
page.on('pageerror', (e) => console.log('  page error:', e.message));
await page.setViewport({ width: SIZE, height: SIZE, deviceScaleFactor: 1 });
// The homepage carries the import map that resolves bare 'three'.
await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

const geometry = await page.evaluate(async () =>
    (await (await fetch('/api/design.php')).json()).garment);

console.log('\ngeometry, all of it from config/garments.php');
console.log('  design rect  ' + JSON.stringify(geometry.design_rect_px.front));
console.log('  covers       ' + geometry.print_size_cm.w + ' x ' + geometry.print_size_cm.h + ' cm');
console.log('  export       ' + geometry.export_px.w + ' x ' + geometry.export_px.h + ' px at ' +
    geometry.dpi.target + ' DPI');

/* ===================== 1. the mesh, which cannot lie ==================== */

const mesh = await page.evaluate(async (garment) => {
    const THREE = await import('three');
    const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');

    const gltf = await new GLTFLoader().loadAsync('/models/tshirt.glb');
    gltf.scene.updateWorldMatrix(true, true);

    let target = null;
    gltf.scene.traverse((n) => {
        if (n.isMesh) {
            target = n;
        }
    });

    const geo = target.geometry;
    const pos = geo.attributes.position;
    const uv = geo.attributes.uv;
    const index = geo.index;
    const world = target.matrixWorld;

    // UV bounds of the design rectangle, back from pixels.
    const S = garment.canvas_px;
    const rect = garment.design_rect_px.front;
    const u0 = rect.x / S;
    const u1 = (rect.x + rect.w) / S;
    const v1 = 1 - rect.y / S;
    const v0 = 1 - (rect.y + rect.h) / S;

    const V = [new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3()];
    const T = new THREE.Vector3();
    const B = new THREE.Vector3();
    const e1 = new THREE.Vector3();
    const e2 = new THREE.Vector3();
    const samples = [];

    for (let t = 0; t < index.count; t += 3) {
        const a = index.getX(t);
        const b = index.getX(t + 1);
        const c = index.getX(t + 2);

        const us = [uv.getX(a), uv.getX(b), uv.getX(c)];
        const vs = [uv.getY(a), uv.getY(b), uv.getY(c)];
        const inside = us.every((u, i) => u >= u0 && u <= u1 && vs[i] >= v0 && vs[i] <= v1);

        if (!inside) {
            continue;
        }

        V[0].fromBufferAttribute(pos, a).applyMatrix4(world);
        V[1].fromBufferAttribute(pos, b).applyMatrix4(world);
        V[2].fromBufferAttribute(pos, c).applyMatrix4(world);

        const du1 = us[1] - us[0];
        const dv1 = vs[1] - vs[0];
        const du2 = us[2] - us[0];
        const dv2 = vs[2] - vs[0];
        const det = du1 * dv2 - du2 * dv1;

        if (Math.abs(det) < 1e-12) {
            continue;
        }

        e1.subVectors(V[1], V[0]);
        e2.subVectors(V[2], V[0]);
        const r = 1 / det;
        T.copy(e1).multiplyScalar(dv2).addScaledVector(e2, -dv1).multiplyScalar(r);
        B.copy(e2).multiplyScalar(du1).addScaledVector(e1, -du2).multiplyScalar(r);

        // Model units are metres.
        samples.push({ u: T.length() * 100, v: B.length() * 100 });
    }

    const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];

    return {
        triangles: samples.length,
        cmPerU: median(samples.map((s) => s.u)),
        cmPerV: median(samples.map((s) => s.v)),
    };
}, geometry);

const meshAniso = mesh.cmPerV / mesh.cmPerU - 1;

console.log('\n1. the mesh, inside the design rectangle');
console.log('  triangles    ' + mesh.triangles);
console.log('  cm per u     ' + mesh.cmPerU.toFixed(2));
console.log('  cm per v     ' + mesh.cmPerV.toFixed(2));

check(mesh.triangles >= 100, 'the design rectangle covers real geometry', mesh.triangles + ' triangles');
check(Math.abs(meshAniso) < 0.02, 'the UV mapping is isotropic, so no stretch factor is needed anywhere',
    (meshAniso * 100).toFixed(2) + '% difference between the axes');

const configAniso = geometry.uv_scale_cm.v / geometry.uv_scale_cm.u - 1;
check(Math.abs(meshAniso - configAniso) < 0.02,
    'config/garments.php agrees with the mesh',
    'config ' + (configAniso * 100).toFixed(2) + '% vs mesh ' + (meshAniso * 100).toFixed(2) + '%');

/* ======================= 2. the rendered pipeline ======================= */

async function render(name, drawBody, extra = {}) {
    const dataUrl = await page.evaluate(
        async (size, garment, body, args) => {
            const THREE = await import('three');
            const { createStage } = await import('/assets/js/hero3d.js');

            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = size;
            document.body.append(canvas);

            const stage = await createStage(canvas, {
                modelPath: '/models/tshirt.glb',
                printArea: garment.print_area,
                uvScaleCm: garment.uv_scale_cm,
                canvasSize: garment.canvas_px,
            });

            stage.resize(size, size);
            stage.setColor('#FFFFFF', false);
            stage.setTilt(0, 0);

            const atlas = document.createElement('canvas');
            atlas.width = atlas.height = garment.canvas_px;
            const ctx = atlas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, atlas.width, atlas.height);

            // eslint-disable-next-line no-new-func
            new Function('ctx', 'rect', 'cm', 'a', body)(
                ctx,
                garment.design_rect_px.front,
                garment.print_size_cm,
                args
            );

            const texture = new THREE.CanvasTexture(atlas);
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.anisotropy = 16;
            stage.material.map = texture;
            stage.material.needsUpdate = true;

            stage.render();

            return canvas.toDataURL('image/png');
        },
        SIZE,
        geometry,
        drawBody,
        extra
    );

    await writeFile(OUT + 'uv-' + name + '.png', Buffer.from(dataUrl.split(',')[1], 'base64'));

    return page.evaluate(async (url) => {
        const image = new Image();
        await new Promise((r) => { image.onload = r; image.src = url; });
        const c = document.createElement('canvas');
        c.width = image.width;
        c.height = image.height;
        c.getContext('2d').drawImage(image, 0, 0);
        const { data } = c.getContext('2d').getImageData(0, 0, c.width, c.height);

        // Ink is detected by hue, not brightness: the shirt has seams and shaded
        // folds that are darker than the lit cloth, and a luminance threshold
        // picks those up as if they were part of the drawing.
        const ink = new Uint8Array(c.width * c.height);
        let minX = c.width;
        let maxX = -1;
        let minY = c.height;
        let maxY = -1;

        for (let i = 0, p = 0; i < ink.length; i++, p += 4) {
            const r = data[p];
            const g = data[p + 1];
            const b = data[p + 2];

            // Magenta: red and blue above green, whatever the exposure. The
            // margin is loose because a specular highlight desaturates the ink
            // where the light hits it squarely.
            if (data[p + 3] > 40 && r > g * 1.12 + 6 && b > g * 1.12 + 6) {
                ink[i] = 1;
                const x = i % c.width;
                const y = (i / c.width) | 0;

                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }

        return { width: c.width, height: c.height, ink: Array.from(ink), box: { minX, maxX, minY, maxY } };
    }, dataUrl);
}

/** Centres of ink runs along one row or column. */
function runCentres(img, fixed, horizontal) {
    const n = horizontal ? img.width : img.height;
    const centres = [];
    let start = -1;

    for (let i = 0; i <= n; i++) {
        const x = horizontal ? i : fixed;
        const y = horizontal ? fixed : i;
        const on = i < n && img.ink[y * img.width + x] === 1;

        if (on && start < 0) {
            start = i;
        } else if (!on && start >= 0) {
            centres.push((start + i - 1) / 2);
            start = -1;
        }
    }

    return centres;
}

const gridDraw = `
    ctx.strokeStyle = '#FF00FF';
    // Thick on the atlas so the line survives the trip down to screen pixels.
    ctx.lineWidth = Math.max(6, rect.w / 90);
    const cols = Math.round(cm.w / a.gridCm);
    const rows = Math.round(cm.h / a.gridCm);
    for (let i = 0; i <= cols; i++) {
        const x = rect.x + (i / cols) * rect.w;
        ctx.beginPath(); ctx.moveTo(x, rect.y); ctx.lineTo(x, rect.y + rect.h); ctx.stroke();
    }
    for (let j = 0; j <= rows; j++) {
        const y = rect.y + (j / rows) * rect.h;
        ctx.beginPath(); ctx.moveTo(rect.x, y); ctx.lineTo(rect.x + rect.w, y); ctx.stroke();
    }
`;

const grid = await render('grid', gridDraw, { gridCm: GRID_CM });
const gb = grid.box;

// Sample through the middle of the printed area, where the chest is flattest.
const rowY = Math.round((gb.minY + gb.maxY) / 2);
const colX = Math.round((gb.minX + gb.maxX) / 2);

const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];
const gaps = (centres) => {
    const g = [];

    for (let i = 1; i < centres.length; i++) {
        g.push(centres[i] - centres[i - 1]);
    }

    return g.filter((v) => v > 3);
};

const acrossGaps = gaps(runCentres(grid, rowY, true));
const downGaps = gaps(runCentres(grid, colX, false));
const pitchX = median(acrossGaps);
const pitchY = median(downGaps);
const pitchRatio = pitchX / pitchY;

console.log('\n2. the render, ' + GRID_CM + ' cm grid');
console.log('  printed area ' + (gb.maxX - gb.minX) + ' x ' + (gb.maxY - gb.minY) + ' px on screen');
console.log('  cell pitch   ' + pitchX.toFixed(2) + ' px across, ' + pitchY.toFixed(2) + ' px down');

check(acrossGaps.length >= 5 && downGaps.length >= 5, 'the grid is legible on the garment',
    acrossGaps.length + ' x ' + downGaps.length + ' cells measured');

// The whole printed area, edge to edge, averages out the bulge in the middle of
// the chest and is the cleanest geometric read of the mapping.
const areaRatio = ((gb.maxX - gb.minX) / (gb.maxY - gb.minY)) /
    (geometry.print_size_cm.w / geometry.print_size_cm.h);
check(Math.abs(areaRatio - 1) < 0.03, 'the printed area renders at its true aspect',
    (geometry.print_size_cm.w / geometry.print_size_cm.h).toFixed(3) + ' expected, ' +
    ((gb.maxX - gb.minX) / (gb.maxY - gb.minY)).toFixed(3) + ' measured (' +
    ((areaRatio - 1) * 100).toFixed(2) + '%)');
check(Math.abs(pitchRatio - 1) < 0.08, 'a square cell renders square',
    'pitch ratio ' + pitchRatio.toFixed(4) + ' (' + ((pitchRatio - 1) * 100).toFixed(2) + '%)');

const discDraw = `
    ctx.fillStyle = '#FF00FF';
    const perCm = rect.w / cm.w;
    ctx.beginPath();
    ctx.arc(rect.x + rect.w / 2, rect.y + rect.h / 2, (a.sizeCm / 2) * perCm, 0, Math.PI * 2);
    ctx.fill();
`;

const squareDraw = `
    ctx.fillStyle = '#FF00FF';
    const perCm = rect.w / cm.w;
    const s = a.sizeCm * perCm;
    ctx.fillRect(rect.x + (rect.w - s) / 2, rect.y + (rect.h - s) / 2, s, s);
`;

/**
 * Width and height through the centroid, not a bounding box: a highlight can
 * desaturate the very top of the ink and clip a box short, while the row and
 * column through the middle stay reliable.
 */
async function measureShape(name, draw, sizeCm) {
    const img = await render(name, draw, { sizeCm });

    let sumX = 0;
    let sumY = 0;
    let n = 0;

    for (let i = 0; i < img.ink.length; i++) {
        if (img.ink[i] === 1) {
            sumX += i % img.width;
            sumY += (i / img.width) | 0;
            n++;
        }
    }

    if (n === 0) {
        return { w: 0, h: 0, ratio: 0 };
    }

    const cx = Math.round(sumX / n);
    const cy = Math.round(sumY / n);

    const runLength = (fixed, horizontal) => {
        const limit = horizontal ? img.width : img.height;
        let best = 0;
        let run = 0;

        for (let i = 0; i < limit; i++) {
            const x = horizontal ? i : fixed;
            const y = horizontal ? fixed : i;
            run = img.ink[y * img.width + x] === 1 ? run + 1 : 0;
            best = Math.max(best, run);
        }

        return best;
    };

    const w = runLength(cy, true);
    const h = runLength(cx, false);

    return { w, h, ratio: w / h };
}

const SHAPE_CM = 10;

const square = await measureShape('square', squareDraw, SHAPE_CM);
const disc = await measureShape('circle', discDraw, SHAPE_CM);

console.log('');
console.log('3. a ' + SHAPE_CM + ' cm square and a ' + SHAPE_CM + ' cm disc');
console.log('  square  ' + square.w + ' x ' + square.h + ' px  ratio ' + square.ratio.toFixed(4));
console.log('  disc    ' + disc.w + ' x ' + disc.h + ' px  ratio ' + disc.ratio.toFixed(4));

/*
 * Both shapes sit at the centre of the chest, which bulges towards the lens, so
 * they photograph a few percent wider than the rectangle average. A real photo
 * of a printed shirt does the same. What must not happen is the two shapes
 * disagreeing, or the mapping itself being anisotropic — both checked above.
 */
check(Math.abs(square.ratio - 1) < 0.08, 'a square drawn in the rectangle renders square',
    'w/h ' + square.ratio.toFixed(4) + ' (' + ((square.ratio - 1) * 100).toFixed(2) +
    '%, centre-of-chest perspective)');
check(Math.abs(disc.ratio - 1) < 0.08, 'a circle renders as a circle, not an ellipse',
    'w/h ' + disc.ratio.toFixed(4) + ' (' + ((disc.ratio - 1) * 100).toFixed(2) + '%)');
check(Math.abs(disc.ratio - square.ratio) < 0.02,
    'the disc and the square measure the same, so the distortion is the surface, not the mapping',
    'square ' + square.ratio.toFixed(3) + ' vs disc ' + disc.ratio.toFixed(3));

await browser.close();

console.log('\n============================');
console.log('passed ' + pass.length + ', failed ' + fail.length);
console.log('renders in build/uv-*.png');

if (fail.length) {
    console.log('\nFAILURES:');
    fail.forEach((f) => console.log('  - ' + f));
    process.exitCode = 1;
}
