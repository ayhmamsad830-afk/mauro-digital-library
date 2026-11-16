<?php
/**
 * Free account: Google button or name / e-mail / password.
 * @var bool $googleConfigured @var string $redirectTo @var string|null $error
 * @var array<string,string> $fieldErrors @var string $name @var string $email
 */
$fe = static fn (string $k): ?string => $fieldErrors[$k] ?? null;
?>
<div class="auth">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('user', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('member_auth.register_title')) ?></h1>
        <p class="auth__free"><?= icon('check', 'icon--sm') ?><?= e(__('member_auth.free_badge')) ?></p>
        <p class="muted"><?= e(__('member_auth.register_subtitle')) ?></p>

        <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>

        <?= \App\Core\View::partial('auth/_google', get_defined_vars()) ?>

        <form class="auth__form" method="post" action="<?= e(route('register.submit')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
            <div class="field">
                <label class="label" for="reg-name"><?= e(__('member_auth.name')) ?></label>
                <input class="input" id="reg-name" name="name" type="text" value="<?= e($name) ?>" autocomplete="name" maxlength="80" required<?= isset($fieldErrors['name']) ? ' aria-invalid="true" aria-describedby="reg-name-err"' : '' ?>>
                <?php if ($m = $fe('name')): ?><p class="error-text" id="reg-name-err"><?= e($m) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="reg-email"><?= e(__('member_auth.email')) ?></label>
                <input class="input" id="reg-email" name="email" type="email" dir="ltr" value="<?= e($email) ?>"
                       autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="190" required<?= isset($fieldErrors['email']) ? ' aria-invalid="true" aria-describedby="reg-email-err"' : '' ?>>
                <?php if ($m = $fe('email')): ?><p class="error-text" id="reg-email-err"><?= e($m) ?>
                    <?php if (!empty($emailTaken)): ?> <a href="<?= e(route('login', [], ['redirect' => $redirectTo])) ?>"><?= e(__('member_auth.login_instead')) ?></a><?php endif; ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="reg-password"><?= e(__('member_auth.password')) ?></label>
                <div class="password-field">
                    <input class="input" id="reg-password" name="password" type="password" dir="ltr" autocomplete="new-password" minlength="8" maxlength="256" required<?= isset($fieldErrors['password']) ? ' aria-invalid="true" aria-describedby="reg-password-err"' : '' ?>>
                    <button type="button" class="password-field__toggle" data-password-toggle="reg-password" aria-controls="reg-password" aria-pressed="false"
                            data-label-show="<?= e(__('admin_auth.show')) ?>" data-label-hide="<?= e(__('admin_auth.hide')) ?>" title="<?= e(__('admin_auth.show')) ?>">
                        <span class="password-field__on"><?= icon('eye') ?></span><span class="password-field__off"><?= icon('eye-off') ?></span>
                        <span class="visually-hidden" data-password-toggle-label><?= e(__('admin_auth.show')) ?></span>
                    </button>
                </div>
                <?php if ($m = $fe('password')): ?><p class="error-text" id="reg-password-err"><?= e($m) ?></p><?php else: ?><p class="help"><?= e(__('member_auth.password_help', ['min' => \App\Services\MemberPasswordAuth::MIN_LENGTH])) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="reg-password2"><?= e(__('member_auth.password_confirm')) ?></label>
                <input class="input" id="reg-password2" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" maxlength="256" required<?= isset($fieldErrors['password_confirmation']) ? ' aria-invalid="true" aria-describedby="reg-password2-err"' : '' ?>>
                <?php if ($m = $fe('password_confirmation')): ?><p class="error-text" id="reg-password2-err"><?= e($m) ?></p><?php endif; ?>
            </div>
            <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= icon('user', 'icon--sm') ?><?= e(__('member_auth.register_submit')) ?></button>
        </form>

        <p class="auth__switch"><?= e(__('member_auth.have_account')) ?> <a href="<?= e(route('login', [], ['redirect' => $redirectTo])) ?>"><?= e(__('member_auth.login_link')) ?></a></p>
        <p class="xsmall muted"><?= e(__('member_auth.legal_prefix')) ?> <a href="<?= e(route('pages.terms')) ?>"><?= e(__('pages.terms.title')) ?></a> · <a href="<?= e(route('pages.privacy')) ?>"><?= e(__('pages.privacy.title')) ?></a></p>
    </div>
</div>
