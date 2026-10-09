/**
 * Acceptance checks for the printed design's colours.
 *
 * A standard material's albedo is `color * map`. While the artwork lived in
 * the garment material's own map, every pixel of a design was multiplied by
 * the shirt's colour: tinted on navy, and on the near-black #1C1C1E taken to
 * nothing at all. The design did not darken -- it vanished.
 *
 * So this does not check the code, it checks the picture. The same slide is
 * rendered on four very different shirts and the pixels of the print are
 * measured: they must come out the same colour on all four, and on black they
 * must still be there.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-print-colour.mjs
 */

import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = new URL('../', import.meta.url).pathname.slice(1);

/** Deliberately far apart, and two of them are the ones that used to break. */
const SHIRTS = [
    { name: 'white', hex: '#EFEDE7' },
    { name: 'yellow', hex: '#E9C400' },
    { name: 'black', hex: '#1C1C1E' },
    { name: 'navy', hex: '#3A5A78' },
];

await mkdir(ROOT + 'build/', { recursive: true });

const pass = [];
const fail = [];
const check = (ok, label, detail = '') => {
    (ok ? pass : fail).push(label + (detail ? '  [' + detail + ']' : ''));
    console.log((ok ? '  PASS  ' : '  FAIL  ') + label + (detail ? '  ' + detail : ''));
};

const fixture = (...args) =>
    execFileSync('php', [ROOT + 'tools/hero-fixture.php', ...args], { encoding: 'utf8' }).trim();

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

console.log(fixture('snapshot'));

/*
 * The WebGL canvas is not readable after the frame is composited, so the
 * picture is taken the way a person sees it -- a screenshot -- and sampled in
 * a scratch page. What is being measured is what was actually painted.
 */
const sampler = await browser.newPage();
await sampler.setContent('<canvas id="c"></canvas>');

const sample = (shot) => sampler.evaluate(async (dataUrl) => {
    const image = new Image();
    await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = reject;
        image.src = dataUrl;
    });

    const canvas = document.getElementById('c');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    context.drawImage(image, 0, 0);

    const { data, width, height } = context.getImageData(0, 0, canvas.width, canvas.height);

    /*
     * The print on this slide is a strong blue block across the chest. Finding
     * it by colour rather than by coordinate keeps the test honest: if the
     * design were being tinted by the shirt, the pixels that answer here would
     * answer differently, which is exactly the failure being looked for.
     */
    let r = 0;
    let g = 0;
    let b = 0;
    let n = 0;

    for (let y = Math.round(height * 0.2); y < Math.round(height * 0.7); y++) {
        for (let x = Math.round(width * 0.3); x < Math.round(width * 0.7); x++) {
            const i = (y * width + x) * 4;
            const [red, green, blue] = [data[i], data[i + 1], data[i + 2]];

            /*
             * Blue, and decisively so. The thresholds have to clear the NAVY
             * SHIRT as well as pale cloth: lit #3A5A78 separates its channels
             * by roughly 62 and 30, the print by 135 and 81, so the line is
             * drawn between them. Without that the navy run measured the shirt
             * as well as the print and reported a difference that was the
             * test's own doing.
             */
            if (blue > 90 && blue - red > 100 && blue - green > 60) {
                r += red;
                g += green;
                b += blue;
                n++;
            }
        }
    }

    /*
     * The cloth itself, everywhere the print is not.
     *
     * The garment's colour still lives on material.color, so the lighting model
     * still has an albedo to shade and the folds should still read. This is
     * what proves it: the spread of brightness across the shirt. A garment
     * whose colour had been flattened into a texture -- or one no longer being
     * lit -- would come back with almost none.
     */
    let low = 255;
    let high = 0;
    let cloth = 0;

    for (let y = Math.round(height * 0.25); y < Math.round(height * 0.65); y++) {
        for (let x = Math.round(width * 0.42); x < Math.round(width * 0.58); x++) {
            const i = (y * width + x) * 4;
            const [red, green, blue] = [data[i], data[i + 1], data[i + 2]];

            if (blue - red > 100 && blue - green > 60) continue;

            const luma = 0.2126 * red + 0.7152 * green + 0.0722 * blue;

            low = Math.min(low, luma);
            high = Math.max(high, luma);
            cloth++;
        }
    }

    return n === 0 ? null : {
        r: Math.round(r / n), g: Math.round(g / n), b: Math.round(b / n), n,
        shading: cloth === 0 ? 0 : Math.round(high - low),
    };
}, shot);

