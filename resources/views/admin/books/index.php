<?php /** @var \App\Support\Paginator $books @var string $q @var string|null $status @var array $counts @var callable $pageUrl */ ?>
<div class="admin-toolbar">
    <form class="admin-filter" method="get" action="<?= e(route('admin.books.index')) ?>" role="search">
        <label class="visually-hidden" for="ab-q"><?= e(__('search.label')) ?></label>
        <input class="input" id="ab-q" type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="<?= e(__('admin.search_books')) ?>">
        <label class="visually-hidden" for="ab-status"><?= e(__('admin.col_status')) ?></label>
        <select class="select" id="ab-status" name="status" data-autosubmit>
            <option value=""><?= e(__('filter.all')) ?> (<?= e(array_sum($counts)) ?>)</option>
            <?php foreach ($counts as $st => $n): ?>
                <option value="<?= e($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(__('admin.status_' . $st)) ?> (<?= e($n) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit"><?= icon('search', 'icon--sm') ?><?= e(__('search.submit')) ?></button>
    </form>
    <a class="btn btn--primary" href="<?= e(route('admin.books.create')) ?>"><?= icon('pen', 'icon--sm') ?><?= e(__('admin.add_book')) ?></a>
</div>

<?php if ($books->items): ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th scope="col"><?= e(__('admin.col_title')) ?></th>
            <th scope="col"><?= e(__('admin.col_status')) ?></th>
            <th scope="col"><?= e(__('admin.col_language')) ?></th>
            <th scope="col"><?= e(__('admin.visibility')) ?></th>
            <th scope="col"><?= e(__('admin.col_file')) ?></th>
            <th scope="col"><?= e(__('admin.col_updated')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($books->items as $b): ?>
            <tr>
                <td class="wrap"><a href="<?= e(route('admin.books.edit', ['id' => $b['id']])) ?>" dir="auto"><?= e($b['title']) ?></a><br><code class="xsmall muted"><?= e($b['slug']) ?></code></td>
                <td><?= \App\Core\View::partial('admin/_status', ['status' => $b['status']]) ?></td>
                <td><?= e(language_name($b['language'])) ?></td>
                <td><?= e(__('admin.visibility_' . $b['visibility'])) ?></td>
                <td><?= $b['current_file_id'] ? '<span class="badge badge--success">' . e(__('admin.file_attached')) . '</span>' : '<span class="badge">' . e(__('admin.file_none')) . '</span>' ?></td>
                <td class="ltr"><?= e(substr((string) $b['updated_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="muted small"><?= e(__('catalog.showing', ['from' => $books->from(), 'to' => $books->to(), 'total' => $books->total])) ?></p>
<?= component('pagination', ['paginator' => $books, 'pageUrl' => $pageUrl]) ?>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'book', 'title' => $q !== '' || $status ? __('catalog.no_results') : __('admin.no_books'), 'text' => $q !== '' || $status ? null : __('admin.no_books_text'), 'actionUrl' => route('admin.books.create'), 'actionLabel' => __('admin.add_book')]) ?>
<?php endif; ?>
