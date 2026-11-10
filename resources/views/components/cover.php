<?php
/**
 * @var array $book  Uploaded cover, or a generated cover when none exists.
 * Decorative: the title is repeated in text nearby.
 * An uploaded cover is always shown whole (never cropped); when its shape is not
 * exactly 2:3, the free space is filled with a soft, blurred copy of the same
 * image (same URL, so the browser downloads it once) instead of empty bars.
 */
$tone = (int) ($book['cover_tone'] ?? 1);
?>
<div class="cover cover--tone-<?= e(max(1, min(8, $tone))) ?> <?= e($class ?? '') ?>">
    <?php if (!empty($book['cover_url'])): ?>
        <img class="cover__fill" src="<?= e($book['cover_url']) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" width="400" height="600">
        <img class="cover__img" src="<?= e($book['cover_url']) ?>" alt="<?= e(__('book.cover_alt', ['title' => $book['title']])) ?>" loading="lazy" decoding="async" width="400" height="600">
    <?php else: ?>
        <span class="cover__text" aria-hidden="true" lang="<?= e($book['language'] ?? 'ar') ?>" dir="<?= ($book['language'] ?? 'ar') === 'ar' ? 'rtl' : 'ltr' ?>">
            <span class="cover__title"><?= e($book['title']) ?></span>
            <span class="cover__author"><?= e(book_authors($book)) ?></span>
        </span>
    <?php endif; ?>
</div>
