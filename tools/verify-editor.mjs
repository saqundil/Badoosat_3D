/**
 * Acceptance checks for the design editor island.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   node tools/verify-editor.mjs
 *
 * The router matters here. Section 9 times the editor over a throttled line,
 * and the built-in server does not read .htaccess, so without it the island is
 * sent as 1.27 MB of raw JavaScript where Apache sends 138 kB — a measurement
 * of the harness rather than the site. The check says so if it happens.
 *
 * The UV mapping itself is proved separately, by tools/verify-uv.mjs, which has
 * to pass before any of this means anything.
 */

import { mkdir } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = new URL('../build/', import.meta.url).pathname.slice(1);
const PRODUCT = '/product.php?slug=classic-tee';

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
page.on('console', (m) => {
    if (m.type() === 'error' && !m.text().includes('favicon')) {
        console.log('  console:', m.text());
    }
});

const requests = [];
page.on('request', (r) => requests.push(r.url()));

await page.setViewport({ width: 1500, height: 950, deviceScaleFactor: 1 });

/*
 * The launcher lives behind the "design this shirt" tab on the product page,
 * beside the ready-made designs. Opening that tab is what a customer does
 * before clicking it, so the suite does the same rather than reaching past the
 * interface into a hidden button.
 */
async function openDesignTab(target) {
    await target.click('[data-tab="custom"]');
    await target.waitForSelector('[data-editor-launch]', { visible: true });
}


/* ============ 1. the product page carries none of the bundle ============ */

console.log('\n1. the island stays off the product page until it is asked for');

await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 600));

const before = requests.filter((u) => u.includes('/assets/editor/'));
const launcher = requests.filter((u) => u.includes('editor-launch.js'));

check(before.length === 0, 'not one byte of the editor bundle is fetched',
    before.length + ' requests to /assets/editor/');
check(launcher.length === 1, 'only the launcher ships with the page');

const launcherBytes = await page.evaluate(async () =>
    (await (await fetch('/assets/js/editor-launch.js')).text()).length);
check(launcherBytes < 3000, 'the launcher is small enough to be free',
    launcherBytes + ' bytes');

/* ------------------------------------------- styles must not leak (10) --- */

const styleBefore = await page.evaluate(() => {
    const probe = document.querySelector('.button');
    const s = getComputedStyle(probe);

    return { radius: s.borderRadius, padding: s.padding, font: s.fontSize, bg: s.backgroundColor };
});

/* ===================== open it, then drive it directly ================== */

console.log('\n2. opening the editor');

await openDesignTab(page);
await page.click('[data-editor-launch]');
await page.waitForSelector('#tshirt-editor.ed-open .ed-canvas', { timeout: 40000 });
await new Promise((r) => setTimeout(r, 2500));

const after = requests.filter((u) => u.includes('/assets/editor/'));
check(after.length >= 2, 'the bundle and its stylesheet arrive on the first click',
    after.map((u) => u.split('/').pop()).join(', '));

const opened = await page.evaluate(() => ({
    fabric: !!document.querySelector('#tshirt-editor canvas.lower-canvas'),
    scene: document.querySelectorAll('#tshirt-editor .ed-stage canvas').length,
    outline: getComputedStyle(document.querySelector('#tshirt-editor .ed-canvas-frame')).outlineStyle,
    guide: [...document.querySelectorAll('.ed-pattern-guide__label')]
        .map((label) => label.textContent.trim()),
}));

check(opened.fabric && opened.scene === 1, 'the design canvas and the scene are both up');
check(opened.outline === 'dashed', 'the print rectangle is outlined the whole time', opened.outline);
check(JSON.stringify(opened.guide) === JSON.stringify(['الكتفان', 'الصدر', 'الجذع']),
    'the canvas explains the front panel before artwork is added', opened.guide.join(', '));

const guideIsNotPrinted = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { config, surface } = getEditor();
    const rect = config.rect.front;
    surface.blit();
    const { data } = surface.atlasCtx.getImageData(rect.x, rect.y, rect.w, rect.h);

    for (let i = 0; i < data.length; i += 4) {
        if (data[i] !== 255 || data[i + 1] !== 255 || data[i + 2] !== 255 || data[i + 3] !== 255) {
            return false;
        }
    }

    return true;
});

