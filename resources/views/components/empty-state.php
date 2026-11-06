<?php /** @var string $title @var string|null $text @var string|null $icon @var string|null $actionUrl @var string|null $actionLabel */ ?>
<div class="empty-state" role="status">
    <span class="empty-state__icon"><?= icon($icon ?? 'book') ?></span>
    <h2 class="empty-state__title"><?= e($title) ?></h2>
    <?php if (!empty($text)): ?><p class="empty-state__text"><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($actionUrl)): ?><a class="btn btn--secondary" href="<?= e($actionUrl) ?>"><?= e($actionLabel ?? '') ?></a><?php endif; ?>
</div>
