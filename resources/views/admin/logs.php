<?php /** @var \App\Support\Paginator $logs @var list<string> $types @var string|null $type @var callable $pageUrl */ ?>
<div class="admin-toolbar">
    <form class="admin-filter" method="get" action="<?= e(route('admin.logs.index')) ?>">
        <label class="visually-hidden" for="al-type"><?= e(__('admin.log_entity')) ?></label>
        <select class="select" id="al-type" name="type" data-autosubmit>
            <option value=""><?= e(__('filter.all')) ?></option>
            <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= e(__('admin.entity_' . $t)) ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn--ghost" type="submit"><?= e(__('filter.apply')) ?></button></noscript>
    </form>
    <p class="muted small"><?= e(__('admin.logs_help')) ?></p>
</div>
<?php if ($logs->items): ?>
<div class="table-wrap">
    <table class="table">
        <thead><tr><th scope="col"><?= e(__('admin.log_when')) ?></th><th scope="col"><?= e(__('admin.log_who')) ?></th><th scope="col"><?= e(__('admin.log_action')) ?></th><th scope="col"><?= e(__('admin.log_details')) ?></th><th scope="col">IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs->items as $l): ?>
            <tr>
                <td class="ltr xsmall"><?= e(substr((string) $l['created_at'], 0, 16)) ?> UTC</td>
                <td dir="auto"><?= e($l['admin_name']) ?></td>
                <td><?= e(__('admin.log_' . str_replace('.', '_', $l['action']))) ?></td>
                <td class="wrap" dir="auto"><?= e((string) $l['description']) ?><?= $l['entity_id'] ? ' <span class="muted xsmall">#' . e($l['entity_id']) . '</span>' : '' ?></td>
                <td class="ltr xsmall"><?= e((string) $l['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= component('pagination', ['paginator' => $logs, 'pageUrl' => $pageUrl]) ?>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'list', 'title' => __('admin.no_activity')]) ?>
<?php endif; ?>
