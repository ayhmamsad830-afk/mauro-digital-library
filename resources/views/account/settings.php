<?php
/**
 * Member settings. @var array $user @var array $account @var array<string,string> $profileErrors
 * @var string|null $passwordError @var array|null $old @var int $favoritesCount @var int $bookmarksCount
 */
$name = (string) ($old['name'] ?? $user['name']);
$loc = (string) ($old['locale'] ?? $account['locale']);
$thm = (string) ($old['theme'] ?? $account['theme']);
$nameInvalid = isset($profileErrors['name']);
?>
<?= \App\Core\View::partial('account/_head', get_defined_vars()) ?>

<div class="container section settings">
    <div class="settings__grid">
        <section class="card card--raised settings__card reveal-in" aria-labelledby="settings-profile">
            <h2 class="h4" id="settings-profile"><?= icon('user', 'icon--sm') ?><?= e(__('settings.profile')) ?></h2>
            <?php if ($profileErrors): ?><?= component('alert', ['type' => 'danger', 'text' => __('contact.fix_errors')]) ?><?php endif; ?>
            <form method="post" action="<?= e(route('account.settings.profile')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="settings-name"><?= e(__('member_auth.name')) ?></label>
                    <input class="input" id="settings-name" name="name" type="text" dir="auto" value="<?= e($name) ?>" maxlength="80" autocomplete="name" required<?= $nameInvalid ? ' aria-invalid="true" aria-describedby="settings-name-error"' : '' ?>>
                    <?php if ($nameInvalid): ?><p class="field__error" id="settings-name-error"><?= e(__($profileErrors['name'])) ?></p><?php endif; ?>
                </div>
                <fieldset class="field settings__choice">
                    <legend class="label"><?= e(__('settings.language')) ?></legend>
                    <label class="check"><input type="radio" name="preferred_locale" value="ar"<?= $loc === 'ar' ? ' checked' : '' ?>><span lang="ar">العربية</span></label>
                    <label class="check"><input type="radio" name="preferred_locale" value="en"<?= $loc === 'en' ? ' checked' : '' ?>><span lang="en">English</span></label>
                </fieldset>
                <fieldset class="field settings__choice">
                    <legend class="label"><?= e(__('settings.theme')) ?></legend>
                    <label class="check"><input type="radio" name="preferred_theme" value="light"<?= $thm === 'light' ? ' checked' : '' ?>><span><?= icon('sun', 'icon--sm') ?><?= e(__('settings.theme_light')) ?></span></label>
                    <label class="check"><input type="radio" name="preferred_theme" value="dark"<?= $thm === 'dark' ? ' checked' : '' ?>><span><?= icon('moon', 'icon--sm') ?><?= e(__('settings.theme_dark')) ?></span></label>
                </fieldset>
                <button class="btn btn--primary" type="submit" data-loading-on-submit><?= icon('check', 'icon--sm') ?><?= e(__('settings.save')) ?></button>
            </form>
        </section>

        <section class="card card--raised settings__card reveal-in" aria-labelledby="settings-security">
            <h2 class="h4" id="settings-security"><?= icon('lock', 'icon--sm') ?><?= e(__('settings.security')) ?></h2>
            <?php if ($account['password']): ?>
                <?php if (!empty($passwordError)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $passwordError]) ?><?php endif; ?>
                <form method="post" action="<?= e(route('account.settings.password')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="settings-current"><?= e(__('settings.current_password')) ?></label>
                        <input class="input" id="settings-current" name="current_password" type="password" dir="ltr" autocomplete="current-password" maxlength="1024" required>
                    </div>
                    <div class="field">
                        <label class="label" for="settings-new"><?= e(__('reset.new_password')) ?></label>
                        <input class="input" id="settings-new" name="new_password" type="password" dir="ltr" autocomplete="new-password" maxlength="256" required aria-describedby="settings-new-help">
                        <p class="help" id="settings-new-help"><?= e(__('member_auth.password_help', ['min' => \App\Services\MemberPasswordAuth::MIN_LENGTH])) ?></p>
                    </div>
                    <div class="field">
                        <label class="label" for="settings-confirm"><?= e(__('member_auth.password_confirm')) ?></label>
                        <input class="input" id="settings-confirm" name="new_password_confirmation" type="password" dir="ltr" autocomplete="new-password" maxlength="256" required>
                    </div>
                    <button class="btn btn--primary" type="submit" data-loading-on-submit><?= icon('lock', 'icon--sm') ?><?= e(__('settings.change_password')) ?></button>
                    <p class="help"><?= e(__('settings.password_signout')) ?></p>
                </form>
            <?php else: ?>
                <p class="muted"><?= e(__('settings.google_only')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card settings__card settings__info reveal-in" aria-labelledby="settings-account">
            <h2 class="h4" id="settings-account"><?= icon('info', 'icon--sm') ?><?= e(__('settings.account')) ?></h2>
            <dl class="book-facts settings__facts">
                <div><dt><?= e(__('member_auth.email')) ?></dt><dd class="ltr"><?= e($user['email']) ?></dd></div>
                <div><dt><?= e(__('settings.signin_methods')) ?></dt><dd><?= e(implode(' · ', array_filter([$account['google'] ? 'Google' : null, $account['password'] ? __('settings.method_password') : null]))) ?></dd></div>
                <div><dt><?= e(__('settings.member_since')) ?></dt><dd><?= e(format_date($user['created_at'] ?? null)) ?></dd></div>
                <?php if (!empty($account['last_login_at'])): ?><div><dt><?= e(__('settings.last_login')) ?></dt><dd><?= e(format_date($account['last_login_at'])) ?></dd></div><?php endif; ?>
            </dl>
            <form method="post" action="<?= e(route('logout')) ?>"><?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= icon('logout', 'icon--sm') ?><?= e(__('account.sign_out')) ?></button>
            </form>
        </section>
    </div>
</div>
