<?php
/** @var array $book @var string|null $variant  null = editorial card, 'boxed' = catalogue card with a Read button */
$url = route('books.show', ['slug' => $book['slug']]);
$category = $book['categories'][0] ?? null;
$boxed = ($variant ?? null) === 'boxed';
?>
<article class="book-card<?= $boxed ? ' book-card--boxed' : '' ?>">
    <?php if (!empty($badge)): ?><span class="book-card__badge badge badge--solid"><?= e($badge) ?></span><?php endif; ?>
    <?= component('cover', ['book' => $book, 'class' => 'book-card__cover']) ?>
    <div class="book-card__body">
        <h3 class="book-card__title" lang="<?= e($book['language']) ?>" dir="auto">
            <a class="book-card__link" href="<?= e($url) ?>"><?= e($book['title']) ?></a>
        </h3>
        <div class="book-card__meta">
            <span class="book-card__author" dir="auto"><?php if ($boxed): ?><span class="book-card__by"><?= e(__('book.by')) ?></span> <?php endif; ?><?= e(book_authors($book)) ?></span>
            <span class="book-card__foot">
                <span class="book-card__category"><?= $category ? e(localized($category, 'name')) : '' ?></span>
                <?= component('rating', ['avg' => $book['rating_avg'] ?? 0, 'count' => $book['rating_count'] ?? 0]) ?>
            </span>
        </div>
        <?php if ($boxed): ?>
            <a class="btn btn--primary btn--sm book-card__cta" href="<?= e(route('reader.show', ['slug' => $book['slug']])) ?>" aria-label="<?= e(__('book.read_now') . ': ' . $book['title']) ?>"><?= icon('book', 'icon--sm') ?><?= e(__('book.read_now')) ?></a>
        <?php endif; ?>
    </div>
</article>
