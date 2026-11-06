<?php /** @var list<array{0:string,1:?string}> $items  [label, url|null] */ ?>
<nav class="breadcrumbs" aria-label="<?= e(__('nav.breadcrumbs')) ?>">
    <ol>
        <?php foreach ($items as $i => [$label, $url]): ?>
            <li><?php if ($url !== null && $i < count($items) - 1): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ol>
</nav>