const results = {};

try {
    console.log('  ' + fixture('model'));

    for (const shirt of SHIRTS) {
        console.log('  ' + fixture('garment', shirt.hex));

        const page = await browser.newPage();
        await page.setCacheEnabled(false);
        await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);

        /* The poster is a photograph of a shirt; only the live scene is the
           thing under test, so wait for it rather than measuring the still. */
        await page.waitForSelector('.hero__card.is-live', { timeout: 40000 });
        await new Promise((r) => setTimeout(r, 1400));

        const box = await page.evaluate(() => {
            const r = document.querySelector('[data-hero-stage]').getBoundingClientRect();

            return { x: Math.round(r.x), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
        });

        const shot = await page.screenshot({ clip: box, encoding: 'base64' });
        results[shirt.name] = await sample('data:image/png;base64,' + shot);

        await page.screenshot({ path: ROOT + 'build/print-' + shirt.name + '.png', clip: box });
        await page.close();

        const seen = results[shirt.name];
        console.log('    ' + shirt.name.padEnd(7) + (seen === null
            ? 'NO PRINT PIXELS FOUND'
            : 'rgb(' + seen.r + ',' + seen.g + ',' + seen.b + ')  over ' + seen.n + ' px'));
    }
} finally {
    console.log('\n' + fixture('restore'));
}

console.log('\n================ the print, shirt by shirt ================');

for (const shirt of SHIRTS) {
    check(results[shirt.name] !== null,
        'the print is visible on ' + shirt.name,
        results[shirt.name] === null ? 'nothing found' : results[shirt.name].n + ' px');
}

/* Every colour against the first, so a drift anywhere shows up. */
const base = results[SHIRTS[0].name];

if (base !== null) {
    for (const shirt of SHIRTS.slice(1)) {
        const seen = results[shirt.name];

        if (seen === null) {
            continue;
        }

        const delta = Math.max(
            Math.abs(seen.r - base.r), Math.abs(seen.g - base.g), Math.abs(seen.b - base.b)
        );

        /*
         * Not zero: the print is lit, and a shirt's own colour still bounces a
         * little light onto it through the environment. What must not happen is
         * the print taking the shirt's colour, which was a shift of hundreds.
         */
        check(delta <= 12,
            'the print is the same colour on ' + shirt.name + ' as on ' + SHIRTS[0].name,
            'rgb(' + seen.r + ',' + seen.g + ',' + seen.b + ') vs rgb('
                + base.r + ',' + base.g + ',' + base.b + '), largest channel difference ' + delta);
    }

    /*
     * And the garment is still a garment. Moving the artwork off the material
     * would have been no fix at all if it cost the shirt its form.
     */
    for (const shirt of SHIRTS) {
        const seen = results[shirt.name];

        if (seen === null) {
            continue;
        }

        check(seen.shading >= 25,
            'the ' + shirt.name + ' shirt still shades -- the folds read',
            seen.shading + ' levels of brightness across the cloth');
    }

    /* The case that used to fail hardest. */
    const black = results.black;

    if (black !== null) {
        check(black.b > 90,
            'on black the print is still bright, not multiplied away',
            'blue channel ' + black.b);
    }
}

/* ------------------------------------------------- the other way in ------- */

