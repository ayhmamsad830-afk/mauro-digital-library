<?php /** @var list<array> $authors @var string $q */ ?>
<div class="admin-toolbar">
    <form class="admin-filter" method="get" action="<?= e(route('admin.authors.index')) ?>" role="search">
        <label class="visually-hidden" for="aa-q"><?= e(__('search.label')) ?></label>
        <input class="input" id="aa-q" type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="<?= e(__('admin.search_authors')) ?>">
        <button class="btn btn--ghost" type="submit"><?= icon('search', 'icon--sm') ?><?= e(__('search.submit')) ?></button>
    </form>
    <a class="btn btn--primary" href="<?= e(route('admin.authors.create')) ?>"><?= icon('pen', 'icon--sm') ?><?= e(__('admin.add_author')) ?></a>
</div>
<?php if ($authors): ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr><th scope="col"><?= e(__('admin.author_name')) ?></th><th scope="col">Slug</th><th scope="col"><?= e(__('admin.books')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($authors as $a): ?>
            <tr>
                <td><a href="<?= e(route('admin.authors.edit', ['id' => $a['id']])) ?>" dir="auto"><?= e($a['name']) ?></a></td>
                <td><code class="xsmall"><?= e($a['slug']) ?></code></td>
                <td><?= e((int) $a['books_count']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'user', 'title' => $q !== '' ? __('catalog.no_results') : __('authors.empty_title'), 'actionUrl' => route('admin.authors.create'), 'actionLabel' => __('admin.add_author')]) ?>
<?php endif; ?>
