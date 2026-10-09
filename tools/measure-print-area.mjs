/**
 * Measures the print rectangles and the UV scale straight off the mesh, and
 * prints the block that belongs in config/garments.php.
 *
 * The numbers are derived, not eyeballed: the rectangle is the UV bounding box
 * of a real patch of cloth (PRINT_W_CM by PRINT_H_CM, DROP_CM below the
 * shoulder line), and the scale is the median |dP/du| and |dP/dv| over the
 * chest in a model whose units are metres.
 *
 * One-off developer step:
 *   php -S 127.0.0.1:8899 -t public
 *   node tools/measure-print-area.mjs
 */

import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PRINT_W_CM = 28;
const PRINT_H_CM = 35;
const DROP_CM = 10;

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

try {
    const page = await browser.newPage();
    page.on('pageerror', (error) => console.error('page:', error.message));
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

    const result = await page.evaluate(
        async (printW, printH, drop) => {
            const THREE = await import('three');
            const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');

            const gltf = await new GLTFLoader().loadAsync('/models/tshirt.glb');
            gltf.scene.updateWorldMatrix(true, true);

            let mesh = null;
            gltf.scene.traverse((node) => {
                if (node.isMesh) {
                    mesh = node;
                }
            });

            const geometry = mesh.geometry;
            const pos = geometry.attributes.position;
            const nor = geometry.attributes.normal;
            const uv = geometry.attributes.uv;
            const world = mesh.matrixWorld;
            const normalMatrix = new THREE.Matrix3().getNormalMatrix(world);
            const box = new THREE.Box3().setFromObject(mesh);
            const centreX = (box.min.x + box.max.x) / 2;

            const P = new THREE.Vector3();
            const N = new THREE.Vector3();
            const points = [];

            for (let i = 0; i < pos.count; i++) {
                P.fromBufferAttribute(pos, i).applyMatrix4(world);
                N.fromBufferAttribute(nor, i).applyMatrix3(normalMatrix).normalize();
                points.push({ x: P.x, y: P.y, nz: N.z, u: uv.getX(i), v: uv.getY(i) });
            }

            const halfWidth = printW / 200;
            const top = box.max.y - drop / 100;
            const bottom = top - printH / 100;

            const rectFor = (facing) => {
                const set = points.filter((p) =>
                    facing * p.nz > 0.7 &&
                    Math.abs(p.x - centreX) <= halfWidth &&
                    p.y >= bottom && p.y <= top);

                return {
                    samples: set.length,
                    u0: +Math.min(...set.map((p) => p.u)).toFixed(4),
                    u1: +Math.max(...set.map((p) => p.u)).toFixed(4),
                    v0: +Math.min(...set.map((p) => p.v)).toFixed(4),
                    v1: +Math.max(...set.map((p) => p.v)).toFixed(4),
                };
            };

            // Per-triangle UV Jacobian over the chest gives centimetres per UV unit.
            const index = geometry.index;
            const T = new THREE.Vector3();
            const B = new THREE.Vector3();
            const e1 = new THREE.Vector3();
            const e2 = new THREE.Vector3();
            const V = [new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3()];
            const scales = [];

            for (let t = 0; t < index.count; t += 3) {
                const a = index.getX(t);
                const b = index.getX(t + 1);
                const c = index.getX(t + 2);

                V[0].fromBufferAttribute(pos, a).applyMatrix4(world);
                V[1].fromBufferAttribute(pos, b).applyMatrix4(world);
                V[2].fromBufferAttribute(pos, c).applyMatrix4(world);

                const my = (V[0].y + V[1].y + V[2].y) / 3;
                const mx = (V[0].x + V[1].x + V[2].x) / 3;
                const mz = (V[0].z + V[1].z + V[2].z) / 3;

                if (mz < 0.05 || my < bottom || my > top || Math.abs(mx - centreX) > halfWidth) {
                    continue;
                }

                const u0 = uv.getX(a);
                const v0 = uv.getY(a);
                const du1 = uv.getX(b) - u0;
                const dv1 = uv.getY(b) - v0;
                const du2 = uv.getX(c) - u0;
                const dv2 = uv.getY(c) - v0;
                const det = du1 * dv2 - du2 * dv1;

                if (Math.abs(det) < 1e-12) {
                    continue;
                }

                e1.subVectors(V[1], V[0]);
                e2.subVectors(V[2], V[0]);
                const r = 1 / det;
                T.copy(e1).multiplyScalar(dv2).addScaledVector(e2, -dv1).multiplyScalar(r);
                B.copy(e2).multiplyScalar(du1).addScaledVector(e1, -du2).multiplyScalar(r);

                scales.push({ u: T.length() * 100, v: B.length() * 100 });
            }

            const median = (values) => {
                const sorted = [...values].sort((a, b) => a - b);

                return sorted[Math.floor(sorted.length / 2)];
            };

            return {
                garmentHeightCm: +((box.max.y - box.min.y) * 100).toFixed(1),
                front: rectFor(1),
                back: rectFor(-1),
                uvScaleCm: {
                    u: +median(scales.map((s) => s.u)).toFixed(1),
                    v: +median(scales.map((s) => s.v)).toFixed(1),
                },
                triangles: scales.length,
            };
        },
        PRINT_W_CM,
        PRINT_H_CM,
        DROP_CM
    );

    const { front, back, uvScaleCm } = result;
    const S = 2048;

    console.log('garment height        ' + result.garmentHeightCm + ' cm');
    console.log('chest triangles       ' + result.triangles);
    console.log('anisotropy            ' + ((uvScaleCm.v / uvScaleCm.u - 1) * 100).toFixed(1) + '%');
    console.log('\nconfig/garments.php:');
    console.log("    'front' => ['u0' => " + front.u0 + ", 'u1' => " + front.u1 +
        ", 'v0' => " + front.v0 + ", 'v1' => " + front.v1 + '],');
    console.log("    'back'  => ['u0' => " + back.u0 + ", 'u1' => " + back.u1 +
        ", 'v0' => " + back.v0 + ", 'v1' => " + back.v1 + '],');
    console.log("    'uv_scale_cm'   => ['u' => " + uvScaleCm.u + ", 'v' => " + uvScaleCm.v + '],');
    console.log("    'chest_rect_px' => ['x' => " + Math.round(front.u0 * S) +
        ", 'y' => " + Math.round((1 - front.v1) * S) +
        ", 'w' => " + Math.round((front.u1 - front.u0) * S) +
        ", 'h' => " + Math.round((front.v1 - front.v0) * S) + '],');
} finally {
    await browser.close();
}
