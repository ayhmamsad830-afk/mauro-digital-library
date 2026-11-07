<?php /** @var string|null $error @var bool $forced @var bool $hasPassword @var string $username */
$pw = static fn (string $id, string $name, string $label, string $autocomplete, string $help = '') => ['id' => $id, 'name' => $name, 'label' => $label, 'ac' => $autocomplete, 'help' => $help];
$fields = [
    $pw('pw-current', 'current_password', $forced ? __('admin_auth.current_temporary') : __('admin_auth.current_password'), 'current-password'),
    $pw('pw-new', 'new_password', __('admin_auth.new_password'), 'new-password', __('admin_auth.policy_help', ['min' => \App\Services\AdminPasswordAuth::MIN_LENGTH])),
    $pw('pw-confirm', 'new_password_confirmation', __('admin_auth.confirm_password'), 'new-password'),
];
?>
<div class="auth auth--admin">
    <div class="auth__card card card--raised">
        <span class="auth__mark"><?= icon('lock', 'icon--lg') ?></span>
        <h1 class="auth__title"><?= e(__('admin_auth.change_title')) ?></h1>
        <p class="muted"><?= e($forced ? __('admin_auth.must_change_intro') : __('admin_auth.change_intro')) ?></p>

        <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>

        <?php if (!$hasPassword): ?>
            <p class="help"><?= e(__('admin_auth.google_only')) ?></p>
            <p><a class="btn btn--ghost" href="<?= e(route('admin.dashboard')) ?>"><?= e(__('admin.dashboard')) ?></a></p>
        <?php else: ?>
        <form class="auth__form" method="post" action="<?= e(route('admin.password.update')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" hidden>
            <?php foreach ($fields as $f): ?>
            <div class="field">
                <label class="label" for="<?= e($f['id']) ?>"><?= e($f['label']) ?></label>
                <div class="password-field">
                    <input class="input" id="<?= e($f['id']) ?>" name="<?= e($f['name']) ?>" type="password" dir="ltr" autocomplete="<?= e($f['ac']) ?>"
                           maxlength="<?= e(\App\Services\AdminPasswordAuth::MAX_LENGTH) ?>" required<?php if ($f['help'] !== ''): ?> aria-describedby="<?= e($f['id']) ?>-help"<?php endif; ?>>
                    <button type="button" class="password-field__toggle" data-password-toggle="<?= e($f['id']) ?>" aria-controls="<?= e($f['id']) ?>" aria-pressed="false"
                            data-label-show="<?= e(__('admin_auth.show')) ?>" data-label-hide="<?= e(__('admin_auth.hide')) ?>" title="<?= e(__('admin_auth.show')) ?>">
                        <span class="password-field__on"><?= icon('eye') ?></span><span class="password-field__off"><?= icon('eye-off') ?></span>
                        <span class="visually-hidden" data-password-toggle-label><?= e(__('admin_auth.show')) ?></span>
                    </button>
                </div>
                <?php if ($f['help'] !== ''): ?><p class="help" id="<?= e($f['id']) ?>-help"><?= e($f['help']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button class="btn btn--primary btn--lg btn--block" type="submit" data-loading-on-submit><?= e(__('admin_auth.change_submit')) ?></button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= e(route('logout')) ?>"><?= csrf_field() ?><button class="btn btn--plain btn--sm" type="submit"><?= icon('logout', 'icon--sm') ?><?= e(__('admin_auth.sign_out')) ?></button></form>
    </div>
</div>
