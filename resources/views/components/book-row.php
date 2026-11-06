<?php
/** @var array $book @var int|null $page @var int|null $percent @var string|null $note @var string|null $actions (trusted HTML) */
$url = route('books.show', ['slug' => $book['slug']]);
?>
<div class="book-row">
    <?= component('cover', ['book' => $book]) ?>
    <div class="stack">
        <p class="book-row__title" lang="<?= e($book['language']) ?>" dir="auto"><a href="<?= e($url) ?>"><?= e($book['title']) ?></a></p>
        <p class="muted small"><?= e(book_authors($book)) ?></p>
        <?php if (isset($percent)): ?>
            <progress class="progress" max="100" value="<?= e((int) $percent) ?>" aria-label="<?= e(__('account.progress_label', ['percent' => (int) $percent])) ?>"></progress>
            <p class="xsmall muted"><?= e(__('account.page_of', ['page' => (int) ($page ?? 1), 'total' => (int) ($book['page_count'] ?? 0)])) ?> · <?= e((int) $percent) ?>%</p>
        <?php endif; ?>
        <?php if (!empty($note)): ?><p class="small muted"><?= e($note) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($actions)): ?><div class="book-row__actions btn-group"><?= $actions ?></div><?php endif; ?>
</div>
