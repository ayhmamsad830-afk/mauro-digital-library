<?php
/**
 * Reusable book card (home rails, catalogue, search, categories, related books).
 * @var array $book
 * @var string|null $variant  null = shelf card, 'boxed' = catalogue card (Read action stays visible on touch screens)
 * @var string|null $badge
 */
$url = route('books.show', ['slug' => $book['slug']]);
$category = $book['categories'][0] ?? null;
$boxed = ($variant ?? null) === 'boxed';
?>
<article class="book-card<?= $boxed ? ' book-card--boxed' : '' ?>">
    <div class="book-card__media">
        <?php if (!empty($badge)): ?><span class="book-card__badge badge badge--solid"><?= e($badge) ?></span><?php endif; ?>
        <?= component('cover', ['book' => $book, 'class' => 'book-card__cover']) ?>
        <a class="book-card__cta" href="<?= e(route('reader.show', ['slug' => $book['slug']])) ?>" aria-label="<?= e(__('book.read_now') . ': ' . $book['title']) ?>"><?= icon('book', 'icon--sm') ?><span><?= e(__('book.read_now')) ?></span></a>
    </div>
    <div class="book-card__body">
        <h3 class="book-card__title" lang="<?= e($book['language']) ?>" dir="auto">
            <a class="book-card__link" href="<?= e($url) ?>"><?= e($book['title']) ?></a>
        </h3>
        <p class="book-card__author" dir="auto"><?= e(book_authors($book)) ?></p>
        <div class="book-card__foot">
            <?php if ($category): ?><span class="book-card__category"><?= e(localized($category, 'name')) ?></span><?php else: ?><span></span><?php endif; ?>
            <?= component('rating', ['avg' => $book['rating_avg'] ?? 0, 'count' => $book['rating_count'] ?? 0]) ?>
        </div>
    </div>
</article>
