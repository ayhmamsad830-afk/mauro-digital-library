<?php /** @var bool $googleConfigured @var string $googleClientId @var string $googleNonce @var string $redirectTo @var string|null $error */ ?>
<div class="auth">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('book', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('auth.title')) ?></h1>
        <p class="muted"><?= e(__('auth.subtitle')) ?></p>

        <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>

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

        <ul class="auth__benefits" role="list">
            <li><?= icon('clock', 'icon--sm') ?><?= e(__('auth.benefit_sync')) ?></li>
            <li><?= icon('heart', 'icon--sm') ?><?= e(__('auth.benefit_favorites')) ?></li>
            <li><?= icon('bookmark', 'icon--sm') ?><?= e(__('auth.benefit_bookmarks')) ?></li>
            <li><?= icon('star', 'icon--sm') ?><?= e(__('auth.benefit_reviews')) ?></li>
        </ul>

        <p class="auth__guest small"><?= e(__('auth.guest_note')) ?> <a href="<?= e(route('books.index')) ?>"><?= e(__('auth.browse_as_guest')) ?></a></p>
        <p class="xsmall muted"><?= e(__('auth.legal_prefix')) ?> <a href="<?= e(route('pages.terms')) ?>"><?= e(__('pages.terms.title')) ?></a> · <a href="<?= e(route('pages.privacy')) ?>"><?= e(__('pages.privacy.title')) ?></a></p>
        <?php if (!empty($demoMode)): ?><p class="xsmall"><a href="<?= e(route('account.preview')) ?>"><?= e(__('demo.account_preview_link')) ?></a></p><?php endif; ?>
    </div>
</div>
