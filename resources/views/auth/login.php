<?php /** @var bool $googleConfigured @var string $googleClientId @var string $googleNonce @var string $redirectTo @var string|null $error @var string $email */ ?>
<div class="auth">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('book', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('auth.title')) ?></h1>
        <p class="muted"><?= e(__('auth.subtitle')) ?></p>

        <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>

        <?= \App\Core\View::partial('auth/_google', get_defined_vars()) ?>

        <form class="auth__form" method="post" action="<?= e(route('login.email')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
            <div class="field">
                <label class="label" for="login-email"><?= e(__('member_auth.email')) ?></label>
                <input class="input" id="login-email" name="email" type="email" dir="ltr" value="<?= e($email) ?>"
                       autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="190" required>
            </div>
            <div class="field">
                <label class="label" for="login-password"><?= e(__('member_auth.password')) ?></label>
                <div class="password-field">
                    <input class="input" id="login-password" name="password" type="password" dir="ltr" autocomplete="current-password" maxlength="1024" required>
                    <button type="button" class="password-field__toggle" data-password-toggle="login-password" aria-controls="login-password" aria-pressed="false"
                            data-label-show="<?= e(__('admin_auth.show')) ?>" data-label-hide="<?= e(__('admin_auth.hide')) ?>" title="<?= e(__('admin_auth.show')) ?>">
                        <span class="password-field__on"><?= icon('eye') ?></span><span class="password-field__off"><?= icon('eye-off') ?></span>
                        <span class="visually-hidden" data-password-toggle-label><?= e(__('admin_auth.show')) ?></span>
                    </button>
                </div>
            </div>
            <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= icon('mail', 'icon--sm') ?><?= e(__('member_auth.login_submit')) ?></button>
            <p class="auth__forgot"><a href="<?= e(route('password.forgot')) ?>"><?= e(__('reset.forgot_link')) ?></a></p>
        </form>

        <p class="auth__switch"><?= e(__('member_auth.no_account')) ?> <a href="<?= e(route('register', [], ['redirect' => $redirectTo])) ?>"><?= e(__('member_auth.create_free')) ?></a></p>

        <ul class="auth__benefits" role="list">
            <li><?= icon('book', 'icon--sm') ?><?= e(__('auth.benefit_full_books')) ?></li>
            <li><?= icon('clock', 'icon--sm') ?><?= e(__('auth.benefit_sync')) ?></li>
            <li><?= icon('heart', 'icon--sm') ?><?= e(__('auth.benefit_favorites')) ?></li>
            <li><?= icon('bookmark', 'icon--sm') ?><?= e(__('auth.benefit_bookmarks')) ?></li>
        </ul>

        <p class="xsmall muted"><?= e(__('auth.legal_prefix')) ?> <a href="<?= e(route('pages.terms')) ?>"><?= e(__('pages.terms.title')) ?></a> · <a href="<?= e(route('pages.privacy')) ?>"><?= e(__('pages.privacy.title')) ?></a></p>
        <?php if (!empty($demoMode)): ?><p class="xsmall"><a href="<?= e(route('account.preview')) ?>"><?= e(__('demo.account_preview_link')) ?></a></p><?php endif; ?>
    </div>
</div>
