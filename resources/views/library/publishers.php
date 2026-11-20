<?php /** @var list<array{name:string,slug:string,books_count:int}> $publishers */ ?>
<div class="page-head page-head--publishers">
    <div class="container">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('publishers.title'), null]]]) ?>
        <h1 class="page-head__title"><?= e(__('publishers.title')) ?></h1>
        <p class="page-head__lead"><?= e(__('publishers.lead')) ?></p>
    </div>
</div>
<div class="container section">
    <?php if ($publishers): ?>
        <ul class="publisher-grid" role="list">
            <?php foreach ($publishers as $p): ?>
            <li>
                <a class="publisher-card card" href="<?= e(route('publishers.show', ['slug' => $p['slug']])) ?>">
                    <span class="publisher-card__mark" aria-hidden="true"><?= icon('book') ?></span>
                    <span class="publisher-card__text">
                        <strong class="publisher-card__name" dir="auto"><?= e($p['name']) ?></strong>
                        <span class="publisher-card__count"><?= e(__('category.books_count', ['count' => (int) $p['books_count']])) ?></span>
                    </span>
                    <span class="publisher-card__arrow"><?= icon('arrow-next', 'icon--dir icon--sm') ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <?= component('empty-state', ['icon' => 'book', 'title' => __('publishers.empty_title'), 'text' => __('home.empty_text')]) ?>
    <?php endif; ?>
</div>
