<?php
/**
 * Public site layout.
 * @var string $content
 * @var string|null $pageTitle
 * @var string|null $metaDescription
 * @var list<string>|null $styles   extra stylesheet paths under /assets/css
 * @var list<string>|null $scripts  extra script paths under /assets/js
 * @var string|null $bodyClass
 * @var bool|null $noindex
 */
$appName = brand_name();
$title = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' — ' . $appName : $appName;
?>
<!doctype html>
<html lang="<?= e(locale()) ?>" dir="<?= e(text_direction()) ?>"<?= !empty($currentTheme) ? ' data-theme="' . e($currentTheme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if (!config('app.indexable', false) || !empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#121715" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121715" media="(prefers-color-scheme: dark)">
    <link rel="preload" href="<?= e(locale() === 'ar' ? '/assets/fonts/plex-arabic-400.woff2' : '/assets/fonts/inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-192.png')) ?>" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/brand/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/pages.css')) ?>">
    <?php foreach ($styles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/' . $style)) ?>">
    <?php endforeach; ?>
    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
    <a class="skip-link" href="#main"><?= e(__('app.skip_to_content')) ?></a>
    <?php if (!empty($demoMode)): ?><?= \App\Core\View::partial('partials/demo-banner') ?><?php endif; ?>
    <?= \App\Core\View::partial('partials/header') ?>
    <main id="main" class="site-main" tabindex="-1">
        <?php if ($notice = flash('notice')): ?><div class="container flash-region"><?= component('alert', ['type' => 'success', 'text' => (string) $notice]) ?></div><?php endif; ?>
        <?= $content ?>
    </main>
    <?= \App\Core\View::partial('partials/footer') ?>
    <div class="toast-region" aria-live="polite" data-toast-region></div>
    <script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e(asset('assets/js/' . $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
