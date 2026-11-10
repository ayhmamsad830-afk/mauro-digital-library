/*
 * MAURO — cosmic atmosphere (purely decorative, vanilla JS, no dependencies).
 *
 *   1. Twinkling stars      a few dozen tiny CSS-animated dots (opacity only)
 *   2. Shooting star        one at a time, every 20–30 s, dark theme only
 *   3. Parallax             the sky drifts a few pixels on scroll (desktop only)
 *   4. Scroll reveal        IntersectionObserver; content below the fold fades up
 *   5. Cursor trail         a short blue light trail (desktop mouse, dark theme)
 *
 * Rules: nothing here is needed to use the site. Without JS the static sky and
 * all content still render; with prefers-reduced-motion everything decorative
 * is skipped; animations pause while the tab is hidden; no loop runs forever
 * (the trail's rAF loop stops as soon as the pointer rests). Styles are set via
 * CSSOM (allowed by the CSP, which forbids inline style attributes).
 */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;
    var cosmos = doc.querySelector('[data-cosmos]');

    function mq(q) { return !!(window.matchMedia && window.matchMedia(q).matches); }
    var reduce = mq('(prefers-reduced-motion: reduce)');
    var finePointer = mq('(hover: hover) and (pointer: fine)');
    var conn = navigator.connection || {};
    var lowPower = conn.saveData === true
        || (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2)
        || (navigator.deviceMemory && navigator.deviceMemory <= 2);

    function isDark() { return root.getAttribute('data-theme') !== 'light'; }
    function rand(min, max) { return min + Math.random() * (max - min); }

    /* ---------------- Page visibility: pause every decorative animation ---------------- */
    function syncVisibility() { root.classList.toggle('cosmos-paused', doc.hidden); }
    doc.addEventListener('visibilitychange', syncVisibility);
    syncVisibility();

    /* ---------------- 1. Twinkling stars ---------------- */
    if (cosmos && !reduce) {
        var starLayer = cosmos.querySelector('[data-cosmos-stars]');
        if (starLayer) {
            // Density follows the viewport area: ~30 on a phone, ~80 on a large desktop.
            var count = Math.round(Math.min(80, Math.max(26, (window.innerWidth * window.innerHeight) / 17000)));
            if (lowPower) count = Math.round(count / 2);
            var frag = doc.createDocumentFragment();
            for (var i = 0; i < count; i++) {
                var s = doc.createElement('span');
                var bright = Math.random() < 0.12;
                s.className = bright ? 'star star--bright' : 'star';
                s.style.left = rand(0, 100).toFixed(2) + '%';
                s.style.top = rand(0, 100).toFixed(2) + '%';
                s.style.setProperty('--s', (bright ? rand(2, 2.8) : rand(1, 1.9)).toFixed(2) + 'px');
                s.style.setProperty('--lo', rand(0.08, 0.3).toFixed(2));
                s.style.setProperty('--hi', (bright ? rand(0.8, 1) : rand(0.45, 0.8)).toFixed(2));
                s.style.animationDuration = rand(3.5, 8).toFixed(2) + 's';
                s.style.animationDelay = (-rand(0, 8)).toFixed(2) + 's';
                frag.appendChild(s);
            }
            starLayer.appendChild(frag);
        }
    }

    /* ---------------- 2. Shooting star (one at a time) ---------------- */
    if (cosmos && !reduce && !lowPower) {
        var shootTimer = null;
        var scheduleShot = function (first) {
            clearTimeout(shootTimer);
            shootTimer = setTimeout(shoot, first ? rand(6000, 12000) : rand(20000, 30000));
        };
        var shoot = function () {
            // Skipped (not queued) while hidden or in the light theme.
            if (doc.hidden || !isDark() || cosmos.querySelector('.shooting-star')) { scheduleShot(); return; }
            var rtl = root.getAttribute('dir') === 'rtl';
            var star = doc.createElement('span');
            star.className = 'shooting-star';
            star.style.top = rand(4, 38).toFixed(1) + '%';
            star.style.left = (rtl ? rand(35, 85) : rand(15, 65)).toFixed(1) + '%';
            // Diagonal fall that follows the reading direction.
            star.style.setProperty('--angle', (rtl ? rand(150, 162) : rand(18, 30)).toFixed(1) + 'deg');
            star.addEventListener('animationend', function () { star.remove(); });
            cosmos.appendChild(star);
            setTimeout(function () { if (star.isConnected) star.remove(); }, 4000); // safety net
            scheduleShot();
        };
        scheduleShot(true);
    }

    /* ---------------- 3. Subtle parallax (desktop, fine pointer) ---------------- */
    if (cosmos && !reduce && !lowPower && finePointer && window.innerWidth >= 1024) {
        var sky = cosmos.querySelector('[data-cosmos-sky]');
        var stars = cosmos.querySelector('[data-cosmos-stars]');
        var ticking = false;
        var applyParallax = function () {
            ticking = false;
            var y = window.scrollY || 0;
            // Max ~40px for the sky and ~20px for the stars: depth without motion sickness.
            if (sky) sky.style.transform = 'translate3d(0,' + (-Math.min(40, y * 0.02)).toFixed(1) + 'px,0)';
            if (stars) stars.style.transform = 'translate3d(0,' + (-Math.min(70, y * 0.035)).toFixed(1) + 'px,0)';
        };
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; window.requestAnimationFrame(applyParallax); }
        }, { passive: true });
        applyParallax();
    }

    /* ---------------- 4. Scroll reveal ----------------
       Only elements that start BELOW the fold are hidden, so nothing the reader
       sees on arrival is delayed. Without IntersectionObserver (or JS) nothing
       is ever hidden. */
    if (!reduce && 'IntersectionObserver' in window) {
        var SELECTOR = [
            '.site-main .section-head',
            '.site-main .rail',
            '.site-main .book-grid:not(.book-grid--rail) > li',
            '.site-main .category-grid > li',
            '.site-main .author-grid > li',
            '.site-main .promise',
            '.site-main .book-body > *',
            '.site-main .reviews',
            '.site-main .empty-state',
            '.site-main .account-section'
        ].join(',');
        var targets = Array.prototype.slice.call(doc.querySelectorAll(SELECTOR));
        var fold = window.innerHeight * 0.95;
        // Read all positions first, then write classes (no layout thrashing).
        var below = targets.filter(function (el) { return el.getBoundingClientRect().top > fold; });
        if (below.length) {
            var io = new IntersectionObserver(function (entries) {
                var n = 0;
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    var el = entry.target;
                    io.unobserve(el);
                    // Items arriving together are staggered slightly (max 320 ms).
                    el.style.setProperty('--reveal-delay', Math.min(n++ * 70, 320) + 'ms');
                    el.classList.add('is-revealed');
                    el.addEventListener('transitionend', function done(ev) {
                        if (ev.target !== el) return;
                        el.removeEventListener('transitionend', done);
                        el.classList.remove('reveal', 'is-revealed');
                        el.style.removeProperty('--reveal-delay');
                    });
                });
            }, { rootMargin: '0px 0px -6% 0px', threshold: 0.08 });
            below.forEach(function (el) { el.classList.add('reveal'); io.observe(el); });
            // Printing or jumping to an anchor must never leave content hidden.
            window.addEventListener('beforeprint', function () {
                below.forEach(function (el) { el.classList.remove('reveal', 'is-revealed'); });
            });
        }
    }

    /* ---------------- 5. Cosmic cursor trail ---------------- */
    var trailEnabled = doc.body && doc.body.hasAttribute('data-cursor-trail');
    if (trailEnabled && finePointer && !reduce && !lowPower) {
        var N = 8;
        var layer = doc.createElement('div');
        layer.className = 'cursor-trail';
        layer.setAttribute('aria-hidden', 'true');
        var dots = [], pts = [];
        for (var d = 0; d < N; d++) {
            var dot = doc.createElement('span');
            dot.className = 'cursor-trail__dot';
            layer.appendChild(dot);
            dots.push(dot);
            pts.push({ x: -100, y: -100 });
        }
        doc.body.appendChild(layer);

        var mouse = { x: -100, y: -100 };
        var lastMove = 0;
        var running = false;

        var tick = function (now) {
            var fade = Math.max(0, 1 - Math.max(0, now - lastMove - 60) / 520);
            var lead = 0.5, follow = 0.42;
            pts[0].x += (mouse.x - pts[0].x) * lead;
            pts[0].y += (mouse.y - pts[0].y) * lead;
            for (var i = 1; i < N; i++) {
                pts[i].x += (pts[i - 1].x - pts[i].x) * follow;
                pts[i].y += (pts[i - 1].y - pts[i].y) * follow;
            }
            for (var j = 0; j < N; j++) {
                var k = 1 - j / N;
                dots[j].style.transform = 'translate3d(' + pts[j].x.toFixed(1) + 'px,' + pts[j].y.toFixed(1) + 'px,0) scale(' + (0.35 + 0.65 * k).toFixed(2) + ')';
                dots[j].style.opacity = (0.5 * k * fade).toFixed(3);
            }
            if (fade > 0) { window.requestAnimationFrame(tick); } else { running = false; }
        };

        doc.addEventListener('pointermove', function (e) {
            if (e.pointerType && e.pointerType !== 'mouse') return;
            if (!isDark()) return;
            mouse.x = e.clientX; mouse.y = e.clientY;
            if (!running) {
                // Restart from the pointer itself so the trail never sweeps across the page.
                for (var i = 0; i < N; i++) { pts[i].x = mouse.x; pts[i].y = mouse.y; }
                running = true;
                window.requestAnimationFrame(tick);
            }
            lastMove = performance.now();
        }, { passive: true });
        // Pointer left the window: let the trail fade out.
        doc.addEventListener('mouseout', function (e) { if (!e.relatedTarget) lastMove = 0; });
    }
})();
