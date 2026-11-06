<?php
/**
 * Book collection: a grid, or a carousel ($rail) with previous/next controls.
 * @var list<array> $books @var bool $rail @var string|null $variant  'boxed' = catalogue grid
 */
$isRail = !empty($rail);
?>
<?php if ($isRail): ?>
<div class="rail" data-rail>
    <button type="button" class="rail__btn rail__btn--prev" data-rail-prev aria-label="<?= e(__('carousel.prev')) ?>" hidden><?= icon('chevron-prev', 'icon--dir') ?></button>
<?php endif; ?>
<ul class="book-grid<?= e($isRail ? ' book-grid--rail' : (!empty($variant) ? ' book-grid--' . $variant : '')) ?>" role="list"<?= $isRail ? ' data-rail-track' : '' ?>>
    <?php foreach ($books as $book): ?>
        <li><?= component('book-card', ['book' => $book, 'badge' => $badge ?? null, 'variant' => $variant ?? null]) ?></li>
    <?php endforeach; ?>
</ul>
<?php if ($isRail): ?>
    <button type="button" class="rail__btn rail__btn--next" data-rail-next aria-label="<?= e(__('carousel.next')) ?>" hidden><?= icon('chevron-next', 'icon--dir') ?></button>
</div>
<?php endif; ?>