/*
 * The hero hands setArtwork a finished texture. The product page and the
 * product editor hand it a raw canvas and let it build the texture itself --
 * the same two lines either way, but a different branch, and the branch that
 * serves two of the three places this had to be fixed.
 *
 * Driving the product page's own carousel to prove it turned out to measure
 * the carousel more than the shirt, so the modules are driven directly: the
 * real model-core, the real drawDesignLayers, a design of a known colour, and
 * the pixels read straight off the GL buffer.
 */
console.log('\n================ the canvas branch ================');

{
    const page = await browser.newPage();
    await page.setCacheEnabled(false);
    await page.setViewport({ width: 900, height: 900, deviceScaleFactor: 1 });
    /* The home page carries the importmap that resolves 'three'. */
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

    const readings = await page.evaluate(async (hexes) => {
        const core = await import('/assets/js/model-core.js');
        const { drawDesignLayers } = await import('/assets/js/hero3d.js');
        const { garment } = JSON.parse(document.querySelector('[data-hero-data]').textContent);

        /* A design of one flat, unmistakable colour, so any tint shows. */
        const swatch = document.createElement('canvas');
        swatch.width = swatch.height = 256;
        const paint = swatch.getContext('2d');
        paint.fillStyle = '#FF00AA';
        paint.fillRect(0, 0, 256, 256);

        const image = new Image();
        await new Promise((resolve) => { image.onload = resolve; image.src = swatch.toDataURL(); });

        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 600;
        document.body.append(canvas);

        const state = core.createScene(canvas);
        await core.loadModel('/models/tshirt.glb');
        core.resize(600, 600);

        /* The product page's call, verbatim: a canvas, not a texture. */
        core.setArtwork(drawDesignLayers(
            [{ image, side: 'front', x: 50, y: 50, scale: 100 }],
            { printArea: garment.print_area, uvScaleCm: garment.uv_scale_cm, canvasSize: garment.canvas_px }
        ));

        const gl = state.renderer.getContext();
        const pixels = new Uint8Array(600 * 600 * 4);
        const out = {};

        for (const hex of hexes) {
            core.setColor(hex);
            state.renderer.render(state.scene, state.camera);
            /* Same turn as the draw: the buffer is not cleared until it is
               composited, so this reads what was just rendered. */
            gl.readPixels(0, 0, 600, 600, gl.RGBA, gl.UNSIGNED_BYTE, pixels);

            let r = 0;
            let g = 0;
            let b = 0;
            let n = 0;

            for (let i = 0; i < pixels.length; i += 4) {
                /* Magenta: red and blue high, green low. Nothing on a shirt of
                   any of these colours comes close. */
                if (pixels[i] > 120 && pixels[i + 2] > 90 && pixels[i] - pixels[i + 1] > 90) {
                    r += pixels[i];
                    g += pixels[i + 1];
                    b += pixels[i + 2];
                    n++;
                }
            }

            out[hex] = n === 0 ? null
                : { r: Math.round(r / n), g: Math.round(g / n), b: Math.round(b / n), n };
        }

        core.dispose();
        canvas.remove();

        return out;
    }, SHIRTS.map((s) => s.hex));

    const first = readings[SHIRTS[0].hex];

    for (const shirt of SHIRTS) {
        const seen = readings[shirt.hex];

        console.log('    ' + shirt.name.padEnd(7) + (seen === null
            ? 'NO PRINT PIXELS FOUND'
            : 'rgb(' + seen.r + ',' + seen.g + ',' + seen.b + ')  over ' + seen.n + ' px'));

        check(seen !== null, 'canvas branch: the print survives on ' + shirt.name,
            seen === null ? 'nothing found' : seen.n + ' px');

        if (seen !== null && first !== null && shirt !== SHIRTS[0]) {
            const delta = Math.max(
                Math.abs(seen.r - first.r), Math.abs(seen.g - first.g), Math.abs(seen.b - first.b)
            );

            check(delta <= 12,
                'canvas branch: unchanged on ' + shirt.name + ' against ' + SHIRTS[0].name,
                'largest channel difference ' + delta);
        }
    }

    await page.close();
}

await browser.close();


console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
