<?php /** @var \App\Support\Paginator $reviews @var string|null $status @var callable $pageUrl */ ?>
<div class="admin-toolbar">
    <nav class="tabs" aria-label="<?= e(__('admin.col_status')) ?>">
        <?php foreach ([null => __('filter.all'), 'published' => __('admin.status_published'), 'hidden' => __('admin.status_hidden')] as $k => $label): ?>
            <a class="tabs__link" href="<?= e(route('admin.reviews.index', [], $k ? ['status' => $k] : [])) ?>"<?= ($status ?? '') === (string) $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="muted small"><?= e(__('admin.reviews_help')) ?></p>
</div>

<?php if ($reviews->items): ?>
<ul class="review-mod" role="list">
    <?php foreach ($reviews->items as $r): ?>
    <li class="card review-mod__item<?= $r['status'] === 'hidden' ? ' is-hidden' : '' ?>">
        <div class="review-mod__head">
            <span class="stars" role="img" aria-label="<?= e(__('admin.rating_of', ['n' => (int) $r['rating']])) ?>"><?= str_repeat('★', (int) $r['rating']) ?><span class="stars__off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span>
            <span class="badge badge--<?= $r['status'] === 'published' ? 'success' : 'warning' ?>"><?= e(__('admin.status_' . $r['status'])) ?></span>
            <?php if ($r['user_status'] !== 'active'): ?><span class="badge badge--danger"><?= e(__('admin.status_suspended')) ?></span><?php endif; ?>
        </div>
        <p class="small"><a href="<?= e(route('admin.books.edit', ['id' => $r['book_id']])) ?>" dir="auto"><?= e($r['book_title']) ?></a> · <span dir="auto"><?= e($r['user_name']) ?></span> · <span class="ltr muted xsmall"><?= e(substr((string) $r['created_at'], 0, 16)) ?> UTC</span></p>
        <?php if ($r['comment'] !== null && $r['comment'] !== ''): ?><p class="review-mod__text" dir="auto"><?= nl2br(e($r['comment'])) ?></p><?php else: ?><p class="muted small"><?= e(__('admin.review_no_comment')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(route('admin.reviews.moderate', ['id' => $r['id']])) ?>"><?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= $r['status'] === 'published' ? 'hidden' : 'published' ?>">
            <input type="hidden" name="redirect" value="<?= e(current_url()) ?>">
            <button class="btn btn--<?= $r['status'] === 'published' ? 'ghost' : 'primary' ?> btn--sm" type="submit"><?= e($r['status'] === 'published' ? __('admin.hide_review') : __('admin.publish_review')) ?></button>
        </form>
    </li>
    <?php endforeach; ?>
</ul>
<?= component('pagination', ['paginator' => $reviews, 'pageUrl' => $pageUrl]) ?>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'star', 'title' => __('admin.reviews_empty')]) ?>
<?php endif; ?>
