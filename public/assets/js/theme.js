/*
 * Runs synchronously in <head> to apply the saved theme before first paint
 * (avoids a light/dark flash). External file so the CSP needs no 'unsafe-inline'.
 */
(function () {
    try {
        var saved = localStorage.getItem('dl_theme');
        if (saved === 'light' || saved === 'dark') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    } catch (e) { /* storage unavailable: server cookie / system preference applies */ }
})();
