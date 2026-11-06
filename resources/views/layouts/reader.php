<?php
/**
 * Full-screen reader layout (no site header/footer).
 * @var string $content @var string|null $pageTitle
 */
$appName = brand_name();
?>
<!doctype html>
<html lang="<?= e(locale()) ?>" dir="<?= e(text_direction()) ?>"<?= !empty($currentTheme) ? ' data-theme="' . e($currentTheme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($pageTitle ?? '') . ' — ' . $appName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
    <link rel="icon" href="<?= e(asset('assets/images/brand/favicon-192.png')) ?>" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/brand/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/reader.css')) ?>">
    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
</head>
<body class="reader-body">
    <a class="skip-link" href="#reader-stage"><?= e(__('reader.skip_to_page')) ?></a>
    <?= $content ?>
    <div class="toast-region" aria-live="polite" data-toast-region></div>
    <script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/reader.js')) ?>" defer></script>
</body>
</html>
