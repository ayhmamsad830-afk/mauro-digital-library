<?php /** @var bool $available @var string|null $state @var string $email */ ?>
<div class="auth">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('lock', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('reset.forgot_title')) ?></h1>

        <?php if (!$available): ?>
            <?= component('alert', ['type' => 'warning', 'text' => __('reset.unavailable')]) ?>
            <p class="muted"><?= e(__('reset.unavailable_help')) ?></p>
            <a class="btn btn--secondary btn--block" href="<?= e(route('contact')) ?>"><?= icon('mail', 'icon--sm') ?><?= e(__('contact.title')) ?></a>
        <?php elseif ($state === 'accepted'): ?>
            <div class="auth__success" role="status">
                <span class="auth__success-mark"><?= icon('check') ?></span>
                <p><?= e(__('reset.sent', ['minutes' => \App\Services\PasswordResetService::TTL_MINUTES])) ?></p>
            </div>
        <?php else: ?>
            <p class="muted"><?= e(__('reset.forgot_intro')) ?></p>
            <?php if ($state === 'throttled'): ?><?= component('alert', ['type' => 'danger', 'text' => __('member_auth.too_many')]) ?><?php endif; ?>
            <form class="auth__form" method="post" action="<?= e(route('password.forgot.send')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="forgot-email"><?= e(__('member_auth.email')) ?></label>
                    <input class="input" id="forgot-email" name="email" type="email" dir="ltr" value="<?= e($email) ?>"
                           autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="190" required>
                </div>
                <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= icon('mail', 'icon--sm') ?><?= e(__('reset.send_link')) ?></button>
            </form>
        <?php endif; ?>

        <p class="auth__switch"><a href="<?= e(route('login')) ?>"><?= e(__('reset.back_to_login')) ?></a></p>
    </div>
</div>