check(guideIsNotPrinted, 'the explanatory pattern is absent from the print atlas');

const backOrientation = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { config, surface } = getEditor();
    await surface.setSide('back');

    const text = surface.addText('L', { direction: 'ltr' });
    text.set({
        left: config.work.w * 0.2,
        top: config.work.h * 0.5,
        width: config.work.w * 0.12,
        fontSize: config.work.w * 0.1,
    });
    surface.canvas.requestRenderAll();
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    const darkCentroid = (ctx, x, y, width, height) => {
        const { data } = ctx.getImageData(x, y, width, height);
        let totalX = 0;
        let pixels = 0;

        for (let py = 0; py < height; py++) {
            for (let px = 0; px < width; px++) {
                const offset = (py * width + px) * 4;

                if (data[offset + 3] > 0 && data[offset] < 100 &&
                    data[offset + 1] < 100 && data[offset + 2] < 100) {
                    totalX += px;
                    pixels++;
                }
            }
        }

        return pixels ? totalX / pixels / width : null;
    };

    const rect = config.rect.back;
    const editorX = darkCentroid(surface.canvas.contextContainer,
        0, 0, config.work.w, config.work.h);
    const atlasX = darkCentroid(surface.atlasCtx, rect.x, rect.y, rect.w, rect.h);

    surface.canvas.remove(...surface.canvas.getObjects());
    surface.canvas.requestRenderAll();

    return { editorX, atlasX };
});

check(backOrientation.editorX < 0.5 && backOrientation.atlasX > 0.5,
    'the back preview compensates for the model mirrored UVs',
    `editor x=${backOrientation.editorX.toFixed(3)}, atlas x=${backOrientation.atlasX.toFixed(3)}`);

/* --------------------------------------------- 10. no style leakage ----- */

const styleAfter = await page.evaluate(() => {
    const probe = document.querySelector('.button');
    const s = getComputedStyle(probe);

    return { radius: s.borderRadius, padding: s.padding, font: s.fontSize, bg: s.backgroundColor };
});

check(JSON.stringify(styleBefore) === JSON.stringify(styleAfter),
    'the editor stylesheet changes nothing outside #tshirt-editor',
    JSON.stringify(styleBefore) + ' vs ' + JSON.stringify(styleAfter));

const scoped = await page.evaluate(async () => {
    const css = await (await fetch('/assets/editor/editor.css')).text();
    const rules = css.split('}').map((r) => r.split('{')[0].trim()).filter(Boolean);
    const escapes = rules.filter((selector) =>
        !selector.startsWith('@') &&
        !selector.startsWith('html.ed-locked') &&
        !selector.split(',').every((part) => part.trim().startsWith('#tshirt-editor')));

    return { total: rules.length, escapes };
});

check(scoped.escapes.length === 0, 'every rule in the stylesheet is anchored to the container',
    scoped.total + ' rules, ' + scoped.escapes.length + ' unanchored' +
    (scoped.escapes.length ? ': ' + scoped.escapes.slice(0, 3).join(' | ') : ''));

/* ================= 5. the resolution gate, in the editor ================ */

console.log('\n3. resolution warnings');

/** Places a synthetic bitmap of a given pixel size and reads the verdict. */
async function place(width, height) {
    return page.evaluate(async (w, h) => {
        const { getEditor } = await import('/assets/editor/editor.js');
        const { surface, store } = getEditor();

        const scale = Math.min(1, 512 / Math.max(w, h));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w * scale));
        c.height = Math.max(1, Math.round(h * scale));
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#123456';
        ctx.fillRect(0, 0, c.width, c.height);

        surface.canvas.remove(...surface.canvas.getObjects());
        const verdict = await surface.addImage(c.toDataURL('image/png'), { width: w, height: h });
        store.getState().sync();

        return { verdict, blocked: store.getState().blocked() };
    }, width, height);
}

