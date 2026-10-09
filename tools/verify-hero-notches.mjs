/**
 * Acceptance checks for the hero's three notches and the chrome floating in
 * them: the geometry constraints at every width, no horizontal scroll, the
 * links reachable, and nothing drawn over them.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-hero-notches.mjs
 */

import { mkdir, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = new URL('../build/', import.meta.url).pathname.slice(1);

const WIDTHS = [1440, 1024, 900, 768, 600, 390, 360];

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

const report = [];

for (const dir of ['ltr', 'rtl']) {
    for (const width of WIDTHS) {
        const page = await browser.newPage();
        const warnings = [];

        page.on('console', (m) => {
            if (m.type() === 'warning' || m.type() === 'error') {
                warnings.push(m.text());
            }
        });
        page.on('pageerror', (e) => warnings.push('pageerror: ' + e.message));

        await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
        await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

        if (dir === 'rtl') {
            await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
            await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => r())));
            /* nudge the observer so the path is rebuilt mirrored */
            await page.evaluate(() => window.dispatchEvent(new Event('resize')));
        }

        await page.evaluate(() => document.fonts.ready);
        await new Promise((r) => setTimeout(r, 350));

        const data = await page.evaluate(() => {
            const card = document.querySelector('[data-hero-card]');
            const box = (sel) => {
                const el = document.querySelector(sel);

                if (el === null) {
                    return null;
                }

                const r = el.getBoundingClientRect();
                const cs = getComputedStyle(el);

                return {
                    x: r.x, y: r.y, w: r.width, h: r.height,
                    display: cs.display, visible: cs.visibility,
                };
            };

            const cardRect = card.getBoundingClientRect();

            return {
                card: { w: cardRect.width, h: cardRect.height, x: cardRect.x, y: cardRect.y },
                notchStart: box('[data-hero-notch="start"]'),
                notchEnd: box('[data-hero-notch="end"]'),
                notchBottom: box('[data-hero-notch="bottom"]'),
                chrome: box('[data-hero-chrome]'),
                single: getComputedStyle(card).getPropertyValue('--chrome-mode').trim() === 'single',
                menuButton: box('[data-hero-menu-button]'),
                navDisplay: getComputedStyle(document.querySelector('[data-hero-menu]')).display,
                navLinks: [...document.querySelectorAll('[data-hero-menu] a')].map((a) => ({
                    text: a.textContent.trim(), href: a.getAttribute('href'),
                })),
                /* every arc radius in the outline, in path order */
                arcs: (() => {
                    const el = document.querySelector('[data-hero-clip]');
                    const cp = el ? getComputedStyle(el).clipPath : '';
                    return [...cp.matchAll(/A ([0-9.]+) /g)].map((m) => +m[1]);
                })(),
                clipped: [...document.querySelectorAll('[data-hero-clip]')]
                    .map((el) => (getComputedStyle(el).clipPath || '').slice(0, 6)),
                isNotched: card.classList.contains('is-notched'),
                violation: card.getAttribute('data-hero-geometry-violation'),
                cssVars: {
                    R: getComputedStyle(card).getPropertyValue('--R').trim(),
                    gap: getComputedStyle(card).getPropertyValue('--notch-gap').trim(),
                    startW: getComputedStyle(card).getPropertyValue('--notch-start-w').trim(),
                    startH: getComputedStyle(card).getPropertyValue('--notch-start-h').trim(),
                    endH: getComputedStyle(card).getPropertyValue('--notch-end-h').trim(),
                    bottomH: getComputedStyle(card).getPropertyValue('--notch-bottom-h').trim(),
                },
                scrollW: document.documentElement.scrollWidth,
                clientW: document.documentElement.clientWidth,
                bodyBg: getComputedStyle(document.body).backgroundColor,
                cardBg: getComputedStyle(card).backgroundColor,
                topbar: document.querySelector('.topbar') !== null,
                /* what is painted at the centre of each floating group */
                hitStart: (() => {
                    const el = document.querySelector('[data-hero-notch="start"] a, [data-hero-notch="start"] button');
                    if (!el) return null;
                    const r = el.getBoundingClientRect();
                    const t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                    return t === null ? null : (t.closest('[data-hero-notch]') !== null);
                })(),
                hitEnd: (() => {
                    const el = document.querySelector('[data-hero-notch="end"] a');
                    if (!el) return null;
                    const r = el.getBoundingClientRect();
                    const t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                    return t === null ? null : (t.closest('[data-hero-notch]') !== null);
                })(),
                hitCta: (() => {
                    const el = document.querySelector('[data-hero-notch="bottom"]');
                    if (!el) return null;
                    const r = el.getBoundingClientRect();
                    const t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                    return t === null ? null : (t.closest('[data-hero-notch]') !== null);
                })(),
            };
        });

        /* --- the constraints, recomputed here from the measured boxes --- */
        const S = data.card.w;
        const H = data.card.h;
        const R = Math.min(Math.round(S * 0.115), 64);
        const curveFor = (nw, nh) => Math.min(52, Math.max(24, Math.round(Math.min(nh, nw) * 0.45)));
        const gapFor = (hc) => Math.min(14, Math.max(6, Math.round(hc * 0.12)));

        const sweepFor = (nh) => {
            const sweepR = Math.max(1, Math.round(nh));
            const cos = 1 - nh / (2 * sweepR);
            const theta = Math.acos(Math.max(-1, Math.min(1, cos)));
            return { sweepR, theta, deg: +(theta * 180 / Math.PI).toFixed(1),
                     run: Math.round(2 * sweepR * Math.sin(theta)) };
        };

        const notch = (name, b, full = false) => {
            if (b === null || b.display === 'none') return null;
            const hc = Math.round(b.h);
            const wc = Math.round(b.w);
            const gap = gapFor(hc);
            if (full) return { nw: Math.round(S), nh: hc + gap, gap, hc, wc, full: true, top: true, run: 0 };
            const nw = wc + gap;
            const nh = hc + gap;
            if (name === 'bottom') {
                const c = curveFor(nw, nh);
                return { nw, nh, gap, hc, wc, full: false, top: false, nr: c, nfEdge: c };
            }
            return { nw, nh, gap, hc, wc, full: false, top: true, ...sweepFor(nh) };
        };

        const N = data.single
            ? { start: notch('start', data.chrome, true), end: null, bottom: notch('bottom', data.notchBottom) }
            : { start: notch('start', data.notchStart), end: notch('end', data.notchEnd),
                bottom: notch('bottom', data.notchBottom) };

        const rows = [];

        for (const [name, n] of Object.entries(N)) {
            if (n === null || n.full) continue;
            if (n.top) {
                rows.push({ dir, width, notch: name, rule: 'sweepR >= nh / 2', left: n.sweepR, right: n.nh / 2, ok: n.sweepR >= n.nh / 2, ge: true });
                rows.push({ dir, width, notch: name, rule: 'nw + run <= W - R', left: n.nw + n.run, right: Math.round(S - R), ok: n.nw + n.run <= S - R });
                continue;
            }
            rows.push({ dir, width, notch: name, rule: 'nr + nfEdge <= nh', left: n.nr + n.nfEdge, right: n.nh, ok: n.nr + n.nfEdge <= n.nh });
            rows.push({ dir, width, notch: name, rule: 'nr + R <= nw', left: n.nr + R, right: n.nw, ok: n.nr + R <= n.nw });
            rows.push({ dir, width, notch: name, rule: 'nw + nfEdge <= W - R', left: n.nw + n.nfEdge, right: Math.round(S - R), ok: n.nw + n.nfEdge <= S - R });
        }

        if (N.start && N.end && !N.start.full && !N.end.full) {
            const l = N.start.nw + N.start.run + N.end.nw + N.end.run;
            rows.push({ dir, width, notch: 'top pair', rule: 'nwS + runS + nwE + runE <= W - 2R', left: l, right: Math.round(S - 2 * R), ok: l <= S - 2 * R });
        }

        report.push({
            dir, width, S: Math.round(S), H: Math.round(H), R, single: data.single,
            notches: N, rows, vars: data.cssVars, violation: data.violation, arcs: data.arcs,
        });

        const tag = dir + ' ' + width;

        console.log('\n--- ' + tag + ' ---');
        console.log('  S=' + Math.round(S) + ' H=' + Math.round(H) +
            ' R=' + R);

        console.log('  top: ' + (data.single ? 'ONE notch spanning the edge' : 'two corner notches'));

        for (const [name, n] of Object.entries(N)) {
            console.log('  notch ' + name.padEnd(7) + (n === null
                ? 'not rendered'
                : 'nw=' + n.nw + ' nh=' + n.nh + ' gap=' + n.gap + ' (content ' + n.wc + 'x' + n.hc + ')' +
                  (n.full ? ' spans edge' : n.top ? ' sweepR=' + n.sweepR + ' theta=' + n.deg + ' run=' + n.run : ' curve=' + n.nr)));
        }

        for (const r of rows.filter((r) => !r.ok)) {
            console.log('  VIOLATION ' + r.notch + '  ' + r.rule + '  ' + r.left + ' > ' + r.right);
        }

        check(data.scrollW <= data.clientW + 1, tag + ': no horizontal scroll',
            data.scrollW + ' vs ' + data.clientW);
        check(data.topbar === false, tag + ': no separate navigation bar');
        check(data.cardBg === 'rgba(0, 0, 0, 0)', tag + ': card has no background of its own', data.cardBg);
        check(data.clipped.length === 2 && data.clipped.every((c) => c === 'path("'),
            tag + ': both layers clipped by path()', data.clipped.join(','));
        check(data.isNotched, tag + ': notches applied');
        check(data.navLinks.length === 3, tag + ': all three links present',
            data.navLinks.map((l) => l.href).join(' '));
        check(data.hitStart === true, tag + ': start notch reachable');
        check(data.hitEnd === true, tag + ': end notch reachable');
        check(data.violation === null || data.violation === undefined,
            tag + ': no geometry violation reported by the page',
            String(data.violation));
        check(data.hitCta === true, tag + ': bottom notch reachable');

        const folded = data.menuButton !== null && data.menuButton.display !== 'none';

        check(width >= 1100 ? !folded : folded, tag + ': menu folded exactly when narrow',
            'folded=' + folded);

        check(width < 600 ? data.single : !data.single,
            tag + ': one spanning notch exactly below 600', 'single=' + data.single);

        check(!data.single || N.start.nh + 1 >= data.chrome.h,
            tag + ': the spanning notch is cut to the row');

        if (folded) {
            check(data.navDisplay === 'none', tag + ': folded links start closed', data.navDisplay);
        }

        await page.screenshot({ path: OUT + 'notch-' + dir + '-' + width + '.png' });
        await page.close();
    }
}

await writeFile(OUT + 'notch-geometry.json', JSON.stringify(report, null, 2));

console.log('\n================ constraint summary ================');
const bad = report.flatMap((r) => r.rows.filter((x) => !x.ok));

if (bad.length === 0) {
    console.log('  every constraint holds at every width');
} else {
    const seen = new Set();

    for (const b of bad) {
        const k = b.width + b.notch + b.rule;
        if (seen.has(k)) continue;
        seen.add(k);
        console.log('  ' + String(b.width).padStart(5) + '  ' + b.notch.padEnd(9) +
            b.rule.padEnd(32) + b.left + ' > ' + b.right);
    }
}

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

await browser.close();
process.exit(fail.length === 0 ? 0 : 1);
