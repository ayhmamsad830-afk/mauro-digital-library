<?php /** Loading placeholder for a book grid. @var int|null $count */ ?>
<ul class="book-grid" role="list" aria-hidden="true">
    <?php for ($i = 0; $i < ($count ?? 4); $i++): ?>
        <li><div class="skeleton skeleton--cover"></div><div class="skeleton skeleton--line"></div><div class="skeleton skeleton--line is-short"></div></li>
    <?php endfor; ?>
</ul>
<p class="loading"><span class="spinner spinner--sm"></span><span><?= e(__('common.loading')) ?></span></p>
