<?php
/**
 * "Signed in as" block of the account menu.
 * @var array $user Auth::user() row
 */
$isAdmin = ($user['role'] ?? '') === 'admin';
$name = trim((string) ($user['name'] ?? ''));
$email = (string) ($user['email'] ?? '');
$display = $name !== '' ? $name : $email;
$avatar = (string) ($user['avatar_url'] ?? '');
?>
<div class="account-menu__who">
    <?php if (str_starts_with($avatar, 'https://')): ?>
        <img class="account-menu__avatar" src="<?= e($avatar) ?>" alt="" width="40" height="40" referrerpolicy="no-referrer" loading="lazy">
    <?php else: ?>
        <span class="account-menu__avatar account-menu__avatar--initial" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($display, 0, 1))) ?></span>
    <?php endif; ?>
    <span class="account-menu__id">
        <span class="account-menu__label"><?= e(__('nav.signed_in_as')) ?></span>
        <span class="account-menu__name" dir="auto"><?= e($display) ?></span>
        <?php if ($name !== '' && $email !== ''): ?><span class="account-menu__email ltr"><?= e($email) ?></span><?php endif; ?>
        <?php if ($isAdmin): ?><span class="badge badge--primary account-menu__role"><?= e(__('admin.title')) ?></span><?php endif; ?>
    </span>
</div>