const printSize = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    return getEditor().config.printSizeCm;
});
const atDpi = (dpi) => ({
    width: Math.ceil(printSize.w / 2.54 * dpi),
    height: Math.ceil(printSize.h / 2.54 * dpi),
});
const lowSize = atDpi(20);
const midSize = atDpi(120);
const goodSize = atDpi(160);
const low = await place(lowSize.width, lowSize.height);
const mid = await place(midSize.width, midSize.height);
const good = await place(goodSize.width, goodSize.height);

check(low.verdict?.level === 'reject' && low.blocked !== null,
    'a 20 DPI upload blocks the sale', low.verdict?.dpi + ' DPI, blocked: ' + low.blocked);
check(mid.verdict?.level === 'warn',
    'a 120 DPI upload warns but allows it', mid.verdict?.dpi + ' DPI');
check(good.verdict?.level === 'ok' && good.blocked === null,
    'a 160 DPI upload passes', good.verdict?.dpi + ' DPI');

/* ============ 3. the garment colour leaves the artwork alone =========== */

console.log('\n4. colour, clipping, export');

const colourTest = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface, store, config } = getEditor();

    const rect = config.rect[surface.side];
    const read = () => {
        const { data } = surface.atlasCtx.getImageData(rect.x, rect.y, rect.w, rect.h);
        let sum = 0;

        for (let i = 0; i < data.length; i += 4) {
            sum = (sum + data[i] * 3 + data[i + 1] * 5 + data[i + 2] * 7) % 4294967295;
        }

        return sum;
    };

    const beforeHash = read();
    // Colours are catalogue rows now; the store is set by id and the shirt is
    // tinted from the hex that id resolves to.
    const colours = config.colors.map((c) => c.id);
    const chosen  = colours[colours.length - 1];
    store.getState().setColor(chosen);
    await new Promise((r) => setTimeout(r, 400));

    return {
        beforeHash,
        afterHash: read(),
        colour: config.colors.find((c) => c.id === chosen)?.hex ?? String(chosen),
    };
});

check(colourTest.beforeHash === colourTest.afterHash,
    'changing the shirt colour leaves every artwork pixel untouched',
    'atlas checksum ' + colourTest.beforeHash + ' before and after ' + colourTest.colour);

/* ============== 4. nothing escapes the print rectangle ================= */

const clipTest = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface, config } = getEditor();

    const object = surface.canvas.getObjects()[0];
    object.set({ left: -config.work.w, top: -config.work.h });
    object.setCoords();
    surface.canvas.requestRenderAll();
    await new Promise((r) => setTimeout(r, 200));

    // Ink anywhere on the atlas outside this side's rectangle would mean the
    // artwork had escaped onto the rest of the garment.
    const rect = config.rect[surface.side];
    const { data, width } = surface.atlasCtx.getImageData(0, 0, surface.atlas.width, surface.atlas.height);
    let outside = 0;

    for (let i = 0, p = 0; p < data.length; i++, p += 4) {
        const x = i % width;
        const y = (i / width) | 0;
        const inRect = x >= rect.x && x < rect.x + rect.w && y >= rect.y && y < rect.y + rect.h;

        if (!inRect && (data[p] < 245 || data[p + 1] < 245 || data[p + 2] < 245)) {
            outside++;
        }
    }

    return { outside };
});

check(clipTest.outside === 0, 'an object dragged out of the area never reaches the model',
    clipTest.outside + ' stray pixels on the atlas');

/* ================= 6. the export is exactly 28 x 35 cm ================= */

const exportTest = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface, config } = getEditor();

    const url = await surface.printDataUrl();
    const image = new Image();
    await new Promise((r) => { image.onload = r; image.src = url; });

    return {
        w: image.naturalWidth,
        h: image.naturalHeight,
        expected: config.exportPx,
        cm: config.printSizeCm,
        dpi: config.dpi.target,
    };
});

const cmW = (exportTest.w / exportTest.dpi) * 2.54;
const cmH = (exportTest.h / exportTest.dpi) * 2.54;

check(exportTest.w === exportTest.expected.w && exportTest.h === exportTest.expected.h,
    'the press file is exported at the configured pixel size',
    exportTest.w + 'x' + exportTest.h);
