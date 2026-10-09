/**
 * Acceptance check for one rule: the garment's colour must not reach the print.
 *
 * A standard material computes albedo as `color * map`. While the artwork WAS
 * the garment material's map, every design pixel was multiplied by the shirt's
 * colour -- so a print shifted hue with the shirt, and on the near-black
 * #1C1C1E the multiplication took it to nothing. The design did not darken, it
 * vanished. model-core.js now draws the artwork on a surface of its own whose
 * colour is left white; this proves it.
 *
 * The test is pixel-level because the claim is pixel-level. For each garment
 * colour the canvas is photographed, the design's own pixels are found by
 * differencing against the same shirt with no design, and those pixels are
 * compared across the colours. Shading is checked in the same pass: a garment
 * that came out flat would be a different bug introduced by the same fix.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-print-color.mjs
 */

import { execFileSync } from 'node:child_process';
import { inflateSync } from 'node:zlib';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = new URL('../', import.meta.url).pathname.slice(1);

const pass = [];
const fail = [];
const check = (ok, label, detail = '') => {
    (ok ? pass : fail).push(label + (detail ? '  [' + detail + ']' : ''));
    console.log((ok ? '  PASS  ' : '  FAIL  ') + label + (detail ? '  ' + detail : ''));
};

/* ----------------------------------------------------------- PNG decoding */

/**
 * Chrome screenshots, as raw RGBA.
 *
 * Only what Chrome actually emits: 8-bit truecolour with alpha, not
 * interlaced. Enough to compare two pictures of the same shirt.
 */
function decodePng(buffer) {
    let width = 0;
    let height = 0;
    let channels = 4;
    const data = [];

    for (let at = 8; at < buffer.length;) {
        const length = buffer.readUInt32BE(at);
        const type = buffer.toString('ascii', at + 4, at + 8);
        const body = buffer.subarray(at + 8, at + 8 + length);

        if (type === 'IHDR') {
            width = body.readUInt32BE(0);
            height = body.readUInt32BE(4);

            /* bit depth, colour type, interlace */
            if (body[8] !== 8 || body[12] !== 0) {
                throw new Error('Unexpected PNG encoding: depth ' + body[8]
                    + ', colour type ' + body[9] + ', interlace ' + body[12]);
            }

            channels = body[9] === 6 ? 4 : (body[9] === 2 ? 3 : 0);

            if (channels === 0) {
                throw new Error('Unexpected PNG colour type ' + body[9]);
            }
        } else if (type === 'IDAT') {
            data.push(body);
        } else if (type === 'IEND') {
            break;
        }

        at += 12 + length;
    }

    const raw = inflateSync(Buffer.concat(data));
    const stride = width * channels;
    const flat = Buffer.alloc(width * height * channels);

    /* Undo the per-row filter. Each row is prefixed with its filter type. */
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        const from = y * (stride + 1) + 1;
        const to = y * stride;

        for (let x = 0; x < stride; x++) {
            const value = raw[from + x];
            const left = x >= channels ? flat[to + x - channels] : 0;
            const up = y > 0 ? flat[to - stride + x] : 0;
            const upLeft = x >= channels && y > 0 ? flat[to - stride + x - channels] : 0;
            let prior = 0;

            if (filter === 1) prior = left;
            else if (filter === 2) prior = up;
            else if (filter === 3) prior = (left + up) >> 1;
            else if (filter === 4) {
                const p = left + up - upLeft;
                const dl = Math.abs(p - left);
                const du = Math.abs(p - up);
                const dul = Math.abs(p - upLeft);
                prior = dl <= du && dl <= dul ? left : (du <= dul ? up : upLeft);
            }

            flat[to + x] = (value + prior) & 0xff;
        }
    }

    if (channels === 4) {
        return { width, height, pixels: flat };
    }

    /* Widen RGB to RGBA so the rest of the file has one shape to read. */
    const pixels = Buffer.alloc(width * height * 4, 255);

    for (let i = 0, j = 0; i < flat.length; i += 3, j += 4) {
        pixels[j] = flat[i];
        pixels[j + 1] = flat[i + 1];
        pixels[j + 2] = flat[i + 2];
    }

    return { width, height, pixels };
}

const fixture = (...args) =>
    execFileSync('php', [ROOT + 'tools/hero-fixture.php', ...args], { encoding: 'utf8' }).trim();

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

console.log(fixture('snapshot'));

/*
 * Photographed on the hero rather than on the product page.
 *
 * Both draw through model-core.js -- the same setColor, the same setArtwork,
 * the same material -- so either proves the rule. The hero is the one whose
 * canvas a headless screenshot reliably captures; the product page's, behind
 * the gallery track, comes back as the slide underneath it, which would make
 * this measure nothing at all.
 */
const palette = JSON.parse(fixture('colors'));

/* White, dark, navy and one more, which is what the palette actually holds. */
const wanted = ['#EFEDE7', '#1C1C1E', '#3A5A78', '#8A2E2E'];
const colours = wanted
    .map((hex) => palette.find((c) => String(c.hex).toUpperCase() === hex))
    .filter(Boolean);

console.log('  palette: ' + colours.map((c) => c.name + ' ' + c.hex).join(', '));

const page = await browser.newPage();
await page.setCacheEnabled(false);
await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

const show = async (colourId, printed) => {
    fixture(printed ? 'testprint' : 'paint', String(colourId), '0');
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForSelector('[data-hero-card].is-live', { timeout: 40000 });
    /* The colour arrives on a 450ms tween; let it finish. */
    await new Promise((r) => setTimeout(r, 2500));
};

const clip = async () => page.evaluate(() => {
    const box = document.querySelector('[data-hero-canvas]').getBoundingClientRect();

    return { x: Math.round(box.x), y: Math.round(box.y),
             width: Math.round(box.width), height: Math.round(box.height) };
});

