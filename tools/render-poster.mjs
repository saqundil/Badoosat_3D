/**
 * Renders public/images/hero-poster.webp from the same module the hero uses,
 * so the still and the live scene frame the garment identically and the
 * cross-fade between them does not move.
 *
 * The poster keeps its alpha: it carries the garment only, so each slide's own
 * card colour shows through it on phones, where the scene never loads.
 *
 * One-off developer step:
 *   npm i -D puppeteer-core sharp
 *   php -S 127.0.0.1:8899 -t public
 *   node tools/render-poster.mjs
 */

import { writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';
import sharp from 'sharp';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const WIDTH = 1600;
const HEIGHT = 1000; // matches REF_ASPECT in hero3d.js
const GARMENT_COLOR = process.env.HERO_COLOR ?? '#E7E4DC';
const OUT = new URL('../public/images/hero-poster.webp', import.meta.url);

const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

try {
    const page = await browser.newPage();
    page.on('pageerror', (error) => console.error('page:', error.message));
    await page.setViewport({ width: WIDTH, height: HEIGHT, deviceScaleFactor: 1 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

    const dataUrl = await page.evaluate(
        async (width, height, color) => {
            const { createStage } = await import('/assets/js/hero3d.js');
            const garment = JSON.parse(
                document.querySelector('[data-hero-data]').textContent
            ).garment;

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            document.body.append(canvas);

            const stage = await createStage(canvas, {
                modelPath: '/models/tshirt.glb',
                printArea: garment.print_area,
                uvScaleCm: garment.uv_scale_cm,
                canvasSize: garment.canvas_px,
            });

            stage.resize(width, height);
            stage.setColor(color, false);
            stage.setTilt(0, 0);

            // No await between render and toDataURL: the drawing buffer is
            // cleared on the next frame.
            stage.render();

            return canvas.toDataURL('image/png');
        },
        WIDTH,
        HEIGHT,
        GARMENT_COLOR
    );

    const png = Buffer.from(dataUrl.split(',')[1], 'base64');

    const webp = await sharp(png)
        .resize({ width: WIDTH })
        .webp({ quality: 82, alphaQuality: 90, effort: 6 })
        .toBuffer();

    await writeFile(OUT, webp);

    const meta = await sharp(webp).metadata();
    console.log(
        'hero-poster.webp  ' + meta.width + 'x' + meta.height +
        '  ' + (webp.length / 1024).toFixed(1) + ' KB  alpha=' + meta.hasAlpha
    );
} finally {
    await browser.close();
}
