<?php
/** Language switch (POST + CSRF). @var string|null $variant */
$other = locale() === 'ar' ? 'en' : 'ar';
?>
<form method="post" action="<?= e(route('locale.update')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="locale" value="<?= e($other) ?>">
    <input type="hidden" name="redirect" value="<?= e($currentUri ?? '/') ?>">
    <button type="submit" class="btn btn--ghost btn--sm" lang="<?= e($other) ?>" aria-label="<?= e(__('pref.switch_to_label')) ?>">
        <?= icon('globe', 'icon--sm') ?><span><?= e(__('pref.switch_to')) ?></span>
    </button>
</form>
