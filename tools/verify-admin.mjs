/**
 * Acceptance checks for the admin side: the live preview really is the hero
 * component, typing and dragging move it, and what was saved is what the
 * visitor gets.
 *
 *   php -S 127.0.0.1:8899 -t public
 *   ADMIN_PASSWORD=... node tools/verify-admin.mjs
 */

import { mkdir, readFile, readdir, unlink } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const USER = process.env.ADMIN_USER ?? 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD ?? 'hero-admin-2026';
const OUT = new URL('../build/', import.meta.url).pathname.slice(1);

await mkdir(OUT, { recursive: true });

/*
 * The login limiter allows five attempts in fifteen minutes, and this suite
 * logs in once per run. Three runs while fixing something and the sixth is
 * refused -- by working code, which then reads as a broken admin page. The
 * counters are cleared so the suite measures the site rather than itself; the
 * limiter's own behaviour is asserted in verify-backend.php.
 */
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

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    /*
     * The preview is a live WebGL canvas at the card's full size, and swiftshader
     * draws it in software. Reading a screenshot back off it takes appreciably
     * longer than the 30-second default allows -- on this machine, not on a
     * customer's. The screenshots here are diagnostics, not assertions, so the
     * timeout is raised rather than the picture made smaller.
     */
    protocolTimeout: 120000,
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

/*
 * The screenshots below are diagnostics, not assertions -- they exist so a
 * failure can be looked at. Capturing one off a page with a live WebGL canvas
 * under swiftshader occasionally wedges the renderer on this machine, and a
 * suite whose every assertion passed should not be reported as failed because
 * a picture could not be taken. The miss is announced and the run continues.
 */
async function shoot(target, path) {
    try {
        await target.screenshot({ path });
    } catch (e) {
        console.log('  (no screenshot: ' + path.split('/').pop() + ' — ' + e.message.slice(0, 60) + ')');
    }
}

const page = await browser.newPage();
page.on('pageerror', (e) => console.log('  page error:', e.message));
page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('favicon')) console.log('  console:', m.text()); });
await page.setViewport({ width: 1680, height: 1000, deviceScaleFactor: 1 });

/* ------------------------------------------------------------------ login */

await page.goto(BASE + '/admin/login.php', { waitUntil: 'networkidle0' });
await page.type('input[name="username"]', USER);
await page.type('input[name="password"]', PASSWORD);
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"]'),
]);
check(page.url().endsWith('/admin/'), 'login lands on the slide list', page.url());

const list = await page.evaluate(() => ({
    rows: document.querySelectorAll('.slide').length,
    draggable: [...document.querySelectorAll('.slide')].every((s) => s.draggable),
}));
check(list.rows >= 2, 'the slides are listed', list.rows + ' rows');
check(list.draggable, 'rows are draggable');

/* --------------------------------------------------------------- reorder */

const reorder = await page.evaluate(async () => {
    const box = document.querySelector('[data-reorder]');
    const ids = [...box.querySelectorAll('.slide')].map((s) => Number(s.dataset.id)).reverse();
    const response = await fetch(box.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': box.dataset.token, Accept: 'application/json' },
        body: JSON.stringify({ action: 'reorder', ids }),
    });

    return { status: response.status, sent: ids };
});
check(reorder.status === 200, 'the reorder endpoint accepts a new order', 'HTTP ' + reorder.status);

await page.goto(BASE + '/admin/', { waitUntil: 'networkidle0' });
const order = await page.evaluate(() => [...document.querySelectorAll('.slide')].map((s) => Number(s.dataset.id)));
check(JSON.stringify(order) === JSON.stringify(reorder.sent), 'it survives a reload', order.join(','));

await page.evaluate(async (ids) => {
    const box = document.querySelector('[data-reorder]');
    await fetch(box.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': box.dataset.token, Accept: 'application/json' },
        body: JSON.stringify({ action: 'reorder', ids }),
    });
}, [...order].reverse());

/* ---------------------------------------------------------------- editor */

const firstId = Math.min(...order);
await page.goto(BASE + '/admin/edit.php?id=' + firstId, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 1200));

