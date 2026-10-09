/**
 * Acceptance checks for the slide editor's preview frame.
 *
 * The claim being tested is a strong one: at a given device width the preview
 * is not merely similar to the site, it is the same numbers. So the site is
 * loaded at that width and the preview is set to it, and the two are compared
 * -- the card's measured size, every value hero-notches.js publishes, and the
 * clip path itself, which is the outline character for character.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   ADMIN_PASSWORD=... node tools/verify-hero-preview.mjs
 */

import { readdir, unlink } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const USER = process.env.ADMIN_USER ?? 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD ?? 'hero-admin-2026';

/* The three the switch offers. 390 is the one that matters most: it is below
   the 600 where the two top notches become one band, which a scaled div in a
   panel could never reproduce. */
const WIDTHS = [1440, 768, 390];

{
    const counters = new URL('../storage/cache/ratelimit/', import.meta.url).pathname.slice(1);

    for (const file of await readdir(counters).catch(() => [])) {
        await unlink(counters + file).catch(() => {});
    }
}

const pass = [];
const fail = [];
const check = (ok, label, detail = '') => {
    (ok ? pass : fail).push(label + (detail ? '  [' + detail + ']' : ''));
    console.log((ok ? '  PASS  ' : '  FAIL  ') + label + (detail ? '  ' + detail : ''));
};

/** Everything about the card's shape that could differ between the two. */
const shape = () => {
    const card = document.querySelector('[data-hero-card]');
    const surface = document.querySelector('.hero__surface');

    if (card === null || surface === null) {
        return null;
    }

    const value = (name) => card.style.getPropertyValue(name).trim();
    const box = surface.getBoundingClientRect();
    const logo = document.querySelector('.brand--hero')?.getBoundingClientRect() ?? null;

    return {
        innerWidth: window.innerWidth,
        S: card.clientWidth,
        H: card.clientHeight,
        dir: document.documentElement.dir,
        lang: document.documentElement.lang,
        mode: getComputedStyle(card).getPropertyValue('--chrome-mode').trim(),
        notched: card.classList.contains('is-notched'),
        R: value('--R'),
        notchStartW: value('--notch-start-w'),
        notchStartH: value('--notch-start-h'),
        notchEndH: value('--notch-end-h'),
        notchBottomW: value('--notch-bottom-w'),
        notchBottomH: value('--notch-bottom-h'),
        path: getComputedStyle(surface).clipPath,
        navText: [...document.querySelectorAll('[data-hero-menu] a')].map((a) => a.textContent.trim()),
        /* Which side the mark sits on, as a share of the card. */
        logoEdge: logo === null ? null : +((logo.left - box.left) / box.width).toFixed(3),
        logoFlush: logo === null ? null : +Math.min(
            Math.abs(logo.left - box.left), Math.abs(logo.right - box.right)
        ).toFixed(1),
    };
};

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

/* ------------------------------------------------------- the site, as a ruler */

const site = {};

for (const width of WIDTHS) {
    const page = await browser.newPage();
    await page.setCacheEnabled(false);
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.fonts.ready);
    await new Promise((r) => setTimeout(r, 900));

    site[width] = await page.evaluate(shape);
    await page.close();
}

/* ------------------------------------------------------------- the preview */

const page = await browser.newPage();
await page.setCacheEnabled(false);
await page.setViewport({ width: 1680, height: 1100, deviceScaleFactor: 1 });

const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => {
    const where = m.location()?.url ?? '';

    /*
     * One message is expected here and nowhere else. Changing the preview's
     * language replaces the whole document, which gives a WebGL context back
     * and asks for another; a browser hands out a limited number and does not
     * always have one ready on the same frame. The hero reports that honestly
     * and keeps the poster -- the behaviour a device with no WebGL gets -- so
     * it is a diagnostic, not a fault, and only here. Anything else fails.
     */
    const contextFlake = m.text().includes('3D stage unavailable');

    if (m.type() === 'error' && !where.includes('favicon') && !contextFlake) {
        errors.push(m.text() + ' <' + where + '>');
    }
});

await page.goto(BASE + '/admin/login.php', { waitUntil: 'networkidle0' });
await page.type('input[name="username"]', USER);
await page.type('input[name="password"]', PASSWORD);
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"]'),
]);

await page.goto(BASE + '/admin/edit.php?id=1', { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 2200));

const inFrame = () => page.frames().find((f) => f.url().includes('hero-preview.php'));

