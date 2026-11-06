/*
 * Protected page-image reader (vanilla JS).
 *
 * - Loads ONE page image at a time from a server URL template (+ neighbours
 *   preloaded). There is no PDF, no PDF.js and no text layer anywhere.
 * - Zoom / fit width / fit page / fullscreen / thumbnails / keyboard / swipe.
 * - Guest progress is saved in localStorage (slug + file version + page only,
 *   no personal data), debounced and on a periodic checkpoint (spec §7.3).
 * - Copy deterrence is limited to the reader area (spec §7.2): no selection,
 *   no drag, no context menu, Ctrl/Cmd + C/A/P/S blocked. This is deterrence,
 *   NOT a guarantee: screenshots, photos and OCR cannot be prevented by a website.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-reader]');
    if (!root) return;

    var M;
    try { M = JSON.parse(root.getAttribute('data-manifest') || '{}'); } catch (e) { return; }

    var stage = root.querySelector('[data-stage]');
    var wrap = root.querySelector('[data-page-wrap]');
    var img = root.querySelector('[data-page-img]');
    var loadingEl = root.querySelector('[data-page-loading]');
    var errorEl = root.querySelector('[data-page-error]');
    var input = root.querySelector('[data-page-input]');
    var progress = root.querySelector('[data-progress]');
    var zoomLabel = root.querySelector('[data-zoom-label]');
    var thumbs = root.querySelector('[data-thumbs]');
    var thumbsList = root.querySelector('[data-thumbs-list]');
    var info = root.querySelector('[data-info]');

    var total = Math.max(1, M.pageCount | 0); // updated by boot() once the session manifest arrives
    var rtl = M.dir === 'rtl';
    var page = 1;
    var fit = 'width';        // 'width' | 'page' | 'free'
    var zoom = 1;             // multiplier on top of the fit size
    var ZOOM_MIN = 0.5, ZOOM_MAX = 3, ZOOM_STEP = 0.25;
    var storeKey = 'dl_progress:' + M.book.slug;
    var thumbsBuilt = false;

    /* ---------------- Signed access (real books) ----------------
     * Real books: POST sessionUrl → {token:{rs,exp,sig}, pageUrl, thumbUrl, …}.
     * Tokens live a few minutes; they are renewed ahead of expiry and on a 401.
     * Demo: static URL templates, no token. */
    var token = null;            // {rs, exp, sig}
    var renewTimer = null;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function qs() {
        return token ? '?rs=' + token.rs + '&exp=' + token.exp + '&sig=' + encodeURIComponent(token.sig) : '';
    }
    function pageUrl(n) { return M.pageUrl.replace('{n}', n) + qs(); }
    function thumbUrl(n) { return M.thumbUrl.replace('{n}', n) + qs(); }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) { data._status = r.status; return data; });
        });
    }

    function scheduleRenew() {
        clearTimeout(renewTimer);
        if (!token || !M.renewUrl) return;
        var ms = Math.max(5000, (token.exp * 1000 - Date.now()) - 60000);
        renewTimer = setTimeout(renew, ms);
    }

    function renew() {
        return postJson(M.renewUrl, { rs: token.rs, book_id: M.book.id }).then(function (d) {
            if (d._status === 409) { toast(M.i18n.versionChanged); setTimeout(function () { location.reload(); }, 1500); throw new Error('version'); }
            if (d._status !== 200 || !d.token) throw new Error('renew');
            token = d.token;
            scheduleRenew();
            // Reload thumbnails that have not loaded yet with the fresh token.
            if (thumbsList) thumbsList.querySelectorAll('img').forEach(function (t, i) { if (!t.complete || !t.naturalWidth) t.src = thumbUrl(i + 1); });
        });
    }

    function startSession() {
        return postJson(M.sessionUrl, { book_id: M.book.id }).then(function (d) {
            if (d._status !== 200 || !d.token) throw new Error((d.error && d.error.message) || M.i18n.loadError);
            token = d.token;
            M.pageUrl = d.pageUrl; M.thumbUrl = d.thumbUrl;
            M.pageCount = d.pageCount; M.fileVersion = d.fileVersion; M.aspect = d.aspect;
            M.member = !!d.member; M.serverProgress = d.progress || null;
            scheduleRenew();
        });
    }
    function toast(msg) { if (window.DL && window.DL.toast) window.DL.toast(msg); }

    /* ---------------- Progress (guest, localStorage) ---------------- */
    function loadProgress() {
        try {
            var saved = JSON.parse(localStorage.getItem(storeKey) || 'null');
            if (!saved || typeof saved.page !== 'number') return null;
            if (saved.v !== M.fileVersion) { toast(M.i18n.versionChanged); return null; }
            if (saved.page < 1 || saved.page > total) return null;
            return saved.page;
        } catch (e) { return null; }
    }
    var saveTimer = null;
    var lastServerPage = null;
    function saveProgress() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            try {
                localStorage.setItem(storeKey, JSON.stringify({ v: M.fileVersion, page: page, total: total, t: Date.now() }));
            } catch (e) { /* storage unavailable */ }
            // Members: also persist in MySQL (debounced; rate-limited server side).
            if (M.member && M.progressUrl && page !== lastServerPage) {
                lastServerPage = page;
                postJson(M.progressUrl, { book_id: M.book.id, page: page, file_version: M.fileVersion }).catch(function () {});
            }
        }, 600);
    }
    setInterval(saveProgress, 30000); // periodic checkpoint; not relying on beforeunload only
    document.addEventListener('visibilitychange', function () { if (document.hidden) saveProgress(); });

    /* ---------------- Sizing ---------------- */
    function applySize() {
        var natW = img.naturalWidth || 900, natH = img.naturalHeight || Math.round(900 * (M.aspect || 1.414));
        // 32px stage padding + 2 × 12px for the device bezel drawn around the page (reader.css).
        var availW = stage.clientWidth - 56, availH = stage.clientHeight - 56;
        var base;
        if (fit === 'page') base = Math.min(availW / natW, availH / natH);
        else base = Math.min(availW, 880) / natW; // calm reading column on wide screens
        var w = Math.max(120, Math.round(natW * base * zoom));
        img.style.width = w + 'px';
        img.style.height = 'auto';
        wrap.style.minHeight = Math.round(w * natH / natW) + 'px';
        zoomLabel.textContent = Math.round(zoom * 100) + '%';
        root.querySelectorAll('[data-action="fit-width"]').forEach(function (b) { b.setAttribute('aria-pressed', String(fit === 'width')); });
        root.querySelectorAll('[data-action="fit-page"]').forEach(function (b) { b.setAttribute('aria-pressed', String(fit === 'page')); });
    }

    function setZoom(z) {
        zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round(z * 100) / 100));
        applySize();
        root.querySelector('[data-action="zoom-in"]').disabled = zoom >= ZOOM_MAX;
        root.querySelector('[data-action="zoom-out"]').disabled = zoom <= ZOOM_MIN;
    }

    /* ---------------- Page loading ---------------- */
    var preloaded = {};
    function preload(n) {
        if (n < 1 || n > total || preloaded[n]) return;
        var p = new Image();
        p.decoding = 'async';
        p.src = pageUrl(n);
        preloaded[n] = p;
    }

    function go(n, opts) {
        n = Math.min(total, Math.max(1, n | 0));
        page = n;
        loadingEl.hidden = false;
        errorEl.hidden = true;
        img.classList.add('is-loading');
        img.alt = M.i18n.pageAlt.replace(':page', n).replace(':total', total);
        img.src = pageUrl(n);
        input.value = n;
        progress.value = n;
        root.querySelector('[data-action="prev"]').disabled = n <= 1;
        root.querySelector('[data-action="next"]').disabled = n >= total;
        markThumb();
        if (!(opts && opts.noSave)) saveProgress();
        if (history.replaceState) history.replaceState(null, '', '#page=' + n);
        stage.scrollTop = 0;
        preload(n + 1); preload(n - 1);
    }

    img.addEventListener('load', function () {
        retried = false;
        loadingEl.hidden = true;
        img.classList.remove('is-loading');
        applySize();
    });
    var retried = false;
    img.addEventListener('error', function () {
        // Expired token (or about to): renew once and retry silently.
        if (token && !retried && M.renewUrl) {
            retried = true;
            renew().then(function () { img.src = pageUrl(page); }).catch(function () { loadingEl.hidden = true; errorEl.hidden = false; });
            return;
        }
        loadingEl.hidden = true;
        errorEl.hidden = false;
    });

    /* ---------------- Thumbnails (lazy) ---------------- */
    function buildThumbs() {
        if (thumbsBuilt) return;
        thumbsBuilt = true;
        var frag = document.createDocumentFragment();
        for (var i = 1; i <= total; i++) {
            var li = document.createElement('li');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'reader-thumb';
            b.setAttribute('data-goto-page', i);
            b.setAttribute('aria-label', M.i18n.thumbLabel.replace(':page', i));
            var t = document.createElement('img');
            t.loading = 'lazy';
            t.decoding = 'async';
            t.alt = '';
            t.draggable = false;
            t.width = 120; t.height = 170;
            t.src = thumbUrl(i);
            var s = document.createElement('span');
            s.textContent = i;
            b.appendChild(t); b.appendChild(s); li.appendChild(b); frag.appendChild(li);
        }
        thumbsList.appendChild(frag);
        markThumb();
    }
    function markThumb() {
        if (!thumbsBuilt) return;
        thumbsList.querySelectorAll('[aria-current]').forEach(function (el) { el.removeAttribute('aria-current'); });
        var cur = thumbsList.querySelector('[data-goto-page="' + page + '"]');
        if (cur) { cur.setAttribute('aria-current', 'page'); cur.scrollIntoView({ block: 'nearest' }); }
    }
    function toggleThumbs(force) {
        var show = typeof force === 'boolean' ? force : thumbs.hidden;
        thumbs.hidden = !show;
        root.classList.toggle('has-thumbs', show);
        root.querySelector('[data-action="thumbs"]').setAttribute('aria-expanded', String(show));
        if (show) buildThumbs();
        setTimeout(applySize, 0);
    }

    function toggleInfo() {
        info.hidden = !info.hidden;
        root.querySelectorAll('[data-action="info"]').forEach(function (b) { b.setAttribute('aria-expanded', String(!info.hidden)); });
    }

    function toggleFullscreen() {
        if (!document.fullscreenElement) { (root.requestFullscreen ? root.requestFullscreen() : Promise.reject()).catch(function () {}); }
        else if (document.exitFullscreen) { document.exitFullscreen(); }
    }
    document.addEventListener('fullscreenchange', function () { setTimeout(applySize, 50); });

    /* ---------------- Controls ---------------- */
    root.addEventListener('click', function (e) {
        var t = e.target.closest('[data-action], [data-goto-page]');
        if (!t) return;
        if (t.hasAttribute('data-goto-page')) {
            go(+t.getAttribute('data-goto-page'));
            if (window.innerWidth < 760) toggleThumbs(false);
            return;
        }
        switch (t.getAttribute('data-action')) {
            case 'prev': go(page - 1); break;
            case 'next': go(page + 1); break;
            case 'zoom-in': fit = fit === 'page' ? 'page' : 'width'; setZoom(zoom + ZOOM_STEP); break;
            case 'zoom-out': setZoom(zoom - ZOOM_STEP); break;
            case 'fit-width': fit = 'width'; setZoom(1); break;
            case 'fit-page': fit = 'page'; setZoom(1); break;
            case 'fullscreen': toggleFullscreen(); break;
            case 'thumbs': toggleThumbs(); break;
            case 'info': toggleInfo(); break;
            case 'bookmark':
                if (M.bookmarkUrl) {
                    postJson(M.bookmarkUrl, { book_id: M.book.id, page: page }).then(function (d) {
                        toast(d._status === 200 ? M.i18n.bookmarkSaved : ((d.error && d.error.message) || M.i18n.loadError));
                    }).catch(function () { toast(M.i18n.loadError); });
                }
                break;
            case 'retry':
                if (M.sessionUrl && !token) { errorEl.hidden = true; startSession().then(boot).catch(function () { errorEl.hidden = false; }); }
                else { retried = false; go(page, { noSave: true }); }
                break;
        }
    });

    root.querySelector('[data-goto]').addEventListener('submit', function (e) {
        e.preventDefault();
        var n = parseInt(input.value, 10);
        if (isNaN(n)) { input.value = page; return; }
        go(n);
        stage.focus();
    });
    input.addEventListener('change', function () { root.querySelector('[data-goto]').requestSubmit(); });

    window.addEventListener('resize', function () { applySize(); });

    /* ---------------- Keyboard ---------------- */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        var mod = e.ctrlKey || e.metaKey;

        // Copy deterrence (reader page only): select-all, copy, print, save.
        if (mod && ['a', 'c', 'p', 's', 'x'].indexOf((e.key || '').toLowerCase()) !== -1 && tag !== 'input') {
            e.preventDefault();
            if ((e.key || '').toLowerCase() !== 'a') toast(M.i18n.copyBlocked);
            return;
        }
        if (tag === 'input' || tag === 'textarea' || mod || e.altKey) return;

        var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
        var backward = rtl ? 'ArrowRight' : 'ArrowLeft';
        switch (e.key) {
            case forward: case 'PageDown': go(page + 1); e.preventDefault(); break;
            case backward: case 'PageUp': go(page - 1); e.preventDefault(); break;
            case 'Home': go(1); e.preventDefault(); break;
            case 'End': go(total); e.preventDefault(); break;
            case '+': case '=': setZoom(zoom + ZOOM_STEP); break;
            case '-': case '_': setZoom(zoom - ZOOM_STEP); break;
            case 'f': case 'F': toggleFullscreen(); break;
            case 't': case 'T': toggleThumbs(); break;
            case 'Escape': if (!info.hidden) toggleInfo(); break;
        }
    });

    /* ---------------- Touch swipe (mobile) ---------------- */
    var sx = 0, sy = 0, st = 0;
    stage.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        sx = e.touches[0].clientX; sy = e.touches[0].clientY; st = Date.now();
    }, { passive: true });
    stage.addEventListener('touchend', function (e) {
        if (zoom > 1 || !st) return; // don't fight panning when zoomed in
        var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
        if (Date.now() - st < 600 && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            var towardsNext = rtl ? dx > 0 : dx < 0;
            go(page + (towardsNext ? 1 : -1));
        }
        st = 0;
    }, { passive: true });

    /* ---------------- Copy deterrence (reader area only) ---------------- */
    ['contextmenu', 'dragstart', 'selectstart', 'copy', 'cut'].forEach(function (type) {
        stage.addEventListener(type, function (e) { e.preventDefault(); });
        if (thumbs) thumbs.addEventListener(type, function (e) { e.preventDefault(); });
    });

    /* ---------------- Start ---------------- */
    function boot() {
        total = Math.max(1, M.pageCount | 0);
        input.max = total; progress.max = total;
        var totalEl = root.querySelector('[data-page-total]');
        if (totalEl) totalEl.textContent = total;
        var fromHash = /#page=(\d+)/.exec(location.hash);
        var sp = M.serverProgress;
        var fromServer = sp && sp.file_version === M.fileVersion && sp.page >= 1 && sp.page <= total ? sp.page : null;
        var start = fromHash ? +fromHash[1] : (fromServer || loadProgress());
        if (start && !fromHash && start > 1) toast(M.i18n.resumed.replace(':page', start));
        if (window.innerWidth >= 1100) toggleThumbs(true);
        setZoom(1);
        go(start || 1, { noSave: true });
    }

    if (M.sessionUrl) {
        loadingEl.hidden = false;
        startSession().then(boot).catch(function (e) {
            loadingEl.hidden = true;
            errorEl.hidden = false;
            var p = errorEl.querySelector('p');
            if (p && e && e.message) p.textContent = e.message;
        });
    } else {
        boot();
    }
})();