/*
 * The preview is a frame now, with a viewport of its own, so the card and
 * everything in it is a separate document from the form. That is the whole
 * point -- the hero's shape is decided by viewport media queries, and a panel
 * cannot have a viewport. See public/admin/hero-preview.php.
 */
const preview = () => page.frames().find((f) => f.url().includes('hero-preview.php'));

const editor = await preview().evaluate(() => ({
    isComponent: !!document.querySelector('.hero[data-hero] .hero__card'),
    hasTab: !!document.querySelector('[data-hero-tab]'),
    hasWordmark: !!document.querySelector('[data-hero-wordmark]'),
    hasStats: document.querySelectorAll('.hero__stat').length,
    stageInert: getComputedStyle(document.querySelector('.hero__stage')).pointerEvents,
    handles: [...document.querySelectorAll('.is-handle')].map((h) => h.dataset.element).sort(),
}));

check(editor.isComponent, 'the preview IS the hero component, not a second implementation');
/*
 * No tab. The preview renders the card with its chrome, exactly as the home
 * page does, and the light tab is the shape the card takes WITHOUT chrome --
 * so it is not on the site and it is not here either. What an administrator
 * can move is what a visitor can see.
 */
check(!editor.hasTab, 'the preview has no light tab, because the site has none');
check(editor.hasWordmark && editor.hasStats === 2,
    'the preview carries the wordmark and both stat cards',
    editor.hasStats + ' stat cards');
check(editor.stageInert === 'none', 'the canvas is inert so elements behind it can be grabbed');
check(JSON.stringify(editor.handles) === JSON.stringify(['stats', 'tagline', 'wordmark']),
    'every element the site shows is a handle', editor.handles.join(', '));

await page.waitForSelector('.hero__card.is-live', { timeout: 40000 }).catch(() => {});
await shoot(page, OUT + 'shot-admin-editor.png');

/* ------------------------------------------------- typing updates the view */

await page.evaluate(() => {
    const field = document.querySelector('input[name="wordmark"]');
    field.value = 'LIVEEDIT';
    field.dispatchEvent(new Event('input', { bubbles: true }));
});
await new Promise((r) => setTimeout(r, 400));

/* Typed in the form, read in the frame: the draft crosses by postMessage. */
const typed = await preview().evaluate(() =>
    document.querySelector('[data-hero-wordmark]').textContent);
check(typed === 'LIVEEDIT', 'typing updates the preview as you type', typed);

/* -------------------------------------------------------------- dragging */

/*
 * The pointer is the page's, the element is the frame's, so the frame's own
 * coordinates have to be offset by where the frame is drawn -- and scaled by
 * how small it is drawn, since the frame is laid out at the device width and
 * painted at a fraction of it.
 */
const framePlacement = await page.evaluate(() => {
    const box = document.getElementById('hero-preview').getBoundingClientRect();

    return { x: box.x, y: box.y, scale: box.width / 1440 };
});

const target = await preview().evaluate(() => {
    const card = document.querySelector('[data-hero-card]').getBoundingClientRect();
    const el = document.querySelector('[data-hero-wordmark]').getBoundingClientRect();

    return { card: card.toJSON(), el: el.toJSON() };
});

target.from = {
    x: framePlacement.x + (target.el.x + target.el.width / 2) * framePlacement.scale,
    y: framePlacement.y + (target.el.y + target.el.height / 2) * framePlacement.scale,
};
target.card = {
    ...target.card,
    width: target.card.width * framePlacement.scale,
    height: target.card.height * framePlacement.scale,
};

// Move it 12% of the card right and 9% down.
const to = {
    x: target.from.x + target.card.width * 0.12,
    y: target.from.y + target.card.height * 0.09,
};

await page.mouse.move(target.from.x, target.from.y);
await page.mouse.down();
await page.mouse.move(to.x, to.y, { steps: 14 });
await page.mouse.up();
await new Promise((r) => setTimeout(r, 350));

