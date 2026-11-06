<?php
/** Brand lockup: official logo if provided (unmodified), otherwise the official name. @var bool|null $compact */
$logo = brand_logo();
$name = brand_name();
?>
<a class="brand<?= $logo ? ' brand--logo' : '' ?>" href="<?= e(route('home')) ?>"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
    <?php if ($logo): ?>
        <?php /* Official artwork, displayed unmodified: no filters, crops or recolouring; only the height is constrained. */ ?>
        <img class="brand__logo" src="<?= e($logo['url']) ?>" alt=""<?= $logo['width'] ? ' width="' . e($logo['width']) . '" height="' . e($logo['height']) . '"' : '' ?> decoding="async">
        <?php if (!config('app.logo_has_wordmark', false)): ?><span class="brand__name"><?= e($name) ?></span><?php else: ?><span class="visually-hidden"><?= e($name) ?></span><?php endif; ?>
    <?php else: ?>
        <span class="brand__mark" aria-hidden="true"><?= icon('book') ?></span>
        <span class="brand__name"><?= e($name) ?></span>
    <?php endif; ?>
</a>
