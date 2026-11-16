<?php
/**
 * Google sign-in button (shared by /login and /register).
 * @var bool $googleConfigured @var string $googleClientId @var string $googleNonce @var string $redirectTo
 */
?>
<?php if ($googleConfigured): ?>
    <div class="auth__google" data-google-signin
         data-client-id="<?= e($googleClientId) ?>" data-nonce="<?= e($googleNonce) ?>"
         data-endpoint="<?= e(route('api.auth.google')) ?>" data-redirect="<?= e($redirectTo) ?>"
         data-locale="<?= e(locale()) ?>" data-error="<?= e(__('auth.failed')) ?>" data-unavailable="<?= e(__('auth.gis_unavailable')) ?>">
        <div class="auth__gis-button" data-gis-button aria-live="polite">
            <span class="loading"><span class="spinner spinner--sm"></span><span><?= e(__('common.loading')) ?></span></span>
        </div>
        <p class="error-text" data-auth-error hidden role="alert"></p>
    </div>
    <noscript><p class="help"><?= e(__('auth.needs_js')) ?></p></noscript>
<?php else: ?>
    <button type="button" class="btn btn--ghost btn--lg btn--block google-btn" disabled aria-describedby="google-unavailable">
        <svg class="google-btn__logo" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.2-2.1 3.5-5.1 3.5-8.7z"/><path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.3a12 12 0 0 0 0 10.8z"/><path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1c.9-2.9 3.6-4.9 6.7-4.9z"/></svg>
        <?= e(__('auth.continue_google')) ?>
    </button>
    <p id="google-unavailable" class="help"><?= e(__('auth.not_configured')) ?></p>
<?php endif; ?>
<p class="auth__divider" role="separator"><span><?= e(__('member_auth.or_email')) ?></span></p>
