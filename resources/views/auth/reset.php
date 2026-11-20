<?php /** @var bool $valid @var string|null $error */ ?>
<div class="auth">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('lock', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('reset.reset_title')) ?></h1>

        <?php if (!$valid): ?>
            <?= component('alert', ['type' => 'danger', 'text' => __('reset.invalid_link')]) ?>
            <a class="btn btn--secondary btn--block" href="<?= e(route('password.forgot')) ?>"><?= e(__('reset.request_new')) ?></a>
        <?php else: ?>
            <p class="muted"><?= e(__('reset.reset_intro')) ?></p>
            <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>
            <form class="auth__form" method="post" action="<?= e(route('password.reset.submit')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="reset-password"><?= e(__('reset.new_password')) ?></label>
                    <input class="input" id="reset-password" name="password" type="password" dir="ltr" autocomplete="new-password"
                           minlength="<?= e((string) \App\Services\MemberPasswordAuth::MIN_LENGTH) ?>" maxlength="256" required aria-describedby="reset-help">
                    <p class="help" id="reset-help"><?= e(__('member_auth.password_help', ['min' => \App\Services\MemberPasswordAuth::MIN_LENGTH])) ?></p>
                </div>
                <div class="field">
                    <label class="label" for="reset-confirm"><?= e(__('member_auth.password_confirm')) ?></label>
                    <input class="input" id="reset-confirm" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" maxlength="256" required>
                </div>
                <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= icon('check', 'icon--sm') ?><?= e(__('reset.save')) ?></button>
            </form>
            <p class="help"><?= e(__('reset.signout_notice')) ?></p>
        <?php endif; ?>

        <p class="auth__switch"><a href="<?= e(route('login')) ?>"><?= e(__('reset.back_to_login')) ?></a></p>
    </div>
</div>