const dragged = await page.evaluate(() =>
    ({ layout: JSON.parse(document.getElementById('layout-field').value || '{}') }));

Object.assign(dragged, await preview().evaluate(() => {
    const el = document.querySelector('[data-hero-wordmark]');

    return {
        dx: parseFloat(el.style.getPropertyValue('--dx')),
        dy: parseFloat(el.style.getPropertyValue('--dy')),
    };
}));

check(Math.abs((dragged.layout.wordmark?.dx ?? 0) - 12) < 1.2
    && Math.abs((dragged.layout.wordmark?.dy ?? 0) - 9) < 1.2,
    'dragging writes the nudge into the form',
    'dx=' + dragged.layout.wordmark?.dx + ' dy=' + dragged.layout.wordmark?.dy);
check(dragged.dx === dragged.layout.wordmark?.dx && dragged.dy === dragged.layout.wordmark?.dy,
    'the element and the stored value agree');

await shoot(page, OUT + 'shot-admin-dragged.png');

/* Shares of the card, so the frame's scale divides out and the number means
   the same thing here as it does on the site. */
const previewGeometry = await preview().evaluate(() => {
    const card = document.querySelector('[data-hero-card]').getBoundingClientRect();
    const el = document.querySelector('[data-hero-wordmark]').getBoundingClientRect();

    return {
        centreX: ((el.x + el.width / 2 - card.x) / card.width) * 100,
        centreY: ((el.y + el.height / 2 - card.y) / card.height) * 100,
        widthShare: el.width / card.width,
    };
});

/* ------------------------------------------------------------------ save */

await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"][class*="primary"]'),
]);
check(page.url().includes('saved=1'), 'saving redirects with a confirmation', page.url());

/* ------------------------------------- the site matches what was previewed */

await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 900));

const live = await page.evaluate(() => {
    const card = document.querySelector('[data-hero-card]').getBoundingClientRect();
    const el = document.querySelector('[data-hero-wordmark]');
    const box = el.getBoundingClientRect();

    return {
        text: el.textContent,
        dx: parseFloat(getComputedStyle(el).getPropertyValue('--dx')),
        dy: parseFloat(getComputedStyle(el).getPropertyValue('--dy')),
        centreX: ((box.x + box.width / 2 - card.x) / card.width) * 100,
        centreY: ((box.y + box.height / 2 - card.y) / card.height) * 100,
        widthShare: box.width / card.width,
    };
});

check(live.text === 'LIVEEDIT', 'the edited text is on the site', live.text);
check(live.dx === dragged.layout.wordmark.dx && live.dy === dragged.layout.wordmark.dy,
    'the saved nudge is what the site reads', live.dx + ', ' + live.dy);
// One percent of the card, on both axes. The preview and the site read the same
// --hero-surface-top out of the same stylesheet now, so there is no second set
// of layout numbers left for them to disagree about.
check(Math.abs(live.centreX - previewGeometry.centreX) < 1
    && Math.abs(live.centreY - previewGeometry.centreY) < 1,
    'the element sits within 1% of where the preview showed it',
    'preview ' + previewGeometry.centreX.toFixed(1) + ',' + previewGeometry.centreY.toFixed(1) +
    ' -> site ' + live.centreX.toFixed(1) + ',' + live.centreY.toFixed(1));
/*
 * The panel's width must not reach the card at all any more.
 *
 * This used to check the opposite -- that a narrower panel narrowed the card
 * and shrank the wordmark with it -- because the preview WAS a panel, and the
 * best that could be asked of it was that it distorted consistently. It is a
 * frame now, laid out at the device width and merely painted smaller, so the
 * honest question has changed: dragging the column narrower may change how big
 * the preview is drawn and nothing else. A card that still moved with the
 * panel would mean the frame was being laid out by its container after all.
 */
await page.goto(BASE + '/admin/edit.php?id=' + firstId, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 1500));

const readInside = () => preview().evaluate(() => ({
    card: document.querySelector('[data-hero-card]').clientWidth,
    font: parseFloat(getComputedStyle(document.querySelector('[data-hero-wordmark]')).fontSize),
    window: window.innerWidth,
}));

