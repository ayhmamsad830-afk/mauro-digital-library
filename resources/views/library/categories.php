<?php /** @var list<array> $categories */ ?>
<div class="page-head">
    <div class="container">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('nav.categories'), null]]]) ?>
        <h1 class="page-head__title"><?= e(__('categories.title')) ?></h1>
        <p class="page-head__sub"><?= e(__('categories.subtitle')) ?></p>
    </div>
</div>
<div class="container section">
    <?php if ($categories): ?>
        <ul class="category-grid category-grid--lg" role="list">
            <?php foreach ($categories as $category): ?><li><?= component('category-card', ['category' => $category]) ?></li><?php endforeach; ?>
        </ul>
        <p class="muted small categories__authors-link"><a href="<?= e(route('authors.index')) ?>"><?= e(__('authors.browse')) ?></a></p>
    <?php else: ?>
        <?= component('empty-state', ['icon' => 'tag', 'title' => __('categories.empty_title'), 'text' => __('categories.empty_text')]) ?>
    <?php endif; ?>
</div>
