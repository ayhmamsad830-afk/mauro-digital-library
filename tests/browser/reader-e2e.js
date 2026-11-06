/*
 * End-to-end protected reader check in a real browser (Playwright/Chromium):
 *   node tests/browser/reader-e2e.js <baseUrl> <adminSessionId> <bookId> <bookSlug> <pdfPath> [shotsDir]
 *
 * 1. Admin uploads a real PDF through the admin form and clicks "Process now".
 * 2. A guest opens the reader: pages arrive as watermarked WebP via signed URLs,
 *    no request or response involves a PDF, thumbnails load, navigation works,
 *    selection/context menu are blocked in the reader, nothing prints.
 */
const { chromium } = require('playwright');
const [, , BASE, SID, BOOK_ID, SLUG, PDF, SHOTS] = process.argv;
const failures = [];
let checks = 0;
const ok = (c, m) => { checks++; if (!c) failures.push(m); };

(async () => {
    const browser = await chromium.launch();

    // ---- 1. admin upload + process
    const admin = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    await admin.addCookies([{ name: 'dl_session', value: SID, url: BASE }, { name: 'dl_locale', value: 'ar', url: BASE }]);
    const ap = await admin.newPage();
    await ap.goto(`${BASE}/admin/books/${BOOK_ID}`);
    ok(await ap.isVisible('#f-pdf'), 'admin: upload field visible');
    await ap.setInputFiles('#f-pdf', PDF);
    await Promise.all([ap.waitForNavigation(), ap.click('form[action$="/replace-pdf"] button[type=submit]')]);
    ok((await ap.textContent('.admin-content')).includes('تم رفع الملف'), 'admin: upload accepted');
    const processBtn = ap.locator('.version form[action$="/process"] button').first();
    ok(await processBtn.isVisible(), 'admin: process-now button');
    await Promise.all([ap.waitForNavigation({ timeout: 120000 }), processBtn.click()]);
    ok((await ap.textContent('.admin-content')).includes('اكتملت معالجة'), 'admin: rendering completed');
    const adminHtml = await ap.content();
    ok(!/original-pdf|private\/|\.pdf"/.test(adminHtml.replace(/accept="application\/pdf,\.pdf"/, '')), 'admin: no storage paths in markup');
    if (SHOTS) {
        await ap.locator('.admin-side').screenshot({ path: `${SHOTS}/admin-pdf-versions.png` });
    }
    await admin.close();

    // ---- 2. guest reader (desktop + mobile)
    for (const [name, vp] of [['desktop', { width: 1280, height: 860 }], ['mobile', { width: 390, height: 844 }]]) {
        const ctx = await browser.newContext({ viewport: vp });
        await ctx.addCookies([{ name: 'dl_locale', value: name === 'desktop' ? 'ar' : 'en', url: BASE }]);
        const page = await ctx.newPage();
        const requests = [];
        const types = [];
        const errors = [];
        page.on('request', r => requests.push(r.url()));
        page.on('response', r => types.push([r.url(), r.headers()['content-type'] || '', r.status()]));
        page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('pageerror', e => errors.push(String(e)));

        await page.goto(`${BASE}/read/${SLUG}`);
        await page.waitForFunction(() => { const i = document.querySelector('[data-page-img]'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 30000 });
        const src = await page.getAttribute('[data-page-img]', 'src');
        ok(/^\/api\/books\/\d+\/pages\/1\?rs=\d+&exp=\d+&sig=[A-Za-z0-9_-]{43}$/.test(src), `${name}: signed page URL (${src})`);
        await page.click('[data-action="next"]');
        await page.waitForFunction(() => document.querySelector('[data-page-input]').value === '2' && document.querySelector('[data-page-img]').complete && document.querySelector('[data-page-img]').naturalWidth > 0);
        ok(true, `${name}: navigation to page 2`);
        const total = await page.textContent('[data-page-total]');
        ok(+total === 4, `${name}: page count from manifest (${total})`);
        if (name === 'desktop') {
            await page.waitForFunction(() => [...document.querySelectorAll('.reader-thumb img')].filter(i => i.complete && i.naturalWidth > 0).length >= 2, null, { timeout: 15000 });
            ok(true, 'desktop: thumbnails loaded');
        }
        const pageResponses = types.filter(([u]) => /\/api\/books\/\d+\/(pages|thumbnails)\//.test(u));
        ok(pageResponses.length > 0 && pageResponses.every(([, t, s]) => t === 'image/webp' && s === 200), `${name}: all page/thumbnail responses are 200 image/webp`);
        ok(!requests.some(u => /\.pdf(\?|$)|pdfjs|pdf\.js|original-pdf|private\//i.test(u)), `${name}: no PDF/private URL requested`);
        ok(!types.some(([, t]) => /application\/pdf/i.test(t)), `${name}: no PDF content-type received`);
        const blocked = await page.evaluate(() => !document.querySelector('[data-stage]').dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true })));
        ok(blocked, `${name}: context menu blocked in reader`);
        await page.keyboard.press('Control+A');
        ok((await page.evaluate(() => String(window.getSelection()))).trim() === '', `${name}: Ctrl+A selects nothing`);
        await page.emulateMedia({ media: 'print' });
        ok(!(await page.isVisible('.reader')), `${name}: reader hidden when printing`);
        await page.emulateMedia({ media: 'screen' });
        ok(errors.length === 0, `${name}: no JS errors (${errors.join(' | ')})`);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        ok(overflow <= 1, `${name}: no horizontal overflow (${overflow})`);
        if (SHOTS) {
            await page.waitForTimeout(300);
            await page.screenshot({ path: `${SHOTS}/reader-${name}.png` });
            if (name === 'desktop') {
                await page.click('[data-action="info"]');
                await page.screenshot({ path: `${SHOTS}/reader-desktop-info.png` });
            }
        }
        await ctx.close();
    }

    await browser.close();
    console.log(`${checks} e2e checks, ${failures.length} failed`);
    failures.forEach(f => console.log('  ✗ ' + f));
    process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
