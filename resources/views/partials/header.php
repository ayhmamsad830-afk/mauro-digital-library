<?php
$appName = brand_name();
$nav = [
    ['home', route('home'), __('nav.home'), 'book', false],
    ['books', route('books.index'), __('nav.library'), 'layers', true],
    ['categories', route('categories.index'), __('nav.categories'), 'tag', true],
    ['popular', route('books.popular'), __('nav.popular'), 'chart', false],
    ['latest', route('books.latest'), __('nav.latest'), 'clock', false],
];
// Only touch the database when a session user exists (guests never trigger a query).
$user = \App\Core\Session::has('user_id') ? \App\Services\Auth::user() : null;
$accountLink = $user
    ? [($user['role'] === 'admin' ? route('admin.dashboard') : route('account')), $user['role'] === 'admin' ? __('admin.title') : __('nav.my_library')]
    : [route('login'), __('nav.sign_in')];
$isCurrent = static fn (string $url, bool $prefix): bool => is_current_path(parse_url($url, PHP_URL_PATH) ?: '/', $prefix);
?>
<header class="site-header">
    <div class="container site-header__inner">
        <?= component('brand', ['label' => $appName . ' — ' . __('nav.home')]) ?>

        <nav class="site-nav" aria-label="<?= e(__('nav.main')) ?>">
            <?php foreach ($nav as [$key, $url, $label, , $prefix]): ?>
                <a class="site-nav__link" href="<?= e($url) ?>"<?= $isCurrent($url, $prefix) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="header-search"><?= component('search-bar', ['size' => 'sm', 'id' => 'header-search']) ?></div>

        <div class="site-tools">
            <span class="tool--desktop"><?= component('lang-switch') ?></span>
            <span class="tool--desktop"><?= component('theme-toggle') ?></span>
            <a class="btn btn--primary btn--sm tool--desktop" href="<?= e($accountLink[0]) ?>"><?= icon('user', 'icon--sm') ?><?= e($accountLink[1]) ?></a>
            <button type="button" class="btn btn--ghost btn--icon menu-toggle" data-drawer-open="site-drawer"
                    aria-controls="site-drawer" aria-expanded="false" aria-label="<?= e(__('nav.open_menu')) ?>">
                <?= icon('menu') ?>
            </button>
        </div>
    </div>
</header>

<div class="drawer" id="site-drawer" data-drawer data-drawer-mobile-only>
    <div class="drawer__backdrop" data-drawer-close></div>
    <div class="drawer__panel" role="dialog" aria-modal="true" aria-labelledby="site-drawer-title">
        <div class="drawer__head">
            <span id="site-drawer-title" class="drawer__title"><?= e($appName) ?></span>
            <button type="button" class="btn btn--plain btn--icon" data-drawer-close aria-label="<?= e(__('nav.close_menu')) ?>"><?= icon('close') ?></button>
        </div>
        <?= component('search-bar', ['size' => 'md', 'id' => 'drawer-search']) ?>
        <nav class="drawer__nav" aria-label="<?= e(__('nav.main')) ?>">
            <?php foreach ($nav as [$key, $url, $label, $ic, $prefix]): ?>
                <a class="drawer__link" href="<?= e($url) ?>"<?= $isCurrent($url, $prefix) ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <p class="drawer__section-title"><?= e(__('nav.preferences')) ?></p>
        <div class="drawer__prefs"><?= component('lang-switch') ?><?= component('theme-toggle') ?></div>
        <a class="btn btn--primary btn--block" href="<?= e($accountLink[0]) ?>"><?= icon('user', 'icon--sm') ?><?= e($accountLink[1]) ?></a>
    </div>
</div>
