<?php
/** @var array|null $book @var list<array> $authors @var list<array> $categories @var list<string> $languages @var list<string> $blockers */
$v = static fn (string $k, $default = '') => old($k, $book[$k] ?? $default);
$inv = static fn (string $k) => field_error($k) ? ' aria-invalid="true" aria-describedby="f-' . $k . '-error"' : '';
$selAuthors = array_map('intval', (array) old('author_ids', $book['author_ids'] ?? []));
$selCats = array_map('intval', (array) old('category_ids', $book['category_ids'] ?? []));
$action = $book ? route('admin.books.update', ['id' => $book['id']]) : route('admin.books.store');
$field = static fn (array $d) => \App\Core\View::partial('admin/_field', $d);
?>
<div class="admin-form-layout">
<form class="card card--pad admin-book-form" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($book): ?><?= method_field('PUT') ?><?php endif; ?>

    <fieldset>
        <legend><?= e(__('admin.section_metadata')) ?></legend>
        <div class="form-grid">
            <?= $field(['name' => 'title', 'label' => __('admin.col_title'), 'required' => true,
                'control' => '<input class="input" id="f-title" name="title" maxlength="255" required dir="auto" value="' . e($v('title')) . '"' . $inv('title') . '>']) ?>
            <?= $field(['name' => 'slug', 'label' => 'Slug', 'help' => __('admin.slug_help_auto'),
                'control' => '<input class="input ltr" id="f-slug" name="slug" maxlength="191" pattern="[a-z0-9-]+" value="' . e($v('slug')) . '"' . $inv('slug') . '>']) ?>
            <?= $field(['name' => 'title_en', 'label' => __('admin.title_en'), 'help' => __('admin.title_en_help'),
                'control' => '<input class="input" id="f-title_en" name="title_en" maxlength="255" dir="ltr" lang="en" value="' . e($v('title_en')) . '"' . $inv('title_en') . '>']) ?>
        </div>
        <?php
        $langOpts = '';
        foreach ($languages as $l) { $langOpts .= '<option value="' . e($l) . '"' . ($v('language', 'ar') === $l ? ' selected' : '') . '>' . e(language_name($l)) . '</option>'; }
        $visOpts = '';
        foreach (['public', 'members'] as $vis) { $visOpts .= '<option value="' . $vis . '"' . ($v('visibility', 'public') === $vis ? ' selected' : '') . '>' . e(__('admin.visibility_' . $vis)) . '</option>'; }
        ?>
        <div class="form-grid">
            <?= $field(['name' => 'language', 'label' => __('book.language'), 'required' => true, 'control' => '<select class="select" id="f-language" name="language"' . $inv('language') . '>' . $langOpts . '</select>']) ?>
            <?= $field(['name' => 'visibility', 'label' => __('admin.visibility'), 'required' => true, 'control' => '<select class="select" id="f-visibility" name="visibility"' . $inv('visibility') . '>' . $visOpts . '</select>']) ?>
            <?= $field(['name' => 'publisher', 'label' => __('book.publisher'), 'control' => '<input class="input" id="f-publisher" name="publisher" maxlength="255" dir="auto" value="' . e($v('publisher')) . '"' . $inv('publisher') . '>']) ?>
            <?= $field(['name' => 'publication_year', 'label' => __('book.year'), 'control' => '<input class="input ltr" id="f-publication_year" name="publication_year" inputmode="numeric" maxlength="4" value="' . e((string) $v('publication_year')) . '"' . $inv('publication_year') . '>']) ?>
            <?= $field(['name' => 'edition', 'label' => __('book.edition'), 'control' => '<input class="input" id="f-edition" name="edition" maxlength="100" dir="auto" value="' . e($v('edition')) . '"' . $inv('edition') . '>']) ?>
            <?= $field(['name' => 'isbn', 'label' => 'ISBN', 'control' => '<input class="input ltr" id="f-isbn" name="isbn" maxlength="20" inputmode="numeric" value="' . e($v('isbn')) . '"' . $inv('isbn') . '>']) ?>
        </div>
        <?= $field(['name' => 'description', 'label' => __('admin.description'), 'help' => __('admin.description_help'),
            'control' => '<textarea class="textarea" id="f-description" name="description" rows="6" maxlength="20000" dir="auto"' . $inv('description') . '>' . e($v('description')) . '</textarea>']) ?>
        <?= $field(['name' => 'description_en', 'label' => __('admin.description_en'), 'help' => __('admin.description_en_help'),
            'control' => '<textarea class="textarea" id="f-description_en" name="description_en" rows="5" maxlength="20000" dir="ltr" lang="en"' . $inv('description_en') . '>' . e($v('description_en')) . '</textarea>']) ?>
        <label class="check"><input type="checkbox" name="is_featured" value="1"<?= (int) $v('is_featured', 0) ? ' checked' : '' ?>> <?= e(__('admin.featured')) ?></label>
    </fieldset>

    <fieldset>
        <legend><?= e(__('admin.section_people')) ?></legend>
        <div class="form-grid">
            <div class="field">
                <span class="label" id="authors-label"><?= e(__('admin.authors')) ?><span class="req" aria-hidden="true">*</span></span>
                <?php if ($authors): ?>
                <div class="checklist" role="group" aria-labelledby="authors-label">
                    <?php foreach ($authors as $a): ?>
                        <label class="check"><input type="checkbox" name="author_ids[]" value="<?= e($a['id']) ?>"<?= in_array($a['id'], $selAuthors, true) ? ' checked' : '' ?>> <span dir="auto"><?= e($a['name']) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><p class="help"><?= e(__('admin.no_authors_yet')) ?> <a href="<?= e(route('admin.authors.create')) ?>"><?= e(__('admin.add_author')) ?></a></p><?php endif; ?>
                <?php if ($err = field_error('author_ids')): ?><p class="error-text"><?= icon('alert', 'icon--sm') ?><?= e($err) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <span class="label" id="cats-label"><?= e(__('admin.categories')) ?><span class="req" aria-hidden="true">*</span></span>
                <?php if ($categories): ?>
                <div class="checklist" role="group" aria-labelledby="cats-label">
                    <?php foreach ($categories as $c): ?>
                        <label class="check"><input type="checkbox" name="category_ids[]" value="<?= e($c['id']) ?>"<?= in_array((int) $c['id'], $selCats, true) ? ' checked' : '' ?>> <?= e(localized($c, 'name')) ?><?= $c['status'] === 'inactive' ? ' <span class="badge">' . e(__('admin.status_inactive')) . '</span>' : '' ?></label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><p class="help"><?= e(__('admin.no_categories_yet')) ?> <a href="<?= e(route('admin.categories.create')) ?>"><?= e(__('admin.add_category')) ?></a></p><?php endif; ?>
                <?php if ($err = field_error('category_ids')): ?><p class="error-text"><?= icon('alert', 'icon--sm') ?><?= e($err) ?></p><?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= e(__('admin.section_rights')) ?></legend>
        <?php
        $rightsOpts = '<option value="">' . e(__('admin.choose')) . '</option>';
        foreach (['owned', 'licensed', 'public_domain'] as $r) { $rightsOpts .= '<option value="' . $r . '"' . ($v('rights_status') === $r ? ' selected' : '') . '>' . e(__('book.rights_' . $r)) . '</option>'; }
        ?>
        <div class="form-grid">
            <?= $field(['name' => 'rights_status', 'label' => __('book.rights'), 'help' => __('admin.rights_required'), 'control' => '<select class="select" id="f-rights_status" name="rights_status"' . $inv('rights_status') . '>' . $rightsOpts . '</select>']) ?>
            <?= $field(['name' => 'license_name', 'label' => __('admin.license_name'), 'control' => '<input class="input" id="f-license_name" name="license_name" maxlength="255" dir="auto" value="' . e($v('license_name')) . '"' . $inv('license_name') . '>']) ?>
            <?= $field(['name' => 'rights_holder', 'label' => __('admin.rights_holder'), 'control' => '<input class="input" id="f-rights_holder" name="rights_holder" maxlength="255" dir="auto" value="' . e($v('rights_holder')) . '"' . $inv('rights_holder') . '>']) ?>
        </div>
        <?= $field(['name' => 'rights_source', 'label' => __('admin.rights_source'), 'help' => __('admin.rights_source_help'),
            'control' => '<textarea class="textarea" id="f-rights_source" name="rights_source" rows="3" maxlength="2000" dir="auto"' . $inv('rights_source') . '>' . e($v('rights_source')) . '</textarea>']) ?>
    </fieldset>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit" data-loading-on-submit><?= e(__('styleguide.save')) ?></button>
        <a class="btn btn--ghost" href="<?= e(route('admin.books.index')) ?>"><?= e(__('styleguide.cancel')) ?></a>
    </div>
