<?php
/** @var \App\Support\Paginator $paginator @var callable(int):string $pageUrl */
if ($paginator->lastPage() <= 1) { return; }
?>
<nav class="pagination" aria-label="<?= e(__('pagination.label')) ?>">
    <ul>
        <li>
            <?php if ($paginator->hasPrevious()): ?>
                <a href="<?= e($pageUrl($paginator->page - 1)) ?>" rel="prev" aria-label="<?= e(__('pagination.previous')) ?>"><?= icon('chevron-prev', 'icon--dir icon--sm') ?></a>
            <?php else: ?>
                <span class="is-disabled" aria-hidden="true"><?= icon('chevron-prev', 'icon--dir icon--sm') ?></span>
            <?php endif; ?>
        </li>
        <?php foreach ($paginator->window() as $p): ?>
            <li>
                <?php if ($p === null): ?><span aria-hidden="true">…</span>
                <?php elseif ($p === $paginator->page): ?><span aria-current="page"><?= e($p) ?></span>
                <?php else: ?><a href="<?= e($pageUrl($p)) ?>" aria-label="<?= e(__('pagination.page', ['page' => $p])) ?>"><?= e($p) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li>
            <?php if ($paginator->hasNext()): ?>
                <a href="<?= e($pageUrl($paginator->page + 1)) ?>" rel="next" aria-label="<?= e(__('pagination.next')) ?>"><?= icon('chevron-next', 'icon--dir icon--sm') ?></a>
            <?php else: ?>
                <span class="is-disabled" aria-hidden="true"><?= icon('chevron-next', 'icon--dir icon--sm') ?></span>
            <?php endif; ?>
        </li>
    </ul>
</nav>