const readFrame = async () => {
    const frame = inFrame();

    return frame === undefined ? null : frame.evaluate(shape);
};

check(inFrame() !== undefined, 'the preview is a frame of its own');

/* The panel is Arabic and right-to-left; the preview must not be. */
const panelDir = await page.evaluate(() => document.documentElement.dir);

for (const width of WIDTHS) {
    if (width !== WIDTHS[0]) {
        await page.click('[data-preview-width="' + width + '"]');
        await new Promise((r) => setTimeout(r, 1400));
    }

    const seen = await readFrame();
    const want = site[width];
    const tag = String(width).padStart(4);

    console.log('\n--- ' + tag + ' ---');
    console.log('  site    S=' + want.S + ' H=' + want.H + ' mode=' + want.mode + ' R=' + want.R);
    console.log('  preview S=' + seen.S + ' H=' + seen.H + ' mode=' + seen.mode + ' R=' + seen.R);

    check(seen.innerWidth === width,
        tag + ': the frame reports the device width, not the column',
        'innerWidth=' + seen.innerWidth);
    check(seen.S === want.S, tag + ': the card measures what it measures on the site',
        'S ' + seen.S + ' vs ' + want.S);
    check(seen.H === want.H, tag + ': ...and is as tall', 'H ' + seen.H + ' vs ' + want.H);
    check(seen.mode === want.mode, tag + ': the same top-edge mode',
        seen.mode + ' vs ' + want.mode);
    check(seen.notched, tag + ': the notches are cut');

    for (const key of ['R', 'notchStartW', 'notchStartH', 'notchEndH', 'notchBottomW', 'notchBottomH']) {
        check(seen[key] === want[key], tag + ': ' + key + ' matches the site',
            seen[key] + ' vs ' + want[key]);
    }

    check(seen.path === want.path, tag + ': the outline is the same path, character for character',
        seen.path === want.path ? 'identical' : seen.path.slice(0, 60) + ' vs ' + want.path.slice(0, 60));

    /*
     * How far the mark sits from the card's edge -- 0 where --hero-edge puts
     * it flush, and whatever the folded row's own padding is below 600. Either
     * way the question is the same one this whole file asks: the same as the
     * site, not some absolute this test decided on.
     */
    check(seen.logoFlush === want.logoFlush,
        tag + ': the mark sits off the card edge by what the site sits off by',
        seen.logoFlush + 'px vs ' + want.logoFlush + 'px');
}

/* ------------------------------------------------------- language and direction */

console.log('\n================ language ================');

await page.click('[data-preview-width="1440"]');
await new Promise((r) => setTimeout(r, 1200));

const english = await readFrame();

check(english.dir === 'ltr' && english.lang === 'en',
    'the preview is left-to-right English, whatever the panel is',
    english.dir + '/' + english.lang + ' inside a ' + panelDir + ' panel');
check(english.dir !== panelDir, 'and does NOT inherit the panel direction');
check(english.navText.join(',') === site[1440].navText.join(','),
    'the links read what the site reads', english.navText.join(','));
check(english.logoEdge !== null && english.logoEdge < 0.5,
    'the mark is at the start edge, as on the English site', String(english.logoEdge));

await page.click('[data-preview-lang="ar"]');
await new Promise((r) => setTimeout(r, 2200));

const arabic = await readFrame();

check(arabic.dir === 'rtl' && arabic.lang === 'ar', 'Arabic flips the frame to right-to-left',
    arabic.dir + '/' + arabic.lang);
check(arabic.navText.join(',') !== english.navText.join(','),
    'and the wording changes with it', arabic.navText.join(','));
check(arabic.logoEdge !== null && arabic.logoEdge > 0.5,
    'the mark moves to the other edge', String(arabic.logoEdge));
check(arabic.logoFlush <= 0.5, 'and is still flush with the card edge', String(arabic.logoFlush));
check(arabic.path !== english.path, 'the outline is mirrored, not reused');
check(arabic.notched && arabic.S === english.S,
    'the notches are cut in both directions at the same size',
    arabic.S + ' vs ' + english.S);

/* The panel itself must be untouched by any of it. */
check(await page.evaluate(() => document.documentElement.dir) === panelDir,
    'the panel keeps its own direction throughout', panelDir);

check(errors.length === 0, 'no console errors', errors.join(' | '));

await page.screenshot({ path: new URL('../build/preview-frame.png', import.meta.url).pathname.slice(1) });
await browser.close();

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
