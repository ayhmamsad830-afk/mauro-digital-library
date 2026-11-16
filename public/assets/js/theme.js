/*
 * Runs synchronously in <head> to apply the saved theme before first paint
 * (avoids a light/dark flash). External file so the CSP needs no 'unsafe-inline'.
 */
(function () {
    try {
        var saved = localStorage.getItem('dl_theme');
        // Light (paper) is the default; only an explicit saved "dark" switches it.
        document.documentElement.setAttribute('data-theme', saved === 'dark' ? 'dark' : 'light');
    } catch (e) { /* storage unavailable: server cookie / light default applies */ }
})();
