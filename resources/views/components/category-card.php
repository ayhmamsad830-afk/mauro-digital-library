<?php /** @var array $category */ ?>
<a class="category-card" href="<?= e(route('categories.show', ['slug' => $category['slug']])) ?>">
    <span class="category-card__icon cover--tone-<?= e((int) ($category['tone'] ?? 1)) ?>"><?= icon('book') ?></span>
    <span>
        <span class="category-card__name"><?= e(localized($category, 'name')) ?></span>
        <?php if (isset($category['books_count'])): ?>
            <span class="category-card__count"><?= e(__('category.books_count', ['count' => (int) $category['books_count']])) ?></span>
        <?php endif; ?>
    </span>
    <span class="category-card__arrow"><?= icon('chevron-next', 'icon--dir icon--sm') ?></span>
</a>
