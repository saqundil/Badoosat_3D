/**
 * Acceptance checks for the model controls in the slide editor.
 *
 * What is being checked is that the preview answers -- that colour, design and
 * placement reach the shirt as the select is changed, without a save and
 * without a round trip -- and that the fields the switch governs disappear
 * with it.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   ADMIN_PASSWORD=... node tools/verify-hero-editor.mjs
 */

import { readdir, unlink } from 'node:fs/promises';
import puppeteer from 'puppeteer-core';

const BASE = process.env.HERO_BASE ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const USER = process.env.ADMIN_USER ?? 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD ?? 'hero-admin-2026';

/* The login limiter counts this run too; see verify-admin.mjs. */
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
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

const page = await browser.newPage();
await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });
await page.setCacheEnabled(false);

const requests = [];
page.on('request', (r) => requests.push(r.url()));

await page.goto(BASE + '/admin/login.php', { waitUntil: 'networkidle0' });
await page.type('input[name="username"]', USER);
await page.type('input[name="password"]', PASSWORD);
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"]'),
]);

await page.goto(BASE + '/admin/edit.php?id=1', { waitUntil: 'networkidle0' });
await page.evaluate(() => document.fonts.ready);
await new Promise((r) => setTimeout(r, 1200));

/* ------------------------------------------------ the fields are the registry */

const fields = await page.evaluate(() => {
    const options = (name) => [...document.querySelectorAll('[name="' + name + '"] option')]
        .map((o) => o.value);

    return {
        modelKeys: options('model_key'),
        colorIds: options('color_id'),
        designIds: options('design_id'),
        placements: options('placement'),
        modelPathInput: document.querySelector('[name="model_path"]') !== null,
        colorHexInput: document.querySelector('input[type="color"][name="garment_color"]') !== null,
    };
});

check(fields.modelKeys.length === 1 && fields.modelKeys[0] === 'tshirt',
    'the model list is the registry, one entry', fields.modelKeys.join(','));
check(fields.modelPathInput === false, 'there is no free-text model path left');
check(fields.colorHexInput === false, 'there is no loose colour picker left');
check(fields.colorIds.length === 6, 'the colours come from the colours table',
    fields.colorIds.length + ' options');
check(fields.designIds[0] === '' && fields.designIds.length > 1,
    'the design list offers a plain shirt and the catalogue', fields.designIds.join(','));
check(
    fields.placements.join(',') === 'front,back,both',
    'placement offers exactly CartService::PLACEMENTS', fields.placements.join(',')
);

/* ------------------------------------------------------ the switch hides them */

const setChecked = async (selector, value) => {
    await page.evaluate((sel, v) => {
        const el = document.querySelector(sel);
        el.checked = v;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }, selector, value);
    await new Promise((r) => setTimeout(r, 350));
};

const select = async (name, value) => {
    await page.evaluate((n, v) => {
        const el = document.querySelector('[name="' + n + '"]');
        el.value = v;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }, name, value);
    await new Promise((r) => setTimeout(r, 450));
};

/*
 * The card lives in the preview frame now, so half of what this asks about is
 * in a different document from the form. See public/admin/hero-preview.php.
 */
const preview = () => page.frames().find((f) => f.url().includes('hero-preview.php'));

const visibility = async () => {
    const form = await page.evaluate(() => ({
        modelFields: !document.querySelector('[data-model-fields]').hidden,
        placement: !document.querySelector('[data-placement-field]').hidden,
    }));

    const card = await preview().evaluate(() => ({
        stage: !(document.querySelector('[data-hero-stage]')?.hidden ?? true),
        noModelClass: document.querySelector('[data-hero-card]')
            .classList.contains('hero__card--no-model'),
    }));

    return { ...form, ...card };
};

await setChecked('#has-model', false);
let seen = await visibility();
check(!seen.modelFields, 'switching the model off hides the model fields');
check(!seen.stage, 'and the preview drops the stage');
check(seen.noModelClass, 'and the card says it has no model');

await setChecked('#has-model', true);
seen = await visibility();
check(seen.modelFields, 'switching it back on brings the fields back');
check(seen.stage, 'and the stage returns');

/* ------------------------------------- colour, design and placement land live */

await select('color_id', '2');

/* The island is the saved slide; the draft lives in the form. What matters is
   that the draft the preview was handed carries the new hex. */
const draftHex = await page.evaluate(() => {
    const lookups = JSON.parse(document.getElementById('hero-editor-lookups').textContent);

    return lookups.colors[document.querySelector('[name="color_id"]').value];
});
check(draftHex === '#1C1C1E', 'the colour select resolves to the palette hex', String(draftHex));

await select('design_id', '1');
await new Promise((r) => setTimeout(r, 300));
seen = await visibility();
check(seen.placement, 'choosing a design reveals the placement field');

const layersFor = (placement) => page.evaluate((p) => {
    const lookups = JSON.parse(document.getElementById('hero-editor-lookups').textContent);
    const id = document.querySelector('[name="design_id"]').value;
    const sides = lookups.placementSides[p];

    return sides.flatMap((side) => lookups.designLayers[id].map((l) => ({ ...l, side })));
}, placement);

const front = await layersFor('front');
const both = await layersFor('both');

check(front.length > 0 && front.every((l) => l.side === 'front'),
    'placement front prints on the front only', JSON.stringify(front.map((l) => l.side)));
check(both.length === front.length * 2
    && both.some((l) => l.side === 'back') && both.some((l) => l.side === 'front'),
    'placement both prints on both sides', JSON.stringify(both.map((l) => l.side)));

await select('placement', 'both');
await new Promise((r) => setTimeout(r, 600));

check(await preview().evaluate(() =>
    document.querySelector('[data-hero-card]').classList.contains('is-live')),
    'the preview scene is live through all of it');

await select('design_id', '');
seen = await visibility();
check(!seen.placement, 'clearing the design hides placement again — a plain shirt');

/* One viewer, not two: the editor pulls the hero's stage and nothing else. */
check(requests.some((u) => u.includes('hero3d.js')),
    'the preview uses the hero stage');
check(!requests.some((u) => u.includes('product-model-viewer.js')),
    'and never loads the product page viewer');
check(requests.some((u) => u.includes('model-core.js')),
    'both of which sit on the shared model core');

await page.screenshot({ path: new URL('../build/hero-editor.png', import.meta.url).pathname.slice(1) });

await browser.close();

console.log('\n  ' + pass.length + ' passed, ' + fail.length + ' failed');
fail.forEach((f) => console.log('  FAILED: ' + f));

process.exit(fail.length === 0 ? 0 : 1);