</form>

<?php if ($book): ?>
<aside class="admin-side">
    <section class="card card--pad" aria-labelledby="status-title">
        <h2 id="status-title" class="h4"><?= e(__('admin.col_status')) ?></h2>
        <p><?= \App\Core\View::partial('admin/_status', ['status' => $book['status']]) ?>
            <?php if ($book['published_at']): ?><span class="muted xsmall ltr"><?= e(substr((string) $book['published_at'], 0, 16)) ?> UTC</span><?php endif; ?></p>

        <?php if ($book['status'] !== 'published' && $blockers): ?>
            <p class="small"><strong><?= e(__('admin.before_publish')) ?></strong></p>
            <ul class="plain-list small blockers"><?php foreach ($blockers as $bl): ?><li><?= icon('alert', 'icon--sm') ?> <?= e($bl) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <div class="btn-group admin-actions">
            <?php if ($book['status'] === 'draft'): ?>
                <form method="post" action="<?= e(route('admin.books.publish', ['id' => $book['id']])) ?>"><?= csrf_field() ?><button class="btn btn--primary btn--sm" type="submit"<?= $blockers ? ' disabled aria-describedby="status-title"' : '' ?>><?= e(__('admin.publish')) ?></button></form>
            <?php endif; ?>
            <?php if ($book['status'] === 'published'): ?>
                <form method="post" action="<?= e(route('admin.books.unpublish', ['id' => $book['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('admin.unpublish')) ?></button></form>
                <a class="btn btn--ghost btn--sm" href="<?= e(route('books.show', ['slug' => $book['slug']])) ?>"><?= e(__('admin.view_public')) ?></a>
            <?php endif; ?>
            <?php if ($book['status'] !== 'archived'): ?>
                <form method="post" action="<?= e(route('admin.books.archive', ['id' => $book['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('admin.archive')) ?></button></form>
            <?php else: ?>
                <form method="post" action="<?= e(route('admin.books.restore', ['id' => $book['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('admin.restore')) ?></button></form>
            <?php endif; ?>
        </div>
    </section>

    <section class="card card--pad" id="cover" aria-labelledby="cover-title">
        <h2 id="cover-title" class="h4"><?= e(__('media.cover')) ?></h2>
        <?php if (!empty($book['cover_path'])): ?>
            <img class="admin-cover-preview" src="<?= e(route('admin.media.cover', ['id' => $book['id']]) . '?v=' . \App\Services\Media\ImageUploadService::version((string) $book['cover_path'])) ?>" alt="<?= e(__('book.cover_alt', ['title' => $book['title']])) ?>" width="160" height="240">
            <form method="post" action="<?= e(route('admin.books.cover.delete', ['id' => $book['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('media.remove_cover')) ?></button></form>
        <?php else: ?>
            <p class="muted small"><?= e(__('media.no_cover')) ?></p>
        <?php endif; ?>
        <form class="upload-form" data-uploading="<?= e(__('upload.in_progress')) ?>" method="post" action="<?= e(route('admin.books.cover', ['id' => $book['id']])) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="label" for="f-cover"><?= e(__('media.upload_cover')) ?></label>
            <input class="input" id="f-cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp" required>
            <p class="help"><?= e(__('media.cover_help', ['mb' => (int) max(1, round((int) config('storage.cover_max_bytes', 2097152) / 1048576))])) ?></p>
            <button class="btn btn--secondary btn--sm" type="submit" data-loading-on-submit><?= icon('upload', 'icon--sm') ?><?= e(__('media.upload_button')) ?></button>
        </form>
    </section>

    <section class="card card--pad" aria-labelledby="file-title">
        <h2 id="file-title" class="h4"><?= e(__('admin.pdf_file')) ?></h2>
        <?php if ($book['current_file']): $f = $book['current_file']; ?>
            <p class="small"><span class="badge badge--success"><?= e(__('pdf.active_version', ['v' => $f['version_number']])) ?></span>
                <span class="muted"><?= e(__('book.pages')) ?>: <?= e((int) $f['page_count']) ?></span></p>
        <?php else: ?>
            <p class="muted small"><?= e(__('admin.file_none_text')) ?></p>
        <?php endif; ?>

        <form class="upload-form" data-uploading="<?= e(__('upload.in_progress')) ?>" method="post" action="<?= e(route('admin.books.upload', ['id' => $book['id']])) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="label" for="f-pdf"><?= e($book['current_file'] ? __('pdf.replace') : __('pdf.upload')) ?></label>
            <input class="input" id="f-pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
            <p class="help"><?= e(__('pdf.upload_help', ['max' => $maxUploadMb])) ?></p>
            <button class="btn btn--primary btn--sm" type="submit" data-loading-on-submit><?= icon('upload', 'icon--sm') ?><?= e(__('pdf.upload_button')) ?></button>
        </form>

        <?php if ($versions): ?>
        <h3 class="xsmall muted versions-title"><?= e(__('pdf.versions')) ?></h3>
        <ul class="version-list" role="list">
            <?php foreach ($versions as $ver):
                $isCurrent = (int) $ver['id'] === (int) ($book['current_file_id'] ?? 0);
                $rs = $ver['render_status'];
                $badge = ['ready' => 'success', 'processing' => 'primary', 'pending' => 'warning', 'failed' => 'danger'][$rs] ?? ''; ?>
            <li class="version">
                <div class="version__head">
                    <strong>v<?= e($ver['version_number']) ?></strong>
                    <span class="badge badge--<?= e($badge) ?>"><?= e(__($rs === 'failed' ? 'pdf.render_failed_status' : 'pdf.render_' . $rs)) ?></span>
                    <?php if ($isCurrent): ?><span class="badge badge--success"><?= e(__('pdf.current')) ?></span><?php endif; ?>
                </div>
                <p class="xsmall muted ltr"><?= e((int) $ver['page_count']) ?> p · <?= e(round((int) $ver['file_size'] / 1048576, 2)) ?> MB · <?= e(substr((string) $ver['created_at'], 0, 16)) ?> UTC</p>
                <?php if ($rs === 'processing' || ($rs === 'pending' && (int) $ver['pages_done'] > 0)): ?>
                    <progress class="progress" max="<?= e(max(1, (int) $ver['page_count'])) ?>" value="<?= e((int) $ver['pages_done']) ?>" aria-label="<?= e(__('pdf.render_processing')) ?>"></progress>
                <?php endif; ?>
                <?php if ($ver['last_error']): ?><p class="error-text xsmall"><?= icon('alert', 'icon--sm') ?><?= e($ver['last_error']) ?> (<?= e((int) $ver['attempts']) ?>×)</p><?php endif; ?>
                <?php if ($ver['job_status'] === 'queued'): ?>
                    <?php if ((int) $ver['page_count'] <= $syncMaxPages): ?>
                        <form method="post" action="<?= e(route('admin.books.files.process', ['id' => $book['id'], 'fileId' => $ver['id']])) ?>"><?= csrf_field() ?><button class="btn btn--secondary btn--sm" type="submit" data-loading-on-submit><?= e(__('pdf.process_now')) ?></button></form>
                    <?php else: ?><p class="help"><?= e(__('pdf.use_worker', ['max' => $syncMaxPages])) ?></p><?php endif; ?>
                <?php elseif ($ver['job_status'] === 'failed'): ?>
                    <form method="post" action="<?= e(route('admin.books.files.retry', ['id' => $book['id'], 'fileId' => $ver['id']])) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= e(__('pdf.retry')) ?></button></form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <p class="help"><?= e(__('pdf.private_note')) ?></p>
    </section>
</aside>
<?php endif; ?>
</div>