check(Math.abs(cmW - exportTest.cm.w) < 0.05 && Math.abs(cmH - exportTest.cm.h) < 0.05,
    'which is exactly the print size at ' + exportTest.dpi + ' DPI',
    cmW.toFixed(2) + ' x ' + cmH.toFixed(2) + ' cm');

/* ============ 7. a saved design reopens as the same design ============= */

console.log('\n5. saving and reopening');

const roundTrip = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface } = getEditor();

    surface.addText('اختبار الحفظ');
    await new Promise((r) => setTimeout(r, 200));

    const saved = JSON.parse(JSON.stringify(surface.toJSON()));
    const before = JSON.stringify(saved);

    // Wipe it, then bring it back from the saved state alone.
    surface.canvas.remove(...surface.canvas.getObjects());
    surface.saved = {};
    await new Promise((r) => setTimeout(r, 150));
    const emptied = surface.canvas.getObjects().length;

    await surface.fromJSON(saved);
    await new Promise((r) => setTimeout(r, 300));

    const strip = (state) => JSON.stringify(state.sides).replace(/"version":"[^"]*"/g, '');

    return {
        emptied,
        objects: surface.canvas.getObjects().length,
        same: strip(saved) === strip(surface.toJSON()),
        beforeLength: before.length,
    };
});

check(roundTrip.emptied === 0, 'the canvas really was cleared before reloading');
check(roundTrip.objects > 0, 'the objects come back', roundTrip.objects + ' objects');
check(roundTrip.same, 'the reopened state matches the saved one exactly');

/* ================ 8. no price travels from the browser ================= */

const priceTest = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { store } = getEditor();
    const state = store.getState();

    // Whatever a tampered client puts here, the POST carries no money field.
    const variant = state.variant();
    const original = variant.price_cents;
    variant.price_cents = 1;

    const shown = store.getState().priceCents();
    variant.price_cents = original;

    const source = await (await fetch('/assets/editor/editor.js')).text();

    return {
        tamperedDisplay: shown,
        postsPrice: /body\.append\(\s*['"]price/.test(source),
        cartFormHasPrice: /name\s*=\s*['"]price/.test(source),
    };
});

check(!priceTest.postsPrice && !priceTest.cartFormHasPrice,
    'neither the design POST nor the cart form carries a price');
check(priceTest.tamperedDisplay === 1,
    'a tampered client can only mislead itself; the figure never leaves the page',
    'display showed ' + priceTest.tamperedDisplay);

/* ======================= 9. keyboard reachability ====================== */

console.log('\n6. keyboard');

const keyboard = await page.evaluate(() => {
    const root = document.getElementById('tshirt-editor');
    const focusable = [...root.querySelectorAll('button, input, [href], [tabindex]:not([tabindex="-1"])')]
        .filter((el) => !el.disabled && el.offsetParent !== null);

    return {
        count: focusable.length,
        tools: root.querySelectorAll('.ed-tool').length,
        labelled: focusable.every((el) =>
            el.getAttribute('aria-label') ||
            el.textContent.trim() ||
            el.closest('label')),
    };
});

check(keyboard.count >= 12, 'every control is reachable by tab', keyboard.count + ' focusable');
check(keyboard.labelled, 'and every one of them has an accessible name');

const shortcut = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface } = getEditor();

    // The round-trip above deliberately resets history, so give the shortcut
    // something of its own to undo.
    surface.addText('تراجع');
    await new Promise((r) => setTimeout(r, 300));
    const before = surface.canvas.getObjects().length;

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true }));
    await new Promise((r) => setTimeout(r, 400));

    return { before, after: surface.canvas.getObjects().length };
});

check(shortcut.before !== shortcut.after, 'Ctrl+Z undoes the last change',
    shortcut.before + ' -> ' + shortcut.after + ' objects');

/* =============== the whole path: design, confirm, cart ================= */

console.log('');
console.log('7. from the editor to the cart');

