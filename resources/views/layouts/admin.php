<?php
/**
 * Admin layout — sidebar + content (preview variant at /demo/admin in demo mode).
 * @var string $content @var string|null $pageTitle
 */
$appName = brand_name();
$preview = !empty($preview);
// [icon, label, url|null (null = later phase), active?]
$items = $preview ? [
    ['dashboard', __('admin.dashboard'), '#overview', true],
    ['book', __('admin.books'), '#books', false],
    ['upload', __('admin.files'), '#render-jobs', false],
    ['list', __('admin.logs'), '#activity', false],
] : [
    ['dashboard', __('admin.dashboard'), route('admin.dashboard'), is_current_path('/admin')],
    ['book', __('admin.books'), route('admin.books.index'), is_current_path('/admin/books', true)],
    ['tag', __('admin.categories'), route('admin.categories.index'), is_current_path('/admin/categories', true)],
    ['user', __('admin.authors'), route('admin.authors.index'), is_current_path('/admin/authors', true)],
    ['upload', __('admin.files'), route('admin.files.index'), is_current_path('/admin/files', true)],
    ['users', __('admin.users'), route('admin.users.index'), is_current_path('/admin/users', true)],
    ['star', __('admin.reviews'), route('admin.reviews.index'), is_current_path('/admin/reviews', true)],
    ['chart', __('admin.analytics'), route('admin.analytics'), is_current_path('/admin/analytics', true)],
    ['list', __('admin.logs'), route('admin.logs.index'), is_current_path('/admin/logs', true)],
    ['settings', __('admin.settings'), route('admin.settings'), is_current_path('/admin/settings', true)],
];
?>
<!doctype html>
<html lang="<?= e(locale()) ?>" dir="<?= e(text_direction()) ?>"<?= !empty($currentTheme) ? ' data-theme="' . e($currentTheme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? '') . ' — ' . __('admin.title') . ' — ' . $appName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-192.png')) ?>" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/brand/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
</head>
<body class="admin-body">
    <a class="skip-link" href="#main"><?= e(__('app.skip_to_content')) ?></a>
    <?php if (!empty($demoMode)): ?><?= \App\Core\View::partial('partials/demo-banner') ?><?php endif; ?>
    <div class="admin">
        <div class="drawer admin-sidebar-wrap" id="admin-nav" data-drawer>
            <div class="drawer__backdrop" data-drawer-close></div>
            <aside class="drawer__panel admin-sidebar" aria-label="<?= e(__('admin.navigation')) ?>">
                <div class="admin-sidebar__brand">
                    <?= component('brand') ?>
                    <button type="button" class="btn btn--plain btn--icon btn--sm admin-sidebar__close" data-drawer-close aria-label="<?= e(__('nav.close_menu')) ?>"><?= icon('close') ?></button>
                </div>
                <p class="admin-sidebar__label"><?= e(__('admin.title')) ?></p>
                <nav>
                    <ul class="admin-nav" role="list">
                        <?php foreach ($items as [$ic, $label, $href, $active]): ?>
                        <li>
                            <?php if ($href !== null): ?>
                                <a class="admin-nav__link" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?> data-drawer-close><?= icon($ic) ?><span><?= e($label) ?></span></a>
                            <?php else: ?>
                                <span class="admin-nav__link is-disabled" aria-disabled="true"><?= icon($ic) ?><span><?= e($label) ?></span><span class="badge"><?= e(__('admin.phase5')) ?></span></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </aside>
        </div>

        <div class="admin-main">
            <header class="admin-topbar">
                <button type="button" class="btn btn--ghost btn--icon btn--sm admin-menu-btn" data-drawer-open="admin-nav" aria-controls="admin-nav" aria-expanded="false" aria-label="<?= e(__('nav.open_menu')) ?>"><?= icon('menu') ?></button>
                <h1 class="admin-topbar__title"><?= e($pageTitle ?? '') ?></h1>
                <div class="site-tools">
                    <?= component('lang-switch') ?>
                    <?= component('theme-toggle') ?>
                    <a class="btn btn--ghost btn--sm hide-sm" href="<?= e(route('home')) ?>"><?= e(__('admin.view_site')) ?></a>
                    <?php if (!empty($adminUser)): ?><span class="avatar avatar--sm" title="<?= e($adminUser['name']) ?>" aria-label="<?= e($adminUser['name']) ?>"><?= e(mb_substr((string) $adminUser['name'], 0, 1)) ?></span>
                        <a class="btn btn--ghost btn--icon btn--sm" href="<?= e(route('admin.password')) ?>" title="<?= e(__('admin_auth.change_title')) ?>" aria-label="<?= e(__('admin_auth.change_title')) ?>"><?= icon('lock') ?></a>
                        <form class="inline-form" method="post" action="<?= e(route('logout')) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--icon btn--sm" type="submit" title="<?= e(__('admin_auth.sign_out')) ?>" aria-label="<?= e(__('admin_auth.sign_out')) ?>"><?= icon('logout') ?></button></form>
                    <?php endif; ?>
                </div>
            </header>
            <main id="main" class="admin-content" tabindex="-1">
                <?php if ($msg = flash('success')): ?><?= component('alert', ['type' => 'success', 'text' => (string) $msg]) ?><?php endif; ?>
                <?php if ($msg = flash('error')): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $msg]) ?><?php endif; ?>
                <?= $content ?>
            </main>
        </div>
    </div>
    <script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