const paintedWidth = () => page.evaluate(() =>
    Math.round(document.getElementById('hero-preview').getBoundingClientRect().width));

const wide = { ...await readInside(), painted: await paintedWidth() };

await page.evaluate(() => {
    document.querySelector('.editor__preview').style.width = '620px';
    window.dispatchEvent(new Event('resize'));
});
await new Promise((r) => setTimeout(r, 600));

const narrow = { ...await readInside(), painted: await paintedWidth() };

await page.evaluate(() => { document.querySelector('.editor__preview').style.width = ''; });

check(narrow.painted < wide.painted, 'narrowing the panel paints the frame smaller',
    wide.painted + ' -> ' + narrow.painted + ' px');
check(narrow.card === wide.card, 'but the card keeps its width',
    wide.card + ' -> ' + narrow.card + ' px');
check(narrow.font === wide.font, 'and the wordmark keeps its size',
    wide.font.toFixed(0) + ' -> ' + narrow.font.toFixed(0) + ' px');
check(narrow.window === wide.window && wide.window === 1440,
    'because the frame is still a 1440px viewport either way',
    wide.window + ' -> ' + narrow.window);

await shoot(page, OUT + 'shot-site-after-save.png');
/* --------------------------------- one stylesheet, one set of numbers ---- */
/*
 * The 5.4% the preview used to drift by was not a rounding error. The top
 * inset of the surface was written twice — once as the header's height and
 * once as --hero-surface-top — and only the second was behind .has-hero, which
 * the admin page is not. The preview therefore laid itself out from a
 * different number than the site did, and everything the administrator dragged
 * inherited the difference.
 *
 * Two guards. The stylesheet may not restate a hero box measurement, and the
 * two pages must resolve the token to the same pixel.
 */
{
    const css = await readFile(new URL('../public/assets/css/admin.css', import.meta.url), 'utf8');

    // Rules in the admin sheet that reach a hero element AND set a box property.
    const offenders = [...css.matchAll(/([^{}]*\.hero[^{}]*)\{([^}]*)\}/g)]
        .filter(([, , body]) => /(^|[;\s])(height|min-height|max-height|width|min-width|top|bottom|inset[\w-]*|aspect-ratio|--(hero|tab|surface|card)[\w-]*)\s*:/.test(body))
        .map(([, selector]) => selector.trim().replace(/\s+/g, ' '));

    check(offenders.length === 0, 'admin.css restates no hero box measurement',
        offenders.length ? offenders.join(' | ') : 'nothing to drift');
}

// The page is still on the editor here, from the panel-width test above.
const previewTop = await page.evaluate(() =>
    getComputedStyle(document.querySelector('.editor__preview .hero__surface')).top);

await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 400));

const siteToken = await page.evaluate(() => ({
    site: getComputedStyle(document.querySelector('.hero__surface')).top,
    bar: getComputedStyle(document.querySelector('.topbar')).height,
}));

check(previewTop === siteToken.site,
    'the preview and the site inset the surface by the same pixel',
    previewTop + ' vs ' + siteToken.site);
// The same token drives the header it is meant to clear, which is the whole
// reason it can only be written once.
check(siteToken.site === siteToken.bar,
    'and that pixel IS the header height, read from the one declaration',
    siteToken.site + ' vs a ' + siteToken.bar + ' header');


/* -------------------------------------------------- put the slide back ---- */

await page.goto(BASE + '/admin/edit.php?id=' + firstId, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 800));
await page.evaluate(() => {
    const field = document.querySelector('input[name="wordmark"]');
    field.value = 'OVERSIZE';
    field.dispatchEvent(new Event('input', { bubbles: true }));
    document.getElementById('reset-layout').click();
});
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"][class*="primary"]'),
]);

await browser.close();

console.log('\n============================');
console.log('passed ' + pass.length + ', failed ' + fail.length);

if (fail.length) {
    console.log('\nFAILURES:');
    fail.forEach((f) => console.log('  - ' + f));
    process.exitCode = 1;
}
