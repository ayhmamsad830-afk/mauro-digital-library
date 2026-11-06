<?php
/** @var string|null $size sm|md|lg @var string|null $value @var string|null $id */
$size = $size ?? 'md';
$id = $id ?? 'search-' . $size;
?>
<form class="search-bar search-bar--<?= e($size) ?>" role="search" method="get" action="<?= e(route('search')) ?>">
    <label class="visually-hidden" for="<?= e($id) ?>"><?= e(__('search.label')) ?></label>
    <span class="search-bar__icon"><?= icon('search') ?></span>
    <input class="search-bar__input" id="<?= e($id) ?>" type="search" name="q" value="<?= e($value ?? '') ?>"
           placeholder="<?= e(__('search.placeholder')) ?>" maxlength="100" autocomplete="off" enterkeyhint="search">
    <?php if ($size === 'lg'): ?>
        <button class="btn btn--primary search-bar__submit" type="submit"><?= e(__('search.submit')) ?></button>
    <?php else: ?>
        <button class="visually-hidden" type="submit"><?= e(__('search.submit')) ?></button>
    <?php endif; ?>
</form>
