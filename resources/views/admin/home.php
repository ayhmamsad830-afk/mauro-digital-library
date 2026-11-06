<?php /** Real dashboard. @var list<array> $stats @var list<array> $recent @var list<array> $activity @var list<array> $renderJobs */ ?>
<section aria-labelledby="overview-title">
    <h2 id="overview-title" class="visually-hidden"><?= e(__('admin.overview')) ?></h2>
    <ul class="stat-grid" role="list">
        <?php foreach ($stats as $s): ?>
        <li class="card stat">
            <span class="stat__label"><?= icon($s['icon'], 'icon--sm') ?><?= e($s['label']) ?></span>
            <span class="stat__value"><?= e(format_number((int) $s['value'])) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<div class="admin-grid">
    <section class="admin-section card" aria-labelledby="recent-title">
        <div class="admin-section__head">
            <h2 id="recent-title" class="h4"><?= e(__('admin.recent_books')) ?></h2>
            <a class="btn btn--primary btn--sm" href="<?= e(route('admin.books.create')) ?>"><?= icon('pen', 'icon--sm') ?><?= e(__('admin.add_book')) ?></a>
        </div>
        <?php if ($recent): ?>
        <div class="table-wrap table-wrap--flush">
            <table class="table">
                <thead><tr><th scope="col"><?= e(__('admin.col_title')) ?></th><th scope="col"><?= e(__('admin.col_status')) ?></th><th scope="col"><?= e(__('admin.col_updated')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $b): ?>
                    <tr>
                        <td class="wrap"><a href="<?= e(route('admin.books.edit', ['id' => $b['id']])) ?>" dir="auto"><?= e($b['title']) ?></a></td>
                        <td><?= \App\Core\View::partial('admin/_status', ['status' => $b['status']]) ?></td>
                        <td class="ltr"><?= e(substr((string) $b['updated_at'], 0, 16)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <?= component('empty-state', ['icon' => 'book', 'title' => __('admin.no_books'), 'text' => __('admin.no_books_text'), 'actionUrl' => route('admin.books.create'), 'actionLabel' => __('admin.add_book')]) ?>
        <?php endif; ?>
    </section>

    <section class="admin-section card" aria-labelledby="jobs-title">
        <div class="admin-section__head"><h2 id="jobs-title" class="h4"><?= e(__('admin.render_jobs')) ?></h2></div>
        <?php if ($renderJobs): ?>
            <ul class="job-list" role="list">
                <?php foreach ($renderJobs as $j): ?>
                <li class="job"><span class="job__title" dir="auto"><?= e($j['title']) ?></span><span class="badge"><?= e(__('admin.job_' . $j['status'])) ?></span><span class="muted xsmall ltr"><?= e((int) $j['pages_done'] . ' / ' . (int) $j['page_count']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted small"><?= e(__('admin.no_render_jobs')) ?></p>
        <?php endif; ?>
        <p class="help"><?= e(__('admin.render_help')) ?></p>
    </section>

    <section class="admin-section card" aria-labelledby="activity-title">
        <div class="admin-section__head"><h2 id="activity-title" class="h4"><?= e(__('admin.activity')) ?></h2></div>
        <?php if ($activity): ?>
        <ol class="activity" role="list">
            <?php foreach ($activity as $a): ?>
            <li><span class="activity__dot" aria-hidden="true"></span><div><strong><?= e(__('admin.log_' . str_replace('.', '_', $a['action']))) ?></strong> <span dir="auto">— <?= e((string) $a['description']) ?></span><br><span class="muted xsmall"><?= e($a['admin_name']) ?> · <span class="ltr"><?= e(substr((string) $a['created_at'], 0, 16)) ?> UTC</span></span></div></li>
            <?php endforeach; ?>
        </ol>
        <?php else: ?>
            <p class="muted small"><?= e(__('admin.no_activity')) ?></p>
        <?php endif; ?>
    </section>
</div>
