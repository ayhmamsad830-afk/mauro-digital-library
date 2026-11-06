<?php
/**
 * Labelled form field with error + help. @var string $name @var string $label @var string $control (trusted HTML)
 * @var bool|null $required @var string|null $help @var string|null $id
 */
$id = $id ?? 'f-' . $name;
$err = field_error($name);
?>
<div class="field">
    <label class="label" for="<?= e($id) ?>"><?= e($label) ?><?php if (!empty($required)): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
    <?= $control ?>
    <?php if (!empty($help)): ?><p class="help" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="error-text" id="<?= e($id) ?>-error"><?= icon('alert', 'icon--sm') ?><?= e($err) ?></p><?php endif; ?>
</div>
