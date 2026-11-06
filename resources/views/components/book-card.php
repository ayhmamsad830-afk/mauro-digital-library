<?php
/**
 * Reusable book card (home rails, catalogue, search, categories, authors filter, related, favorites).
 * Hierarchy: category label → serif title → author → rating + "Read now" text link.
 * @var array $book
 * @var string|null $variant  kept for callers ('boxed' = catalogue grid); the card itself is identical
 * @var string|null $badge
 */
$url = route('books.show', ['slug' => $book['slug']]);
$category = $book['categories'][0] ?? null;
?>
<article class="book-card">
    <div class="book-card__media">
        <?php if (!empty($badge)): ?><span class="book-card__badge badge badge--solid"><?= e($badge) ?></span><?php endif; ?>
        <?= component('cover', ['book' => $book, 'class' => 'book-card__cover']) ?>
    </div>
    <div class="book-card__body">
        <span class="book-card__category"><?= $category ? e(localized($category, 'name')) : '' ?></span>
        <h3 class="book-card__title" lang="<?= e($book['language']) ?>" dir="auto">
            <a class="book-card__link" href="<?= e($url) ?>"><?= e($book['title']) ?></a>
        </h3>
        <p class="book-card__author"><?= component('author-names', ['authors' => $book['authors'] ?? []]) ?></p>
        <div class="book-card__foot">
            <?= component('rating', ['avg' => $book['rating_avg'] ?? 0, 'count' => $book['rating_count'] ?? 0]) ?>
            <a class="book-card__read" href="<?= e(route('reader.show', ['slug' => $book['slug']])) ?>" aria-label="<?= e(__('book.read_now') . ': ' . $book['title']) ?>"><?= e(__('book.read_now')) ?><?= icon('chevron-next', 'icon--dir icon--sm') ?></a>
        </div>
    </div>
</article>
