<?php
/**
 * @var string $heading @var string|null $subtitle @var \App\Support\BookQuery $query
 * @var \App\Support\Paginator $results @var array|null $lockedCategory
 * @var list<array> $categories @var list<array> $authors @var list<string> $languages
 * @var list<int> $perPageOptions @var callable $urlFor @var callable $pageUrl
 * @var string $formAction @var string $clearUrl @var string $defaultSort
 */
$sortLabels = ['newest' => __('sort.newest'), 'popular' => __('sort.popular'), 'rating' => __('sort.rating'), 'title' => __('sort.title')];
$crumbs = [[__('nav.home'), route('home')], [__('nav.library'), route('books.index')]];
if ($lockedCategory) { $crumbs[] = [__('nav.categories'), route('categories.index')]; }
$crumbs[] = [$heading, null];
$activeFilters = [];
if ($query->q !== '') { $activeFilters[] = [__('filter.search_term', ['q' => $query->q]), $urlFor(['q' => null])]; }
if (!$lockedCategory && $query->category) {
    foreach ($categories as $c) { if ($c['slug'] === $query->category) { $activeFilters[] = [localized($c, 'name'), $urlFor(['category' => null])]; } }
}
if ($query->author) {
    foreach ($authors as $a) { if ($a['slug'] === $query->author) { $activeFilters[] = [$a['name'], $urlFor(['author' => null])]; } }
}
if ($query->language) { $activeFilters[] = [language_name($query->language), $urlFor(['language' => null])]; }
?>
<div class="page-head page-head--catalog">
    <div class="container">
        <?= component('breadcrumbs', ['items' => $crumbs]) ?>
        <h1 class="page-head__title"><?= e($heading) ?></h1>
        <?php if ($subtitle): ?><p class="page-head__sub"><?= e($subtitle) ?></p><?php endif; ?>
    </div>
</div>

