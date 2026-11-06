<?php
/** DESIGN PREVIEW with fictional figures. @var list<array> $stats @var list<array> $books @var list<string> $statuses @var list<array> $renderJobs @var list<array> $activity */
$statusBadge = ['published' => 'success', 'draft' => 'warning', 'archived' => '', 'completed' => 'success', 'processing' => 'primary', 'failed' => 'danger', 'queued' => ''];
?>
<?= component('alert', ['type' => 'warning', 'title' => __('demo.label'), 'text' => __('admin.demo_notice')]) ?>

<section id="overview" class="admin-section" aria-labelledby="overview-title">
    <h2 id="overview-title" class="visually-hidden"><?= e(__('admin.overview')) ?></h2>
    <ul class="stat-grid" role="list">
        <?php foreach ($stats as $s): ?>
        <li class="card stat">
            <span class="stat__label"><?= icon($s['icon'], 'icon--sm') ?><?= e($s['label']) ?></span>
            <span class="stat__value"><?= e(format_number($s['value'])) ?></span>
            <span class="stat__note"><?= e(__('admin.demo_figure')) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<div class="admin-grid">
    <section id="books" class="admin-section card" aria-labelledby="books-title">
        <div class="admin-section__head">
            <h2 id="books-title" class="h4"><?= e(__('admin.recent_books')) ?></h2>
            <button type="button" class="btn btn--primary btn--sm" disabled title="<?= e(__('admin.phase5')) ?>"><?= icon('upload', 'icon--sm') ?><?= e(__('admin.add_book')) ?></button>
        </div>
        <div class="table-wrap table-wrap--flush">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('admin.col_title')) ?></th>
                    <th scope="col"><?= e(__('admin.col_status')) ?></th>
                    <th scope="col"><?= e(__('admin.col_language')) ?></th>
                    <th scope="col"><?= e(__('admin.col_pages')) ?></th>
                    <th scope="col"><?= e(__('admin.col_published')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($books as $i => $b): $st = $statuses[$i] ?? 'draft'; ?>
                    <tr>
                        <td class="wrap"><a href="<?= e(route('books.show', ['slug' => $b['slug']])) ?>" lang="<?= e($b['language']) ?>" dir="auto"><?= e($b['title']) ?></a></td>
                        <td><span class="badge badge--<?= e($statusBadge[$st]) ?>"><?= e(__('admin.status_' . $st)) ?></span></td>
                        <td><?= e(language_name($b['language'])) ?></td>
                        <td><?= e(format_number((int) $b['page_count'])) ?></td>
                        <td><?= e(format_date($b['published_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="render-jobs" class="admin-section card" aria-labelledby="jobs-title">
        <div class="admin-section__head"><h2 id="jobs-title" class="h4"><?= e(__('admin.render_jobs')) ?></h2></div>
        <ul class="job-list" role="list">
            <?php foreach ($renderJobs as $j): ?>
            <li class="job">
                <span class="job__title" dir="auto"><?= e($j['title']) ?></span>
                <span class="badge badge--<?= e($statusBadge[$j['status']]) ?>"><?php if ($j['status'] === 'processing'): ?><span class="spinner spinner--sm"></span><?php endif; ?><?= e(__('admin.job_' . $j['status'])) ?></span>
                <span class="muted xsmall ltr"><?= e($j['pages']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="help"><?= e(__('admin.render_help')) ?></p>
    </section>

    <section id="activity" class="admin-section card" aria-labelledby="activity-title">
        <div class="admin-section__head"><h2 id="activity-title" class="h4"><?= e(__('admin.activity')) ?></h2></div>
        <ol class="activity" role="list">
            <?php foreach ($activity as $a): ?>
            <li><span class="activity__dot" aria-hidden="true"></span><div><strong><?= e($a['action']) ?></strong> <span dir="auto">— <?= e($a['entity']) ?></span><br><time class="muted xsmall ltr"><?= e($a['time']) ?></time></div></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="admin-section card" aria-labelledby="form-title">
        <div class="admin-section__head"><h2 id="form-title" class="h4"><?= e(__('admin.quick_book_form')) ?></h2></div>
        <form class="admin-form" action="#" data-preview-form aria-describedby="form-preview-note">
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="a-title"><?= e(__('admin.col_title')) ?><span class="req" aria-hidden="true">*</span></label>
                    <input class="input" id="a-title" maxlength="255" required>
                </div>
                <div class="field">
                    <label class="label" for="a-slug">Slug<span class="req" aria-hidden="true">*</span></label>
                    <input class="input ltr" id="a-slug" pattern="[a-z0-9-]+" required>
                    <p class="help"><?= e(__('admin.slug_help')) ?></p>
                </div>
                <div class="field">
                    <label class="label" for="a-visibility"><?= e(__('admin.visibility')) ?></label>
                    <select class="select" id="a-visibility"><option><?= e(__('admin.visibility_public')) ?></option><option><?= e(__('admin.visibility_members')) ?></option></select>
                </div>
                <div class="field">
                    <label class="label" for="a-rights"><?= e(__('book.rights')) ?><span class="req" aria-hidden="true">*</span></label>
                    <select class="select" id="a-rights" aria-invalid="true" aria-describedby="a-rights-err"><option value=""><?= e(__('admin.choose')) ?></option><option><?= e(__('book.rights_owned')) ?></option><option><?= e(__('book.rights_licensed')) ?></option><option><?= e(__('book.rights_public_domain')) ?></option></select>
                    <p class="error-text" id="a-rights-err"><?= icon('alert', 'icon--sm') ?><?= e(__('admin.rights_required')) ?></p>
                </div>
            </div>
            <div class="field">
                <label class="label" for="a-pdf"><?= e(__('admin.pdf_file')) ?></label>
                <input class="input" id="a-pdf" type="file" accept="application/pdf" disabled>
                <p class="help"><?= e(__('admin.pdf_help')) ?></p>
            </div>
            <p id="form-preview-note" class="help"><?= e(__('admin.form_preview_note')) ?></p>
        </form>
    </section>
</div>
