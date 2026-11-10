<?php
/**
 * Signed-in account menu: who is signed in, shortcuts, switch account, sign out.
 * Built on <details>, so it opens and closes without JavaScript; app.js adds
 * closing on Escape / outside click and returns focus to the button.
 *
 * @var array  $user     Auth::user() row (name, email, avatar_url, role)
 * @var string $variant  'header' (drop-down) | 'drawer' (inline list, mobile menu)
 */
$variant = $variant ?? 'header';
$isAdmin = ($user['role'] ?? '') === 'admin';
$display = trim((string) ($user['name'] ?? '')) ?: (string) ($user['email'] ?? '');
$avatar = (string) ($user['avatar_url'] ?? '');

$links = [
    [route('account'), __('nav.my_library'), 'user'],
    [route('account.history'), __('account.history'), 'clock'],
    [route('account.favorites'), __('account.favorites'), 'heart'],
    [route('account.bookmarks'), __('account.bookmarks'), 'bookmark'],
];
if ($isAdmin) {
    $links[] = [route('admin.dashboard'), __('admin.title'), 'shield'];
}
?>
<?php if ($variant === 'drawer'): ?>
<div class="account-menu account-menu--inline">
    <?= component('account-who', ['user' => $user]) ?>
    <nav class="account-menu__list" aria-label="<?= e(__('nav.account_menu')) ?>">
        <?php foreach ($links as [$url, $label, $ic]): ?>
            <a class="account-menu__item" href="<?= e($url) ?>"<?= is_current_path(parse_url($url, PHP_URL_PATH) ?: '/', false) ? ' aria-current="page"' : '' ?>><?= icon($ic, 'icon--sm') ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?= component('account-actions') ?>
</div>
<?php else: ?>
<details class="account-menu" data-account-menu>
    <summary class="btn btn--primary btn--sm account-menu__toggle" aria-label="<?= e(__('nav.account_menu') . ': ' . $display) ?>">
        <?php if (str_starts_with($avatar, 'https://')): ?>
            <img class="account-menu__mini" src="<?= e($avatar) ?>" alt="" width="22" height="22" referrerpolicy="no-referrer">
        <?php else: ?>
            <?= icon('user', 'icon--sm') ?>
        <?php endif; ?>
        <span><?= e($isAdmin ? __('admin.title') : __('nav.my_library')) ?></span>
        <?= icon('chevron-down', 'icon--sm account-menu__chevron') ?>
    </summary>
    <div class="account-menu__panel">
        <?= component('account-who', ['user' => $user]) ?>
        <nav class="account-menu__list" aria-label="<?= e(__('nav.account_menu')) ?>">
            <?php foreach ($links as [$url, $label, $ic]): ?>
                <a class="account-menu__item" href="<?= e($url) ?>"<?= is_current_path(parse_url($url, PHP_URL_PATH) ?: '/', false) ? ' aria-current="page"' : '' ?>><?= icon($ic, 'icon--sm') ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?= component('account-actions') ?>
    </div>
</details>
<?php endif; ?>
