<?php
/** @var array|null $author */
$v = static fn (string $k, $default = '') => old($k, $author[$k] ?? $default);
$inv = static fn (string $k) => field_error($k) ? ' aria-invalid="true" aria-describedby="f-' . $k . '-error"' : '';
$field = static fn (array $d) => \App\Core\View::partial('admin/_field', $d);
$action = $author ? route('admin.authors.update', ['id' => $author['id']]) : route('admin.authors.store');
?>
<form class="card card--pad admin-narrow-form" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($author): ?><?= method_field('PUT') ?><?php endif; ?>
    <div class="form-grid">
        <?= $field(['name' => 'name', 'label' => __('admin.author_name'), 'required' => true, 'control' => '<input class="input" id="f-name" name="name" maxlength="255" dir="auto" required value="' . e($v('name')) . '"' . $inv('name') . '>']) ?>
        <?= $field(['name' => 'slug', 'label' => 'Slug', 'help' => __('admin.slug_help_auto'), 'control' => '<input class="input ltr" id="f-slug" name="slug" maxlength="191" value="' . e($v('slug')) . '"' . $inv('slug') . '>']) ?>
    </div>
    <?= $field(['name' => 'biography', 'label' => __('admin.biography'), 'control' => '<textarea class="textarea" id="f-biography" name="biography" rows="6" maxlength="10000" dir="auto"' . $inv('biography') . '>' . e($v('biography')) . '</textarea>']) ?>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= e(__('styleguide.save')) ?></button>
        <a class="btn btn--ghost" href="<?= e(route('admin.authors.index')) ?>"><?= e(__('styleguide.cancel')) ?></a>
    </div>
</form>

<?php if ($author): ?>
<section class="card card--pad admin-narrow-form" id="photo" aria-labelledby="photo-title">
    <h2 id="photo-title" class="h4"><?= e(__('media.author_photo')) ?></h2>
    <?php if (!empty($author['photo_path'])): ?>
        <img class="admin-photo-preview" src="<?= e(route('admin.media.author', ['id' => $author['id']]) . '?v=' . \App\Services\Media\ImageUploadService::version((string) $author['photo_path'])) ?>" alt="<?= e($author['name']) ?>" width="120" height="120">
    <?php endif; ?>
    <form class="upload-form" data-uploading="<?= e(__('upload.in_progress')) ?>" method="post" action="<?= e(route('admin.authors.photo', ['id' => $author['id']])) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label class="label" for="f-photo"><?= e(__('media.upload_photo')) ?></label>
        <input class="input" id="f-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
        <p class="help"><?= e(__('media.cover_help', ['mb' => (int) max(1, round((int) config('storage.cover_max_bytes', 2097152) / 1048576))])) ?></p>
        <button class="btn btn--secondary btn--sm" type="submit" data-loading-on-submit><?= icon('upload', 'icon--sm') ?><?= e(__('media.upload_button')) ?></button>
    </form>
</section>
<?php endif; ?>