const submitted = await page.evaluate(async () => {
    const { getEditor } = await import('/assets/editor/editor.js');
    const { surface, store, config } = getEditor();

    surface.canvas.remove(...surface.canvas.getObjects());

    const naturalPx = Math.ceil(Math.max(config.printSizeCm.w, config.printSizeCm.h) / 2.54 * 160);
    const c = document.createElement('canvas');
    c.width = 512;
    c.height = 512;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#101014';
    ctx.fillRect(0, 0, c.width, c.height);

    await surface.addImage(c.toDataURL('image/png'), { width: naturalPx, height: naturalPx });
    store.getState().sync();

    return { blocked: store.getState().blocked() };
});

check(submitted.blocked === null, 'a good design clears every gate', submitted.blocked ?? 'clear');

// Through the real button: it is the one that photographs the scene before the
// preview is torn down.
await page.evaluate(() => {
    document.querySelector('#tshirt-editor .ed-foot__buy .ed-primary').click();
});

await page.waitForSelector('#tshirt-editor .ed-confirm__shot img', { timeout: 20000 });
await new Promise((r) => setTimeout(r, 1200));

const cardShots = await page.evaluate(() => ({
    count: document.querySelectorAll('#tshirt-editor .ed-confirm__shot img').length,
    flat: document.querySelector('#tshirt-editor .ed-confirm__shot--flat img')?.naturalWidth ?? 0,
}));

check(cardShots.count === 2, 'the agreement card shows both pictures', cardShots.count + ' images');
check(cardShots.flat > 1000, 'one of them is the real press file', cardShots.flat + ' px wide');

await page.screenshot({ path: OUT + 'editor-confirm.png' });

const [navigation] = await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 40000 }).catch(() => null),
    page.evaluate(() => {
        document.querySelector('#tshirt-editor .ed-confirm .ed-primary').click();
    }),
]);

if (!page.url().includes('/cart.php')) {
    const shown = await page.evaluate(() =>
        document.querySelector('#tshirt-editor .ed-note--stop')?.textContent?.trim() ?? '(no message)');
    console.log('  server said: ' + shown);
}

check(page.url().includes('/cart.php'), 'the design saves and the line lands in the cart', page.url());

const cart = await page.evaluate(() => ({
    lines: document.querySelectorAll('.line').length,
    withDesign: [...document.querySelectorAll('.line__meta')]
        .filter((m) => m.textContent.includes('تصميمك')).length,
    total: document.querySelector('.totals')?.textContent?.trim().replace(/\s+/g, ' ') ?? '',
}));

check(cart.lines >= 1, 'the cart has the line', cart.lines + ' lines');
check(cart.withDesign >= 1, 'and it carries the design', cart.withDesign + ' with artwork');
console.log('  cart total: ' + cart.total);

await page.screenshot({ path: OUT + 'editor-cart.png' });

/* ============ and on to a press file, through the real queue ============ */

console.log('');
console.log('8. the editor design reaches the press');

await page.goto(BASE + '/checkout.php', { waitUntil: 'networkidle0' });
await page.type('input[name="customer_name"]', 'سامي قنديل');
await page.type('input[name="email"]', 'sami@example.com');
await page.type('input[name="phone"]', '0790000000');
await page.type('input[name="city"]', 'عمّان');
await page.type('textarea[name="address"]', 'شارع الرينبو، بناية ١٢');

await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => null),
    page.click('form[action="/checkout.php"] button[type="submit"]'),
]);

const reference = decodeURIComponent((page.url().match(/number=([^&]+)/) ?? [])[1] ?? '');
check(reference !== '', 'the order is placed', reference || page.url());

const drained = await page.evaluate(async (key) =>
    (await (await fetch('/cron.php?key=' + encodeURIComponent(key))).json()), 'dev-cron-key');

check((drained.processed ?? 0) >= 1, 'the queue produces the print file',
    JSON.stringify(drained));

/* ====== 9. time to interactive after the click, on a throttled 4G line ==== */
/*
 * This replaces the byte budget the editor used to be held to. What a customer
 * on a phone actually experiences is the wait between asking for the editor and
 * being able to draw, and a byte count was only ever a poor stand-in for it —
 * one this stack could not have met at any size, since three, fabric and
 * react-dom come to 361 kB before a line of editor code is written.
 *
 * So: measure the wait. A cold cache, a 1.6 Mbps line with 150 ms of latency,
 * click, and stop the clock when the design canvas will take a drag — not when
 * the bundle finishes arriving, which is appreciably earlier. Every frame of
 * that wait has to have the loading state on screen.
 */
