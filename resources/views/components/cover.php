<?php
/** @var array $book  Generated cover when no uploaded cover exists. Decorative: the title is repeated in text nearby. */
$tone = (int) ($book['cover_tone'] ?? 1);
?>
<div class="cover cover--tone-<?= e(max(1, min(8, $tone))) ?> <?= e($class ?? '') ?>">
    <?php if (!empty($book['cover_url'])): ?>
        <img src="<?= e($book['cover_url']) ?>" alt="<?= e(__('book.cover_alt', ['title' => $book['title']])) ?>" loading="lazy" decoding="async" width="400" height="600">
    <?php else: ?>
        <span class="cover__art" aria-hidden="true"></span>
        <span class="cover__frame" aria-hidden="true"></span>
        <span class="cover__text" aria-hidden="true" lang="<?= e($book['language'] ?? 'ar') ?>" dir="<?= ($book['language'] ?? 'ar') === 'ar' ? 'rtl' : 'ltr' ?>">
            <span class="cover__title"><?= e($book['title']) ?></span>
            <span class="cover__author"><?= e(book_authors($book)) ?></span>
        </span>
    <?php endif; ?>
</div>
