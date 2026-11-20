<?php /** @var list<array{name:string,type:string,size:int,modified:int,checksum:string,integrity:string,detail:string}> $backups @var array|null $latest */ ?>
<div class="admin-toolbar">
    <p class="muted small"><?= e(__('admin.backups_help')) ?></p>
</div>

<div class="stat-grid stat-grid--compact">
    <div class="stat card">
        <span class="stat__label"><?= e(__('admin.backups_latest')) ?></span>
        <?php if ($latest): ?>
            <span class="stat__value ltr"><?= e(gmdate('Y-m-d H:i', $latest['modified'])) ?> UTC</span>
            <span class="badge badge--success"><?= e(__('admin.backup_ok')) ?></span>
        <?php else: ?>
            <span class="stat__value"><?= e(__('admin.backups_none')) ?></span>
            <span class="badge badge--warning"><?= e(__('admin.backups_none_help')) ?></span>
        <?php endif; ?>
    </div>
    <div class="stat card">
        <span class="stat__label"><?= e(__('admin.backups_count')) ?></span>
        <span class="stat__value"><?= e(format_number(count($backups))) ?></span>
    </div>
</div>

<?php if ($backups): ?>
<div class="table-wrap">
    <table class="table table--stack backups-table">
        <thead>
            <tr>
                <th scope="col"><?= e(__('admin.backup_file')) ?></th>
                <th scope="col"><?= e(__('admin.backup_type')) ?></th>
                <th scope="col"><?= e(__('admin.backup_date')) ?></th>
                <th scope="col"><?= e(__('admin.backup_size')) ?></th>
                <th scope="col"><?= e(__('admin.backup_checksum')) ?></th>
                <th scope="col"><?= e(__('admin.backup_integrity')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
            <tr>
                <td class="ltr xsmall backups-table__name"><?= e($b['name']) ?></td>
                <td data-label="<?= e(__('admin.backup_type')) ?>"><?= e(__('admin.backup_type_' . $b['type'])) ?></td>
                <td class="ltr xsmall" data-label="<?= e(__('admin.backup_date')) ?>"><?= e(gmdate('Y-m-d H:i', $b['modified'])) ?> UTC</td>
                <td class="ltr xsmall" data-label="<?= e(__('admin.backup_size')) ?>"><?= e(number_format($b['size'] / 1048576, 2)) ?> MB</td>
                <td data-label="<?= e(__('admin.backup_checksum')) ?>"><span class="badge badge--<?= e(['match' => 'success', 'missing' => 'outline', 'skipped' => 'outline', 'mismatch' => 'danger'][$b['checksum']] ?? 'outline') ?>"><?= e(__('admin.backup_checksum_' . $b['checksum'])) ?></span></td>
                <td data-label="<?= e(__('admin.backup_integrity')) ?>"><span class="badge badge--<?= e(['ok' => 'success', 'invalid' => 'danger', 'unchecked' => 'outline'][$b['integrity']] ?? 'outline') ?>"><?= e(__('admin.backup_integrity_' . $b['integrity'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'layers', 'title' => __('admin.backups_none'), 'text' => __('admin.backups_none_help')]) ?>
<?php endif; ?>

<div class="card admin-note">
    <h2 class="h4"><?= icon('info', 'icon--sm') ?><?= e(__('admin.backups_howto_title')) ?></h2>
    <p class="small"><?= e(__('admin.backups_howto_text')) ?></p>
    <code class="ltr small">php bin/console backup:run</code>
</div>
