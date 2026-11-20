<?php
/**
 * Member area header + section tabs (shared by the library sections and settings).
 * @var array $user @var string $section @var int $favoritesCount @var int $bookmarksCount
 */
$tabs = [
    'overview' => [route('account'), __('account.overview'), 'user'],
    'history' => [route('account.history'), __('account.history'), 'clock'],
    'favorites' => [route('account.favorites'), __('account.favorites') . ' (' . $favoritesCount . ')', 'heart'],
    'bookmarks' => [route('account.bookmarks'), __('account.bookmarks') . ' (' . $bookmarksCount . ')', 'bookmark'],
    'settings' => [route('account.settings'), __('settings.title'), 'settings'],
];
?>
<div class="page-head page-head--account">
    <div class="container account-head">
        <?php if (!empty($user['avatar_url'])): ?>
            <img class="avatar avatar--lg" src="<?= e($user['avatar_url']) ?>" alt="" width="72" height="72" referrerpolicy="no-referrer">
        <?php else: ?>
            <span class="avatar avatar--lg" aria-hidden="true"><?= e(mb_substr((string) $user['name'], 0, 1) ?: '?') ?></span>
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?= e(__('account.welcome')) ?></p>
            <h1 class="page-head__title" dir="auto"><?= e($user['name'] !== '' ? $user['name'] : $user['email']) ?></h1>
            <p class="muted small ltr"><?= e($user['email']) ?></p>
        </div>
    </div>
</div>
<div class="container">
    <nav class="tabs account-tabs" aria-label="<?= e(__('account.sections')) ?>">
        <?php foreach ($tabs as $key => [$url, $label, $ic]): ?>
            <a href="<?= e($url) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= icon($ic, 'icon--sm') ?><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
