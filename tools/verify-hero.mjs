/**
 * Acceptance checks for the hero: layer order, the concave joint, slide
 * switching, the mobile cut-off, attribution, accessibility and LCP.
 *
 *   php -S 127.0.0.1:8899 -t public
 *   node tools/verify-hero.mjs
 */

import { mkdir, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = new URL('../build/', import.meta.url).pathname.slice(1);

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

/* ============================== DESKTOP ============================== */
console.log('\n--- desktop 1440x900 ---');
{
    const page = await browser.newPage();
    const requests = [];
    page.on('request', (r) => requests.push(r.url()));
    page.on('pageerror', (e) => console.log('  page error:', e.message));

    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    await page.waitForSelector('.hero__card.is-live', { timeout: 40000 });

    check(requests.some((u) => u.includes('/vendor/three/build/three.module.js')), 'desktop loads three.js');
    check(requests.filter((u) => u.includes('tshirt.glb')).length === 1, 'model fetched once');

    /* ---- criterion 2: the five layers, in the order the brief sets out ---- */
    const layers = await page.evaluate(() => {
        const z = (sel) => {
            const el = document.querySelector(sel);

            return el === null ? null : getComputedStyle(el).zIndex;
        };
        const rect = (sel) => {
            const el = document.querySelector(sel);

            return el ? el.getBoundingClientRect().toJSON() : null;
        };

        return {
            bg: z('[data-hero-bg]'),
            back: z('[data-hero-layer="back"]'),
            tab: z('[data-hero-tab]'),
            stage: z('[data-hero-stage]'),
            front: z('[data-hero-layer="front"]'),
            frontPointerEvents: getComputedStyle(document.querySelector('[data-hero-layer="front"]')).pointerEvents,
            card: rect('[data-hero-card]'),
            wordmark: rect('[data-hero-wordmark]'),
            stats: rect('[data-hero-stats]'),
        };
    });

    /*
     * The light tab is the editor preview's now: on the site the card carries
     * the navigation in its notches instead, and there is no tab to order. Its
     * z-index is checked only where it is rendered.
     */
    const tabOrder = layers.tab === null || +layers.tab === 22;

    check(+layers.bg === 10 && +layers.back === 20 && tabOrder
        && +layers.stage === 30 && +layers.front === 40,
        'layer order bg 10 < wordmark 20 < ' + (layers.tab === null ? '' : 'tab 22 < ') + 'canvas 30 < front 40',
        [layers.bg, layers.back, layers.tab ?? 'no tab', layers.stage, layers.front].join(' '));
    check(layers.frontPointerEvents === 'none', 'the front layer is pointer-events:none');

    // The garment renders in the middle of the card; anything claiming to be in
    // front of it has to overlap that band.
    const band = { x0: layers.card.x + layers.card.width * 0.32, x1: layers.card.x + layers.card.width * 0.68 };
    const overlaps = (r) => r.x < band.x1 && r.x + r.width > band.x0;

    check(overlaps(layers.wordmark), 'the wordmark crosses the garment (and sits behind it)');
    check(overlaps(layers.stats), 'the stat cards cross the garment (and sit in front of it)');

    await page.screenshot({ path: OUT + 'shot-desktop-slide1.png' });

    /* ---------- criterion 1: switching swaps colour, text, background ------- */
    /*
     * Autoplay off for this one. It re-arms on every goTo and reads the media
     * query as it does, so the first click below is what stops it. Left running
     * it can advance the slide inside the settling wait, and then the two
     * samples are of the same slide and the check fails for a reason that has
     * nothing to do with what it is asking. What is under test is the dot.
     */
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);

    await page.evaluate(() => document.querySelectorAll('.hero__dot')[0].click());
    await new Promise((r) => setTimeout(r, 700));

    const before = await page.evaluate(() => ({
        wordmark: document.querySelector('[data-hero-wordmark]').textContent,
        tab: document.querySelector('[data-hero-tab-word]')?.textContent ?? null,
        bg: getComputedStyle(document.querySelector('[data-hero-bg]')).backgroundColor,
        stats: [...document.querySelectorAll('.hero__stat-value')].map((s) => s.textContent).join('|'),
    }));

    const glbBefore = requests.filter((u) => u.includes('tshirt.glb')).length;
    await page.evaluate(() => document.querySelectorAll('.hero__dot')[1].click());

    // Headless runs its animation timeline slower than wall time, so poll for
    // the transition to land rather than sampling one moment.
    await page.waitForFunction(
        (was) => getComputedStyle(document.querySelector('[data-hero-bg]')).backgroundColor !== was,
        { timeout: 6000, polling: 100 }, before.bg
    ).catch(() => {});

    const after = await page.evaluate(() => ({
        wordmark: document.querySelector('[data-hero-wordmark]').textContent,
        tab: document.querySelector('[data-hero-tab-word]')?.textContent ?? null,
        bg: getComputedStyle(document.querySelector('[data-hero-bg]')).backgroundColor,
        stats: [...document.querySelectorAll('.hero__stat-value')].map((s) => s.textContent).join('|'),
        live: document.querySelector('.hero__card').classList.contains('is-live'),
        current: [...document.querySelectorAll('.hero__dot')].map((d) => d.getAttribute('aria-current')),
    }));

    check(before.wordmark !== after.wordmark, 'the wordmark changes', before.wordmark + ' -> ' + after.wordmark);
    if (before.tab !== null) {
        check(before.tab !== after.tab, 'the tab word changes', before.tab + ' -> ' + after.tab);
    } else {
        console.log('  SKIP  the tab word changes  [no tab on the site hero]');
    }
    check(before.bg !== after.bg, 'the background changes', before.bg + ' -> ' + after.bg);
    check(before.stats !== after.stats, 'the stat cards change', before.stats + ' -> ' + after.stats);
    check(requests.filter((u) => u.includes('tshirt.glb')).length === glbBefore, 'the model is NOT refetched');
    check(after.live, 'the canvas stays live through the switch: no flash back to the poster');
    check(after.current[1] === 'true' && after.current[0] === 'false', 'the dots follow the slide');

    await page.emulateMediaFeatures([]);

    await page.screenshot({ path: OUT + 'shot-desktop-slide2.png' });

    /* ------------------------ criterion 13: attribution -------------------- */
    const credit = await page.evaluate(() => {
        const el = document.querySelector('.credit');

        return el ? {
            text: el.textContent.replace(/\s+/g, ' ').trim(),
            links: [...el.querySelectorAll('a')].map((a) => a.href),
            visible: el.getBoundingClientRect().height > 0,
        } : null;
    });

    check(credit !== null && credit.visible && credit.text.includes('MadeByYeshe'),
        'the attribution names the author');
    check(credit.links.some((h) => h.includes('sketchfab.com/3d-models/basic-t-shirt')),
        'it links the original model');
    check(credit.links.some((h) => h.includes('creativecommons.org/licenses/by/4.0')),
        'it links the licence');

    /* --------------------------- accessibility ----------------------------- */
    const a11y = await page.evaluate(() => ({
        label: document.querySelector('.hero').getAttribute('aria-label'),
        layersHidden: [...document.querySelectorAll('.hero__layer, [data-hero-tab]')]
            .every((l) => l.getAttribute('aria-hidden') === 'true'),
        dotsAreButtons: [...document.querySelectorAll('.hero__dot')]
            .every((d) => d.tagName === 'BUTTON' && d.hasAttribute('aria-label')),
        srLength: document.querySelector('[data-hero-sr]')?.textContent?.length ?? 0,
    }));

    check(!!a11y.label, 'the hero section has an accessible name', a11y.label);
    check(a11y.layersHidden, 'decorative layers are aria-hidden, so nothing is read twice');
    check(a11y.dotsAreButtons, 'the dots are real, labelled buttons');
    check(a11y.srLength > 0, 'the slide copy is exposed once to screen readers');

    const keyboard = await page.evaluate(async () => {
        const dots = [...document.querySelectorAll('.hero__dot')];
        dots[0].focus();
        const start = dots.findIndex((d) => d.getAttribute('aria-current') === 'true');
        document.querySelector('[data-hero-dots]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
        await new Promise((r) => setTimeout(r, 250));

        return {
            start,
            end: [...document.querySelectorAll('.hero__dot')].findIndex((d) => d.getAttribute('aria-current') === 'true'),
        };
    });

    check(keyboard.start !== keyboard.end, 'arrow keys move between slides',
        keyboard.start + ' -> ' + keyboard.end);

    await page.close();
}

/* ================= criterion 3: the concave joint, any width ============= */
console.log('\n--- criterion 3: the tab joins the card edge with no gap ---');
{
    for (const width of [1600, 1440, 1180, 980, 820, 768]) {
        const page = await browser.newPage();
        await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
        await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
        await new Promise((r) => setTimeout(r, 400));

        /* The joint belongs to the tab, and the tab belongs to the editor
           preview now; the site card's three notches are covered by
           tools/verify-hero-notches.mjs instead. */
        if (await page.evaluate(() => document.querySelector('[data-hero-tab]') === null)) {
            console.log('  SKIP  ' + width + ': no tab on the site hero');
            await page.close();
            continue;
        }

        const probe = await page.evaluate(() => {
            const card = document.querySelector('[data-hero-card]').getBoundingClientRect();
            const tab = document.querySelector('[data-hero-tab]').getBoundingClientRect();
            const notch = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--tab-notch'));
            const paper = getComputedStyle(document.documentElement).getPropertyValue('--paper').trim();

            return { card: card.toJSON(), tab: tab.toJSON(), notch, paper };
        });

        // One row two pixels below the card's top edge, running from beyond the
        // notch into the tab. The dark stretch at the start is the concave cut
        // itself and is meant to be there; a gap would be dark pixels appearing
        // again AFTER the paper has begun.
        const y = Math.round(probe.card.y + 2);
        const x0 = Math.round(Math.min(probe.tab.x, probe.tab.right) - probe.notch - 4);
        const x1 = Math.round(Math.max(probe.tab.x, probe.tab.right) + 4);

        const strip = await page.screenshot({
            clip: { x: Math.max(0, x0), y, width: Math.max(8, x1 - x0), height: 1 },
        });

        await writeFile(OUT + 'joint-' + width + '.png', strip);

        const dark = await page.evaluate(async (dataUrl) => {
            const image = new Image();
            await new Promise((r) => { image.onload = r; image.src = dataUrl; });
            const canvas = document.createElement('canvas');
            canvas.width = image.width;
            canvas.height = image.height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(image, 0, 0);
            const { data } = ctx.getImageData(0, 0, image.width, 1);

            // A gap would show the card colour, around 12% of paper. Sub-pixel
            // antialiasing where the tab meets the card's own rounded corner
            // never falls below 70%, so the two are not close.
            const level = (i) => (data[i] + data[i + 1] + data[i + 2]) / 3 / 237;

            let started = false;
            let gaps = 0;
            let paper = 0;
            let darkest = 1;

            for (let i = 0; i < data.length; i += 4) {
                const l = level(i);

                if (l > 0.85) {
                    started = true;
                    paper++;
                } else if (started) {
                    darkest = Math.min(darkest, l);

                    if (l < 0.6) {
                        gaps++;
                    }
                }
            }

            return { count: gaps, total: image.width, paper, darkest: Math.round(darkest * 100) };
        }, 'data:image/png;base64,' + strip.toString('base64'));

        check(dark.count === 0 && dark.paper > 20, width + 'px: the joint is unbroken',
            dark.paper + ' px of paper, no pixel below 60% (darkest ' + dark.darkest + '%)');

        await page.close();
    }
}

/* ========== the tab sits UNDER the garment: a pixel-level check ========== */
/*
 * The z-index number alone proves nothing. .hero__surface carries a clip-path,
 * which makes it a stacking context, so for a while the tab read z-22 in the
 * stylesheet and still painted above everything. What follows renders the card
 * four times — as it stands, without the tab, without the garment, without
 * either — and asks who owns the pixels the two of them share.
 *
 * The tab is parked over the middle of the card first, through the very
 * --dx/--dy the editor writes when an administrator drags it. That makes the
 * overlap certain instead of leaving it to whichever slide happens to load: a
 * tab that only escapes the garment because it is off to one side is the same
 * latent bug, one drag away from showing.
 */
console.log('\n--- the garment passes in front of the tab ---');

async function tabUnderGarment(page, label) {
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 2500));

    if (await page.evaluate(() => document.querySelector('[data-hero-tab]') === null)) {
        console.log('  SKIP  ' + label + ': no tab on the site hero');

        return;
    }

    // Park the tab on the garment and hand back the box to photograph.
    const clip = await page.evaluate(() => {
        const card = document.querySelector('[data-hero-card]').getBoundingClientRect();
        const tab = document.querySelector('[data-hero-tab]');
        const before = tab.getBoundingClientRect();

        // --dx/--dy are percentages of the card, which is the container.
        const dx = ((card.x + card.width / 2) - (before.x + before.width / 2)) / card.width * 100;
        const dy = ((card.y + card.height / 2) - (before.y + before.height / 2)) / card.height * 100;

        tab.style.setProperty('--dx', dx.toFixed(3));
        tab.style.setProperty('--dy', dy.toFixed(3));

        const r = tab.getBoundingClientRect();

        return { x: Math.round(r.x), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
    });

    const shoot = async (hide) => {
        await page.evaluate((sels) => {
            for (const s of ['[data-hero-tab]', '[data-hero-stage]']) {
                document.querySelector(s).style.visibility = sels.includes(s) ? 'hidden' : '';
            }
        }, hide);
        await new Promise((r) => setTimeout(r, 120));

        const buffer = await page.screenshot({ clip });

        return 'data:image/png;base64,' + buffer.toString('base64');
    };

    const shots = {
        all: await shoot([]),
        noTab: await shoot(['[data-hero-tab]']),
        noStage: await shoot(['[data-hero-stage]']),
        neither: await shoot(['[data-hero-tab]', '[data-hero-stage]']),
    };

    await page.evaluate(() => {
        for (const s of ['[data-hero-tab]', '[data-hero-stage]']) {
            document.querySelector(s).style.visibility = '';
        }
    });

    const verdict = await page.evaluate(async (urls) => {
        const read = async (url) => {
            const image = new Image();
            await new Promise((r) => { image.onload = r; image.src = url; });
            const canvas = document.createElement('canvas');
            canvas.width = image.width;
            canvas.height = image.height;
            canvas.getContext('2d').drawImage(image, 0, 0);

            return canvas.getContext('2d').getImageData(0, 0, image.width, image.height).data;
        };

        const all = await read(urls.all);
        const noTab = await read(urls.noTab);
        const noStage = await read(urls.noStage);
        const neither = await read(urls.neither);

        const far = (a, b, i) => Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]);

        let word = 0;
        let swallowed = 0;

        for (let i = 0; i < all.length; i += 4) {
            if (far(noStage, neither, i) < 40) {
                continue;               // the tab's word does not paint here
            }

            word++;

            if (far(all, noTab, i) < 12) {
                swallowed++;            // ...and with the stage drawn it is gone
            }
        }

        return { word, swallowed, pixels: all.length / 4 };
    }, shots);

    const hidden = verdict.word ? verdict.swallowed / verdict.word : 0;

    check(verdict.word > 100, label + ': the tab does paint over the garment’s box',
        verdict.word + ' word pixels of ' + verdict.pixels + ' in the box');
    check(hidden > 0.98, label + ': and the garment covers every one of them',
        verdict.swallowed + ' of ' + verdict.word + ' (' + Math.round(hidden * 100) + '%)');

    await page.screenshot({ path: OUT + 'tab-under-' + label + '.png', clip });

    for (const [name, url] of Object.entries(shots)) {
        await writeFile(OUT + 'tab-' + label + '-' + name + '.png',
            Buffer.from(url.split(',')[1], 'base64'));
    }
}

