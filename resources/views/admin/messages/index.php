<?php /** @var \App\Support\Paginator $messages @var string|null $status @var bool $emailDelivery @var callable $pageUrl */ ?>
<div class="admin-toolbar">
    <nav class="tabs" aria-label="<?= e(__('admin.col_status')) ?>">
        <?php foreach ([null => __('admin.messages_open'), 'new' => __('admin.message_status_new'), 'read' => __('admin.message_status_read'), 'archived' => __('admin.message_status_archived')] as $k => $label): ?>
            <a class="tabs__link" href="<?= e(route('admin.messages.index', [], $k ? ['status' => $k] : [])) ?>"<?= ($status ?? '') === (string) $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="muted small"><?= e(__($emailDelivery ? 'admin.messages_help_email' : 'admin.messages_help_stored')) ?></p>
</div>

<?php if ($messages->items): ?>
<ul class="review-mod" role="list">
    <?php foreach ($messages->items as $m): ?>
    <li class="card review-mod__item<?= $m['status'] === 'archived' ? ' is-hidden' : '' ?>">
        <div class="review-mod__head">
            <strong dir="auto"><?= e($m['subject']) ?></strong>
            <span class="badge badge--<?= e(['new' => 'primary', 'read' => 'outline', 'archived' => 'outline'][$m['status']] ?? 'outline') ?>"><?= e(__('admin.message_status_' . $m['status'])) ?></span>
            <span class="badge badge--<?= e(['sent' => 'success', 'failed' => 'danger', 'not_configured' => 'outline'][$m['notify_status']] ?? 'outline') ?>"><?= e(__('admin.message_notify_' . $m['notify_status'])) ?></span>
        </div>
        <p class="small"><span dir="auto"><?= e($m['name']) ?></span> · <a class="ltr" href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a> · <span class="ltr muted xsmall"><?= e($m['created_at']) ?> UTC</span> · <span class="muted xsmall"><?= e(language_name($m['locale'])) ?></span></p>
        <p class="review-mod__text" dir="auto"><?= nl2br(e($m['message'])) ?></p>
        <div class="review-mod__actions">
            <?php foreach (array_diff(\App\Services\ContactService::STATUSES, [$m['status']]) as $next): ?>
            <form method="post" action="<?= e(route('admin.messages.status', ['id' => $m['id']])) ?>"><?= csrf_field() ?>
                <input type="hidden" name="status" value="<?= e($next) ?>">
                <input type="hidden" name="redirect" value="<?= e(current_url()) ?>">
                <button class="btn btn--<?= $next === 'read' ? 'primary' : 'ghost' ?> btn--sm" type="submit"><?= e(__('admin.message_mark_' . $next)) ?></button>
            </form>
            <?php endforeach; ?>
        </div>
    </li>
    <?php endforeach; ?>
</ul>
<?= component('pagination', ['paginator' => $messages, 'pageUrl' => $pageUrl]) ?>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'mail', 'title' => __('admin.messages_empty')]) ?>
<?php endif; ?>
