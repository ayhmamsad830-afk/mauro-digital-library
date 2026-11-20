/*
 * Runs synchronously in <head> to apply the saved theme before first paint
 * (avoids a light/dark flash). External file so the CSP needs no 'unsafe-inline'.
 * Priority: the dl_theme cookie (set by the toggle, by /account/settings and at
 * sign-in from the member's saved preference) > this browser's localStorage > dark.
 */
(function () {
    var theme = null;
    var m = /(?:^|;\s*)dl_theme=(light|dark)(?:;|$)/.exec(document.cookie || '');
    if (m) theme = m[1];
    try {
        if (!theme) theme = localStorage.getItem('dl_theme');
        if (theme === 'light' || theme === 'dark') localStorage.setItem('dl_theme', theme);
    } catch (e) { /* storage unavailable: the cookie / dark default applies */ }
    // Dark is the default; only an explicit saved "light" switches it off.
    document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
})();
