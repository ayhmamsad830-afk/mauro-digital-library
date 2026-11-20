/*
 * MAURO — decorative motion (vanilla JS, no dependencies). Replaces cosmic.js (2026-11-19).
 *
 *   - Page visibility   pauses the featured-book float while the tab is hidden
 *   - Scroll reveal     IntersectionObserver; content below the fold fades up
 *
 * Nothing here is needed to use the site: without JS, or with
 * prefers-reduced-motion, nothing is ever hidden. Styles are set via CSSOM
 * (allowed by the CSP, which forbids inline style attributes).
 */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;
    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------------- Page visibility: pause decorative animation ---------------- */
    function syncVisibility() { root.classList.toggle('motion-paused', doc.hidden); }
    doc.addEventListener('visibilitychange', syncVisibility);
    syncVisibility();

    /* ---------------- Scroll reveal ----------------
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
            '.site-main .publisher-grid > li',
            '.site-main .promise',
            '.site-main .book-body > *',
            '.site-main .reviews',
            '.site-main .empty-state',
            '.site-main .account-section',
            '.site-main .about-block'
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
            // Safety net: content jumped over in one scroll (End key, fast fling, anchor link)
            // never intersects, so the observer never fires for it. Show it without animation.
            var pending = false;
            var sweep = function () {
                pending = false;
                var vh = window.innerHeight;
                below = below.filter(function (el) {
                    if (!el.classList.contains('reveal') || el.classList.contains('is-revealed')) return false;
                    if (el.getBoundingClientRect().top > vh) return true; // still ahead: keep waiting
                    io.unobserve(el);
                    el.classList.remove('reveal');
                    return false;
                });
                if (!below.length) window.removeEventListener('scroll', onScroll);
            };
            var onScroll = function () {
                if (!pending) { pending = true; window.setTimeout(sweep, 250); }
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            // Printing must never leave content hidden.
            window.addEventListener('beforeprint', function () {
                below.forEach(function (el) { el.classList.remove('reveal', 'is-revealed'); });
            });
        }
    }
})();
