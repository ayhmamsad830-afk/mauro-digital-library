/*
 * Google Identity Services sign-in (popup mode). The ID token is posted to our
 * backend, which verifies it server-side; nothing from Google is trusted here.
 */
(function () {
    'use strict';
    var box = document.querySelector('[data-google-signin]');
    if (!box) return;
    var errEl = box.querySelector('[data-auth-error]');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function showError(msg) { errEl.textContent = msg; errEl.hidden = false; }

    function onCredential(resp) {
        errEl.hidden = true;
        fetch(box.getAttribute('data-endpoint'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ credential: resp.credential, redirect: box.getAttribute('data-redirect') })
        }).then(function (r) { return r.json().then(function (d) { return [r.status, d]; }); })
          .then(function (res) {
              if (res[0] === 200 && res[1].redirect) { location.assign(res[1].redirect); return; }
              showError((res[1].error && res[1].error.message) || box.getAttribute('data-error'));
          })
          .catch(function () { showError(box.getAttribute('data-error')); });
    }

    function init() {
        if (!window.google || !google.accounts || !google.accounts.id) { showError(box.getAttribute('data-unavailable')); return; }
        google.accounts.id.initialize({
            client_id: box.getAttribute('data-client-id'),
            nonce: box.getAttribute('data-nonce'),
            callback: onCredential,
            ux_mode: 'popup',
            context: 'signin',
            itp_support: true
        });
        var target = box.querySelector('[data-gis-button]');
        target.textContent = '';
        google.accounts.id.renderButton(target, {
            type: 'standard', theme: document.documentElement.getAttribute('data-theme') === 'dark' ? 'filled_black' : 'outline',
            size: 'large', text: 'continue_with', shape: 'pill', logo_alignment: 'left',
            locale: box.getAttribute('data-locale'), width: Math.min(360, target.clientWidth || 320)
        });
    }

    var s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = init;
    s.onerror = function () { showError(box.getAttribute('data-unavailable')); };
    document.head.appendChild(s);
})();
