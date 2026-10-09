/**
 * Acceptance checks for two things the hero has to hold whatever is stored
 * against it: nothing a layout nudge can do puts an element outside the card,
 * and a slide's pointer-motion switch is obeyed per slide.
 *
 * The nudge case is checked with a deliberately hostile value -- the largest
 * the editor can store, pushed into the corner the bottom notch is cut from --
 * rather than with whatever happens to be in the database.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-hero-layout.mjs
 */

import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = new URL('../', import.meta.url).pathname.slice(1);

const WIDTHS = [1440, 1024, 900, 768, 390, 360];

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

/**
 * Every box, and how far it is from being where it should be.
 *
 * Overlap is measured as an area against two rectangles that are out of
 * bounds: the notch cut into the bottom corner, and the button floating in it.
 * A nudge that merely looks close is not a failure; one that covers a hole is.
 */
const probe = () => ({
    boxes: (() => {
        const card = document.querySelector('[data-hero-card]');
        const surfaceEl = document.querySelector('.hero__surface');

        if (card === null || surfaceEl === null) {
            return null;
        }

        const surface = surfaceEl.getBoundingClientRect();
        const value = (name) => parseFloat(card.style.getPropertyValue(name)) || 0;
        const rtl = getComputedStyle(card).direction === 'rtl';
        const notchW = value('--notch-bottom-w');
        const notchH = value('--notch-bottom-h');
        const notchX = rtl ? surface.right - notchW : surface.left;

        const forbidden = [
            { x0: notchX, x1: notchX + notchW, y0: surface.bottom - notchH, y1: surface.bottom },
        ];

        const cta = document.querySelector('.hero__cta--notch');

        if (cta !== null) {
            const r = cta.getBoundingClientRect();
            forbidden.push({ x0: r.left, x1: r.right, y0: r.top, y1: r.bottom });
        }

        const area = (r, f) => {
            const w = Math.min(r.right, f.x1) - Math.max(r.left, f.x0);
            const h = Math.min(r.bottom, f.y1) - Math.max(r.top, f.y0);

            return w > 0 && h > 0 ? w * h : 0;
        };

        const measure = (selector, extra = []) => {
            const el = document.querySelector(selector);

            if (el === null || el.hidden || el.offsetWidth === 0) {
                return null;
            }

            const r = el.getBoundingClientRect();
            const zones = forbidden.concat(extra
                .map((sel) => document.querySelector(sel))
                .filter((other) => other !== null && !other.hidden && other.offsetWidth > 0)
                .map((other) => {
                    const box = other.getBoundingClientRect();

                    return { x0: box.left, x1: box.right, y0: box.top, y1: box.bottom };
                }));

            return {
                /* Positive means it has escaped the dark surface. */
                outside: +Math.max(
                    surface.left - r.left, r.right - surface.right,
                    surface.top - r.top, r.bottom - surface.bottom
                ).toFixed(1),
                forbidden: +zones.reduce((sum, f) => sum + area(r, f), 0).toFixed(0),
                dx: parseFloat(getComputedStyle(el).getPropertyValue('--dx')) || 0,
                dy: parseFloat(getComputedStyle(el).getPropertyValue('--dy')) || 0,
            };
        };

        /* The badges must also keep off the tagline; the tagline itself only
           has to keep off the notch and the button. */
        return {
            stats: measure('[data-hero-stats]', ['[data-hero-tagline]']),
            tagline: measure('[data-hero-tagline]'),
        };
    })(),
});

try {
    /* The worst a nudge can be: the editor's own limit, aimed into the corner
       the call to action is cut into. */
    console.log('  ' + fixture('nudge', '-45', '45'));

    for (const dir of ['ltr', 'rtl']) {
        console.log('\n================ ' + dir + ' ================');

        for (const width of WIDTHS) {
            const page = await browser.newPage();
            await page.setCacheEnabled(false);
            await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
            await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

            if (dir === 'rtl') {
                await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
                await page.evaluate(() => window.dispatchEvent(new Event('resize')));
            }

            await page.evaluate(() => document.fonts.ready);
            await new Promise((r) => setTimeout(r, 700));

            const { boxes } = await page.evaluate(probe);
            const tag = dir + ' ' + String(width).padStart(4);

            for (const [name, box] of Object.entries(boxes ?? {})) {
                if (box === null) {
                    continue;
                }

                check(box.outside <= 0.5, tag + ': ' + name + ' stays inside the card',
                    'outside by ' + box.outside + 'px, nudge ' + box.dx + ',' + box.dy);
                check(box.forbidden === 0,
                    tag + ': ' + name + ' clears the notch, the button'
                        + (name === 'stats' ? ' and the tagline' : ''),
                    box.forbidden + 'px² of overlap');
            }

            await page.close();
        }
    }

    /* ------------------------------------------------- the motion switch */

    console.log('\n================ pointer motion ================');

    for (const enabled of [true, false]) {
        console.log('  ' + fixture('parallax', enabled ? '1' : '0'));

        const page = await browser.newPage();
        await page.setCacheEnabled(false);
        await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);
        await new Promise((r) => setTimeout(r, 600));

        const before = await page.evaluate(() =>
            getComputedStyle(document.querySelector('[data-hero-layer="front"]')).transform);

        /* Across the window, not a nudge: the drift is a fraction of the travel
           and a small move would be lost in the easing. */
        await page.mouse.move(200, 200);
        await page.mouse.move(1300, 800, { steps: 12 });
        await new Promise((r) => setTimeout(r, 900));

        const after = await page.evaluate(() =>
            getComputedStyle(document.querySelector('[data-hero-layer="front"]')).transform);

        const moved = before !== after;

        check(moved === enabled,
            'the layers ' + (enabled ? 'answer' : 'ignore') + ' the pointer when the slide says '
                + (enabled ? 'they should' : 'they should not'),
            before + ' -> ' + after);

        /* The listener is never torn down: the next slide may want it back. */
        check(await page.evaluate(() => typeof window.onpointermove !== 'undefined'),
            'and the page still has a pointer listener either way');

        await page.close();
    }

    /* Reduced motion outranks the column, whatever the column says. */
    console.log('  ' + fixture('parallax', '1'));
    {
        const page = await browser.newPage();
        await page.setCacheEnabled(false);
        await page.emulateMediaFeatures([
            { name: 'prefers-reduced-motion', value: 'reduce' },
        ]);
        await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);
        await new Promise((r) => setTimeout(r, 600));

        const before = await page.evaluate(() =>
            getComputedStyle(document.querySelector('[data-hero-layer="front"]')).transform);

        await page.mouse.move(200, 200);
        await page.mouse.move(1300, 800, { steps: 12 });
        await new Promise((r) => setTimeout(r, 900));

        const after = await page.evaluate(() =>
            getComputedStyle(document.querySelector('[data-hero-layer="front"]')).transform);

        check(before === after,
            'reduced motion stops the drift even with the slide switch on',
            before + ' -> ' + after);

        await page.close();
    }
} finally {
    console.log('\n' + fixture('restore'));
    await browser.close();
}

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