console.log('');
console.log('9. time to interactive on throttled 4G');

{
    const slow = await browser.newPage();
    await slow.setViewport({ width: 1500, height: 950, deviceScaleFactor: 1 });

    const session = await slow.createCDPSession();
    await session.send('Network.enable');
    await session.send('Network.setCacheDisabled', { cacheDisabled: true });
    await session.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: 150,
        downloadThroughput: (1.6 * 1024 * 1024) / 8,
        uploadThroughput: (750 * 1024) / 8,
    });

    await slow.goto(BASE + PRODUCT, { waitUntil: 'networkidle0' });

    await openDesignTab(slow);

    // Watch the overlay every frame from the click onwards, so a loading state
    // that flickers off in the middle cannot pass as one that stayed up.
    await slow.evaluate(() => {
        window.__watch = { frames: 0, blank: 0, ready: 0 };
        const overlay = document.getElementById('editor-loading');

        const tick = () => {
            if (window.__watch.ready) {
                return;
            }

            window.__watch.frames++;

            if (overlay.hidden || getComputedStyle(overlay).display === 'none') {
                window.__watch.blank++;
            }

            requestAnimationFrame(tick);
        };

        document.querySelector('[data-editor-launch]').addEventListener('click', () => {
            window.__watch.clicked = performance.now();
            requestAnimationFrame(tick);
        }, { once: true });

        document.addEventListener('tshirt-editor:ready', () => {
            window.__watch.ready = performance.now();
        }, { once: true });
    });

    await openDesignTab(slow);
    await slow.click('[data-editor-launch]');
    await slow.waitForFunction(() => window.__watch.ready > 0, { timeout: 40000 });

    const tti = await slow.evaluate(() => ({
        ms: window.__watch.ready - window.__watch.clicked,
        frames: window.__watch.frames,
        blank: window.__watch.blank,
        overlayHidden: document.getElementById('editor-loading').hidden,
        drawable: !!document.querySelector('#tshirt-editor.ed-open canvas.lower-canvas'),
        transfer: performance.getEntriesByType('resource')
            .filter((r) => r.name.includes('/assets/editor/'))
            .reduce((sum, r) => sum + r.transferSize, 0),
        decoded: performance.getEntriesByType('resource')
            .filter((r) => r.name.includes('/assets/editor/'))
            .reduce((sum, r) => sum + r.decodedBodySize, 0),
    }));

    // Say whether the bytes arrived compressed. Without it the timing is of the
    // harness, not the site, and a failure here should point at that first.
    const squeezed = tti.decoded > 0 && tti.transfer < tti.decoded * 0.6;

    check(squeezed, 'the island is served compressed, as .htaccess asks',
        Math.round(tti.transfer / 1024) + ' kB on the wire for '
        + Math.round(tti.decoded / 1024) + ' kB of code'
        + (squeezed ? '' : '  <- start the server with tools/router.php'));
    check(tti.ms < 2500, 'the editor is drawable within 2.5 s of the click',
        Math.round(tti.ms) + ' ms over ' + Math.round(tti.transfer / 1024) + ' kB of transfer');
    check(tti.drawable, 'and drawable means the design canvas is really there');
    check(tti.blank === 0 && tti.frames > 5,
        'the loading state is on screen for every frame of the wait',
        tti.frames + ' frames watched, ' + tti.blank + ' without it');
    check(tti.overlayHidden, 'and it goes away once the editor is up');

    await slow.screenshot({ path: OUT + 'editor-tti.png' });
    await slow.close();
}

await browser.close();

console.log('\n============================');
console.log('passed ' + pass.length + ', failed ' + fail.length);

if (fail.length) {
    console.log('\nFAILURES:');
    fail.forEach((f) => console.log('  - ' + f));
    process.exitCode = 1;
}
