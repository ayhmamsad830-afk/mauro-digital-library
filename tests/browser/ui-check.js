/*
 * Browser UI checks (Playwright + Chromium).
 *   node tests/browser/ui-check.js http://127.0.0.1:8099 [screenshotDir]
 * Exits 1 on any failure. Run against a server with APP_DEMO_UI=true.
 *
 * Checks, for every page × viewport (mobile 375, tablet 768, desktop 1280):
 *   HTTP 200, no console/page errors, no horizontal scroll, lang/dir set.
 * Plus interactions: mobile drawer, theme persistence, AR⇄EN switch,
 * catalogue filters drawer, and the protected reader (images only, no PDF).
 */
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8099';
const SHOTS = process.argv[3] || null;
const failures = [];
let checks = 0;
const ok = (cond, msg) => { checks++; if (!cond) failures.push(msg); };

const PAGES = [
    '/', '/books', '/books?category=history&sort=rating', '/search?q=xyz-no-match', '/books/gardens-of-the-quiet-river',
    '/books/the-patient-machine', '/categories', '/categories/science', '/authors', '/latest', '/popular',
    '/login', '/demo/account', '/demo/admin', '/styleguide', '/privacy', '/read/gardens-of-the-quiet-river', '/does-not-exist',
];
// Basic accessibility audit run in the page: labelled controls, alt text,
// named buttons/links, exactly one <h1>, unique ids, lang attribute.
const A11Y = () => {
    const issues = [];
    const name = el => (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || '').trim()
        || (el.getAttribute('aria-labelledby') || '').split(/\s+/).map(id => document.getElementById(id)?.textContent || '').join('').trim()
        || [...el.querySelectorAll('img[alt]')].map(i => i.alt).join('').trim();
    const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    document.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(el => {
        const labelled = (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`)) || el.closest('label') || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
        if (!labelled) issues.push(`unlabelled ${el.tagName.toLowerCase()}[name=${el.name}]`);
    });
    document.querySelectorAll('img').forEach(el => { if (!el.hasAttribute('alt')) issues.push(`img without alt ${el.src.slice(-40)}`); });
    document.querySelectorAll('button, a[href]').forEach(el => { if (visible(el) && !name(el)) issues.push(`unnamed ${el.tagName.toLowerCase()} ${el.outerHTML.slice(0, 80)}`); });
    const h1 = document.querySelectorAll('h1').length;
    if (h1 !== 1) issues.push(`${h1} h1 elements`);
    const ids = {};
    document.querySelectorAll('[id]').forEach(el => { ids[el.id] = (ids[el.id] || 0) + 1; });
    Object.entries(ids).filter(([, n]) => n > 1).forEach(([id]) => issues.push(`duplicate id ${id}`));
    if (!document.documentElement.lang) issues.push('missing lang');
    return issues;
};

const VIEWPORTS = { mobile: { width: 375, height: 760 }, tablet: { width: 768, height: 1000 }, desktop: { width: 1280, height: 860 } };

async function newPage(browser, vp, cookies = []) {
    const ctx = await browser.newContext({ viewport: vp });
    if (cookies.length) await ctx.addCookies(cookies.map(c => ({ ...c, url: BASE })));
    const page = await ctx.newPage();
    page._errors = [];
    page.on('console', m => { if (m.type() === 'error' && !/status of 404/.test(m.text())) page._errors.push(m.text()); });
    page.on('pageerror', e => page._errors.push(String(e)));
    return { ctx, page };
}

(async () => {
    const browser = await chromium.launch();

    // 1. Every page × viewport × locale
    for (const locale of ['ar', 'en']) {
        for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
            const { ctx, page } = await newPage(browser, vp, [{ name: 'dl_locale', value: locale }]);
            for (const path of PAGES) {
                const res = await page.goto(BASE + path, { waitUntil: 'load' });
                const expected = path === '/does-not-exist' ? 404 : 200;
                ok(res.status() === expected, `${locale}/${vpName} ${path}: status ${res.status()}`);
                const info = await page.evaluate(() => ({
                    overflow: document.documentElement.scrollWidth - window.innerWidth,
                    lang: document.documentElement.lang, dir: document.documentElement.dir,
                }));
                ok(info.overflow <= 1, `${locale}/${vpName} ${path}: horizontal overflow ${info.overflow}px`);
                ok(info.lang === locale && info.dir === (locale === 'ar' ? 'rtl' : 'ltr'), `${locale}/${vpName} ${path}: lang/dir ${info.lang}/${info.dir}`);
                const issues = await page.evaluate(A11Y);
                ok(issues.length === 0, `${locale}/${vpName} ${path}: a11y ${issues.join(' | ')}`);
                ok(page._errors.length === 0, `${locale}/${vpName} ${path}: JS errors ${page._errors.join(' | ')}`);
                page._errors = [];
                if (SHOTS && ['/', '/books', '/books/gardens-of-the-quiet-river', '/login', '/demo/account', '/demo/admin', '/read/gardens-of-the-quiet-river', '/styleguide'].includes(path) && vpName !== 'tablet') {
                    const name = `${locale}-${vpName}-${(path.replace(/[/?=&]+/g, '_').replace(/^_|_$/g, '') || 'home')}.png`;
                    await page.waitForTimeout(path.startsWith('/read') ? 600 : 50);
                    await page.screenshot({ path: `${SHOTS}/${name}`, fullPage: !path.startsWith('/read') && !path.startsWith('/demo/admin') });
                }
            }
            await ctx.close();
        }
    }

    // 2. Mobile drawer navigation
    {
        const { ctx, page } = await newPage(browser, VIEWPORTS.mobile);
        await page.goto(BASE + '/');
        ok(await page.isVisible('.menu-toggle'), 'mobile: menu button visible');
        ok(!(await page.isVisible('.site-nav')), 'mobile: desktop nav hidden');
        await page.click('.menu-toggle');
        ok(await page.isVisible('#site-drawer .drawer__panel'), 'mobile: drawer opens');
        ok((await page.getAttribute('.menu-toggle', 'aria-expanded')) === 'true', 'mobile: aria-expanded=true');
        await page.keyboard.press('Escape');
        ok(!(await page.isVisible('#site-drawer .drawer__panel')), 'mobile: Escape closes drawer');
        await page.click('.menu-toggle');
        await page.click('#site-drawer .drawer__link[href="/books"]');
        await page.waitForURL(BASE + '/books');
        ok(true, 'mobile: drawer link navigates');
        // filters drawer
        await page.click('.toolbar__filter-btn');
        ok(await page.isVisible('#filters-drawer .drawer__panel'), 'mobile: filters drawer opens');
        await page.click('#filters-drawer .filters__close');
        ok(!(await page.isVisible('#filters-drawer .drawer__panel')), 'mobile: filters drawer closes');
        await ctx.close();
    }

    // 3. Theme toggle persists across reloads (cookie + localStorage)
    {
        const { ctx, page } = await newPage(browser, VIEWPORTS.desktop);
        await page.goto(BASE + '/');
        const before = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        await page.click('.site-tools [data-theme-toggle]');
        const after = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        ok(after && after !== before, `theme: toggled (${before} → ${after})`);
        await page.reload();
        const persisted = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        ok(persisted === after, `theme: persisted after reload (${persisted})`);
        const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        ok(after === 'dark' ? bg === 'rgb(18, 23, 21)' : bg === 'rgb(245, 240, 230)', `theme: body background matches (${bg})`);
        await ctx.close();
    }

    // 4. Language switch keeps the page and flips direction
    {
        const { ctx, page } = await newPage(browser, VIEWPORTS.desktop);
        await page.goto(BASE + '/books?sort=popular');
        await page.click('.site-tools form[action="/locale"] button');
        await page.waitForLoadState('load');
        const st = await page.evaluate(() => ({ dir: document.documentElement.dir, url: location.pathname + location.search }));
        ok(st.dir === 'ltr' && st.url === '/books?sort=popular', `lang switch: ${JSON.stringify(st)}`);
        await ctx.close();
    }

    // 5. Protected reader: images only, navigation, deterrence
    for (const [vpName, vp] of [['desktop', VIEWPORTS.desktop], ['mobile', VIEWPORTS.mobile]]) {
        const { ctx, page } = await newPage(browser, vp);
        const requests = [];
        page.on('request', r => requests.push(r.url()));
        await page.goto(BASE + '/read/the-patient-machine');
        await page.waitForFunction(() => { const i = document.querySelector('[data-page-img]'); return i && i.complete && i.naturalWidth > 0; });
        ok(true, `reader/${vpName}: first page image rendered`);
        await page.click('[data-action="next"]');
        await page.waitForFunction(() => document.querySelector('[data-page-input]').value === '2');
        ok((await page.evaluate(() => location.hash)) === '#page=2', `reader/${vpName}: hash updated`);
        await page.waitForTimeout(800);
        const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('dl_progress:the-patient-machine') || 'null'));
        ok(saved && saved.page === 2 && saved.v === 'demo-1', `reader/${vpName}: guest progress saved ${JSON.stringify(saved)}`);
        const prevented = await page.evaluate(() => {
            const ev = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            return !document.querySelector('[data-stage]').dispatchEvent(ev);
        });
        ok(prevented, `reader/${vpName}: context menu blocked in reader`);
        const userSelect = await page.evaluate(() => getComputedStyle(document.querySelector('[data-stage]')).userSelect);
        ok(userSelect === 'none', `reader/${vpName}: user-select none`);
        if (vpName === 'desktop') {
            await page.click('[data-action="zoom-in"]');
            ok((await page.textContent('[data-zoom-label]')).trim() === '125%', 'reader: zoom in');
            ok(await page.isVisible('[data-thumbs]'), 'reader: thumbnails open on desktop');
            await page.click('[data-goto-page="5"]');
            await page.waitForFunction(() => document.querySelector('[data-page-input]').value === '5');
            ok(true, 'reader: thumbnail navigation');
            await page.reload();
            await page.waitForFunction(() => document.querySelector('[data-page-input]').value === '5');
            ok(true, 'reader: position restored after reload');
            await page.emulateMedia({ media: 'print' });
            ok(!(await page.isVisible('.reader')), 'reader: hidden in print media');
            await page.emulateMedia({ media: 'screen' });
        }
        const bad = requests.filter(u => /\.pdf(\?|$)|pdf\.js|pdfjs/i.test(u));
        ok(bad.length === 0, `reader/${vpName}: no PDF requests (${bad.join(', ')})`);
        const html = await page.content();
        ok(!/\.pdf\b/i.test(html), `reader/${vpName}: no .pdf reference in markup`);
        ok(page._errors.length === 0, `reader/${vpName}: JS errors ${page._errors.join(' | ')}`);
        await ctx.close();
    }

    await browser.close();
    console.log(`${checks} browser checks, ${failures.length} failed`);
    failures.forEach(f => console.log('  ✗ ' + f));
    process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