<div class="container catalog">
    <aside class="catalog__filters" aria-labelledby="filters-title">
        <div class="drawer drawer--filters" id="filters-drawer" data-drawer>
            <div class="drawer__backdrop" data-drawer-close></div>
            <form class="drawer__panel filters" method="get" action="<?= e($formAction) ?>" role="search" aria-labelledby="filters-title">
                <div class="drawer__head filters__head">
                    <h2 id="filters-title" class="filters__title"><?= icon('filter', 'icon--sm') ?><?= e(__('filter.title')) ?></h2>
                    <?php if ($query->hasFilters()): ?><a class="filters__clear" href="<?= e($clearUrl) ?>"><?= e(__('filter.clear')) ?> <?= icon('close', 'icon--sm') ?></a><?php endif; ?>
                    <button type="button" class="btn btn--plain btn--icon filters__close" data-drawer-close aria-label="<?= e(__('filter.close')) ?>"><?= icon('close') ?></button>
                </div>

                <details class="filter-group" open>
                    <summary class="filter-group__head"><?= e(__('search.label')) ?><?= icon('chevron-next', 'icon--sm filter-group__chev') ?></summary>
                    <div class="filter-group__body filter-group__body--pad">
                        <label class="visually-hidden" for="f-q"><?= e(__('search.label')) ?></label>
                        <input class="input" id="f-q" type="search" name="q" value="<?= e($query->q) ?>" maxlength="100" placeholder="<?= e(__('search.placeholder')) ?>">
                    </div>
                </details>

                <?php if (!$lockedCategory && $categories): ?>
                <details class="filter-group" open>
                    <summary class="filter-group__head"><?= e(__('filter.category')) ?><?= icon('chevron-next', 'icon--sm filter-group__chev') ?></summary>
                    <fieldset class="filter-group__body filter-group__body--list">
                        <legend class="visually-hidden"><?= e(__('filter.category')) ?></legend>
                        <label class="filter-option"><input type="radio" name="category" value=""<?= !$query->category ? ' checked' : '' ?>><span><?= e(__('filter.all')) ?></span></label>
                        <?php foreach ($categories as $c): ?>
                            <label class="filter-option"><input type="radio" name="category" value="<?= e($c['slug']) ?>"<?= $query->category === $c['slug'] ? ' checked' : '' ?>><span><?= e(localized($c, 'name')) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                </details>
                <?php endif; ?>

                <?php if ($authors): ?>
                <details class="filter-group" open>
                    <summary class="filter-group__head"><?= e(__('filter.author')) ?><?= icon('chevron-next', 'icon--sm filter-group__chev') ?></summary>
                    <fieldset class="filter-group__body filter-group__body--list filter-group__body--scroll">
                        <legend class="visually-hidden"><?= e(__('filter.author')) ?></legend>
                        <label class="filter-option"><input type="radio" name="author" value=""<?= !$query->author ? ' checked' : '' ?>><span><?= e(__('filter.all')) ?></span></label>
                        <?php foreach ($authors as $a): ?>
                            <label class="filter-option"><input type="radio" name="author" value="<?= e($a['slug']) ?>"<?= $query->author === $a['slug'] ? ' checked' : '' ?>><span dir="auto"><?= e($a['name']) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                </details>
                <?php endif; ?>

                <?php if ($languages): ?>
                <details class="filter-group" open>
                    <summary class="filter-group__head"><?= e(__('filter.language')) ?><?= icon('chevron-next', 'icon--sm filter-group__chev') ?></summary>
                    <fieldset class="filter-group__body filter-group__body--list">
                        <legend class="visually-hidden"><?= e(__('filter.language')) ?></legend>
                        <label class="filter-option"><input type="radio" name="language" value=""<?= $query->language === null ? ' checked' : '' ?>><span><?= e(__('filter.all')) ?></span></label>
                        <?php foreach ($languages as $code): ?>
                            <label class="filter-option"><input type="radio" name="language" value="<?= e($code) ?>"<?= $query->language === $code ? ' checked' : '' ?>><span><?= e(language_name($code)) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                </details>
                <?php endif; ?>

                <input type="hidden" name="sort" value="<?= e($query->sort) ?>">
                <input type="hidden" name="per_page" value="<?= e($query->perPage) ?>">
                <div class="form-actions">
                    <button class="btn btn--primary btn--block" type="submit"><?= e(__('filter.apply')) ?></button>
                    <?php if ($query->hasFilters()): ?><a class="btn btn--ghost btn--block filters__clear-btn" href="<?= e($clearUrl) ?>"><?= e(__('filter.clear')) ?></a><?php endif; ?>
                </div>
            </form>
        </div>
    </aside>

    <section class="catalog__results" aria-labelledby="results-title">
        <div class="toolbar">
            <p id="results-title" class="toolbar__count" role="status">
                <?php if ($results->total > 0): ?>
                    <?= e(__('catalog.showing', ['from' => $results->from(), 'to' => $results->to(), 'total' => $results->total])) ?>
                <?php else: ?>
                    <?= e(__('catalog.no_results_short')) ?>
                <?php endif; ?>
            </p>
            <form class="toolbar__controls" method="get" action="<?= e($formAction) ?>">
                <?php foreach ($query->toQuery(['sort' => null, 'per_page' => null, 'page' => null], 0, '') as $k => $v): ?>
                    <?php if (!($lockedCategory && $k === 'category')): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <button type="button" class="btn btn--ghost btn--sm toolbar__filter-btn" data-drawer-open="filters-drawer" aria-controls="filters-drawer" aria-expanded="false">
                    <?= icon('filter', 'icon--sm') ?><?= e(__('filter.title')) ?><?php if ($activeFilters): ?> <span class="badge badge--primary"><?= e(count($activeFilters)) ?></span><?php endif; ?>
                </button>
                <label class="visually-hidden" for="per_page"><?= e(__('catalog.per_page')) ?></label>
                <select class="select select--box" id="per_page" name="per_page" data-autosubmit>
                    <?php foreach ($perPageOptions as $n): ?>
                        <option value="<?= e($n) ?>"<?= $query->perPage === $n ? ' selected' : '' ?>><?= e(__('catalog.per_page_option', ['n' => $n])) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="select-box">
                    <label class="select-box__label" for="sort"><?= e(__('sort.label')) ?>:</label>
                    <select class="select select--box" id="sort" name="sort" data-autosubmit>
                        <?php foreach ($sortLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $query->sort === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <noscript><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('filter.apply')) ?></button></noscript>
            </form>
        </div>

        <?php if ($activeFilters): ?>
        <div class="active-filters cluster">
            <?php foreach ($activeFilters as [$label, $url]): ?>
                <a class="chip" href="<?= e($url) ?>" aria-label="<?= e(__('filter.remove', ['filter' => $label])) ?>"><?= e($label) ?> <?= icon('close', 'icon--sm') ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($results->items): ?>
            <?= component('book-grid', ['books' => $results->items, 'variant' => 'boxed']) ?>
            <?= component('pagination', ['paginator' => $results, 'pageUrl' => $pageUrl]) ?>
        <?php elseif ($query->hasFilters()): ?>
            <?= component('empty-state', ['icon' => 'search', 'title' => __('catalog.no_results'), 'text' => __('catalog.no_results_text'), 'actionUrl' => $clearUrl, 'actionLabel' => __('filter.clear')]) ?>
        <?php else: ?>
            <?= component('empty-state', ['icon' => 'layers', 'title' => __('home.empty_title'), 'text' => __('home.empty_text')]) ?>
        <?php endif; ?>
    </section>
</div>