{
    const desktop = await browser.newPage();
    await desktop.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await tabUnderGarment(desktop, 'desktop');
    await desktop.close();

    // On a phone the poster is the garment; the layering has to hold there too.
    const phone = await browser.newPage();
    await phone.emulate({
        name: 'phone',
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 ' +
            '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true, isLandscape: false },
    });
    await tabUnderGarment(phone, 'mobile');
    await phone.close();
}

/* ============================== MOBILE ============================== */
console.log('\n--- criterion 4: mobile 390x844, coarse pointer ---');
{
    const page = await browser.newPage();
    const requests = [];
    page.on('request', (r) => requests.push(r.url()));

    await page.emulate({
        name: 'phone',
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 ' +
            '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        viewport: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true, isLandscape: false },
    });

    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 3000));

    const state = await page.evaluate(() => {
        const rect = (sel) => document.querySelector(sel).getBoundingClientRect().toJSON();
        const card = rect('[data-hero-card]');
        const poster = rect('.hero__poster');
        const wordmark = rect('[data-hero-wordmark]');
        const stats = rect('[data-hero-stats]');
        const overlapsY = (a, b) => a.top < b.bottom && a.bottom > b.top;
        const overlapsX = (a, b) => a.left < b.right && a.right > b.left;

        return {
            coarse: matchMedia('(pointer: coarse)').matches,
            width: innerWidth,
            live: document.querySelector('.hero__card').classList.contains('is-live'),
            posterOpacity: getComputedStyle(document.querySelector('.hero__poster')).opacity,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            // The poster is the product on a phone; layering has to hold here too.
            wordmarkBehind: overlapsX(wordmark, poster) && overlapsY(wordmark, poster)
                && wordmark.width > poster.width * 0.55,
            statsOnGarment: overlapsX(stats, poster) && overlapsY(stats, poster),
            cardWidth: card.width,
        };
    });

    const three = requests.filter((u) => u.includes('/vendor/three/'));
    const glb = requests.filter((u) => u.includes('.glb'));

    check(state.coarse || state.width < 768, 'the mobile gate applies',
        'coarse=' + state.coarse + ' width=' + state.width);
    check(three.length === 0, 'NOT ONE file is requested from vendor/three', three.length + ' requests');
    check(glb.length === 0, 'the model is never downloaded', glb.length + ' requests');
    check(!state.live, 'the canvas never goes live');
    check(state.posterOpacity === '1', 'the poster stays visible');
    check(state.overflow === 0, 'the page does not scroll sideways', state.overflow + ' px');
    check(state.wordmarkBehind, 'the wordmark still runs out from behind the garment');
    check(state.statsOnGarment, 'the stat cards still sit on the garment');

    await page.screenshot({ path: OUT + 'shot-mobile.png' });
    await writeFile(OUT + 'mobile-requests.txt', requests.join('\n'));
    await page.close();
}

