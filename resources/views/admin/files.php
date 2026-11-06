<?php /** @var list<array> $files @var string|null $status @var int $freeMb */ ?>
<div class="admin-toolbar">
    <nav class="tabs" aria-label="<?= e(__('admin.col_status')) ?>">
        <?php foreach ([null, 'ready', 'processing', 'pending', 'failed'] as $k): ?>
            <a href="<?= e(route('admin.files.index', [], $k ? ['status' => $k] : [])) ?>"<?= ($status ?? '') === (string) $k ? ' aria-current="page"' : '' ?>><?= e($k ? __($k === 'failed' ? 'pdf.render_failed_status' : 'pdf.render_' . $k) : __('filter.all')) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="muted small"><?= e(__('admin.files_disk', ['mb' => $freeMb === PHP_INT_MAX ? '—' : format_number($freeMb)])) ?></p>
</div>
<?php if ($files): ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr><th scope="col"><?= e(__('admin.col_title')) ?></th><th scope="col"><?= e(__('admin.version')) ?></th><th scope="col"><?= e(__('admin.col_status')) ?></th><th scope="col"><?= e(__('book.pages')) ?></th><th scope="col"><?= e(__('admin.size')) ?></th><th scope="col"><?= e(__('admin.uploaded')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): $current = (int) $f['current_file_id'] === (int) $f['id']; ?>
            <tr>
                <td class="wrap"><a href="<?= e(route('admin.books.edit', ['id' => $f['book_id']])) ?>#pdf" dir="auto"><?= e($f['title']) ?></a></td>
                <td>v<?= e($f['version_number']) ?><?= $current ? ' <span class="badge badge--success">' . e(__('pdf.current')) . '</span>' : '' ?></td>
                <td><span class="badge badge--<?= e(['ready' => 'success', 'failed' => 'danger', 'processing' => 'primary'][$f['render_status']] ?? 'warning') ?>"><?= e(__($f['render_status'] === 'failed' ? 'pdf.render_failed_status' : 'pdf.render_' . $f['render_status'])) ?></span>
                    <?php if ($f['render_status'] === 'failed' && $f['last_error']): ?><br><span class="xsmall muted"><?= e(mb_substr((string) $f['last_error'], 0, 160)) ?></span><?php endif; ?></td>
                <td class="ltr"><?= e(($f['job_status'] ? (int) $f['pages_done'] . ' / ' : '') . (int) $f['page_count']) ?></td>
                <td class="ltr xsmall"><?= e(number_format((int) $f['file_size'] / 1048576, 1)) ?> MB</td>
                <td class="ltr xsmall"><?= e(substr((string) $f['created_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'upload', 'title' => __('admin.files_empty')]) ?>
<?php endif; ?>
