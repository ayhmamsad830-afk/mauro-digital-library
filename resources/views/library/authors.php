<?php /** @var list<array> $authors */ ?>
<div class="page-head">
    <div class="container">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('authors.title'), null]]]) ?>
        <h1 class="page-head__title"><?= e(__('authors.title')) ?></h1>
    </div>
</div>
<div class="container section">
    <?php if ($authors): ?>
        <ul class="author-grid" role="list">
            <?php foreach ($authors as $a): ?>
            <li>
                <a class="author-card card" href="<?= e(route('books.index', [], ['author' => $a['slug']])) ?>">
                    <?php if (!empty($a['photo_url'])): ?><img class="avatar avatar--img" src="<?= e($a['photo_url']) ?>" alt="" width="48" height="48" loading="lazy"><?php else: ?><span class="avatar" aria-hidden="true"><?= e(mb_substr($a['name'], 0, 1)) ?></span><?php endif; ?>
                    <span><strong><?= e($a['name']) ?></strong><br><span class="muted small"><?= e(__('category.books_count', ['count' => (int) $a['books_count']])) ?></span></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <?= component('empty-state', ['icon' => 'user', 'title' => __('authors.empty_title'), 'text' => __('home.empty_text')]) ?>
    <?php endif; ?>
</div>
