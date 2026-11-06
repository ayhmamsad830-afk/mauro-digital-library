/*
 * Offer to import guest reading progress (localStorage) into the account —
 * only after the member explicitly agrees (spec §7.3). Asked once per browser.
 */
(function () {
    'use strict';
    var box = document.querySelector('[data-sync-prompt]');
    if (!box) return;
    var items = [];
    try {
        if (localStorage.getItem('dl_sync_answered') === '1') return;
        for (var i = 0; i < localStorage.length; i++) {
            var k = localStorage.key(i);
            if (k && k.indexOf('dl_progress:') === 0) {
                var v = JSON.parse(localStorage.getItem(k) || 'null');
                if (v && typeof v.page === 'number' && typeof v.v === 'number') items.push({ slug: k.slice(12), page: v.page, v: v.v });
            }
        }
    } catch (e) { return; }
    if (!items.length) return;
    box.hidden = false;

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var result = box.querySelector('[data-sync-result]');
    function done() { try { localStorage.setItem('dl_sync_answered', '1'); } catch (e) {} }

    box.querySelector('[data-sync-decline]').addEventListener('click', function () { done(); box.hidden = true; });
    box.querySelector('[data-sync-accept]').addEventListener('click', function (ev) {
        ev.target.disabled = true;
        fetch(box.getAttribute('data-endpoint'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ items: items.slice(0, 50) })
        }).then(function (r) { return r.json(); }).then(function (d) {
            done();
            result.textContent = (box.getAttribute('data-done') || '✓') + ' ' + (d.imported || 0);
            setTimeout(function () { location.reload(); }, 900);
        }).catch(function () { ev.target.disabled = false; });
    });
})();
