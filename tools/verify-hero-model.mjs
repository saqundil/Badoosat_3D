/**
 * Acceptance checks for the hero slide's model controls.
 *
 * The promise worth testing is a negative one: a slide that says it has no
 * model must not cost a visitor one byte of three.js. That cannot be read off
 * the markup -- the import is dynamic -- so this counts network requests.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-hero-model.mjs
 */

import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = new URL('../', import.meta.url).pathname.slice(1);
const OUT = ROOT + 'build/';

const WIDTHS = [1440, 1024, 900, 768, 390, 360];

/** Anything that only exists to draw a garment. */
const IS_3D = (url) => /vendor\/three|three\.module|\.glb($|\?)|hero3d\.js|model-core\.js/.test(url);

await mkdir(OUT, { recursive: true });

const pass = [];
const fail = [];
const check = (ok, label, detail = '') => {
    (ok ? pass : fail).push(label + (detail ? '  [' + detail + ']' : ''));
    console.log((ok ? '  PASS  ' : '  FAIL  ') + label + (detail ? '  ' + detail : ''));
};

const fixture = (command) =>
    execFileSync('php', [ROOT + 'tools/hero-fixture.php', command], { encoding: 'utf8' }).trim();

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

const report = [];

console.log(fixture('snapshot'));

try {
    for (const mode of ['model', 'no-model']) {
        console.log('\n================ ' + mode + ' ================');
        console.log('  ' + fixture(mode));

        for (const width of WIDTHS) {
            const page = await browser.newPage();
            const requests = [];
            const warnings = [];

            await page.setCacheEnabled(false);
            page.on('request', (r) => requests.push(r.url()));
            page.on('console', (m) => {
                /* The dev router serves no favicon and never has; that 404 is
                   the server's, not the hero's. The URL is on the location,
                   not in the message, which only says a resource failed. */
                const where = m.location()?.url ?? '';

                if (m.type() === 'error' && !where.includes('favicon')) {
                    warnings.push(m.text() + ' <' + where + '>');
                }
            });
            page.on('pageerror', (e) => warnings.push('pageerror: ' + e.message));

            await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
            await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
            await page.evaluate(() => document.fonts.ready);
            /* Long enough for the observer to fire and the import to land. */
            await new Promise((r) => setTimeout(r, 1200));

            const data = await page.evaluate(() => {
                const card = document.querySelector('[data-hero-card]');
                const island = document.querySelector('[data-hero-data]');
                const slides = island ? JSON.parse(island.textContent).slides : [];

                return {
                    slideCount: slides.length,
                    hasModel: slides[0]?.has_model ?? null,
                    modelFile: slides[0]?.model_file ?? null,
                    layers: slides[0]?.design_layers?.length ?? 0,
                    showBadges: slides[0]?.show_badges ?? null,
                    stageInDom: document.querySelector('[data-hero-stage]') !== null,
                    canvasInDom: document.querySelector('[data-hero-canvas]') !== null,
                    posterInDom: document.querySelector('[data-hero-poster]') !== null,
                    isLive: card?.classList.contains('is-live') ?? false,
                    noModelClass: card?.classList.contains('hero__card--no-model') ?? false,
                    statsHidden: document.querySelector('[data-hero-stats]')?.hidden ?? null,
                    /* Both boxes as a share of the card, so the check is the
                       one that matters: the badges must not cross the word. */
                    bands: (() => {
                        const c = card.getBoundingClientRect();
                        const band = (sel) => {
                            const el = document.querySelector(sel);
                            if (!el || el.hidden) return null;
                            const r = el.getBoundingClientRect();
                            return { top: +((r.top - c.top) / c.height * 100).toFixed(1),
                                     bot: +((r.bottom - c.top) / c.height * 100).toFixed(1) };
                        };
                        return { stats: band('[data-hero-stats]'), word: band('[data-hero-wordmark]') };
                    })(),
                    bg: (() => {
                        const el = document.querySelector('[data-hero-bg]');
                        if (!el) return null;
                        const cs = getComputedStyle(el);
                        return { color: cs.backgroundColor, image: cs.backgroundImage };
                    })(),
                    wordmark: document.querySelector('[data-hero-wordmark]')?.textContent?.trim() ?? '',
                    scrollW: document.documentElement.scrollWidth,
                    clientW: document.documentElement.clientWidth,
                };
            });

            const threeD = requests.filter(IS_3D);
            const tag = mode + ' ' + width;
            /* Below 768, or on a coarse pointer, the poster stands in at every
               width -- so only the wide cases prove a model actually loads. */
            const expectsModel = mode === 'model' && width >= 768;

            report.push({ mode, width, threeD, ...data });

            console.log('\n--- ' + tag + ' ---');
            console.log('  has_model=' + data.hasModel + ' stage=' + data.stageInDom +
                ' live=' + data.isLive + ' 3D requests=' + threeD.length);

            if (mode === 'no-model') {
                check(threeD.length === 0, tag + ': ZERO 3D requests',
                    threeD.length === 0 ? '0' : threeD.join(' '));
                check(data.stageInDom === false, tag + ': no model layer in the HTML');
                check(data.canvasInDom === false, tag + ': no canvas');
                check(data.posterInDom === false, tag + ': no garment poster');
                check(data.noModelClass, tag + ': the card says it has no model');
                check(data.layers === 0, tag + ': no design layers published');
            } else {
                check(data.stageInDom, tag + ': the model layer is in the HTML');
                check(data.modelFile === '/models/tshirt.glb',
                    tag + ': the file comes from the registry', String(data.modelFile));

                if (expectsModel) {
                    check(threeD.length > 0, tag + ': the model is fetched', String(threeD.length));
                    check(data.isLive, tag + ': the scene goes live');
                } else {
                    check(threeD.length === 0, tag + ': narrow screens keep the poster',
                        threeD.join(' ') || '0');
                }
            }

            /* True in both modes: the slide's own background and words are the
               slide's, model or no model. */
            const painted = (data.bg?.color && data.bg.color !== 'rgba(0, 0, 0, 0)')
                || (data.bg?.image && data.bg.image !== 'none');
            check(Boolean(painted), tag + ': the slide background is painted',
                data.bg?.color + ' / ' + (data.bg?.image ?? '').slice(0, 40));
            check(data.wordmark !== '', tag + ': the words are there', data.wordmark);
            check(data.scrollW <= data.clientW + 1, tag + ': no horizontal scroll',
                data.scrollW + ' vs ' + data.clientW);

            /*
             * The badges sit in a band of the card, model or no model, and that
             * band has to stay clear of the wordmark either way -- which is the
             * whole reason a slide with no garment needs no second position.
             */
            const { stats, word } = data.bands;

            if (stats && word) {
                check(stats.top >= word.bot - 0.5,
                    tag + ': the badges clear the wordmark',
                    'badges ' + stats.top + '-' + stats.bot + '%, word ' + word.top + '-' + word.bot + '%');
            }

            check(warnings.length === 0, tag + ': no console errors', warnings.join(' | '));

            await page.screenshot({ path: OUT + 'model-' + mode + '-' + width + '.png' });
            await page.close();
        }
    }
} finally {
    console.log('\n' + fixture('restore'));
    await browser.close();
}

await writeFile(OUT + 'hero-model.json', JSON.stringify(report, null, 2));

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
