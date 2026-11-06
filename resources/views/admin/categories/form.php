<?php
/** @var array|null $category @var list<array> $parents */
$v = static fn (string $k, $default = '') => old($k, $category[$k] ?? $default);
$inv = static fn (string $k) => field_error($k) ? ' aria-invalid="true" aria-describedby="f-' . $k . '-error"' : '';
$field = static fn (array $d) => \App\Core\View::partial('admin/_field', $d);
$action = $category ? route('admin.categories.update', ['id' => $category['id']]) : route('admin.categories.store');
$parentOpts = '<option value="">' . e(__('admin.no_parent')) . '</option>';
foreach ($parents as $p) {
    $parentOpts .= '<option value="' . e($p['id']) . '"' . ((string) $v('parent_id') === (string) $p['id'] ? ' selected' : '') . '>' . e(localized($p, 'name')) . '</option>';
}
?>
<form class="card card--pad admin-narrow-form" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($category): ?><?= method_field('PUT') ?><?php endif; ?>
    <div class="form-grid">
        <?= $field(['name' => 'name_ar', 'label' => __('admin.name_ar'), 'required' => true, 'control' => '<input class="input" id="f-name_ar" name="name_ar" maxlength="191" lang="ar" dir="rtl" required value="' . e($v('name_ar')) . '"' . $inv('name_ar') . '>']) ?>
        <?= $field(['name' => 'name_en', 'label' => __('admin.name_en'), 'control' => '<input class="input" id="f-name_en" name="name_en" maxlength="191" lang="en" dir="ltr" value="' . e($v('name_en')) . '"' . $inv('name_en') . '>']) ?>
        <?= $field(['name' => 'slug', 'label' => 'Slug', 'help' => __('admin.slug_help_auto'), 'control' => '<input class="input ltr" id="f-slug" name="slug" maxlength="191" value="' . e($v('slug')) . '"' . $inv('slug') . '>']) ?>
        <?= $field(['name' => 'parent_id', 'label' => __('admin.parent'), 'control' => '<select class="select" id="f-parent_id" name="parent_id"' . $inv('parent_id') . '>' . $parentOpts . '</select>']) ?>
        <?= $field(['name' => 'sort_order', 'label' => __('admin.sort_order'), 'control' => '<input class="input ltr" id="f-sort_order" name="sort_order" inputmode="numeric" value="' . e((string) $v('sort_order', 0)) . '"' . $inv('sort_order') . '>']) ?>
        <?= $field(['name' => 'status', 'label' => __('admin.col_status'), 'control' => '<select class="select" id="f-status" name="status"><option value="active"' . ($v('status', 'active') === 'active' ? ' selected' : '') . '>' . e(__('admin.status_active')) . '</option><option value="inactive"' . ($v('status') === 'inactive' ? ' selected' : '') . '>' . e(__('admin.status_inactive')) . '</option></select>']) ?>
    </div>
    <?= $field(['name' => 'description_ar', 'label' => __('admin.description_ar'), 'control' => '<textarea class="textarea" id="f-description_ar" name="description_ar" rows="3" lang="ar" dir="rtl"' . $inv('description_ar') . '>' . e($v('description_ar')) . '</textarea>']) ?>
    <?= $field(['name' => 'description_en', 'label' => __('admin.description_en'), 'control' => '<textarea class="textarea" id="f-description_en" name="description_en" rows="3" lang="en" dir="ltr"' . $inv('description_en') . '>' . e($v('description_en')) . '</textarea>']) ?>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= e(__('styleguide.save')) ?></button>
        <a class="btn btn--ghost" href="<?= e(route('admin.categories.index')) ?>"><?= e(__('styleguide.cancel')) ?></a>
    </div>
</form>
