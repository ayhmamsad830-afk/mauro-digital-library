/*
 * Real (non-demo) admin + member flows in Chromium against a SQLite-harness server:
 *   node tests/browser/admin-member-check.js <base> <adminSid> <memberSid> <bookSlug> <coverPng> [shotsDir]
 *
 * Every admin screen at 390/1280 px in AR and EN (200, no overflow, no JS errors),
 * then: member favourite + review + account pages, admin cover upload shown in the
 * catalogue, review moderation, settings save, suspend → member signed out.
 */
const { chromium } = require('playwright');
const [, , BASE, ADMIN, MEMBER, SLUG, COVER, SHOTS] = process.argv;
const failures = [];
let checks = 0;
const ok = (c, m) => { checks++; if (!c) failures.push(m); };

async function ctxFor(browser, sid, vp, locale = 'ar') {
    const ctx = await browser.newContext({ viewport: vp });
    const cookies = [{ name: 'dl_locale', value: locale, url: BASE }];
    if (sid) cookies.push({ name: 'dl_session', value: sid, url: BASE });
    await ctx.addCookies(cookies);
    const page = await ctx.newPage();
    page._errors = [];
    page.on('console', m => { if (m.type() === 'error' && !/status of (404|403)/.test(m.text())) page._errors.push(m.text()); });
    page.on('pageerror', e => page._errors.push(String(e)));
    return { ctx, page };
}

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

const ADMIN_PAGES = ['/admin', '/admin/books', '/admin/users', '/admin/reviews', '/admin/analytics', '/admin/analytics?days=7',
    '/admin/logs', '/admin/settings', '/admin/files', '/admin/categories', '/admin/authors'];

