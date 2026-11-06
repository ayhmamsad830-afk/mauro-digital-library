<?php
/**
 * @var array|null $featured
 * @var list<array> $latest
 * @var list<array> $popular
 * @var list<array> $categories
 * @var list<array> $continueReading
 */
$hasBooks = $latest !== [] || $popular !== [];
// Real catalogue covers fanned behind the featured book (decorative, no extra query).
$shelf = [];
if ($featured) {
    foreach (array_merge($popular, $latest) as $b) {
        if ($b['slug'] !== $featured['slug'] && !isset($shelf[$b['slug']])) { $shelf[$b['slug']] = $b; }
        if (count($shelf) === 2) { break; }
    }
    $shelf = array_values($shelf);
}
?>
<section class="hero">
    <div class="container hero__grid">
        <div class="hero__copy">
            <p class="eyebrow"><?= e(__('home.hero_kicker')) ?> · <?= e(brand_name()) ?></p>
            <h1 class="hero__title display"><?= e(__('home.hero_title')) ?></h1>
            <p class="hero__text"><?= e(__('home.hero_text')) ?></p>
            <div class="hero__actions">
                <a class="btn btn--primary btn--lg" href="<?= e(route('books.index')) ?>"><?= e(__('home.explore')) ?></a>
                <a class="btn btn--ghost btn--lg" href="<?= e(route('categories.index')) ?>"><?= e(__('home.browse_categories')) ?></a>
            </div>
            <?= component('search-bar', ['size' => 'lg', 'id' => 'hero-search']) ?>
            <ul class="hero__points" role="list">
                <li><?= icon('check', 'icon--sm') ?><?= e(__('home.point_free')) ?></li>
                <li><?= icon('check', 'icon--sm') ?><?= e(__('home.point_no_install')) ?></li>
                <li><?= icon('check', 'icon--sm') ?><?= e(__('home.point_sync')) ?></li>
            </ul>
        </div>

        <?php if ($featured): ?>
        <?php $featuredUrl = route('books.show', ['slug' => $featured['slug']]); ?>
        <aside class="showcase" aria-labelledby="featured-title">
            <div class="showcase__stage" aria-hidden="true">
                <span class="showcase__light"></span>
                <div class="showcase__covers showcase__covers--<?= e(count($shelf)) ?>">
                    <?php foreach ($shelf as $i => $b): ?>
                        <div class="showcase__back showcase__back--<?= e($i + 1) ?>"><?= component('cover', ['book' => $b]) ?></div>
                    <?php endforeach; ?>
                    <a class="showcase__front" href="<?= e($featuredUrl) ?>" tabindex="-1"><?= component('cover', ['book' => $featured]) ?></a>
                </div>
                <span class="showcase__table"></span>
            </div>
            <div class="showcase__info">
                <p class="eyebrow"><?= icon('star', 'icon--sm') ?><?= e(__('home.featured')) ?></p>
                <h2 id="featured-title" class="showcase__title display" lang="<?= e($featured['language']) ?>" dir="auto">
                    <a href="<?= e($featuredUrl) ?>"><?= e($featured['title']) ?></a>
                </h2>
                <p class="showcase__author" dir="auto"><?= e(book_authors($featured)) ?></p>
                <?php if (($featured['rating_count'] ?? 0) > 0): ?><div class="showcase__rating"><?= component('rating', ['avg' => $featured['rating_avg'], 'count' => $featured['rating_count']]) ?></div><?php endif; ?>
                <?php if (!empty($featured['description'])): ?><p class="showcase__desc" lang="<?= e($featured['language']) ?>" dir="auto"><?= e($featured['description']) ?></p><?php endif; ?>
                <div class="btn-group">
                    <a class="btn btn--primary" href="<?= e(route('reader.show', ['slug' => $featured['slug']])) ?>"><?= icon('book', 'icon--sm') ?><?= e(__('book.read_now')) ?></a>
                    <a class="btn btn--ghost" href="<?= e($featuredUrl) ?>"><?= e(__('book.details')) ?></a>
                </div>
            </div>
        </aside>
        <?php else: ?>
        <aside class="hero__art" aria-hidden="true">
            <div class="hero__stack"><span></span><span></span><span></span></div>
        </aside>
        <?php endif; ?>
    </div>
</section>

<?php if ($continueReading !== []): ?>
<section class="section" aria-labelledby="continue-title">
    <div class="container">
        <?= component('section-head', ['title' => __('home.continue_reading'), 'id' => 'continue-title', 'link' => route('account'), 'linkLabel' => __('nav.my_library')]) ?>
    </div>
</section>
<?php endif; ?>

<?php if (!$hasBooks): ?>
<section class="section">
    <div class="container">
        <?= component('empty-state', ['icon' => 'layers', 'title' => __('home.empty_title'), 'text' => __('home.empty_text')]) ?>
    </div>
</section>
<?php else: ?>

<?php if ($popular !== []): ?>
<section class="section section--first" aria-labelledby="popular-title">
    <div class="container"><div class="shelf">
        <?= component('section-head', ['title' => __('home.popular'), 'sub' => __('home.popular_sub'), 'id' => 'popular-title', 'link' => route('books.popular')]) ?>
        <?= component('book-grid', ['books' => $popular, 'rail' => true]) ?>
    </div></div>
</section>
<?php endif; ?>

<?php if ($categories !== []): ?>
<section class="section section--paper" aria-labelledby="categories-title">
    <div class="container"><div class="shelf">
        <?= component('section-head', ['title' => __('home.categories'), 'id' => 'categories-title', 'link' => route('categories.index')]) ?>
        <ul class="category-grid" role="list">
            <?php foreach ($categories as $category): ?><li><?= component('category-card', ['category' => $category]) ?></li><?php endforeach; ?>
        </ul>
    </div></div>
</section>
<?php endif; ?>

<?php if ($latest !== []): ?>
<section class="section" aria-labelledby="latest-title">
    <div class="container"><div class="shelf">
        <?= component('section-head', ['title' => __('home.latest'), 'sub' => __('home.latest_sub'), 'id' => 'latest-title', 'link' => route('books.latest')]) ?>
        <?= component('book-grid', ['books' => $latest]) ?>
    </div></div>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="section section--promise" aria-labelledby="how-title">
    <div class="container">
        <div class="promise">
            <h2 id="how-title" class="promise__title display"><?= e(__('home.principles_title')) ?></h2>
            <ol class="promise__list" role="list">
                <?php $n = 0; foreach (['open' => 'book', 'protected' => 'shield', 'bilingual' => 'globe'] as $key => $ic): $n++; ?>
                <li class="promise__item">
                    <span class="promise__icon"><?= icon($ic) ?></span>
                    <span class="promise__num" aria-hidden="true"><?= e(str_pad((string) $n, 2, '0', STR_PAD_LEFT)) ?></span>
                    <h3 class="promise__item-title"><?= e(__("home.principle_{$key}_title")) ?></h3>
                    <p><?= e(__("home.principle_{$key}_text")) ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>
