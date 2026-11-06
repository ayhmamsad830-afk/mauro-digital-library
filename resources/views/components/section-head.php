<?php /** @var string $title @var string|null $id @var string|null $sub @var string|null $link @var string|null $linkLabel */ ?>
<div class="section-head">
    <div class="section-head__text">
        <?php if (!empty($sub)): ?><p class="section-head__sub eyebrow"><?= e($sub) ?></p><?php endif; ?>
        <h2 class="section-head__title"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?>><?= e($title) ?></h2>
    </div>
    <?php if (!empty($link)): ?>
        <a class="section-head__link btn btn--ghost" href="<?= e($link) ?>"><?= e($linkLabel ?? __('common.view_all')) ?> <?= icon('arrow-next', 'icon--dir icon--sm') ?></a>
    <?php endif; ?>
</div>
