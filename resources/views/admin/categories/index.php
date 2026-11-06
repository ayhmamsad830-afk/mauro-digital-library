<?php /** @var list<array> $categories */ ?>
<div class="admin-toolbar">
    <p class="muted small"><?= e(__('admin.categories_help')) ?></p>
    <a class="btn btn--primary" href="<?= e(route('admin.categories.create')) ?>"><?= icon('pen', 'icon--sm') ?><?= e(__('admin.add_category')) ?></a>
</div>
<?php if ($categories): ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th scope="col"><?= e(__('admin.name_ar')) ?></th><th scope="col"><?= e(__('admin.name_en')) ?></th>
            <th scope="col">Slug</th><th scope="col"><?= e(__('admin.parent')) ?></th><th scope="col"><?= e(__('admin.sort_order')) ?></th>
            <th scope="col"><?= e(__('admin.books')) ?></th><th scope="col"><?= e(__('admin.col_status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(__('admin.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td><?= $c['parent_id'] ? '<span class="muted" aria-hidden="true">↳ </span>' : '' ?><a href="<?= e(route('admin.categories.edit', ['id' => $c['id']])) ?>" lang="ar" dir="rtl"><?= e($c['name_ar']) ?></a></td>
                <td lang="en" dir="ltr"><?= e((string) $c['name_en']) ?></td>
                <td><code class="xsmall"><?= e($c['slug']) ?></code></td>
                <td><?= e($c['parent_id'] ? localized(['name_ar' => $c['parent_name_ar'], 'name_en' => $c['parent_name_en']], 'name') : '—') ?></td>
                <td><?= e((int) $c['sort_order']) ?></td>
                <td><?= e((int) $c['books_count']) ?></td>
                <td><?= \App\Core\View::partial('admin/_status', ['status' => $c['status']]) ?></td>
                <td><form method="post" action="<?= e(route('admin.categories.toggle', ['id' => $c['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e($c['status'] === 'active' ? __('admin.deactivate') : __('admin.activate')) ?></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'tag', 'title' => __('categories.empty_title'), 'actionUrl' => route('admin.categories.create'), 'actionLabel' => __('admin.add_category')]) ?>
<?php endif; ?>
