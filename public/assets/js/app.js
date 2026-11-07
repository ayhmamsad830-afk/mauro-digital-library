/* Digital Library — shared front-end behaviour (vanilla JS, no framework). */
(function () {
    'use strict';

    var root = document.documentElement;

    /* ---------------- Theme (light/dark) ---------------- */
    function currentTheme() {
        var explicit = root.getAttribute('data-theme');
        if (explicit === 'light' || explicit === 'dark') return explicit;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function saveTheme(theme) {
        root.setAttribute('data-theme', theme);
        try { localStorage.setItem('dl_theme', theme); } catch (e) { /* storage blocked */ }
        // Cookie lets the server render the right theme on the first byte (no flash).
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'dl_theme=' + theme + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-theme-toggle]')) {
            saveTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        }
    });
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.setAttribute('aria-pressed', currentTheme() === 'dark' ? 'true' : 'false');
    });

    /* ---------------- Drawer (mobile navigation, filters) ---------------- */
    var lastFocus = null;
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

    function openDrawer(drawer, opener) {
        lastFocus = opener || document.activeElement;
        drawer.classList.add('is-open');
        document.body.classList.add('is-locked');
        document.querySelectorAll('[data-drawer-open="' + drawer.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        var first = drawer.querySelector('[data-drawer-close]:not(.drawer__backdrop)') || drawer.querySelector(FOCUSABLE);
        if (first) first.focus();
    }

    function closeDrawer(drawer) {
        drawer.classList.remove('is-open');
        document.body.classList.remove('is-locked');
        document.querySelectorAll('[data-drawer-open="' + drawer.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-drawer-open]');
        if (opener) {
            var drawer = document.getElementById(opener.getAttribute('data-drawer-open'));
            if (drawer) { event.preventDefault(); openDrawer(drawer, opener); }
            return;
        }
        var closer = event.target.closest('[data-drawer-close]');
        if (closer) {
            var d = closer.closest('[data-drawer]');
            if (d) closeDrawer(d);
        }
    });

    document.addEventListener('keydown', function (event) {
        var open = document.querySelector('[data-drawer].is-open');
        if (!open) return;
        if (event.key === 'Escape') { closeDrawer(open); return; }
        if (event.key === 'Tab') {
            // Keep keyboard focus inside the open drawer.
            var items = Array.prototype.filter.call(open.querySelectorAll(FOCUSABLE), function (el) { return el.offsetParent !== null; });
            if (!items.length) return;
            var first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { last.focus(); event.preventDefault(); }
            else if (!event.shiftKey && document.activeElement === last) { first.focus(); event.preventDefault(); }
        }
    });

    // Close the drawer if the viewport grows past the mobile breakpoint.
    window.addEventListener('resize', function () {
        var open = document.querySelector('[data-drawer].is-open');
        if (open && open.hasAttribute('data-drawer-mobile-only') && window.innerWidth > 860) closeDrawer(open);
    });

    /* ---------------- Auto-submit selects (catalogue sort / per-page) ---------------- */
    document.addEventListener('change', function (event) {
        var el = event.target.closest('[data-autosubmit]');
        if (el && el.form) el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
    });

    /* ---------------- Toasts ---------------- */
    window.DL = window.DL || {};
    window.DL.toast = function (message) {
        var region = document.querySelector('[data-toast-region]');
        if (!region) return;
        var t = document.createElement('div');
        t.className = 'toast';
        t.textContent = message;
        region.appendChild(t);
        setTimeout(function () { t.remove(); }, 3500);
    };

    /* ---------------- Loading state on submitted forms ---------------- */
    document.addEventListener('submit', function (event) {
        // Design-preview forms (Phase 2) must never submit anywhere.
        if (event.target.hasAttribute('data-preview-form')) { event.preventDefault(); return; }
        var btn = event.target.querySelector('[data-loading-on-submit]');
        if (btn) { btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); }
        showUploadStatus(event.target);
    });

    /* ---------------- Upload status (admin file forms) ----------------
       The form still submits natively (server answers with a redirect + flash
       message), so this is an honest "in progress" indicator: file name, size
       and an indeterminate bar — no invented percentage. */
    function showUploadStatus(form) {
        if (!form.hasAttribute('data-uploading') || form.querySelector('.upload-status')) return;
        var input = form.querySelector('input[type="file"]');
        var file = input && input.files && input.files[0];
        if (!file) return;
        var size = file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB';
        var box = document.createElement('div');
        box.className = 'upload-status';
        box.setAttribute('role', 'status');
        var bar = document.createElement('span');
        bar.className = 'upload-status__bar';
        bar.setAttribute('aria-hidden', 'true');
        var label = document.createElement('span');
        label.className = 'upload-status__label';
        label.textContent = form.getAttribute('data-uploading');
        var meta = document.createElement('span');
        meta.className = 'upload-status__file';
        meta.textContent = file.name + ' · ' + size;
        meta.setAttribute('dir', 'auto');
        box.appendChild(label); box.appendChild(meta); box.appendChild(bar);
        form.appendChild(box);
    }

    /* ---------------- Book carousels ----------------
       Previous/next page by one full view; the track snaps to whole cards.
       Works in RTL (scrollLeft runs negative there) and hides the controls
       when every card already fits. Touch, trackpad and keyboard focus keep
       working natively because the track is an ordinary scroll container. */
    document.querySelectorAll('[data-rail]').forEach(function (rail) {
        var track = rail.querySelector('[data-rail-track]');
        var prev = rail.querySelector('[data-rail-prev]');
        var next = rail.querySelector('[data-rail-next]');
        if (!track || !prev || !next) return;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function isRtl() { return getComputedStyle(track).direction === 'rtl'; }
        function step() {
            var gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            return track.clientWidth + gap;
        }
        function go(dir) {
            track.scrollBy({ left: (isRtl() ? -1 : 1) * dir * step(), behavior: reduce ? 'auto' : 'smooth' });
        }
        function update() {
            var max = track.scrollWidth - track.clientWidth;
            var pos = Math.abs(track.scrollLeft);
            var fits = max <= 2;
            prev.hidden = next.hidden = fits;
            prev.disabled = pos <= 2;
            next.disabled = pos >= max - 2;
        }
        prev.addEventListener('click', function () { go(-1); });
        next.addEventListener('click', function () { go(1); });
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    });

    /* ---------------- Show / hide password (admin sign-in) ---------------- */
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-password-toggle]');
        if (!btn) return;
        var input = document.getElementById(btn.getAttribute('data-password-toggle'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        var label = btn.getAttribute(show ? 'data-label-hide' : 'data-label-show');
        btn.title = label;
        var sr = btn.querySelector('[data-password-toggle-label]');
        if (sr) sr.textContent = label;
        input.focus();
    });
    // Never leave a revealed password visible when the form is submitted.
    document.addEventListener('submit', function (event) {
        event.target.querySelectorAll('[data-password-toggle][aria-pressed="true"]').forEach(function (b) { b.click(); });
    }, true);
})();
