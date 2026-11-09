/*
 * Runs synchronously in <head> to apply the saved theme before first paint
 * (avoids a light/dark flash). External file so the CSP needs no 'unsafe-inline'.
 */
(function () {
    try {
        var saved = localStorage.getItem('dl_theme');
        // Dark is the default; only an explicit saved "light" switches it off.
        document.documentElement.setAttribute('data-theme', saved === 'light' ? 'light' : 'dark');
    } catch (e) { /* storage unavailable: server cookie / dark default applies */ }
})();