const shoot = async () => decodePng(await page.screenshot({ clip: await clip() }));

/* ------------------------------- which pixels are the print, and not the shirt */

/*
 * The mask is built on the BLACK shirt on purpose. This design is a pale line
 * on transparency, and finding it by differencing against an ivory garment
 * means looking for white on white -- a few hundred edge pixels. Against black
 * it is unmistakable, which is also the case the bug destroyed.
 */
const black = colours.find((c) => String(c.hex).toUpperCase() === '#1C1C1E') ?? colours[1];

await show(black.id, false);
const bare = await shoot();

await show(black.id, true);
const printed = await shoot();

const mask = [];

for (let i = 0; i < bare.pixels.length; i += 4) {
    const d = Math.abs(printed.pixels[i] - bare.pixels[i])
        + Math.abs(printed.pixels[i + 1] - bare.pixels[i + 1])
        + Math.abs(printed.pixels[i + 2] - bare.pixels[i + 2]);

    if (d > 60) mask.push(i);
}

const inMask = new Set(mask);

check(mask.length > 2000, 'the design is actually on the shirt to measure',
    mask.length + ' px differ from the bare garment');

/* ------------------------------------ the same print on every garment colour */

const shots = { [black.id]: printed };

for (const colour of colours.filter((c) => c.id !== black.id)) {
    await show(colour.id, true);
    shots[colour.id] = await shoot();
}

const sample = (image) => {
    let r = 0;
    let g = 0;
    let b = 0;

    for (const i of mask) {
        r += image.pixels[i];
        g += image.pixels[i + 1];
        b += image.pixels[i + 2];
    }

    return [r / mask.length, g / mask.length, b / mask.length];
};

/*
 * The garment's own pixels, to prove the colour really is being applied.
 *
 * Without this, "the print does not change with the shirt" would also be
 * satisfied by a shirt that never changed either -- a passing test measuring
 * nothing. These are the pixels that are garment and not print.
 */
/*
 * Cloth, not background. The card behind the shirt is dark and takes up most
 * of the frame, so averaging "everything that is not the print" buries the
 * colour change in it. The garment is the set of non-print pixels that DO
 * repaint when the colour changes -- which is the definition that matters.
 */
const garment = [];

for (let i = 0; i < bare.pixels.length; i += 4) {
    if (inMask.has(i)) continue;

    const a = shots[colours[0].id].pixels;
    const b = shots[black.id].pixels;
    const moved = Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]);

    if (moved > 25) garment.push(i);
}

check(garment.length > 5000, 'the garment repaints when the colour changes',
    garment.length + ' px of cloth move between ' + colours[0].name + ' and ' + black.name);

const meanOf = (image, indices) => {
    let r = 0;
    let g = 0;
    let b = 0;

    for (const i of indices) {
        r += image.pixels[i];
        g += image.pixels[i + 1];
        b += image.pixels[i + 2];
    }

    return [r / indices.length, g / indices.length, b / indices.length];
};

const shirtOnBlack = meanOf(shots[black.id], garment);

for (const colour of colours.filter((c) => c.id !== black.id)) {
    const shirt = meanOf(shots[colour.id], garment);
    const moved = Math.max(...shirt.map((v, i) => Math.abs(v - shirtOnBlack[i])));

    check(moved > 20, 'the garment itself does change colour to ' + colour.name,
        'moved ' + moved.toFixed(1) + '/255 from ' + black.name);
}

const first = colours[0];
const reference = sample(shots[first.id]);

console.log('\n  print colour, averaged over ' + mask.length + ' design pixels:');

for (const colour of colours) {
    const mean = sample(shots[colour.id]);
    const drift = Math.max(...mean.map((v, i) => Math.abs(v - reference[i])));

    console.log('    ' + colour.name.padEnd(12) + colour.hex.padEnd(9)
        + 'rgb(' + mean.map((v) => v.toFixed(0).padStart(3)).join(',') + ')'
        + '   drift ' + drift.toFixed(1));

    /* 12/255 of slack: the print is lit by the same lights on every shirt, but
       the garment showing through the artwork's own soft edges is not the same
       colour, and the edge pixels are inside the mask. */
    check(drift <= 12,
        'the print keeps its colours on ' + colour.name + ' ' + colour.hex,
        'drift ' + drift.toFixed(1) + '/255 from ' + first.name);
}

/* The failure that started this: on black the print used to go to nothing. */
const onBlack = sample(shots[black.id]);

check(Math.max(...onBlack) > 110,
    'and is still bright on ' + black.name + ' rather than multiplied away',
    'rgb(' + onBlack.map((v) => v.toFixed(0)).join(',') + ')');

/* ------------------------------------------ the garment is still a garment */

/*
 * Flattening the shirt would be the obvious way to break this fix -- paint the
 * colour into the texture and the lighting stops reading as a body. So the
 * garment's own pixels are checked for spread: a lit cylinder has a range of
 * values across it, a flat fill does not.
 */
for (const colour of [colours[0], black]) {
    const image = shots[colour.id];
    let low = 255;
    let high = 0;

    for (const i of garment) {
        const v = (image.pixels[i] + image.pixels[i + 1] + image.pixels[i + 2]) / 3;

        if (image.pixels[i + 3] < 200) continue;

        low = Math.min(low, v);
        high = Math.max(high, v);
    }

    check(high - low > 25,
        'the garment is still shaded on ' + colour.name,
        'luminance spans ' + low.toFixed(0) + '-' + high.toFixed(0));
}

await page.screenshot({ path: new URL('../build/print-color.png', import.meta.url).pathname.slice(1) });
console.log(fixture('restore'));
await browser.close();

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
