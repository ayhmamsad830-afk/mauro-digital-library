# MAURO — Known issues and accepted exceptions

Issues listed here are understood, accepted by the project owner, and deliberately
**not** hidden from the test suite. A failing check that matches an entry below is
expected; any other failure is a regression.

## KI-1 — `/login`: Google Identity Services inline styles blocked by the CSP

**Status:** accepted as a known third-party exception (owner decision, 2026-11-19).
**Test impact:** `Feature\BrowserUiTest` reports 6 failing browser checks out of 587 —
`ar|en` × `mobile|tablet|desktop` on `/login`, message
`Refused to apply inline style because it violates … "style-src 'self' https://accounts.google.com/gsi/style"`.
The test is intentionally left strict (no filtering of these messages).

### Cause

- The Google Identity Services client (`https://accounts.google.com/gsi/client`), loaded on
  `/login`, injects inline `<style>` content at runtime.
- MAURO's CSP follows Google's documented policy for GIS exactly:
  `script-src 'self' https://accounts.google.com/gsi/client; style-src 'self' https://accounts.google.com/gsi/style;
  connect-src 'self' https://accounts.google.com/gsi/; frame-src https://accounts.google.com/gsi/`.
- MAURO's own `/login` markup contains no inline style (`style=` attribute or `<style>` block).

### Evidence (2026-11-19, Chromium 141 / Playwright 1.56.1)

| Origin | Google button rendered | "origin not allowed" | Inline-style violations |
|---|---|---|---|
| `http://localhost:8000` (authorised for the client ID) | yes | 0 | 4 |
| `http://127.0.0.1:8000` | yes | 1 | 3 |
| `http://127.0.0.1:<random test port>` | yes | 1 | 3 |

- The violations occur even on the authorised origin: they come from Google's script, not from the environment.
- The "origin not allowed" error is environmental: only `http://localhost:8000` is registered in the
  Google Cloud console; the test suite serves `127.0.0.1` on random ports.
- Functional impact: none observed — the Google button iframe renders in every case.

### Why it is not "fixed"

Silencing the violations would require `'unsafe-inline'` or hashes of Google-controlled styles in
`style-src`, i.e. weakening the CSP for third-party content that can change at any time. Rejected.

### Revisit when

- Google changes GIS so that it no longer injects inline styles, or documents a nonce mechanism; or
- the sign-in button stops rendering or Google sign-in fails for a real user (then it is a bug, not this entry).

## KI-2 — `127.0.0.1` is not an authorised Google origin (development only)

Google sign-in works on `http://localhost:8000` only. Use that URL (not `127.0.0.1`) for manual
Google sign-in tests. Production needs its HTTPS domain added to the OAuth client's
authorised JavaScript origins before launch.