(async () => {
    const browser = await chromium.launch();

    // 1. Admin screens × viewport × locale
    for (const locale of ['ar', 'en']) {
        for (const [vpName, vp] of [['mobile', { width: 390, height: 844 }], ['desktop', { width: 1280, height: 900 }]]) {
            const { ctx, page } = await ctxFor(browser, ADMIN, vp, locale);
            for (const p of ADMIN_PAGES) {
                const res = await page.goto(BASE + p);
                ok(res.status() === 200, `${locale}/${vpName} ${p}: status ${res.status()}`);
                const st = await page.evaluate(() => ({ o: document.documentElement.scrollWidth - innerWidth, dir: document.documentElement.dir,
                    disabled: document.querySelectorAll('.admin-nav .is-disabled').length }));
                ok(st.o <= 1, `${locale}/${vpName} ${p}: horizontal overflow ${st.o}px`);
                ok(st.dir === (locale === 'ar' ? 'rtl' : 'ltr'), `${locale}/${vpName} ${p}: dir ${st.dir}`);
                ok(st.disabled === 0, `${locale}/${vpName} ${p}: disabled nav items ${st.disabled}`);
                const issues = await page.evaluate(A11Y);
                ok(issues.length === 0, `${locale}/${vpName} ${p}: a11y ${issues.join(' | ')}`);
                ok(page._errors.length === 0, `${locale}/${vpName} ${p}: JS errors ${page._errors.join(' | ')}`);
                page._errors = [];
                if (SHOTS && locale === 'ar' && ['/admin/users', '/admin/reviews', '/admin/analytics', '/admin/settings', '/admin/files', '/admin/logs'].includes(p)) {
                    await page.screenshot({ path: `${SHOTS}/admin-${vpName}-${p.replace(/\W+/g, '_').replace(/^_|_$/g, '')}.png`, fullPage: true });
                }
            }
            await ctx.close();
        }
    }

    // 2. Member: favourite, review, account pages
    {
        const { ctx, page } = await ctxFor(browser, MEMBER, { width: 390, height: 844 }, 'en');
        await page.goto(`${BASE}/books/${SLUG}`);
        const favForm = page.locator('form[action*="/account/favorites/"] button');
        ok(await favForm.isVisible(), 'member: favourite button visible');
        await Promise.all([page.waitForNavigation(), favForm.click()]);
        ok((await page.textContent('body')).includes('favorites'), 'member: favourite added (flash)');
        await page.click('label[for="rate-4"]');
        ok(await page.isChecked('#rate-4'), 'member: star label selects rating 4');
        await page.fill('textarea[name="comment"]', 'Clear and well organised <b>really</b>.');
        await Promise.all([page.waitForNavigation(), page.click('form[action$="/review"] button[type=submit]')]);
        const body = await page.content();
        ok(body.includes('Clear and well organised &lt;b&gt;really&lt;/b&gt;.'), 'member: review shown, HTML escaped');
        for (const p of ['/account', '/account/favorites', '/account/history', '/account/bookmarks']) {
            const r = await page.goto(BASE + p);
            ok(r.status() === 200, `member ${p}: ${r.status()}`);
            ok(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth) <= 1, `member ${p}: overflow`);
        }
        await page.goto(`${BASE}/account/favorites`);
        ok((await page.content()).includes(`/books/${SLUG}`), 'member: favourite listed');
        ok((await page.goto(`${BASE}/admin`)).status() === 403, 'member: admin forbidden');
        ok(page._errors.length === 0, `member: JS errors ${page._errors.join(' | ')}`);
        if (SHOTS) {
            await page.goto(`${BASE}/account`);
            await page.screenshot({ path: `${SHOTS}/member-mobile-account.png`, fullPage: true });
        }
        await ctx.close();
    }

    // 3. Admin: cover upload, moderation, settings, suspension
    {
        const { ctx, page } = await ctxFor(browser, ADMIN, { width: 1280, height: 900 }, 'en');
        await page.goto(`${BASE}/admin/books`);
        const editHref = await page.evaluate((slug) => {
            const row = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes(slug)) || document.querySelector('tbody tr');
            return row.querySelector('a[href^="/admin/books/"]').getAttribute('href');
        }, SLUG);
        await page.goto(BASE + editHref);
        await page.setInputFiles('#f-cover', COVER);
        await Promise.all([page.waitForNavigation(), page.click('#cover button[type=submit]')]);
        ok((await page.textContent('.admin-content')).includes('Cover saved'), 'admin: cover saved');
        ok(await page.evaluate(() => { const i = document.querySelector('.admin-cover-preview'); return !!i && i.complete && i.naturalWidth > 0; }), 'admin: cover preview loads');

        const pub = await (await browser.newContext()).newPage();
        await pub.goto(`${BASE}/books/${SLUG}`);
        await pub.waitForFunction(() => [...document.querySelectorAll('.cover img')].some(i => i.complete && i.naturalWidth > 0));
        ok(true, 'public: uploaded cover displayed');
        const coverSrc = await pub.evaluate(() => document.querySelector('.cover img').getAttribute('src'));
        ok(/^\/media\/covers\/\d+\?v=[a-f0-9]{8}$/.test(coverSrc), `public: cover URL has no storage path (${coverSrc})`);

        await page.goto(`${BASE}/admin/reviews`);
        const hide = page.locator('.review-mod__item', { hasText: 'Clear and well organised' }).locator('button');
        await Promise.all([page.waitForNavigation(), hide.click()]);
        ok((await page.textContent('.admin-content')).includes('Review hidden'), 'admin: review hidden');
        await pub.goto(`${BASE}/books/${SLUG}`);
        ok(!(await pub.content()).includes('Clear and well organised'), 'public: hidden review not shown');

        await page.goto(`${BASE}/admin/settings`);
        await page.selectOption('#s-pp', '12');
        await page.fill('#s-mail', 'rights@example.org');
        await Promise.all([page.waitForNavigation(), page.click('form[action$="/admin/settings"] button[type=submit]')]);
        ok((await page.textContent('.admin-content')).includes('Saved'), 'admin: settings saved');
        await pub.goto(`${BASE}/copyright`);
        ok((await pub.content()).includes('mailto:rights@example.org'), 'public: contact email on copyright page');
        await pub.goto(`${BASE}/books`);
        ok((await pub.inputValue('#per_page')) === '12', 'public: catalogue page size from settings');

        await page.goto(`${BASE}/admin/users`);
        const row = page.locator('tbody tr', { hasText: 'e2e-member@example.invalid' });
        await Promise.all([page.waitForNavigation(), row.locator('form[action$="/status"] button').click()]);
        ok((await page.locator('tbody tr', { hasText: 'e2e-member@example.invalid' }).textContent()).includes('Suspended'), 'admin: member suspended');
        const { ctx: mctx, page: mp } = await ctxFor(browser, MEMBER, { width: 1280, height: 900 }, 'en');
        await mp.goto(`${BASE}/account`);
        ok(new URL(mp.url()).pathname === '/login', `suspended member redirected to login (${mp.url()})`);
        await mctx.close();
        await page.goto(`${BASE}/admin/logs`);
        const logText = await page.textContent('.admin-content');
        ok(['Suspended an account', 'Hid a review', 'Changed site settings', 'Uploaded a cover'].every(t => logText.includes(t)), 'admin: actions in activity log');
        ok(page._errors.length === 0, `admin flows: JS errors ${page._errors.join(' | ')}`);
        await pub.context().close();
        await ctx.close();
    }

    await browser.close();
    console.log(`${checks} admin/member checks, ${failures.length} failed`);
    failures.forEach(f => console.log('  ✗ ' + f));
    process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
