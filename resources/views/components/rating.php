<?php
/** @var float $avg @var int $count */
if (($count ?? 0) < 1) { return; }
?>
<span class="rating">
    <?= icon('star') ?>
    <span><?= e(number_format((float) $avg, 1)) ?></span>
    <span class="rating__count">(<?= e(format_number((int) $count)) ?>)</span>
    <span class="visually-hidden"><?= e(__('book.rating_sr', ['avg' => number_format((float) $avg, 1), 'count' => (int) $count])) ?></span>
</span>
