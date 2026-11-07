<?php /** @var string $redirectTo @var string|null $error @var string $username @var bool $googleLogin */ ?>
<div class="auth auth--admin">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('lock', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('admin_auth.title')) ?></h1>
        <p class="muted"><?= e(__('admin_auth.subtitle')) ?></p>

        <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>

        <form class="auth__form" method="post" action="<?= e(route('admin.login.submit')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
            <div class="field">
                <label class="label" for="admin-username"><?= e(__('admin_auth.username')) ?></label>
                <input class="input" id="admin-username" name="username" type="text" dir="ltr" value="<?= e($username) ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="64" required<?= $username === '' ? ' autofocus' : '' ?>>
            </div>
            <div class="field">
                <label class="label" for="admin-password"><?= e(__('admin_auth.password')) ?></label>
                <div class="password-field">
                    <input class="input" id="admin-password" name="password" type="password" dir="ltr"
                           autocomplete="current-password" maxlength="1024" required<?= $username !== '' ? ' autofocus' : '' ?>>
                    <button type="button" class="password-field__toggle" data-password-toggle="admin-password" aria-controls="admin-password" aria-pressed="false"
                            data-label-show="<?= e(__('admin_auth.show')) ?>" data-label-hide="<?= e(__('admin_auth.hide')) ?>" title="<?= e(__('admin_auth.show')) ?>">
                        <span class="password-field__on"><?= icon('eye') ?></span><span class="password-field__off"><?= icon('eye-off') ?></span>
                        <span class="visually-hidden" data-password-toggle-label><?= e(__('admin_auth.show')) ?></span>
                    </button>
                </div>
            </div>
            <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= icon('lock', 'icon--sm') ?><?= e(__('admin_auth.submit')) ?></button>
        </form>

        <p class="xsmall muted"><?= icon('shield', 'icon--sm') ?> <?= e(__('admin_auth.security_note')) ?></p>
        <?php if ($googleLogin): ?><p class="small"><a href="<?= e(route('login')) ?>"><?= e(__('admin_auth.member_signin')) ?></a></p><?php endif; ?>
    </div>
</div>
