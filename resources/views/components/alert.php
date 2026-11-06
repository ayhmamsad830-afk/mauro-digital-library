<?php
/** @var string $type info|success|warning|danger @var string|null $title @var string $text */
$icons = ['info' => 'info', 'success' => 'check', 'warning' => 'alert', 'danger' => 'alert'];
$type = isset($icons[$type ?? '']) ? $type : 'info';
?>
<div class="alert alert--<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>">
    <?= icon($icons[$type]) ?>
    <div>
        <?php if (!empty($title)): ?><strong class="alert__title"><?= e($title) ?></strong><?php endif; ?>
        <p><?= e($text) ?></p>
    </div>
</div>