/* ===================== criterion 5: LCP on throttled 4G ================== */
console.log('\n--- criterion 5: LCP, throttled 4G (1.6 Mbps, 150 ms RTT) ---');
{
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

    const client = await page.createCDPSession();
    await client.send('Network.enable');
    await client.send('Network.clearBrowserCache');
    await client.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: 150,
        downloadThroughput: (1.6 * 1024 * 1024) / 8,
        uploadThroughput: (750 * 1024) / 8,
    });

    await page.evaluateOnNewDocument(() => {
        window.__lcp = 0;
        window.__el = '';
        new PerformanceObserver((list) => {
            for (const e of list.getEntries()) {
                window.__lcp = e.startTime;
                window.__el = e.element ? e.element.className : '';
            }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
    });

    await page.goto(BASE + '/', { waitUntil: 'load' });
    await new Promise((r) => setTimeout(r, 3500));

    const m = await page.evaluate(() => ({
        lcp: window.__lcp,
        el: window.__el,
        fcp: performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? 0,
        beforeLcp: performance.getEntriesByType('resource')
            .filter((r) => r.responseEnd <= window.__lcp)
            .map((r) => r.name.split('/').pop()),
        weight: performance.getEntriesByType('resource').reduce((s, r) => s + (r.transferSize || 0), 0),
    }));

    check(m.lcp > 0 && m.lcp < 2500, 'LCP under 2.5 s', Math.round(m.lcp) + ' ms on .' + m.el);
    check(!m.beforeLcp.some((n) => n.endsWith('.glb')), 'the GLB is not on the LCP path',
        'before LCP: ' + m.beforeLcp.join(', '));
    console.log('  FCP ' + Math.round(m.fcp) + ' ms, page weight ' + (m.weight / 1024).toFixed(0) + ' KB');

    await page.close();
}

await browser.close();

console.log('\n============================');
console.log('passed ' + pass.length + ', failed ' + fail.length);

if (fail.length) {
    console.log('\nFAILURES:');
    fail.forEach((f) => console.log('  - ' + f));
    process.exitCode = 1;
}
